<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

// Cal5\Tests\X -> tests/lib/X.php, Cal5\Tests\Compat\X -> tests/compat/X.php
spl_autoload_register(static function (string $class): void {
    $map = [
        'Cal5\\Tests\\Compat\\' => __DIR__ . '/../compat/',
        'Cal5\\Tests\\' => __DIR__ . '/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
