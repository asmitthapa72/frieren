<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\network;

class ModuleLinuxHelper
{
    const DHCP_LEASE_FILES = [
        '/var/lib/misc/dnsmasq.leases',
        '/var/lib/dnsmasq/dnsmasq.leases',
        '/var/lib/NetworkManager/dnsmasq.leases',
    ];

    public static function runPing($host)
    {
        return self::runTextCommand('ping', ['-c', '5', '-W', '2', '--', $host]);
    }

    public static function runTraceroute($host)
    {
        return self::runTextCommand('traceroute', ['-q', '1', '-w', '1', '-m', '15', '--', $host]);
    }

    public static function runNslookup($host)
    {
        return self::runTextCommand('nslookup', [$host]);
    }

    public static function getArpTable()
    {
        $result = self::runCommand('ip', ['-json', 'neigh']);
        if (!$result['ok']) {
            return [];
        }

        $entries = json_decode($result['stdout'], true);
        if (!is_array($entries)) {
            return [];
        }

        $neighbors = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['dst'], $entry['dev'])) {
                continue;
            }

            $state = $entry['state'] ?? '';
            if (is_array($state)) {
                $state = implode(',', $state);
            }
            $neighbors[] = [
                'ip' => (string)$entry['dst'],
                'mac' => (string)($entry['lladdr'] ?? ''),
                'device' => (string)$entry['dev'],
                'state' => is_string($state) ? $state : '',
            ];
        }

        return $neighbors;
    }

    public static function getDhcpLeases()
    {
        $leases = [];
        foreach (self::getLeaseFiles() as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (!is_array($parts) || count($parts) < 4 || !ctype_digit($parts[0])) {
                    continue;
                }
                if (!self::isValidMac($parts[1]) || !self::isValidIpv4($parts[2])) {
                    continue;
                }

                $leases[] = [
                    'hostname' => $parts[3] === '*' ? '' : $parts[3],
                    'ip' => $parts[2],
                    'mac' => $parts[1],
                    'expires' => (int)$parts[0],
                ];
            }
        }

        return $leases;
    }

    public static function staticLeaseExists($mac)
    {
        // No DHCP server backend is configured by this desktop-client helper.
        return false;
    }

    public static function getStaticLeases()
    {
        return [];
    }

    public static function addStaticLease($name, $mac, $ip)
    {
        return false;
    }

    public static function deleteStaticLease($mac)
    {
        return false;
    }

    public static function getInterfaces()
    {
        $profiles = self::getConnectionProfiles();
        $activeConnections = self::getActiveConnections();
        $interfaces = [];

        foreach ($profiles as $profile) {
            if (!in_array($profile['type'], ['802-3-ethernet', 'wifi', 'wireguard', 'vlan', 'bridge', 'bond', 'team'], true)) {
                continue;
            }
            if ($profile['type'] === 'wifi' && !isset($activeConnections[$profile['uuid']])) {
                continue;
            }

            $details = self::getConnectionDetails($profile['uuid']);
            if ($details === null) {
                continue;
            }

            $name = $details['connection.id'] ?? $profile['name'];
            $device = $activeConnections[$profile['uuid']] ?? ($details['connection.interface-name'] ?? null);
            $proto = self::profileProtocol($details);
            $addresses = self::splitList($details['ipv4.addresses'] ?? '');
            $ipaddr = null;
            $netmask = null;
            if ($addresses && preg_match('/^([^\/]+)\/(\d{1,2})$/', $addresses[0], $match)) {
                $ipaddr = $match[1];
                $netmask = self::cidrToNetmask((int)$match[2]);
            }

            $interfaces[] = [
                'name' => $name,
                'proto' => $proto,
                'up' => isset($activeConnections[$profile['uuid']]),
                'ipaddr' => $ipaddr,
                'netmask' => $netmask,
                'gateway' => ($details['ipv4.gateway'] ?? '') !== '' ? $details['ipv4.gateway'] : null,
                'dns' => self::splitList($details['ipv4.dns'] ?? ''),
                'uptime' => 0,
                'device' => $device !== '' ? $device : null,
                'mtu' => ($details['802-3-ethernet.mtu'] ?? '') !== '' ? $details['802-3-ethernet.mtu'] : null,
                'macaddr' => ($profile['type'] === 'wifi'
                    ? ($details['802-11-wireless.cloned-mac-address'] ?? '')
                    : ($details['802-3-ethernet.cloned-mac-address'] ?? '')) !== ''
                    ? ($profile['type'] === 'wifi'
                        ? $details['802-11-wireless.cloned-mac-address']
                        : $details['802-3-ethernet.cloned-mac-address'])
                    : null,
                'peerdns' => ($details['ipv4.ignore-auto-dns'] ?? 'no') !== 'yes',
            ];
        }

        return $interfaces;
    }

    public static function interfaceExists($name)
    {
        foreach (self::getConnectionProfiles() as $profile) {
            $details = self::getConnectionDetails($profile['uuid']);
            if ($details !== null && ($details['connection.id'] ?? $profile['name']) === $name) {
                return true;
            }
        }

        return false;
    }

    public static function getAvailableDevices()
    {
        $result = self::runCommand('ip', ['-json', 'link', 'show']);
        if (!$result['ok']) {
            return [];
        }

        $links = json_decode($result['stdout'], true);
        if (!is_array($links)) {
            return [];
        }

        $devices = [];
        foreach ($links as $link) {
            if (is_array($link) && isset($link['ifname']) && $link['ifname'] !== 'lo') {
                $devices[] = (string)$link['ifname'];
            }
        }
        sort($devices, SORT_STRING);

        return array_values(array_unique($devices));
    }

    public static function addInterface($name, $device, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu = '', $macaddr = '', $peerdns = true)
    {
        if (!self::isValidName($name)
            || !in_array($proto, ['static', 'dhcp', 'dhcpv6'], true)
            || self::interfaceExists($name)
            || !in_array($device, self::getAvailableDevices(), true)
            || !self::isValidOptionalSettings($mtu, $macaddr)
            || !self::isValidAddressSettings($proto, $ipaddr, $netmask, $gateway, $dns)) {
            return false;
        }

        $type = self::deviceConnectionType($device);
        if ($type === null || ($type === 'wifi' && $mtu !== '')) {
            return false;
        }

        $args = ['connection', 'add', 'type', $type, 'ifname', $device, 'con-name', $name];
        $args = array_merge($args, self::profileSettings($type, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu, $macaddr, $peerdns));
        return self::runCommand('nmcli', $args)['ok'];
    }

    public static function removeInterface($name)
    {
        $profile = self::findProfile($name);
        if ($profile === null) {
            return false;
        }

        return self::runCommand('nmcli', ['connection', 'delete', 'uuid', $profile['uuid']])['ok'];
    }

    public static function setInterface($name, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu = '', $macaddr = '', $peerdns = true)
    {
        $profile = self::findProfile($name);
        if ($profile === null || !in_array($profile['type'], ['ethernet', 'wifi'], true)
            || ($profile['type'] === 'wifi' && $mtu !== '')
            || !in_array($proto, ['static', 'dhcp', 'dhcpv6'], true)
            || !self::isValidOptionalSettings($mtu, $macaddr)
            || !self::isValidAddressSettings($proto, $ipaddr, $netmask, $gateway, $dns)) {
            return false;
        }

        $args = array_merge(
            ['connection', 'modify', 'uuid', $profile['uuid']],
            self::profileSettings($profile['type'], $proto, $ipaddr, $netmask, $gateway, $dns, $mtu, $macaddr, $peerdns)
        );
        return self::runCommand('nmcli', $args)['ok'];
    }

    public static function toggleInterface($name, $action)
    {
        if (!in_array($action, ['up', 'down', 'restart'], true)) {
            return false;
        }
        $profile = self::findProfile($name);
        if ($profile === null) {
            return false;
        }

        if ($action === 'restart') {
            $down = self::runCommand('nmcli', ['connection', 'down', 'uuid', $profile['uuid']]);
            if (!$down['ok']) {
                return false;
            }
            return self::runCommand('nmcli', ['connection', 'up', 'uuid', $profile['uuid']])['ok'];
        }

        return self::runCommand('nmcli', ['connection', $action, 'uuid', $profile['uuid']])['ok'];
    }

    private static function runTextCommand($command, $arguments)
    {
        $result = self::runCommand($command, $arguments);
        $output = trim($result['stdout'] . ($result['stderr'] !== '' ? $result['stderr'] : ''));

        return $output !== '' ? $output : 'No response';
    }

    /** Executes an argv vector directly; no shell parses user- or system-derived values. */
    private static function runCommand($command, $arguments)
    {
        $binary = self::findExecutable($command);
        if ($binary === null || !function_exists('proc_open')) {
            return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exitCode' => 127];
        }

        $argv = array_merge([$binary], array_map('strval', $arguments));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exitCode' => 127];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $startedAt = microtime(true);

        while (is_resource($pipes[1]) || is_resource($pipes[2])) {
            $read = [];
            if (is_resource($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (is_resource($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false) {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }

            foreach ([1, 2] as $index) {
                if (is_resource($pipes[$index]) && feof($pipes[$index])) {
                    fclose($pipes[$index]);
                    $pipes[$index] = null;
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index])) {
                        $chunk = stream_get_contents($pipes[$index]);
                        if ($chunk !== false) {
                            if ($index === 1) {
                                $stdout .= $chunk;
                            } else {
                                $stderr .= $chunk;
                            }
                        }
                        fclose($pipes[$index]);
                        $pipes[$index] = null;
                    }
                }
                break;
            }

            if (microtime(true) - $startedAt > 30) {
                proc_terminate($process);
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index])) {
                        fclose($pipes[$index]);
                        $pipes[$index] = null;
                    }
                }
                proc_close($process);
                return ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => 124];
            }
        }

        $closedCode = proc_close($process);
        if ($exitCode === null || $exitCode < 0) {
            $exitCode = $closedCode;
        }

        return [
            'ok' => $exitCode === 0,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode,
        ];
    }

    private static function findExecutable($command)
    {
        $path = getenv('PATH');
        if (!is_string($path)) {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = rtrim($directory, '/') . '/' . $command;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function getConnectionProfiles()
    {
        $result = self::runCommand('nmcli', ['--terse', '--escape', 'yes', '--fields', 'NAME,UUID,TYPE', 'connection', 'show']);
        if (!$result['ok']) {
            return [];
        }

        $profiles = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = self::splitNmcliFields($line);
            if (count($fields) >= 3 && $fields[1] !== '') {
                $type = [
                    '802-3-ethernet' => 'ethernet',
                    '802-11-wireless' => 'wifi',
                ][$fields[2]] ?? $fields[2];
                $profiles[] = ['name' => $fields[0], 'uuid' => $fields[1], 'type' => $type];
            }
        }

        return $profiles;
    }

    private static function getActiveConnections()
    {
        $result = self::runCommand('nmcli', ['--terse', '--escape', 'yes', '--fields', 'UUID,DEVICE', 'connection', 'show', '--active']);
        if (!$result['ok']) {
            return [];
        }

        $active = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = self::splitNmcliFields($line);
            if (count($fields) >= 2 && $fields[0] !== '' && $fields[1] !== '--') {
                $active[$fields[0]] = $fields[1];
            }
        }

        return $active;
    }

    private static function getConnectionDetails($uuid)
    {
        $fields = [
            'connection.id', 'connection.uuid', 'connection.type', 'connection.interface-name',
            'ipv4.method', 'ipv4.addresses', 'ipv4.gateway', 'ipv4.dns', 'ipv4.ignore-auto-dns',
            'ipv6.method', '802-3-ethernet.mtu', '802-3-ethernet.cloned-mac-address',
            '802-11-wireless.cloned-mac-address',
        ];
        $result = self::runCommand('nmcli', ['--terse', '--escape', 'yes', '--fields', implode(',', $fields), 'connection', 'show', 'uuid', $uuid]);
        if (!$result['ok']) {
            return null;
        }

        $details = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $property = self::splitNmcliFields($line);
            if (count($property) >= 2) {
                $details[$property[0]] = $property[1];
            }
        }

        foreach ($fields as $field) {
            $details[$field] = $details[$field] ?? '';
        }

        return $details;
    }

    private static function splitNmcliFields($line)
    {
        $fields = [];
        $field = '';
        $escaped = false;
        $length = strlen($line);
        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];
            if ($escaped) {
                $field .= $character === 'n' ? "\n" : ($character === 't' ? "\t" : $character);
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === ':') {
                $fields[] = $field;
                $field = '';
            } else {
                $field .= $character;
            }
        }
        if ($escaped) {
            $field .= '\\';
        }
        $fields[] = $field;

        return $fields;
    }

    private static function getLeaseFiles()
    {
        $files = self::DHCP_LEASE_FILES;
        $matches = glob('/var/lib/NetworkManager/dnsmasq-*.leases');
        if (is_array($matches)) {
            $files = array_merge($files, $matches);
        }

        return array_values(array_unique(array_filter($files, 'is_file')));
    }

    private static function findProfile($name)
    {
        foreach (self::getConnectionProfiles() as $profile) {
            $details = self::getConnectionDetails($profile['uuid']);
            if ($details !== null && ($details['connection.id'] ?? $profile['name']) === $name) {
                return $profile;
            }
        }

        return null;
    }

    private static function profileProtocol($details)
    {
        if (($details['ipv4.method'] ?? '') === 'manual') {
            return 'static';
        }
        if (($details['ipv6.method'] ?? '') === 'dhcp') {
            return 'dhcpv6';
        }
        if (($details['ipv4.method'] ?? '') === 'auto') {
            return 'dhcp';
        }

        return (string)($details['ipv4.method'] ?? '');
    }

    private static function deviceConnectionType($device)
    {
        $result = self::runCommand('ip', ['-json', 'link', 'show', 'dev', $device]);
        if (!$result['ok']) {
            return null;
        }
        $links = json_decode($result['stdout'], true);
        if (!is_array($links) || !isset($links[0]['link_type'])) {
            return null;
        }

        if ($links[0]['link_type'] !== 'ether') {
            return null;
        }
        $result = self::runCommand('nmcli', ['--terse', '--escape', 'yes', '--fields', 'DEVICE,TYPE', 'device', 'status']);
        if (!$result['ok']) {
            return null;
        }
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            $fields = self::splitNmcliFields($line);
            if (count($fields) >= 2 && $fields[0] === $device) {
                $type = [
                    '802-3-ethernet' => 'ethernet',
                    '802-11-wireless' => 'wifi',
                ][$fields[1]] ?? $fields[1];
                if (in_array($type, ['ethernet', 'wifi'], true)) {
                    return $type;
                }
            }
        }

        return 'ethernet';
    }

    private static function profileSettings($type, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu, $macaddr, $peerdns)
    {
        $args = [];
        if ($proto === 'static') {
            $args = array_merge($args, [
                'ipv4.method', 'manual',
                'ipv4.addresses', $ipaddr . '/' . self::netmaskToCidr($netmask),
                'ipv4.gateway', $gateway,
                'ipv4.dns', implode(',', $dns),
                'ipv4.ignore-auto-dns', $peerdns ? 'no' : 'yes',
                'ipv6.method', 'ignore',
            ]);
        } elseif ($proto === 'dhcp') {
            $args = array_merge($args, [
                'ipv4.method', 'auto',
                'ipv4.addresses', '', 'ipv4.gateway', '', 'ipv4.dns', '',
                'ipv4.ignore-auto-dns', $peerdns ? 'no' : 'yes',
                'ipv6.method', 'auto',
            ]);
        } else {
            $args = array_merge($args, [
                'ipv4.method', 'disabled',
                'ipv4.addresses', '', 'ipv4.gateway', '', 'ipv4.dns', '',
                'ipv6.method', 'dhcp',
            ]);
        }

        if ($type === 'ethernet') {
            $args[] = '802-3-ethernet.mtu';
            $args[] = $mtu === '' ? 'auto' : (string)$mtu;
            $args[] = '802-3-ethernet.cloned-mac-address';
            $args[] = $macaddr === '' ? 'preserve' : $macaddr;
        } elseif ($type === 'wifi') {
            if ($mtu !== '') {
                return [];
            }
            $args[] = '802-11-wireless.cloned-mac-address';
            $args[] = $macaddr === '' ? 'preserve' : $macaddr;
        } else {
            return [];
        }

        return $args;
    }

    private static function isValidAddressSettings($proto, $ipaddr, $netmask, $gateway, $dns)
    {
        if ($proto !== 'static') {
            return true;
        }
        if (!self::isValidIpv4($ipaddr) || !self::isValidNetmask($netmask)
            || ($gateway !== '' && !self::isValidIpv4($gateway)) || !is_array($dns)) {
            return false;
        }
        foreach ($dns as $server) {
            if (!self::isValidIpv4($server)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidOptionalSettings($mtu, $macaddr)
    {
        return ($mtu === '' || (is_numeric($mtu) && (int)$mtu >= 576 && (int)$mtu <= 9216))
            && ($macaddr === '' || self::isValidMac($macaddr));
    }

    private static function isValidName($name)
    {
        return is_string($name) && preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1;
    }

    private static function isValidMac($mac)
    {
        return is_string($mac) && preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $mac) === 1;
    }

    private static function isValidIpv4($ip)
    {
        if (!is_string($ip) || !preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $ip)) {
            return false;
        }
        foreach (explode('.', $ip) as $octet) {
            if ((int)$octet > 255) {
                return false;
            }
        }

        return true;
    }

    private static function isValidNetmask($netmask)
    {
        if (!self::isValidIpv4($netmask)) {
            return false;
        }
        $binary = '';
        foreach (explode('.', $netmask) as $octet) {
            $binary .= str_pad(decbin((int)$octet), 8, '0', STR_PAD_LEFT);
        }

        return preg_match('/^1*0*$/', $binary) === 1;
    }

    private static function netmaskToCidr($netmask)
    {
        $bits = 0;
        foreach (explode('.', $netmask) as $octet) {
            $bits += substr_count(decbin((int)$octet), '1');
        }

        return $bits;
    }

    private static function cidrToNetmask($bits)
    {
        $bits = max(0, min(32, (int)$bits));
        $octets = [];
        for ($index = 0; $index < 4; $index++) {
            $take = max(0, min(8, $bits - ($index * 8)));
            $octets[] = $take === 0 ? 0 : 256 - (1 << (8 - $take));
        }

        return implode('.', $octets);
    }

    private static function splitList($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $items = preg_split('/[,;\s]+/', trim($value));

        return array_values(array_filter($items, function ($item) {
            return $item !== '';
        }));
    }
}