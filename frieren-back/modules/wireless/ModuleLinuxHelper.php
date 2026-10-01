<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\wireless;

class ModuleLinuxHelper
{
    const PROFILE_PREFIX = 'Frieren wireless ';

    private const PROFILE_FIELDS = [
        'connection.id',
        'connection.uuid',
        'connection.type',
        'connection.interface-name',
        'connection.autoconnect',
        '802-11-wireless.mode',
        '802-11-wireless.ssid',
        '802-11-wireless.bssid',
        '802-11-wireless.hidden',
        '802-11-wireless-security.key-mgmt',
        'ipv4.method',
    ];

    public static function scanForNetworks($device)
    {
        if (!self::isWirelessDevice($device)) {
            return [];
        }

        $result = self::runCommand('nmcli', [
            '--terse', '--escape', 'yes', '--fields', 'SSID,BSSID,CHAN,SIGNAL,SECURITY',
            'device', 'wifi', 'list', '--rescan', 'yes', 'ifname', $device,
        ]);
        if (!$result['ok']) {
            return [];
        }

        $networks = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = self::splitNmcliFields($line);
            if (count($fields) < 5 || $fields[0] === '' || preg_match('//u', $fields[0]) !== 1) {
                continue;
            }

            $security = self::formatScanSecurity($fields[4]);
            $signal = is_numeric($fields[3]) ? (int)$fields[3] : null;
            $networks[] = [
                'bssid' => strtoupper($fields[1]),
                'ssid' => $fields[0],
                'channel' => is_numeric($fields[2]) ? (int)$fields[2] : 0,
                'signal' => null,
                'quality' => $signal ?? 0,
                'security' => $security,
            ];
        }

        return $networks;
    }

    public static function getWirelessOverview()
    {
        $active = self::getActiveConnections();
        $profiles = self::getOwnedProfiles();
        $overview = [];

        foreach (self::getWirelessDevices() as $device) {
            $radioInfo = self::getRadioInfo($device['name']);
            $interfaces = [];
            foreach ($profiles as $profile) {
                $details = self::getProfileDetails($profile['uuid']);
                if ($details === null) {
                    continue;
                }
                $profileDevice = $details['connection.interface-name'] ?? '';
                $activeDevice = $active[$profile['uuid']] ?? '';
                if ($profileDevice !== $device['name'] && $activeDevice !== $device['name']) {
                    continue;
                }

                $interfaces[] = [
                    'radio' => $device['name'],
                    'ifname' => $activeDevice !== '' ? $activeDevice : ($profileDevice !== '' ? $profileDevice : null),
                    'section' => $profile['uuid'],
                    'mode' => ($details['802-11-wireless.mode'] ?? '') === 'ap' ? 'ap' : 'sta',
                    'ssid' => $details['802-11-wireless.ssid'] ?? '',
                    'bssid' => strtoupper($details['802-11-wireless.bssid'] ?? ''),
                    'encryption' => self::formatProfileEncryption($details['802-11-wireless-security.key-mgmt'] ?? ''),
                    'network' => '',
                    'hidden' => ($details['802-11-wireless.hidden'] ?? 'no') === 'yes',
                    'up' => isset($active[$profile['uuid']]),
                    'disabled' => ($details['connection.autoconnect'] ?? 'yes') !== 'yes',
                ];
            }

            $overview[$device['name']] = [
                'channel' => $radioInfo['channel'],
                'txpower' => $radioInfo['txpower'],
                'frequency' => $radioInfo['frequency'],
                'band' => $radioInfo['band'],
                'supportedBands' => $radioInfo['supportedBands'],
                'htmode' => null,
                'up' => $device['connected'],
                'disabled' => null,
                'phy' => $radioInfo['phy'],
                'country' => $radioInfo['country'],
                'hardware' => null,
                'isUsb' => $radioInfo['isUsb'],
                'hwmodes' => null,
                'htmodes' => [],
                'interfaces' => $interfaces,
            ];
        }

        return $overview;
    }

    public static function getRadioConfig($radio)
    {
        if (!self::isWirelessDevice($radio)) {
            return self::emptyRadioConfig();
        }

        $info = self::getRadioInfo($radio);
        $config = self::emptyRadioConfig();
        $config['current']['channel'] = $info['channel'];
        $config['current']['txpower'] = $info['txpower'];
        $config['current']['country'] = $info['country'];

        return $config;
    }

    public static function getEncryptionOptions($radio, $mode)
    {
        if (!in_array($mode, ['ap', 'sta'], true) || !self::isWirelessDevice($radio)) {
            return ['options' => []];
        }
        if ($mode === 'ap') {
            $apSupport = self::runCommand('nmcli', ['--get-values', 'WIFI-PROPERTIES.AP', 'device', 'show', $radio]);
            if (!$apSupport['ok'] || stripos($apSupport['stdout'], 'yes') === false) {
                return ['options' => []];
            }
        }

        $options = [
            ['value' => 'none', 'label' => 'None'],
            ['value' => 'psk2+ccmp', 'label' => 'WPA2-PSK'],
            ['value' => 'psk-mixed+ccmp', 'label' => 'WPA/WPA2 Mixed'],
        ];
        $wpa3 = self::runCommand('nmcli', ['--get-values', 'WIFI-PROPERTIES.WPA3', 'device', 'show', $radio]);
        if ($wpa3['ok'] && stripos($wpa3['stdout'], 'yes') !== false) {
            $options[] = ['value' => 'sae', 'label' => 'WPA3-SAE'];
        }

        return ['options' => $options];
    }

    public static function setRadioConfig($radio, $channel, $txpower, $htmode, $country, $disabled, $cellDensity = 0, $distance = 0)
    {
        return false;
    }

    public static function getAssociationList($interface)
    {
        if (!self::isValidDeviceName($interface) || !self::isWirelessDevice($interface)) {
            return [];
        }

        $result = self::runCommand('iw', ['dev', $interface, 'station', 'dump']);
        if (!$result['ok']) {
            return [];
        }

        $stations = [];
        $station = null;
        foreach (preg_split('/\r?\n/', $result['stdout']) as $line) {
            if (preg_match('/^\s*Station\s+([0-9a-fA-F:]{17})\s+/', $line, $match)) {
                if ($station !== null) {
                    $stations[] = $station;
                }
                $station = [
                    'mac' => strtoupper($match[1]),
                    'signal' => null,
                    'noise' => null,
                    'rx_rate' => null,
                    'tx_rate' => null,
                    'inactive' => null,
                ];
                continue;
            }
            if ($station === null) {
                continue;
            }
            if (preg_match('/^\s*inactive time:\s*(\d+)\s*ms/i', $line, $match)) {
                $station['inactive'] = (int)$match[1];
            } elseif (preg_match('/^\s*signal:\s*(-?\d+)\s*dBm/i', $line, $match)) {
                $station['signal'] = (int)$match[1];
            } elseif (preg_match('/^\s*tx bitrate:\s*([0-9.]+)/i', $line, $match)) {
                $station['tx_rate'] = (float)$match[1];
            } elseif (preg_match('/^\s*rx bitrate:\s*([0-9.]+)/i', $line, $match)) {
                $station['rx_rate'] = (float)$match[1];
            }
        }
        if ($station !== null) {
            $stations[] = $station;
        }

        return $stations;
    }

    public static function getInterfaceStatus($section)
    {
        $profile = self::findOwnedProfile($section);
        if ($profile === null) {
            return ['state' => 'UNKNOWN', 'mode' => ''];
        }

        $details = self::getProfileDetails($profile['uuid']);
        if ($details === null) {
            return ['state' => 'UNKNOWN', 'mode' => 'sta'];
        }

        $activeConnections = self::getActiveConnections();
        $device = $activeConnections[$profile['uuid']] ?? ($details['connection.interface-name'] ?? '');
        $active = isset($activeConnections[$profile['uuid']]);
        $state = $active ? 'COMPLETED' : ((($details['connection.autoconnect'] ?? 'yes') === 'yes') ? 'WAITING' : 'DOWN');
        $ip = '';
        $frequency = null;
        if ($active && $device !== '') {
            $ipResult = self::runCommand('nmcli', ['--get-values', 'IP4.ADDRESS', 'device', 'show', $device]);
            if ($ipResult['ok']) {
                $ip = trim(strtok(trim($ipResult['stdout']), "\r\n") ?: '');
                if (strpos($ip, '/') !== false) {
                    $ip = strstr($ip, '/', true);
                }
            }
            $frequency = self::getRadioInfo($device)['frequency'];
        }

        return [
            'state' => $state,
            'mode' => ($details['802-11-wireless.mode'] ?? '') === 'ap' ? 'ap' : 'sta',
            'ssid' => $details['802-11-wireless.ssid'] ?? '',
            'bssid' => strtoupper($details['802-11-wireless.bssid'] ?? ''),
            'ip' => $ip,
            'frequency' => $frequency,
        ];
    }

    public static function addInterface($radio, $ssid, $encryption, $key, $mode, $network, $hidden, $disabled, $isManagement = false, $isRecon = false, $ieee80211w = 0, $bssid = '')
    {
        if (!in_array($mode, ['sta', 'ap'], true) || !self::isWirelessDevice($radio) || !self::isValidSsid($ssid)
            || !self::isValidStationSecurity($encryption, $key) || !self::isValidBssid($bssid)
            || !self::isValidFlag($hidden) || !self::isValidFlag($disabled)) {
            return false;
        }
        if ($mode === 'ap') {
            $apSupport = self::runCommand('nmcli', ['--get-values', 'WIFI-PROPERTIES.AP', 'device', 'show', $radio]);
            if (!$apSupport['ok'] || stripos($apSupport['stdout'], 'yes') === false) {
                return false;
            }
        }

        try {
            $tag = bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return false;
        }
        $name = self::PROFILE_PREFIX . $tag;
        $arguments = [
            'connection', 'add', 'type', 'wifi', 'ifname', $radio,
            'con-name', $name, 'ssid', $ssid,
            '802-11-wireless.mode', $mode === 'ap' ? 'ap' : 'infrastructure',
            'ipv4.method', $mode === 'ap' ? 'shared' : 'auto',
            'ipv6.method', $mode === 'ap' ? 'ignore' : 'auto',
            'connection.autoconnect', self::isTruthy($disabled) ? 'no' : 'yes',
            '802-11-wireless.hidden', self::isTruthy($hidden) ? 'yes' : 'no',
        ];
        if ($encryption !== 'none') {
            $arguments = array_merge($arguments, self::securityArguments($encryption, $key));
        }
        if ($mode === 'sta' && $bssid !== '') {
            $arguments = array_merge($arguments, ['802-11-wireless.bssid', strtoupper($bssid)]);
        }

        $created = self::runCommand('nmcli', $arguments);
        if (!$created['ok']) {
            return false;
        }
        $profile = self::findOwnedProfileByName($name);
        if ($profile === null) {
            return false;
        }
        if (!self::isTruthy($disabled)) {
            self::runCommand('nmcli', ['connection', 'up', 'uuid', $profile['uuid']]);
        }

        return $profile['uuid'];
    }

    public static function removeInterface($section)
    {
        $profile = self::findOwnedProfile($section);
        if ($profile === null) {
            return false;
        }

        return self::runCommand('nmcli', ['connection', 'delete', 'uuid', $profile['uuid']])['ok'];
    }

    public static function toggleInterface($section, $disabled)
    {
        if (!self::isValidFlag($disabled)) {
            return false;
        }
        $profile = self::findOwnedProfile($section);
        if ($profile === null) {
            return false;
        }

        $disable = self::isTruthy($disabled);
        $modified = self::runCommand('nmcli', [
            'connection', 'modify', 'uuid', $profile['uuid'],
            'connection.autoconnect', $disable ? 'no' : 'yes',
        ]);
        if (!$modified['ok']) {
            return false;
        }
        if ($disable) {
            self::runCommand('nmcli', ['connection', 'down', 'uuid', $profile['uuid']]);
        } else {
            self::runCommand('nmcli', ['connection', 'up', 'uuid', $profile['uuid']]);
        }

        return true;
    }

    public static function getInterfaceConfig($section)
    {
        $profile = self::findOwnedProfile($section);
        $details = $profile !== null ? self::getProfileDetails($profile['uuid']) : null;
        if ($details === null) {
            return self::emptyInterfaceConfig();
        }

        return [
            'device' => $details['connection.interface-name'] ?? '',
            'network' => '',
            'mode' => ($details['802-11-wireless.mode'] ?? '') === 'ap' ? 'ap' : 'sta',
            'ssid' => $details['802-11-wireless.ssid'] ?? '',
            'encryption' => self::profileEncryptionValue($details['802-11-wireless-security.key-mgmt'] ?? ''),
            'key' => $details['802-11-wireless-security.psk'] ?? '',
            'disabled' => ($details['connection.autoconnect'] ?? 'yes') === 'yes' ? '0' : '1',
            'hidden' => ($details['802-11-wireless.hidden'] ?? 'no') === 'yes' ? '1' : '0',
            'bssid' => strtoupper($details['802-11-wireless.bssid'] ?? ''),
            'ieee80211w' => '',
            'isManagement' => '0',
            'isRecon' => '0',
        ];
    }

    public static function setInterfaceConfig($section, $ssid, $encryption, $key, $mode, $network, $hidden, $disabled, $isManagement = false, $isRecon = false, $ieee80211w = 0, $bssid = '')
    {
        if (!in_array($mode, ['sta', 'ap'], true) || !self::isValidSsid($ssid) || !self::isValidStationSecurity($encryption, $key)
            || ($mode === 'sta' && !self::isValidBssid($bssid)) || !self::isValidFlag($hidden) || !self::isValidFlag($disabled)) {
            return false;
        }
        $profile = self::findOwnedProfile($section);
        if ($profile === null) {
            return false;
        }
        if ($mode === 'ap') {
            $details = self::getProfileDetails($profile['uuid']);
            $radio = $details['connection.interface-name'] ?? '';
            $apSupport = self::runCommand('nmcli', ['--get-values', 'WIFI-PROPERTIES.AP', 'device', 'show', $radio]);
            if (!$apSupport['ok'] || stripos($apSupport['stdout'], 'yes') === false) {
                return false;
            }
        }

        $arguments = [
            'connection', 'modify', 'uuid', $profile['uuid'],
            '802-11-wireless.mode', $mode === 'ap' ? 'ap' : 'infrastructure',
            'ipv4.method', $mode === 'ap' ? 'shared' : 'auto',
            'ipv6.method', $mode === 'ap' ? 'ignore' : 'auto',
            '802-11-wireless.ssid', $ssid,
            '802-11-wireless.hidden', self::isTruthy($hidden) ? 'yes' : 'no',
            '802-11-wireless.bssid', $mode === 'sta' && $bssid !== '' ? strtoupper($bssid) : '',
            'connection.autoconnect', self::isTruthy($disabled) ? 'no' : 'yes',
        ];
        $arguments = array_merge($arguments, self::securityArguments($encryption, $key));
        $modified = self::runCommand('nmcli', $arguments);
        if (!$modified['ok']) {
            return false;
        }

        if (self::isTruthy($disabled)) {
            self::runCommand('nmcli', ['connection', 'down', 'uuid', $profile['uuid']]);
        } else {
            self::runCommand('nmcli', ['connection', 'up', 'uuid', $profile['uuid']]);
        }

        return true;
    }

    public static function getRawWirelessConfig()
    {
        return '';
    }

    public static function setRawWirelessConfig($content)
    {
        return false;
    }

    public static function resetWirelessConfig()
    {
        $profiles = self::getOwnedProfiles();
        if (!$profiles) {
            return true;
        }
        foreach ($profiles as $profile) {
            if (!self::runCommand('nmcli', ['connection', 'delete', 'uuid', $profile['uuid']])['ok']) {
                return false;
            }
        }

        return true;
    }

    private static function emptyRadioConfig()
    {
        return [
            'current' => [
                'channel' => null,
                'txpower' => null,
                'htmode' => null,
                'country' => null,
                'disabled' => null,
                'cell_density' => null,
                'distance' => null,
            ],
            'available' => [
                'channels' => [],
                'txpowers' => [],
                'countries' => [],
                'htmodes' => [],
            ],
        ];
    }

    private static function emptyInterfaceConfig()
    {
        return [
            'device' => '', 'network' => '', 'mode' => '', 'ssid' => '', 'encryption' => '',
            'key' => '', 'disabled' => '0', 'hidden' => '0', 'bssid' => '', 'ieee80211w' => '',
            'isManagement' => '0', 'isRecon' => '0',
        ];
    }

    private static function getWirelessDevices()
    {
        $result = self::runCommand('nmcli', [
            '--terse', '--escape', 'yes', '--fields', 'DEVICE,TYPE,STATE', 'device', 'status',
        ]);
        if (!$result['ok']) {
            return [];
        }

        $devices = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = self::splitNmcliFields($line);
            if (count($fields) < 3 || $fields[1] !== 'wifi' && $fields[1] !== '802-11-wireless') {
                continue;
            }
            $name = $fields[0];
            if (!self::isValidDeviceName($name)) {
                continue;
            }
            $devices[] = [
                'name' => $name,
                'connected' => stripos($fields[2], 'connected') !== false,
            ];
        }

        return $devices;
    }

    private static function isWirelessDevice($device)
    {
        if (!self::isValidDeviceName($device)) {
            return false;
        }
        foreach (self::getWirelessDevices() as $entry) {
            if ($entry['name'] === $device) {
                return true;
            }
        }

        return false;
    }

    private static function getRadioInfo($device)
    {
        $empty = [
            'channel' => null,
            'frequency' => null,
            'txpower' => null,
            'band' => 'Unknown',
            'supportedBands' => [],
            'phy' => null,
            'country' => null,
            'isUsb' => false,
        ];
        if (!self::isValidDeviceName($device)) {
            return $empty;
        }

        $info = self::runCommand('iw', ['dev', $device, 'info']);
        if (!$info['ok']) {
            return $empty;
        }
        if (preg_match('/^\s*wiphy\s+(\d+)\s*$/m', $info['stdout'], $match)) {
            $empty['phy'] = 'phy' . $match[1];
        }
        if (preg_match('/^\s*channel\s+(\d+)\s+\((\d+)\s+MHz\)/m', $info['stdout'], $match)) {
            $empty['channel'] = (int)$match[1];
            $empty['frequency'] = (int)$match[2];
            $empty['band'] = self::frequencyBand((int)$match[2]);
        }
        if (preg_match('/^\s*txpower\s+([0-9.]+)\s+dBm/m', $info['stdout'], $match)) {
            $empty['txpower'] = (float)$match[1];
        }

        if ($empty['phy'] !== null) {
            $phy = self::runCommand('iw', ['phy', $empty['phy'], 'info']);
            if ($phy['ok']) {
                $bands = [];
                if (preg_match_all('/^\s*\*\s+(\d+)\s+MHz/m', $phy['stdout'], $matches)) {
                    foreach ($matches[1] as $frequency) {
                        $band = self::frequencyBand((int)$frequency);
                        if ($band !== 'Unknown') {
                            $bands[$band] = true;
                        }
                    }
                }
                $empty['supportedBands'] = array_keys($bands);
            }
            $sysfsPath = @realpath('/sys/class/net/' . $device . '/phy80211/device');
            $empty['isUsb'] = is_string($sysfsPath) && strpos($sysfsPath, '/usb') !== false;
        }

        $regulatory = self::runCommand('iw', ['reg', 'get']);
        if ($regulatory['ok'] && preg_match('/^country\s+([A-Z]{2}):/m', $regulatory['stdout'], $match)) {
            $empty['country'] = $match[1];
        }

        return $empty;
    }

    private static function frequencyBand($frequency)
    {
        if ($frequency >= 2400 && $frequency < 2500) {
            return '2.4 GHz';
        }
        if ($frequency >= 5000 && $frequency < 5925) {
            return '5 GHz';
        }
        if ($frequency >= 5925 && $frequency < 7125) {
            return '6 GHz';
        }

        return 'Unknown';
    }

    private static function getOwnedProfiles()
    {
        $result = self::runCommand('nmcli', [
            '--terse', '--escape', 'yes', '--fields', 'NAME,UUID,TYPE', 'connection', 'show',
        ]);
        if (!$result['ok']) {
            return [];
        }

        $profiles = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = self::splitNmcliFields($line);
            if (count($fields) < 3 || strpos($fields[0], self::PROFILE_PREFIX) !== 0
                || !in_array($fields[2], ['wifi', '802-11-wireless'], true)
                || !self::isValidUuid($fields[1])) {
                continue;
            }
            $profiles[] = ['name' => $fields[0], 'uuid' => strtolower($fields[1]), 'type' => 'wifi'];
        }

        return $profiles;
    }

    private static function getActiveConnections()
    {
        $result = self::runCommand('nmcli', [
            '--terse', '--escape', 'yes', '--fields', 'UUID,DEVICE', 'connection', 'show', '--active',
        ]);
        if (!$result['ok']) {
            return [];
        }

        $active = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = self::splitNmcliFields($line);
            if (count($fields) >= 2 && self::isValidUuid($fields[0]) && $fields[1] !== '--') {
                $active[strtolower($fields[0])] = $fields[1];
            }
        }

        return $active;
    }

    private static function getProfileDetails($uuid)
    {
        if (!self::isValidUuid($uuid)) {
            return null;
        }
        $result = self::runCommand('nmcli', [
            '--terse', '--escape', 'yes', '--fields', implode(',', self::PROFILE_FIELDS),
            'connection', 'show', 'uuid', strtolower($uuid),
        ]);
        if (!$result['ok']) {
            return null;
        }

        $properties = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = substr($line, 0, $separator);
            $properties[$name] = self::unescapeNmcliValue(substr($line, $separator + 1));
        }

        return $properties;
    }

    private static function findOwnedProfile($section)
    {
        if (!self::isValidUuid($section)) {
            return null;
        }
        $uuid = strtolower($section);
        foreach (self::getOwnedProfiles() as $profile) {
            if ($profile['uuid'] === $uuid) {
                return $profile;
            }
        }

        return null;
    }

    private static function findOwnedProfileByName($name)
    {
        foreach (self::getOwnedProfiles() as $profile) {
            if ($profile['name'] === $name) {
                return $profile;
            }
        }

        return null;
    }

    private static function splitNmcliFields($line)
    {
        $fields = [];
        $field = '';
        $escaped = false;
        for ($index = 0, $length = strlen($line); $index < $length; $index++) {
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

    private static function unescapeNmcliValue($value)
    {
        $result = '';
        $escaped = false;
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $character = $value[$index];
            if ($escaped) {
                $result .= $character === 'n' ? "\n" : ($character === 't' ? "\t" : $character);
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } else {
                $result .= $character;
            }
        }
        if ($escaped) {
            $result .= '\\';
        }

        return $result;
    }

    private static function runCommand($command, $arguments = [])
    {
        $binary = self::findExecutable($command);
        if ($binary === null || !function_exists('proc_open')) {
            return ['ok' => false, 'stdout' => '', 'exitCode' => 127];
        }
        $argv = array_merge([$binary], array_map('strval', $arguments));
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'a'],
        ];
        try {
            $process = @proc_open($argv, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'stdout' => '', 'exitCode' => 127];
        }
        if (!is_resource($process)) {
            return ['ok' => false, 'stdout' => '', 'exitCode' => 127];
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $output = $output === false ? '' : $output;

        return ['ok' => $exitCode === 0, 'stdout' => $output, 'exitCode' => $exitCode];
    }

    private static function findExecutable($command)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $command)) {
            return null;
        }
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

    private static function formatScanSecurity($security)
    {
        $security = strtoupper(trim($security));
        if ($security === '' || $security === '--') {
            return 'Open';
        }
        $parts = [];
        if (strpos($security, 'WPA3') !== false || strpos($security, 'SAE') !== false) {
            $parts[] = 'WPA3-SAE';
        }
        if (strpos($security, 'WPA2') !== false || strpos($security, 'RSN') !== false) {
            $parts[] = 'WPA2-PSK';
        }
        if (strpos($security, 'WPA1') !== false || preg_match('/\bWPA\b/', $security)) {
            $parts[] = 'WPA-PSK';
        }
        if (strpos($security, 'WEP') !== false) {
            $parts[] = 'WEP';
        }

        return $parts ? implode(' / ', array_unique($parts)) : 'Encrypted';
    }

    private static function formatProfileEncryption($keyManagement)
    {
        return match ($keyManagement) {
            'wpa-psk' => 'WPA2-PSK',
            'sae' => 'WPA3-SAE',
            default => $keyManagement === '' ? 'Open' : 'Encrypted',
        };
    }

    private static function profileEncryptionValue($keyManagement)
    {
        return match ($keyManagement) {
            'wpa-psk' => 'psk2+ccmp',
            'sae' => 'sae',
            default => 'none',
        };
    }

    private static function securityArguments($encryption, $key)
    {
        if ($encryption === 'none') {
            return ['wifi-sec.key-mgmt', '', 'wifi-sec.psk', ''];
        }
        $keyManagement = $encryption === 'sae' ? 'sae' : 'wpa-psk';

        return ['wifi-sec.key-mgmt', $keyManagement, 'wifi-sec.psk', $key];
    }

    private static function isValidStationSecurity($encryption, $key)
    {
        if (!is_string($encryption) || !is_string($key)
            || !in_array($encryption, ['none', 'psk2+ccmp', 'psk-mixed+ccmp', 'sae', 'sae+ccmp'], true)) {
            return false;
        }
        if ($encryption === 'none') {
            return true;
        }
        return (strlen($key) >= 8 && strlen($key) <= 63) || preg_match('/^[a-fA-F0-9]{64}$/', $key) === 1;
    }

    private static function isValidSsid($ssid)
    {
        return is_string($ssid) && $ssid !== '' && strlen($ssid) <= 32
            && preg_match('//u', $ssid) === 1 && preg_match('/[\x00-\x1F\x7F]/', $ssid) !== 1;
    }

    private static function isValidBssid($bssid)
    {
        return is_string($bssid) && ($bssid === '' || preg_match('/^(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $bssid) === 1);
    }

    private static function isValidFlag($value)
    {
        return in_array($value, [true, false, 0, 1, '0', '1'], true);
    }

    private static function isTruthy($value)
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function isValidDeviceName($device)
    {
        return is_string($device) && strlen($device) <= 15
            && preg_match('/^[a-zA-Z0-9_.:-]+$/', $device) === 1;
    }

    private static function isValidUuid($uuid)
    {
        return is_string($uuid)
            && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $uuid) === 1;
    }
}