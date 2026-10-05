<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Core;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    public function testReadsTheGatewayConfiguration(): void
    {
        $credentials = Credentials::fromArray(['sandbox' => false, 'merchant_id' => ' 11 ', 'pos_id' => '12', 'crc' => 'c', 'api_key' => 'k']);

        self::assertSame(11, $credentials->merchantId);
        self::assertSame(12, $credentials->posId);
        self::assertFalse($credentials->sandbox);
        self::assertTrue($credentials->isComplete());
        self::assertSame(Credentials::PRODUCTION_URL, $credentials->baseUrl());
    }

    public function testThePointOfSaleIsTheMerchantUnlessSaidOtherwise(): void
    {
        self::assertSame(11, Credentials::fromArray(['merchant_id' => '11', 'pos_id' => ''])->posId);
    }

    public function testAMethodThatSaysNothingAboutTheEnvironmentStaysInTheSandbox(): void
    {
        $credentials = Credentials::fromArray(['merchant_id' => '11', 'crc' => 'c', 'api_key' => 'k']);

        self::assertTrue($credentials->sandbox);
        self::assertSame(Credentials::SANDBOX_URL, $credentials->baseUrl());
    }

    public function testAnUnfilledMethodIsIncompleteRatherThanAnError(): void
    {
        self::assertFalse(Credentials::fromArray([])->isComplete());
        self::assertFalse(Credentials::fromArray(['merchant_id' => 'abc', 'crc' => 'c', 'api_key' => 'k'])->isComplete());
        self::assertFalse(Credentials::fromArray(['merchant_id' => '11', 'crc' => ' ', 'api_key' => 'k'])->isComplete());
        self::assertFalse(Credentials::fromArray(['merchant_id' => ['11'], 'crc' => 'c', 'api_key' => 'k'])->isComplete());
    }
}
