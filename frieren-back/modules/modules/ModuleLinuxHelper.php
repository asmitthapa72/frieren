<?php

namespace frieren\modules\modules;

class ModuleLinuxHelper
{
    public static function getModuleFolders($modulesRoot)
    {
        $roots = is_array($modulesRoot) ? $modulesRoot : [$modulesRoot];
        $folders = [];
        foreach ($roots as $root) {
            if (!is_dir($root) || !is_readable($root)) {
                continue;
            }
            $entries = @scandir($root);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($path) || is_link($path)) {
                    continue;
                }

                $publicPath = $path . '/public';
                if (is_file($path . '/manifest.json')) {
                    $folders[] = $path;
                } elseif (is_file($publicPath . '/manifest.json')) {
                    $folders[] = $publicPath;
                }
            }
        }

        return array_values(array_unique($folders));
    }

    public static function getAllModuleSizes($modulesRoot)
    {
        $folders = self::getModuleFolders($modulesRoot);
        if ($folders === false) {
            return [];
        }

        $sizes = [];
        foreach ($folders as $folder) {
            $bytes = 0;
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && !$file->isLink()) {
                        $size = $file->getSize();
                        if ($size > 0) {
                            $bytes += $size;
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $bytes = 0;
            }
            $name = basename($folder) === 'public' ? basename(dirname($folder)) : basename($folder);
            $sizes[$name] = self::formatSize($bytes);
        }

        return $sizes;
    }

    private static function formatSize($bytes)
    {
        $units = ['B', 'K', 'M', 'G', 'T'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : sprintf('%.1f', $size)) . $units[$unit];
    }
}