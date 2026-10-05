<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Account;

use Calmfox\SyliusPrzelewy24Plugin\Account\CredentialsResolver;
use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\Shop;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\SyliusTestCase;

final class CredentialsResolverTest extends SyliusTestCase
{
    public function testTheSavedAccountWins(): void
    {
        $method = Shop::payment()->getMethod();
        \assert(null !== $method);
        $resolver = Shop::resolver(new Credentials(22, 23, 'account-crc', 'account-key', false));

        $credentials = $resolver->forMethod($method);

        self::assertSame(22, $credentials->merchantId);
        self::assertSame('account-crc', $credentials->crc);
        self::assertFalse($credentials->sandbox);
        self::assertSame(CredentialsResolver::SOURCE_ACCOUNT, $resolver->sourceFor($method));
    }

    /** An installation upgraded from 1.0 keeps taking payments before the account page is saved. */
    public function testWithoutASavedAccountTheMethodKeepsItsOwnKeys(): void
    {
        $method = Shop::payment()->getMethod();
        \assert(null !== $method);
        $resolver = Shop::resolver();

        self::assertSame('crc-key', $resolver->forMethod($method)->crc);
        self::assertSame(CredentialsResolver::SOURCE_METHOD, $resolver->sourceFor($method));
    }

    /** Never half of one and half of the other: an incomplete account is not patched from the method. */
    public function testAnIncompleteAccountIsNotMixedWithTheMethodKeys(): void
    {
        $method = Shop::payment()->getMethod();
        \assert(null !== $method);

        $credentials = Shop::resolver(new Credentials(22, 22, '', 'account-key', false))->forMethod($method);

        self::assertFalse($credentials->isComplete());
        self::assertSame('', $credentials->crc);
    }
}
