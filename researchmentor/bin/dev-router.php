<?php
/** Router script for PHP's built-in server (development only): php -S 127.0.0.1:8080 -t public bin/dev-router.php */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // serve static assets directly
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
