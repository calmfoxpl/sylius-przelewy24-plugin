<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Gateway;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * The gateway, built on Sylius 2 payment requests rather than Payum.
 *
 *  1. The customer places the order and Sylius opens a `capture` request. The capture handler
 *     registers a transaction with Przelewy24, and the HTTP response provider sends the customer
 *     to the Przelewy24 payment page.
 *  2. Przelewy24 notifies /payment-methods/{method code}. The notify payment provider finds the
 *     payment by its session ID; the notify handler checks the signature, verifies the money with
 *     the API and completes the payment.
 *  3. The customer comes back to /order/after-pay/{hash} and Sylius opens a `status` request. The
 *     status handler asks Przelewy24 how the transaction stands and settles it itself when the
 *     notification has not arrived yet — or never will, because a firewall keeps it out.
 */
final class Przelewy24Gateway
{
    public const FACTORY = 'przelewy24';

    /** The session ID of the most recently registered transaction, in `Payment::details`. */
    public const DETAILS_SESSION_ID = 'przelewy24_session_id';

    public static function supports(PaymentMethodInterface $method): bool
    {
        $config = $method->getGatewayConfig();

        return $config instanceof GatewayConfigInterface &&
            self::FACTORY === ($config->getConfig()['factory'] ?? $config->getFactoryName());
    }

    public static function credentials(PaymentMethodInterface $method): Credentials
    {
        return Credentials::fromArray($method->getGatewayConfig()?->getConfig() ?? []);
    }
}
