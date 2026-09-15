<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'CdrGen\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
