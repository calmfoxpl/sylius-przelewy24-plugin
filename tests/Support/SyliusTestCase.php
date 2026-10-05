<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Support;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\PaymentRequest;

/** Tests of the parts that live inside Sylius; they skip themselves when Sylius is not installed. */
abstract class SyliusTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(PaymentRequest::class)) {
            self::markTestSkipped('Sylius is not installed (composer install, or AUTOLOAD=<shop>/vendor/autoload.php).');
        }
    }
}
