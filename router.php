<?php
// router.php for PHP built-in server
// Serves existing files; otherwise routes all requests to index.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;
if ($uri !== '/' && file_exists($path) && !is_dir($path)) {
    return false; // let the server handle the static file
}
require __DIR__ . '/index.php';
