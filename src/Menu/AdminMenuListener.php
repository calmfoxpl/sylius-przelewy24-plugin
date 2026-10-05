<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * Calmfox services → Przelewy24: the account and its keys. The payment methods stay under
 * Configuration → Payment methods, where Sylius has them.
 */
final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        CalmfoxSection::in($event->getMenu())
            ->addChild('calmfox_przelewy24', ['route' => 'calmfox_przelewy24_admin_account'])
            ->setLabel('calmfox_przelewy24.account.menu')
            ->setLabelAttribute('icon', 'tabler:credit-card')
        ;
    }
}
