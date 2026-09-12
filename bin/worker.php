<?php

declare(strict_types=1);
$store = require dirname(__DIR__).'/bootstrap.php';
$worker = new Neo\Worker($store);
do {
    $worked = $worker->once();
    if (in_array('--once', $argv, true)) {
        break;
    } if (!$worked) {
        sleep(2);
    }
} while (true);
