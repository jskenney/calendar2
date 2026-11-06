<?php

declare(strict_types=1);

/*
 * Class autoloader for Cal5 (no Composer needed).
 * Cal5\Foo\Bar is loaded from src/Foo/Bar.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Cal5\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
