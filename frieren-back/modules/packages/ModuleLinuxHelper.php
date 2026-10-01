<?php

namespace frieren\modules\packages;

use frieren\helper\BackgroundTaskHelper;

class ModuleLinuxHelper extends ModuleOpenWrtHelper
{
    public static function getInstalledPackages()
    {
        $result = self::runCommand([
            'dpkg-query',
            '-W',
            '-f=Package: ${binary:Package}\nVersion: ${Version}\nDescription: ${binary:Summary}\n\n',
        ]);
        if ($result === false || $result['code'] !== 0) {
            return [];
        }

        return self::parseText($result['output']);
    }

    public static function updateLists()
    {
        BackgroundTaskHelper::start('opkg-update', 'sudo -n apt-get update');
    }

    public static function updateAvailablePackages()
    {
        $logPath = BackgroundTaskHelper::getLogPath('opkg-available');
        BackgroundTaskHelper::start('opkg-available', 'apt-cache dumpavail > ' . escapeshellarg($logPath));
    }

    public static function installPackage($packageName)
    {
        if (!self::isValidPackageName($packageName)) {
            return false;
        }

        BackgroundTaskHelper::start('opkg-install', 'sudo -n apt-get install -y -- ' . escapeshellarg($packageName));

        return true;
    }

    public static function removePackage($packageName, $autoremove = false)
    {
        if (!self::isValidPackageName($packageName)) {
            return false;
        }

        $command = 'sudo -n apt-get remove -y ' . ($autoremove ? '--autoremove ' : '') . '-- ' . escapeshellarg($packageName);
        BackgroundTaskHelper::start('opkg-remove', $command);

        return true;
    }

    private static function isValidPackageName($packageName)
    {
        return is_string($packageName) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9+.-]*$/', $packageName) === 1;
    }

    private static function parseText($text)
    {
        $path = tempnam(sys_get_temp_dir(), 'frieren-packages-');
        if ($path === false) {
            return [];
        }

        file_put_contents($path, $text);
        $packages = parent::parsePackageFile($path);
        unlink($path);

        return $packages;
    }

    private static function runCommand($command)
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        return ['output' => $output === false ? '' : $output, 'code' => $code];
    }
}