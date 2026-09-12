<?php

// Local PHP development server only. Production routes through Nginx.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/app.css','/app.js'], true)) {
    return false;
}
require __DIR__.'/index.php';
