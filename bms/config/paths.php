<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = '';
    if (strpos($script, '/modules/') !== false) {
        $base = explode('/modules/', $script)[0];
    } elseif (strpos($script, '/includes/') !== false) {
        $base = explode('/includes/', $script)[0];
    } else {
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    }
    define('BASE_URL', $protocol . '://' . $host . $base . '/');
}

function url($path = '') {
    return BASE_URL . ltrim($path, '/');
}

function path($path = '') {
    return BASE_PATH . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/'));
}
