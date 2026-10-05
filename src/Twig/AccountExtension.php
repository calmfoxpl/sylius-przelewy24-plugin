<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Twig;

use Calmfox\SyliusPrzelewy24Plugin\Account\AccountStore;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `calmfox_przelewy24_account()`, for the payment method page: which account a method pays into,
 * without the keys. Only what a shopkeeper needs to recognise the account: test or production
 * mode and the merchant ID.
 */
final class AccountExtension extends AbstractExtension
{
    public function __construct(private readonly AccountStore $store)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('calmfox_przelewy24_account', $this->account(...))];
    }

    /** @return array{saved: bool, sandbox: bool, merchant_id: int} */
    public function account(): array
    {
        $credentials = $this->store->credentials();
        if (null === $credentials) {
            return ['saved' => false, 'sandbox' => true, 'merchant_id' => 0];
        }

        return ['saved' => true, 'sandbox' => $credentials->sandbox, 'merchant_id' => $credentials->merchantId];
    }
}
