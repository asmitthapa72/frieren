<?php

namespace frieren\modules\settings;

class ModuleLinuxHelper
{
    const MIN_SYNC_EPOCH = 1577836800;
    const THEME_KEY = 'frieren.@settings[0].theme';
    const TERMINAL_THEME_KEY = 'frieren.@settings[0].terminal_theme';
    const TERMINAL_FONT_SIZE_KEY = 'frieren.@settings[0].terminal_font_size';
    const TERMINAL_CURSOR_STYLE_KEY = 'frieren.@settings[0].terminal_cursor_style';
    const TERMINAL_CURSOR_BLINK_KEY = 'frieren.@settings[0].terminal_cursor_blink';
    const TERMINAL_AUTOLOGIN_KEY = 'frieren.@settings[0].terminal_autologin';
    const TERMINAL_ENABLED_KEY = 'frieren.@settings[0].terminal_enabled';

    public static function convertOpenWrtTimezoneValue($timezone)
    {
        if (strpos($timezone, 'GMT') !== false) {
            $newSign = strpos($timezone, '-') !== false ? '+' : '-';
            $timezone = str_replace(['+', '-'], $newSign, $timezone);
        }

        return $timezone;
    }

    public static function getSystemTimeZone()
    {
        $offset = (int) date('Z');
        $hours = (int) floor(abs($offset) / 3600);
        if ($hours === 0) {
            return 'GMT0';
        }

        return 'GMT' . ($offset < 0 ? '-' : '+') . $hours;
    }

    public static function changeSystemTimeZone($timezone)
    {
        if (!is_string($timezone) || !preg_match('/^GMT([+-]?)(0|[1-9]|1[0-2])$/', $timezone, $matches)) {
            return false;
        }
        $hours = (int) $matches[2];
        $sign = $matches[1];
        if ($hours === 0) {
            $zone = 'Etc/UTC';
        } else {
            $ianaSign = $sign === '-' ? '+' : '-';
            $zone = 'Etc/GMT' . $ianaSign . $hours;
        }

        $result = self::runCommand(['timedatectl', 'set-timezone', $zone]);

        return $result !== false && $result['code'] === 0;
    }

    public static function syncDatetimeFromBrowser($datetime)
    {
        if (!is_numeric($datetime) || (int) $datetime < self::MIN_SYNC_EPOCH) {
            return false;
        }
        $result = self::runCommand(['date', '-s', '@' . (string) (int) $datetime]);

        return $result !== false && $result['code'] === 0;
    }

    public static function applyBrowserDatetime($datetime, $timezone)
    {
        $timezoneSet = self::changeSystemTimeZone($timezone);
        $datetimeSet = self::syncDatetimeFromBrowser($datetime);

        return $timezoneSet || $datetimeSet;
    }

    public static function setSystemHostname($hostname)
    {
        if (!is_string($hostname) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $hostname)) {
            return false;
        }
        $result = self::runCommand(['hostnamectl', 'set-hostname', $hostname]);

        return $result !== false && $result['code'] === 0 && gethostname() === $hostname;
    }

    public static function changeUserPassword($current, $new)
    {
        if (!is_string($current) || !is_string($new) || $new === '' || preg_match('/[\r\n]/', $new)) {
            return false;
        }
        $username = getenv('FRIEREN_USER');
        if ($username === false || $username === '') {
            $userInfo = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
            $username = is_array($userInfo) ? $userInfo['name'] : '';
        }
        if ($username === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*[$]?$/', $username)
            || !\frieren\helper\LinuxHelper::verifyPassword($username, $current)) {
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        try {
            $process = @proc_open(['sudo', '-n', 'chpasswd'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } catch (\Throwable $exception) {
            return false;
        }
        if (!is_resource($process)) {
            return false;
        }

        $previousHash = \frieren\helper\LinuxHelper::getLoginPasswordHash();
        try {
            \frieren\helper\LinuxHelper::setLoginPasswordHash(password_hash($new, PASSWORD_DEFAULT));
        } catch (\Throwable $exception) {
            fclose($pipes[0]);
            proc_terminate($process);
            proc_close($process);
            return false;
        }

        $written = fwrite($pipes[0], $username . ':' . $new . "\n");
        fclose($pipes[0]);
        $code = proc_close($process);

        if ($written === false || $code !== 0) {
            try {
                if ($previousHash === null) {
                    \frieren\helper\LinuxHelper::uciDelete(\frieren\helper\LinuxHelper::LOGIN_PASSWORD_HASH_KEY);
                } else {
                    \frieren\helper\LinuxHelper::setLoginPasswordHash($previousHash);
                }
            } catch (\Throwable $exception) {
                return false;
            }
            return false;
        }

        return true;
    }

    public static function setPanelTheme($theme)
    {
        return self::savePreference(self::THEME_KEY, $theme) && \frieren\helper\LinuxHelper::uciGet(self::THEME_KEY, false) === $theme;
    }

    public static function getTerminalAutologin()
    {
        return \frieren\helper\LinuxHelper::uciGet(self::TERMINAL_AUTOLOGIN_KEY, false) === true;
    }

    public static function getTerminalTheme()
    {
        return \frieren\helper\LinuxHelper::uciGet(self::TERMINAL_THEME_KEY, false) ?? 'default';
    }

    public static function saveTerminalSettings($theme, $fontSize, $cursorStyle, $cursorBlink, $autologin, $terminalEnabled)
    {
        if (!is_string($cursorStyle) || !in_array($cursorStyle, ['block', 'underline', 'bar'], true)) {
            return false;
        }
        $settings = [
            self::TERMINAL_THEME_KEY => $theme,
            self::TERMINAL_FONT_SIZE_KEY => (string) max(8, min(32, (int) $fontSize)),
            self::TERMINAL_CURSOR_STYLE_KEY => $cursorStyle,
            self::TERMINAL_CURSOR_BLINK_KEY => (bool) $cursorBlink,
            self::TERMINAL_AUTOLOGIN_KEY => (bool) $autologin,
            self::TERMINAL_ENABLED_KEY => (bool) $terminalEnabled,
        ];
        try {
            foreach ($settings as $key => $value) {
                \frieren\helper\LinuxHelper::uciSet($key, $value, false, false);
            }
            \frieren\helper\LinuxHelper::uciCommit();
        } catch (\Throwable $exception) {
            return false;
        }

        return true;
    }

    public static function isTerminalEnabled()
    {
        return \frieren\helper\LinuxHelper::uciGet(self::TERMINAL_ENABLED_KEY, false) !== false;
    }

    public static function getTerminalFontSize()
    {
        return (int) (\frieren\helper\LinuxHelper::uciGet(self::TERMINAL_FONT_SIZE_KEY, false) ?? 13);
    }

    public static function getTerminalCursorStyle()
    {
        return \frieren\helper\LinuxHelper::uciGet(self::TERMINAL_CURSOR_STYLE_KEY, false) ?? 'block';
    }

    public static function getTerminalCursorBlink()
    {
        return \frieren\helper\LinuxHelper::uciGet(self::TERMINAL_CURSOR_BLINK_KEY, false) === true;
    }

    public static function getSectionData()
    {
        return [
            'hostname' => gethostname() ?: 'localhost',
            'timezone' => self::getSystemTimeZone(),
            'theme' => \frieren\helper\LinuxHelper::uciGet(self::THEME_KEY, false) ?? 'auto',
            'terminalAutologin' => self::getTerminalAutologin(),
            'terminalTheme' => self::getTerminalTheme(),
            'fontSize' => self::getTerminalFontSize(),
            'cursorStyle' => self::getTerminalCursorStyle(),
            'cursorBlink' => self::getTerminalCursorBlink(),
            'terminalEnabled' => self::isTerminalEnabled(),
        ];
    }

    private static function savePreference($key, $value)
    {
        try {
            \frieren\helper\LinuxHelper::uciSet($key, $value);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
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