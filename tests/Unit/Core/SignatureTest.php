<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Core;

use Calmfox\SyliusPrzelewy24Plugin\Core\Notification;
use Calmfox\SyliusPrzelewy24Plugin\Core\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The expected hashes are computed from JSON written out by hand, not by the code under test —
 * the field order, the integer types and the unescaped slashes are exactly what a signature
 * depends on, so the test has to spell them out.
 */
final class SignatureTest extends TestCase
{
    public function testRegistrationSignsTheDocumentedFieldsInOrder(): void
    {
        self::assertSame(
            hash('sha384', '{"sessionId":"a1/ż","merchantId":11,"amount":12345,"currency":"PLN","crc":"crc-key"}'),
            Signature::forRegistration('a1/ż', 11, 12345, 'PLN', 'crc-key'),
        );
    }

    public function testVerificationSignsTheDocumentedFieldsInOrder(): void
    {
        self::assertSame(
            hash('sha384', '{"sessionId":"s","orderId":987,"amount":100,"currency":"PLN","crc":"k"}'),
            Signature::forVerification('s', 987, 100, 'PLN', 'k'),
        );
    }

    public function testNotificationSignsTheDocumentedFieldsInOrder(): void
    {
        $notification = new Notification(11, 12, 's', 100, 100, 'PLN', 987, 154, 'p24-A1-B2', 'ignored');

        self::assertSame(
            hash('sha384', '{"merchantId":11,"posId":12,"sessionId":"s","amount":100,"originAmount":100,"currency":"PLN","orderId":987,"methodId":154,"statement":"p24-A1-B2","crc":"k"}'),
            Signature::forNotification($notification, 'k'),
        );
    }

    public function testAStringAmountIsADifferentSignature(): void
    {
        self::assertNotSame(
            Signature::of(['amount' => 100], 'k'),
            Signature::of(['amount' => '100'], 'k'),
        );
    }
}
