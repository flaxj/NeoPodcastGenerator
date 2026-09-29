<?php

// Copy to config.php. Environment variables override these values.
return [
    'NEO_MODE' => 'server',
    'NEO_SETUP_TOKEN' => '', // Set your own random secret, at least 20 characters.
    'NEO_BASE_PATH' => '', // For example /podcast; empty for a domain root.
    'NEO_DATA' => __DIR__.'/var', // Prefer an absolute path outside the web root.
    'NEO_SECURE_COOKIES' => '1',
    'NEO_MAX_UPLOAD' => '10737418240',
];
