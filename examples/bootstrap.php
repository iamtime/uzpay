<?php

// Composer's autoloader after `composer install`; without it, a plain PSR-4 loader for src/.
if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'Uzpay\\')) {
            require __DIR__.'/../src/'.str_replace('\\', '/', substr($class, 6)).'.php';
        }
    });
}
