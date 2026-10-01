<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

class DeviceConfig
{
    // This flag tells the framework which helpers to use depending on which host variant is running the project
    const GUESS_TYPE = 'OpenWrt';

    public static function getSystemFamily()
    {
        $systemFamily = getenv('FRIEREN_SYSTEM_FAMILY');

        return $systemFamily !== false && $systemFamily !== '' ? $systemFamily : self::GUESS_TYPE;
    }

    public static function getModuleRootFolder()
    {
        $configuredPath = getenv('FRIEREN_MODULE_ROOT');
        if ($configuredPath !== false && $configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        return self::getSystemFamily() === 'Linux'
            ? dirname(__DIR__, 2) . '/modules'
            : self::MODULE_ROOT_FOLDER;
    }

    public static function getModuleRoots()
    {
        $roots = [self::getModuleRootFolder()];
        $extraRoots = getenv('FRIEREN_MODULE_EXTRA_ROOT');
        if ($extraRoots !== false && $extraRoots !== '') {
            foreach (explode(PATH_SEPARATOR, $extraRoots) as $root) {
                $root = rtrim($root, '/');
                if ($root !== '' && !in_array($root, $roots, true)) {
                    $roots[] = $root;
                }
            }
        }

        return $roots;
    }

    public static function findModulePath($moduleName)
    {
        if (!is_string($moduleName) || !preg_match('/^[a-zA-Z0-9_-]+$/', $moduleName)) {
            return false;
        }

        foreach (self::getModuleRoots() as $root) {
            $rootPath = realpath($root);
            if ($rootPath === false) {
                continue;
            }
            foreach (["{$rootPath}/{$moduleName}", "{$rootPath}/{$moduleName}/public"] as $modulePath) {
                $moduleRealPath = realpath($modulePath);
                if ($moduleRealPath && strpos($moduleRealPath, $rootPath . DIRECTORY_SEPARATOR) === 0
                    && is_file($moduleRealPath . '/manifest.json')) {
                    return $moduleRealPath;
                }
            }
        }

        return false;
    }

    public static function getModuleSdRootFolder()
    {
        $configuredPath = getenv('FRIEREN_MODULE_SD_ROOT');

        return ($configuredPath !== false && $configuredPath !== '')
            ? rtrim($configuredPath, '/')
            : self::MODULE_SD_ROOT_FOLDER;
    }

    public static function getDataRootFolder()
    {
        $configuredPath = getenv('FRIEREN_DATA_ROOT');
        if ($configuredPath !== false && $configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        $dataHome = getenv('XDG_DATA_HOME');
        if ($dataHome === false || $dataHome === '') {
            $home = getenv('HOME');
            $dataHome = ($home !== false && $home !== '') ? $home . '/.local/share' : sys_get_temp_dir();
        }

        return rtrim($dataHome, '/') . '/frieren';
    }


    // Module behaviour
    const MODULE_ROOT_FOLDER = '/frieren/modules';
    const MODULE_SD_ROOT_FOLDER = '/sd/modules';
    const MODULE_USE_INTERNAL_STORAGE = true;
    const MODULE_USE_USB_STORAGE = true;
    const MODULE_HIDE_SYSTEM_MODULES = true;


    // Remote content
    const MODULE_SERVER_URL = 'https://raw.githubusercontent.com/xchwarze/frieren-modules-release/master';
    const MODULE_JSON_PATH = '%s/json/modules.json';
    const MODULE_PACKAGE_PATH = '%s/modules/%s';
    const NEWS_JSON_PATH = '%s/json/news.json';
}
