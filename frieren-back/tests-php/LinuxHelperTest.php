<?php

namespace frieren\helper;

use PHPUnit\Framework\TestCase;

class LinuxHelperTest extends TestCase
{
    public function testFactoryCreatesLinuxHelper(): void
    {
        $helper = HelperFactory::create('Linux');

        $this->assertInstanceOf(LinuxHelper::class, $helper);
    }

    public function testPanelPreferencesRoundTripInIsolatedJsonStore(): void
    {
        $path = sys_get_temp_dir() . '/frieren-test-' . bin2hex(random_bytes(8)) . '.json';
        $previousPath = getenv('FRIEREN_CONFIG_FILE');
        putenv('FRIEREN_CONFIG_FILE=' . $path);

        try {
            LinuxHelper::uciSetJson('frieren.@settings[0].sidebar', ['dashboard' => true]);
            $this->assertSame(
                ['dashboard' => true],
                LinuxHelper::uciGetJson('frieren.@settings[0].sidebar')
            );

            LinuxHelper::uciDelete('frieren.@settings[0].sidebar');
            $this->assertNull(LinuxHelper::uciGet('frieren.@settings[0].sidebar', false));
        } finally {
            if ($previousPath === false) {
                putenv('FRIEREN_CONFIG_FILE');
            } else {
                putenv('FRIEREN_CONFIG_FILE=' . $previousPath);
            }
            @unlink($path);
            @unlink($path . '.lock');
        }
    }

    public function testPersistedLoginHashOverridesTheStartupHash(): void
    {
        $path = sys_get_temp_dir() . '/frieren-auth-test-' . bin2hex(random_bytes(8)) . '.json';
        $previousPath = getenv('FRIEREN_CONFIG_FILE');
        $previousUser = getenv('FRIEREN_USER');
        $previousHash = getenv('FRIEREN_PASSWORD_HASH');
        putenv('FRIEREN_CONFIG_FILE=' . $path);
        putenv('FRIEREN_USER=frieren-test-user');
        putenv('FRIEREN_PASSWORD_HASH=' . password_hash('old-password', PASSWORD_DEFAULT));

        try {
            LinuxHelper::setLoginPasswordHash(password_hash('new-password', PASSWORD_DEFAULT));

            $this->assertTrue(LinuxHelper::verifyPassword('frieren-test-user', 'new-password'));
            $this->assertFalse(LinuxHelper::verifyPassword('frieren-test-user', 'old-password'));
        } finally {
            $previousPath === false ? putenv('FRIEREN_CONFIG_FILE') : putenv('FRIEREN_CONFIG_FILE=' . $previousPath);
            $previousUser === false ? putenv('FRIEREN_USER') : putenv('FRIEREN_USER=' . $previousUser);
            $previousHash === false ? putenv('FRIEREN_PASSWORD_HASH') : putenv('FRIEREN_PASSWORD_HASH=' . $previousHash);
            @unlink($path);
            @unlink($path . '.lock');
        }
    }

    public function testLinuxModuleHelpersExposeEveryOpenWrtHelperMethod(): void
    {
        foreach (['dashboard', 'header', 'system', 'modules', 'network', 'packages', 'settings', 'wireless'] as $module) {
            $namespace = 'frieren\\modules\\' . $module . '\\';
            $openWrtClass = $namespace . 'ModuleOpenWrtHelper';
            $linuxClass = $namespace . 'ModuleLinuxHelper';
            $missingMethods = array_diff(get_class_methods($openWrtClass), get_class_methods($linuxClass));

            $this->assertSame([], array_values($missingMethods), "Linux {$module} helper is missing public methods");
        }
    }

    public function testExtraModuleRootResolvesCompanionPublicDirectory(): void
    {
        $root = sys_get_temp_dir() . '/frieren-extra-modules-' . bin2hex(random_bytes(8));
        $modulePath = $root . '/sample/public';
        mkdir($modulePath, 0700, true);
        file_put_contents($modulePath . '/manifest.json', '{"name":"sample"}');
        $previousRoot = getenv('FRIEREN_MODULE_EXTRA_ROOT');
        putenv('FRIEREN_MODULE_EXTRA_ROOT=' . $root);

        try {
            $this->assertSame($modulePath, \DeviceConfig::findModulePath('sample'));
            $this->assertFalse(\DeviceConfig::findModulePath('../outside'));
        } finally {
            $previousRoot === false
                ? putenv('FRIEREN_MODULE_EXTRA_ROOT')
                : putenv('FRIEREN_MODULE_EXTRA_ROOT=' . $previousRoot);
            unlink($modulePath . '/manifest.json');
            rmdir($modulePath);
            rmdir(dirname($modulePath));
            rmdir($root);
        }
    }
}