<?php

namespace frieren\modules\system;

class ModuleLinuxHelper
{
    const SYSTEM_LOGS_LIMIT = 1000;
    const CRITICAL_SERVICES = [
        'NetworkManager.service',
        'systemd-networkd.service',
        'networking.service',
        'ssh.service',
        'sshd.service',
        'dropbear.service',
        'apache2.service',
        'nginx.service',
        'lighttpd.service',
        'firewalld.service',
        'ufw.service',
    ];

    public static function getUsbDevices()
    {
        $result = self::runCommand(['lsusb']);
        if ($result === false || $result['code'] !== 0) {
            return false;
        }

        $devices = [];
        foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
            if (preg_match('/^Bus (\d{3}) Device (\d{3}): ID ([a-fA-F0-9]{4}:[a-fA-F0-9]{4}) (.+)$/', $line, $matches)) {
                $devices[] = [
                    'bus' => $matches[1],
                    'device' => $matches[2],
                    'id' => $matches[3],
                    'name' => $matches[4],
                ];
            }
        }

        return $devices;
    }

    public static function getFileSystemUsage()
    {
        $result = self::runCommand(['df', '-T', '-h', '-P']);
        if ($result === false || $result['code'] !== 0) {
            return false;
        }

        $lines = preg_split('/\r?\n/', trim($result['output']));
        array_shift($lines);
        $filesystems = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 7);
            if (count($parts) === 7) {
                $filesystems[] = [
                    'filesystem' => $parts[0],
                    'type' => $parts[1],
                    'size' => $parts[2],
                    'used' => $parts[3],
                    'available' => $parts[4],
                    'usePercent' => $parts[5],
                    'mountedOn' => $parts[6],
                ];
            }
        }

        return $filesystems;
    }

    public static function getSystemLogs($searchPattern = null)
    {
        $result = self::runCommand(['journalctl', '--no-pager', '-n', (string) self::SYSTEM_LOGS_LIMIT, '-o', 'short-unix']);
        if ($result === false || $result['code'] !== 0) {
            return false;
        }

        $logs = [];
        foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
            if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s+\S+\s+(.*)$/', $line, $matches)) {
                continue;
            }

            $message = $matches[2];
            if ($searchPattern !== null && $searchPattern !== '') {
                $matched = @preg_match((string) $searchPattern, $message);
                if ($matched === false) {
                    $matched = stripos($message, (string) $searchPattern) !== false ? 1 : 0;
                }
                if ($matched !== 1) {
                    continue;
                }
            }

            $tag = 'system';
            $process = 'system';
            if (preg_match('/^([A-Za-z0-9_.@-]+)(?:\[(\d+)\])?:\s?(.*)$/', $message, $entry)) {
                $tag = $entry[1];
                $process = isset($entry[2]) && $entry[2] !== '' ? $entry[2] : $entry[1];
                $message = $entry[3];
            }
            $logs[] = [
                'timestamp' => (int) $matches[1],
                'tag' => $tag,
                'process' => $process,
                'message' => $message,
            ];
        }

        return array_reverse($logs);
    }

    public static function serviceExists($name)
    {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_.@-]+(?:\.service)?$/', $name)) {
            return false;
        }
        $unit = self::serviceUnitName($name);
        $result = self::runCommand(['systemctl', 'show', '--property=LoadState', '--value', $unit]);

        return $result !== false && $result['code'] === 0 && trim($result['output']) === 'loaded';
    }

    public static function listServices()
    {
        $result = self::runCommand(['systemctl', 'list-unit-files', '--type=service', '--no-legend', '--no-pager']);
        if ($result === false || $result['code'] !== 0) {
            return [];
        }

        $services = [];
        foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!$parts || !isset($parts[0]) || !preg_match('/^[a-zA-Z0-9_.@-]+\.service$/', $parts[0])) {
                continue;
            }
            $name = $parts[0];
            $state = self::serviceState($name);
            $services[] = [
                'name' => $name,
                'enabled' => $state['enabled'],
                'running' => $state['running'],
                'critical' => in_array($name, self::CRITICAL_SERVICES, true),
            ];
        }
        usort($services, function ($first, $second) {
            return strcmp($first['name'], $second['name']);
        });

        return $services;
    }

    public static function serviceState($name)
    {
        $unit = self::serviceUnitName($name);
        $enabled = self::runCommand(['systemctl', 'is-enabled', $unit]);
        $running = self::runCommand(['systemctl', 'is-active', $unit]);

        return [
            'enabled' => $enabled !== false && $enabled['code'] === 0 && in_array(trim($enabled['output']), ['enabled', 'enabled-runtime', 'linked', 'linked-runtime', 'alias'], true),
            'running' => $running !== false && $running['code'] === 0 && trim($running['output']) === 'active',
        ];
    }

    public static function controlService($name, $command)
    {
        if (!self::serviceExists($name) || !in_array($command, ['start', 'stop', 'restart'], true)) {
            return false;
        }
        $result = self::runCommand(['systemctl', $command, self::serviceUnitName($name)]);

        return $result !== false && $result['code'] === 0;
    }

    public static function setServiceEnabled($name, $enabled)
    {
        if (!self::serviceExists($name)) {
            return false;
        }
        $result = self::runCommand(['systemctl', $enabled ? 'enable' : 'disable', self::serviceUnitName($name)]);

        return $result !== false && $result['code'] === 0;
    }

    private static function serviceUnitName($name)
    {
        return substr($name, -8) === '.service' ? $name : $name . '.service';
    }

    private static function runCommand($command)
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'a'],
        ];
        try {
            $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } catch (\Throwable $exception) {
            return false;
        }
        if (!is_resource($process)) {
            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        return ['output' => $output === false ? '' : $output, 'code' => $code];
    }
}