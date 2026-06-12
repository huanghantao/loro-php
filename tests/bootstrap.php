<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
} else {
    foreach ([
        'LoroFFI.php',
    ] as $file) {
        require_once __DIR__ . '/../src/' . $file;
    }
}

require_once __DIR__ . '/LoroTestCase.php';
