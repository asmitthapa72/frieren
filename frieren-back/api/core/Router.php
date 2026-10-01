<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\core;

/**
 * Handles routing of requests to appropriate modules.
 */
class Router {
    /**
     * @var array The request data.
     */
    private $request;

    /**
     * Constructor for Router.
     *
     * @param array $request The request data.
     */
    public function __construct($request) {
        $this->request = $request;
    }

    /**
     * Routes the request to the appropriate module based on request data.
     *
     * @return mixed The response from the routed module.
     * @throws \Exception if the module is not specified or cannot be loaded.
     */
    public function routeModule() {
        $module = $this->request['module'] ?? '';
        if (empty($module) || !preg_match('/^[a-z0-9_]+$/i', $module)) {
            throw new \Exception('No valid module has been specified.');
        }

        return $this->loadModule($module)->getResponseHandler();
    }

    /**
     * Loads a module and executes its route method.
     *
     * @param string $moduleName The name of the module to load.
     * @return mixed The instance of the module class.
     * @throws \Exception if the module file or class does not exist.
     */
    private function loadModule($moduleName)
    {
        $controllerName = ucfirst($moduleName) . 'Controller';

        // $moduleName is already restricted to /^[a-z0-9_]+$/i by routeModule(), which
        // rules out '.'/'/' and therefore any '../' traversal on its own. The realpath()
        // check below is a second, independent layer so a future change to that regex
        // can't silently reopen a traversal path through $moduleFilePath.
        $moduleDirectory = \DeviceConfig::findModulePath($moduleName);
        $moduleFilePath = $moduleDirectory ? "{$moduleDirectory}/{$controllerName}.php" : '';
        $moduleRealPath = $moduleFilePath !== '' ? realpath($moduleFilePath) : false;
        if (!$moduleDirectory || !$moduleRealPath || dirname($moduleRealPath) !== $moduleDirectory) {
            throw new \Exception("Module file for '{$moduleName}' does not exist.");
        }

        if (\DeviceConfig::getSystemFamily() === 'Linux') {
            $manifest = json_decode((string) @file_get_contents($moduleDirectory . '/manifest.json'), true);
            $guestTypes = is_array($manifest) ? ($manifest['guestType'] ?? []) : [];
            if (is_array($guestTypes) && $guestTypes && !in_array('Linux', $guestTypes, true)) {
                throw new \Exception("Module '{$moduleName}' does not support Linux.");
            }
        }

        require($moduleFilePath);
        $moduleClass = "frieren\\modules\\{$moduleName}\\{$controllerName}";
        if (!class_exists($moduleClass, false)) {
            throw new \Exception("The class {$moduleClass} does not exist in the module file.");
        }

        return new $moduleClass($this->request, $moduleName);
    }
}
