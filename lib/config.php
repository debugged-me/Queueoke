<?php
/**
 * Loads config.php (user secrets). Falls back to sample so the app
 * doesn't fatal-error before the user has filled in their keys.
 */
function config_get(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/../config.php';
    if (!is_file($path)) {
        $path = __DIR__ . '/../config.sample.php';
    }
    $cfg = require $path;
    return $cfg;
}
