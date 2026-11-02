<?php

declare(strict_types=1);

use Pig\CodingAgent\Hooks\HookApi;
use PigPermissionGate\Extension;

/*
 * pig-permission-gate — blocks `rm -rf /`, asks before `sudo`, `dd`, `git reset --hard` and the
 * like, and turns `rm` into a move to the Trash (or `_to_delete/`).
 *
 * A package installed from git is not run through Composer, so nothing autoloads its classes;
 * this registers the loader for the package's own namespace, once.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PigPermissionGate\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 18)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

return static function (HookApi $pi): void {
    Extension::register($pi);
};
