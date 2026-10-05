<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Menu;

use Knp\Menu\ItemInterface;

/**
 * The "Calmfox services" group in the admin sidebar, which every Calmfox plugin adds its pages to.
 *
 * The split it stands for: what belongs to one shipping or payment method stays on that method's
 * page, where Sylius keeps it and where a shopkeeper looks for it. What is set up once for the
 * whole service (an account, its keys, a connection, a policy) gets a page of its own here, so it
 * can be found without knowing which method happens to carry it, and changed without opening one.
 *
 * Every plugin carries its own copy of this class instead of depending on a shared package. The
 * group is found by its key, so whichever plugin builds the menu first creates it and the others
 * add to it; that only works while the key, label and icon are the same in every copy.
 */
final class CalmfoxSection
{
    public const KEY = 'calmfox';

    public static function in(ItemInterface $menu): ItemInterface
    {
        $section = $menu->getChild(self::KEY);
        if (null !== $section) {
            return $section;
        }

        $section = $menu
            ->addChild(self::KEY)
            ->setLabel('calmfox.menu.section')
            ->setLabelAttribute('icon', 'tabler:plug-connected')
        ;
        self::placeAfterConfiguration($menu);

        return $section;
    }

    /**
     * New groups land at the end of the menu. Right after Configuration is where somebody looking
     * for a setting already is. Without a Configuration group the order is left alone.
     */
    private static function placeAfterConfiguration(ItemInterface $menu): void
    {
        $keys = array_values(array_filter(
            array_map('strval', array_keys($menu->getChildren())),
            static fn (string $key): bool => self::KEY !== $key,
        ));
        $configuration = array_search('configuration', $keys, true);
        if (false === $configuration) {
            return;
        }

        array_splice($keys, $configuration + 1, 0, [self::KEY]);
        $menu->reorderChildren($keys);
    }
}
