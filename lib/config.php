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
    // Auto-detect PUBLIC_URL if not set or still the placeholder.
    if (empty($cfg['PUBLIC_URL']) || str_contains($cfg['PUBLIC_URL'], 'example.com')) {
        $cfg['PUBLIC_URL'] = detect_public_url();
    }
    return $cfg;
}

/** Detect the public base URL from the current request, including subfolder. */
function detect_public_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    // Detect the app's subfolder by comparing the script path to the request URI.
    // e.g. if request is /queueoke/tv.php and script is tv.php, base is /queueoke
    $script = $_SERVER['SCRIPT_NAME'] ?? '';       // /queueoke/tv.php
    $scriptDir = rtrim(str_replace('\\', '/', dirname($script)), '/'); // /queueoke or ''

    return $scheme . '://' . $host . $scriptDir;
}
