<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Core;

use Calmfox\SyliusPrzelewy24Plugin\Core\SessionId;
use PHPUnit\Framework\TestCase;

final class SessionIdTest extends TestCase
{
    private const HASH = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testCarriesTheCaptureHashAndTheAttempt(): void
    {
        self::assertSame(self::HASH . '_3', SessionId::for(self::HASH, 3));
        self::assertSame(self::HASH, SessionId::captureHashOf(SessionId::for(strtoupper(self::HASH), 3)));
    }

    public function testFitsTheLimitPrzelewy24Sets(): void
    {
        self::assertLessThanOrEqual(100, \strlen(SessionId::for(self::HASH, 999999)));
    }

    public function testDoesNotRecogniseOtherSessionIds(): void
    {
        self::assertNull(SessionId::captureHashOf('65f1c2a7d9b3e'));
        self::assertNull(SessionId::captureHashOf(self::HASH));
        self::assertNull(SessionId::captureHashOf(self::HASH . '_x'));
        self::assertNull(SessionId::captureHashOf(self::HASH . '_1_2'));
    }

    public function testRefusesSomethingThatIsNotAPaymentRequestHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SessionId::for('order-15', 1);
    }
}
