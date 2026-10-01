<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\terminal;

use frieren\helper\HelperFactory;

class TerminalController extends \frieren\core\Controller
{
    const TTYD_PATH = "/usr/bin/ttyd";
    const TTYD_SD_PATH = "/sd/usr/bin/ttyd";
    const LAN_INTERFACE = 'lan';
    const FALLBACK_LAN_DEVICE = 'br-lan';

    public $endpointRoutes = [
        'startTerminal' => true,
        'stopTerminal' => true,
        'getStatus' => true,
    ];

    // ttyd is launched via nohup (execBackground) and forks asynchronously, so an
    // immediate pgrep can miss it and report a spurious failure — this is why the
    // very first open after install fails while later ones succeed. Poll briefly to
    // let the process spawn and bind port 5001 before reporting status.
    const START_POLL_ATTEMPTS = 10;
    const START_POLL_INTERVAL_US = 150000; // 150ms -> up to ~1.5s total

    private function getTerminalPath()
    {
        if ($this->setupCoreHelper()::isSDAvailable() && file_exists(self::TTYD_SD_PATH)) {
            return self::TTYD_SD_PATH;
        }

        if (\DeviceConfig::getSystemFamily() === 'Linux') {
            $path = trim((string) shell_exec('command -v ttyd 2>/dev/null'));
            return $path !== '' ? $path : self::TTYD_PATH;
        }

        return self::TTYD_PATH;
    }

    private function getSettingsHelper()
    {
        return HelperFactory::createModuleHelper('settings', \DeviceConfig::getSystemFamily());
    }

    private function getNetworkHelper()
    {
        return HelperFactory::createModuleHelper('network', \DeviceConfig::getSystemFamily());
    }

    /**
     * Resolves the device backing the `lan` interface, which ttyd binds to.
     * Not every board bridges its LAN, so the `br-lan` literal this used to
     * hardcode simply does not exist on some of them (ttyd then fails to bind
     * with no visible error); the fallback keeps the common case unchanged
     * when netifd reports nothing.
     *
     * @return string Device name (e.g. br-lan, eth0).
     */
    private function getLanDevice()
    {
        foreach ($this->getNetworkHelper()::getInterfaces() as $interface) {
            if ($interface['name'] === self::LAN_INTERFACE && !empty($interface['device'])) {
                return $interface['device'];
            }
        }

        return self::FALLBACK_LAN_DEVICE;
    }

    private function waitForRunning($terminal)
    {
        // Sleep before each check: ttyd never appears in under one interval, so the
        // happy path costs a single pgrep instead of two or three.
        for ($attempt = 0; $attempt < self::START_POLL_ATTEMPTS; $attempt++) {
            usleep(self::START_POLL_INTERVAL_US);
            if ($this->setupCoreHelper()::checkRunning($terminal)) {
                return true;
            }
        }

        return false;
    }

    public function startTerminal()
    {
        if (!$this->getSettingsHelper()::isTerminalEnabled()) {
            return self::setError('Terminal is disabled');
        }

        // disable ttyd instance
        if (\DeviceConfig::getSystemFamily() === 'Linux') {
            if (!$this->setupCoreHelper()::commandExists('ttyd')) {
                return self::setError('ttyd is not installed');
            }
        } else {
            exec("/etc/init.d/ttyd stop");
            $this->setupCoreHelper()::execBackground("/etc/init.d/ttyd disable");
        }

        // terminal implementation
        $terminal = $this->getTerminalPath();
        $status = $this->setupCoreHelper()::checkRunning($terminal);
        if (!$status) {
            $shell = $this->getSettingsHelper()::getTerminalAutologin()
                ? (\DeviceConfig::getSystemFamily() === 'Linux' ? '/bin/bash' : '/bin/ash')
                : '/bin/login';
            // Launch from /root so the (autologin) shell opens there instead of
            // inheriting the PHP process cwd (/usr/share/frieren/api). The cd is
            // confined to this subshell; the paths are fixed and the bind device
            // comes from netifd, so no user input reaches this command.
            if (\DeviceConfig::getSystemFamily() === 'Linux') {
                $command = escapeshellarg($terminal) . ' -p 5001 -i 127.0.0.1 ' . escapeshellarg($shell);
            } else {
                $command = "sh -c 'cd /root && {$terminal} -p 5001 -i {$this->getLanDevice()} {$shell}'";
            }
            $this->setupCoreHelper()::execBackground($command);
            $status = $this->waitForRunning($terminal);
            if (!$status) {
                $this->logger("Terminal could not be run! command exec: {$command}");
            }
        }

        $response = ["success" => $status];
        if ($status) {
            $response['terminalTheme'] = $this->getSettingsHelper()::getTerminalTheme();
            $response['fontSize'] = $this->getSettingsHelper()::getTerminalFontSize();
            $response['cursorStyle'] = $this->getSettingsHelper()::getTerminalCursorStyle();
            $response['cursorBlink'] = $this->getSettingsHelper()::getTerminalCursorBlink();
        }

        self::setSuccess($response);
    }

    public function stopTerminal()
    {
        if (!$this->getSettingsHelper()::isTerminalEnabled()) {
            return self::setError('Terminal is disabled');
        }

        if (\DeviceConfig::getSystemFamily() === 'Linux') {
            $this->setupCoreHelper()::exec("pkill -x ttyd");
        } else {
            $this->setupCoreHelper()::exec("/usr/bin/killall ttyd");
        }
        $status = $this->setupCoreHelper()::checkRunning($this->getTerminalPath());
        if ($status) {
            $this->logger("Terminal could not be stop! command exec: /usr/bin/killall ttyd");
        }

        self::setSuccess(["success" => !$status]);
    }

    public function getStatus()
    {
        if (!$this->getSettingsHelper()::isTerminalEnabled()) {
            return self::setSuccess(["status" => false]);
        }

        self::setSuccess(["status" => $this->setupCoreHelper()::checkRunning($this->getTerminalPath())]);
    }
}
