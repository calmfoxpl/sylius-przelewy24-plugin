<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CalmfoxSyliusPrzelewy24Extension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        (new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config')))->load('services.yaml');
    }

    /** The account entity is mapped by the plugin, so the application does not have to. */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'CalmfoxSyliusPrzelewy24Plugin' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => \dirname(__DIR__) . '/Entity',
                        'prefix' => 'Calmfox\SyliusPrzelewy24Plugin\Entity',
                        'alias' => 'CalmfoxSyliusPrzelewy24Plugin',
                    ],
                ],
            ],
        ]);
    }
}
