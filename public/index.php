<?php

declare(strict_types=1);
$store = require dirname(__DIR__).'/bootstrap.php';
(new Neo\App($store))->run();
