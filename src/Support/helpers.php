<?php

declare(strict_types=1);

use Terrarium\Support\Env;

if (!function_exists('env')) {
    /**
     * Reads an environment variable loaded from .env (or the real process environment).
     * Converts "true"/"false"/"null"/"empty" strings to their PHP equivalents.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}
