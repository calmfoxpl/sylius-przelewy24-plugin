<?php

/**
 * Composer's autoloader when there is one, and a PSR-4 fallback when there is not.
 *
 * The core tests cover src/Core, which knows nothing about Symfony or Sylius, so they run in a
 * checkout with no `composer install` and no Sylius at all — which is what makes them usable as
 * a quick check while working on the plugin. The tests of the API client and the gateway need
 * Symfony and Sylius, and skip themselves without them.
 *
 * AUTOLOAD=/path/to/shop/vendor/autoload.php borrows the vendor directory of a Sylius shop, which
 * runs those tests too without installing anything here.
 */

declare(strict_types=1);

$autoload = \dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;

    return;
}

$borrowed = getenv('AUTOLOAD');
if (\is_string($borrowed) && is_file($borrowed)) {
    require $borrowed;
}

spl_autoload_register(static function (string $class): void {
    $roots = [
        'Tests\\Calmfox\\SyliusPrzelewy24Plugin\\' => __DIR__ . '/',
        'Calmfox\\SyliusPrzelewy24Plugin\\' => \dirname(__DIR__) . '/src/',
    ];

    foreach ($roots as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $directory . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
}, true, true);
