<?php

namespace frieren\modules\dashboard;

class ModuleLinuxHelper
{
    public static function getUbusSystemBoard()
    {
        $release = self::readOsRelease();
        $model = self::readFirstLine([
            '/sys/firmware/devicetree/base/model',
            '/sys/devices/virtual/dmi/id/product_name',
        ]);

        return [
            'hostname' => gethostname() ?: 'localhost',
            'model' => $model !== '' ? $model : 'Unknown',
            'system' => php_uname('m'),
            'kernel' => php_uname('r'),
            'release' => [
                'target' => php_uname('m'),
                'distribution' => $release['PRETTY_NAME'] ?? ($release['NAME'] ?? 'Linux'),
                'version' => $release['VERSION_ID'] ?? ($release['VERSION'] ?? ''),
                'revision' => $release['VERSION_CODENAME'] ?? ($release['BUILD_ID'] ?? ''),
            ],
        ];
    }

    public static function getUbusSystemInfo()
    {
        $meminfo = self::readMemInfo();
        $uptime = @file_get_contents('/proc/uptime');
        $load = @file_get_contents('/proc/loadavg');
        if ($meminfo === [] || $uptime === false || $load === false) {
            return false;
        }

        $cpuCores = self::getCpuCoreCount();
        if ($cpuCores < 1) {
            return false;
        }

        $loadParts = preg_split('/\s+/', trim($load));
        $uptimeSeconds = (int) floor((float) strtok(trim($uptime), ' '));
        $memoryTotal = $meminfo['MemTotal'] ?? 0;
        $memoryAvailable = $meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? 0);
        $swapTotal = $meminfo['SwapTotal'] ?? 0;
        $swapFree = $meminfo['SwapFree'] ?? 0;
        $loadPercent = isset($loadParts[0]) ? ((float) $loadParts[0] / $cpuCores) * 100 : 0;

        return [
            'cpu_cores' => $cpuCores,
            'cpu_usage' => min(round($loadPercent, 1), 100) . '%',
            'memory_used' => ($memoryTotal > 0 ? round((($memoryTotal - $memoryAvailable) / $memoryTotal) * 100, 2) : 0) . '%',
            'swap_used' => ($swapTotal > 0 ? round((($swapTotal - $swapFree) / $swapTotal) * 100, 2) : 0) . '%',
            'uptime' => self::secondsToUptime($uptimeSeconds),
            'localtime' => date('Y-m-d H:i:s'),
        ];
    }

    public static function getUptime()
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false) {
            return '0 sec';
        }

        return self::secondsToUptime((int) floor((float) strtok(trim($uptime), ' ')));
    }

    public static function sizeToHuman($size, $isBytes = false)
    {
        if ($isBytes) {
            $size /= 1024;
        }

        $units = ['KB', 'MB', 'GB'];
        for ($unit = 0; $size >= 1024 && $unit < count($units) - 1; $unit++) {
            $size /= 1024;
        }

        return sprintf('%.2f %s', $size, $units[$unit]);
    }

    public static function secondsToUptime($seconds)
    {
        $seconds = max(0, (int) $seconds);
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return sprintf('%dd %02d:%02d hrs', $days, $hours, $minutes);
        }
        if ($hours > 0 || $minutes > 0) {
            return sprintf('%02d:%02d hrs', $hours, $minutes);
        }

        return sprintf('%d sec', $seconds);
    }

    private static function getCpuCoreCount()
    {
        $stat = @file('/proc/stat');
        if ($stat === false) {
            return 0;
        }

        $cores = 0;
        foreach ($stat as $line) {
            if (preg_match('/^cpu[0-9]+\s/', $line)) {
                $cores++;
            }
        }

        return $cores;
    }

    private static function readMemInfo()
    {
        $lines = @file('/proc/meminfo');
        if ($lines === false) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z_]+):\s+(\d+)/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        return $values;
    }

    private static function readOsRelease()
    {
        $contents = @file_get_contents('/etc/os-release');
        if ($contents === false) {
            return [];
        }

        $values = [];
        foreach (preg_split('/\r?\n/', $contents) as $line) {
            if (!preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                continue;
            }

            $value = trim($matches[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            $values[$matches[1]] = stripcslashes($value);
        }

        return $values;
    }

    private static function readFirstLine($paths)
    {
        foreach ($paths as $path) {
            if (!is_readable($path) || is_dir($path)) {
                continue;
            }
            $value = trim((string) @file_get_contents($path), "\0\r\n\t ");
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}