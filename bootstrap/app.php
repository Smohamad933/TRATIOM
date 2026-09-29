<?php

declare(strict_types=1);

use Terrarium\Kernel\Application;
use Terrarium\Support\Config;
use Terrarium\Support\Env;

$basePath = dirname(__DIR__);

// Composer autoloader when available; otherwise a built-in PSR-4 loader (the app has no runtime dependencies)
if (is_file($basePath . '/vendor/autoload.php')) {
    require $basePath . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($basePath): void {
        if (str_starts_with($class, 'Terrarium\\')) {
            $file = $basePath . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    require_once $basePath . '/src/Support/helpers.php';
}

Env::load($basePath . '/.env');

return new Application($basePath, Config::fromDirectory($basePath . '/config'));
