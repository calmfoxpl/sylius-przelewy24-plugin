<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Account;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Which keys a payment method pays with.
 *
 * The account saved in Calmfox services → Przelewy24 wins, whole. Until one is saved, a method
 * uses the keys stored with it, which is where versions before 1.1 kept them: an upgrade must not
 * stop a shop from taking payments between the deployment and somebody opening the new page.
 *
 * Never a field from one and a field from the other. A merchant ID from the account with a CRC
 * key from the method would be two halves of different accounts, and Przelewy24 would refuse it
 * in a way nobody could explain from the panel.
 */
final class CredentialsResolver
{
    public const SOURCE_ACCOUNT = 'account';

    public const SOURCE_METHOD = 'method';

    public function __construct(private readonly SavedAccount $account)
    {
    }

    public function forMethod(PaymentMethodInterface $method): Credentials
    {
        return $this->account->credentials() ?? Przelewy24Gateway::credentials($method);
    }

    public function sourceFor(PaymentMethodInterface $method): string
    {
        return null !== $this->account->credentials() ? self::SOURCE_ACCOUNT : self::SOURCE_METHOD;
    }
}
