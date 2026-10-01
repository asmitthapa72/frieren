<?php

namespace frieren\helper;

/** Linux host implementation for shared operating-system operations. */
class LinuxHelper extends OpenWrtHelper
{
    const LOGIN_PASSWORD_HASH_KEY = 'frieren.@auth[0].password_hash';

    public static function execBackground($command, $redirect = '/dev/null 2>&1')
    {
        exec('/usr/bin/nohup sh -c ' . escapeshellarg($command) . " > {$redirect} &");
    }

    public static function checkDependency($dependencies)
    {
        $missing = [];
        foreach ($dependencies as $dependency) {
            $package = escapeshellarg($dependency);
            $installed = self::exec("dpkg-query -W -f='\${db:Status-Status}' -- {$package} 2>/dev/null", true, true);
            if ($installed !== 'installed') {
                $missing[] = $dependency;
            }
        }

        return $missing ? 'Missing dependencies: ' . implode(', ', $missing) : true;
    }

    public static function installDependency($dependencies, $installToSD = false, $taskName = 'module-dependencies')
    {
        $packages = array_filter(preg_split('/\s+/', trim($dependencies)));
        if ($packages) {
            $command = 'sudo -n apt-get update && sudo -n apt-get install -y -- ' . implode(' ', array_map('escapeshellarg', $packages));
            BackgroundTaskHelper::start($taskName, $command);
        }

        return true;
    }

    public static function isSDAvailable()
    {
        return false;
    }

    public static function downloadFile($url, $savePath, $flagPath)
    {
        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $safeUrl = escapeshellarg($url);
        $safeSavePath = escapeshellarg($savePath);
        $safeFlagPath = escapeshellarg($flagPath);
        $command = "if command -v curl >/dev/null 2>&1; then curl --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output {$safeSavePath} {$safeUrl}; "
            . "elif command -v wget >/dev/null 2>&1; then wget --quiet --timeout=10 --output-document={$safeSavePath} {$safeUrl}; "
            . 'else exit 127; fi; result=$?; touch ' . $safeFlagPath . '; exit $result';
        @unlink($flagPath);
        self::execBackground($command);

        return true;
    }

    public static function verifyPassword($username, $password)
    {
        $configuredUser = getenv('FRIEREN_USER');
        $passwordHash = getenv('FRIEREN_PASSWORD_HASH');

        if ($configuredUser === false || $configuredUser === '') {
            $userInfo = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
            $configuredUser = is_array($userInfo) ? $userInfo['name'] : '';
        }

        $storedHash = self::uciGet(self::LOGIN_PASSWORD_HASH_KEY, false);
        if (is_string($storedHash) && $storedHash !== '') {
            $passwordHash = $storedHash;
        }

        return $username === $configuredUser
            && is_string($passwordHash)
            && $passwordHash !== ''
            && password_verify($password, $passwordHash);
    }

    public static function getLoginPasswordHash()
    {
        $hash = self::uciGet(self::LOGIN_PASSWORD_HASH_KEY, false);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public static function setLoginPasswordHash($hash)
    {
        if (!is_string($hash) || $hash === '') {
            throw new \InvalidArgumentException('A password hash is required.');
        }

        self::uciSet(self::LOGIN_PASSWORD_HASH_KEY, $hash);
    }

    public static function execUbusCall($namespace, $method, $args = [])
    {
        return false;
    }

    public static function uciReadConfig($configName)
    {
        $prefix = $configName . '.';
        $config = [];
        foreach (self::readConfigData() as $key => $value) {
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                $config[substr($key, strlen($prefix))] = $value;
            }
        }

        return $config;
    }

    public static function uciGet($uciString, $throwOnError = true)
    {
        $config = self::readConfigData();
        if (array_key_exists($uciString, $config)) {
            return $config[$uciString];
        }

        if ($throwOnError) {
            throw new \RuntimeException('UCI configuration is only available on OpenWrt.');
        }

        return null;
    }

    public static function uciSet($settingString, $value, $isList = false, $autoCommit = true)
    {
        self::updateConfigData(function (&$config) use ($settingString, $value, $isList) {
            if ($isList) {
                $config[$settingString] = $config[$settingString] ?? [];
                $config[$settingString][] = $value;
            } else {
                $config[$settingString] = ($value === null || $value === '') ? 'UNSET' : $value;
            }
        });
    }

    public static function uciDelete($settingString, $autoCommit = true)
    {
        self::updateConfigData(function (&$config) use ($settingString) {
            unset($config[$settingString]);
        });
    }

    public static function uciCommit()
    {
        return true;
    }

    public static function uciGetJson($uciString, $throwOnError = true)
    {
        return self::uciGet($uciString, $throwOnError) ?? [];
    }

    public static function uciSetJson($settingString, $value, $autoCommit = true)
    {
        self::uciSet($settingString, $value, false, $autoCommit);
    }

    public static function uciGetConfig($configName)
    {
        return self::uciReadConfig($configName);
    }

    public static function uciGetSection($configName, $section)
    {
        $prefix = $configName . '.' . $section . '.';
        $options = [];
        foreach (self::readConfigData() as $key => $value) {
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                $options[substr($key, strlen($prefix))] = $value;
            }
        }

        return $options;
    }

    private static function getConfigFile()
    {
        $configuredPath = getenv('FRIEREN_CONFIG_FILE');
        if ($configuredPath !== false && $configuredPath !== '') {
            return $configuredPath;
        }

        $configHome = getenv('XDG_CONFIG_HOME');
        if ($configHome === false || $configHome === '') {
            $home = getenv('HOME');
            $configHome = ($home !== false && $home !== '') ? $home . '/.config' : sys_get_temp_dir();
        }

        return rtrim($configHome, '/') . '/frieren/config.json';
    }

    private static function readConfigData()
    {
        $file = self::getConfigFile();
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    private static function updateConfigData($callback)
    {
        $file = self::getConfigFile();
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Frieren configuration directory.');
        }

        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock Frieren configuration.');
        }

        $data = self::readConfigData();
        $callback($data);
        $temporaryFile = $file . '.' . getmypid() . '.tmp';
        $written = file_put_contents($temporaryFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($written === false || !rename($temporaryFile, $file)) {
            @unlink($temporaryFile);
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new \RuntimeException('Unable to save Frieren configuration.');
        }

        chmod($file, 0600);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}