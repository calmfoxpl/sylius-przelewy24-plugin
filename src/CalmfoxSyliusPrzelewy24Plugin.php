<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CalmfoxSyliusPrzelewy24Plugin extends Bundle
{
    use SyliusPluginTrait;

    public const VERSION = '1.0.0';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
