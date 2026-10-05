<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Form\Type;

use Symfony\Component\Form\AbstractType;

/**
 * The gateway section of a Przelewy24 payment method: Configuration → Payment methods.
 *
 * It has no fields on purpose. The account and its keys are set once for the shop, in Calmfox
 * services → Przelewy24, and every Przelewy24 method pays into it; what is particular to one
 * method (its name, channels, position) is on the rest of this form, where Sylius keeps it.
 *
 * Sylius still needs a form type registered for the gateway, and an empty compound form leaves
 * the stored configuration as it was. That matters on an installation upgraded from 1.0: the keys
 * a method carries keep working until the account is saved, even if the method is edited first.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class GatewayConfigurationType extends AbstractType
{
}
