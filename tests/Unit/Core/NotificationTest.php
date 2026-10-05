<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Core;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Core\Notification;
use Calmfox\SyliusPrzelewy24Plugin\Core\Signature;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testReadsTheNotificationPrzelewy24Sends(): void
    {
        $notification = Notification::fromJson(self::body());

        self::assertNotNull($notification);
        self::assertSame('s_1', $notification->sessionId);
        self::assertSame(12345, $notification->amount);
        self::assertSame(987654, $notification->orderId);
        self::assertSame('p24-A1-B2', $notification->statement);
    }

    public function testAcceptsNumbersSentAsStrings(): void
    {
        self::assertSame(12345, Notification::fromJson(self::body(['amount' => '12345']))?->amount);
    }

    public function testRefusesWhatIsNotANotification(): void
    {
        self::assertNull(Notification::fromJson('not json'));
        self::assertNull(Notification::fromJson('"a string"'));
        self::assertNull(Notification::fromJson(self::body(['orderId' => null])));
        self::assertNull(Notification::fromJson(self::body(['amount' => '12.50'])));
        self::assertNull(Notification::fromJson(self::body(['sign' => 5])));
    }

    public function testIsSignedForTheShopWhoseCrcKeySignedIt(): void
    {
        $credentials = new Credentials(11, 11, 'crc-key', 'api-key', true);

        self::assertTrue(Notification::fromJson(self::signed($credentials))?->isSignedFor($credentials));
    }

    public function testIsNotSignedForAnotherKeyOrAnotherMerchant(): void
    {
        $body = self::signed(new Credentials(11, 11, 'crc-key', 'api-key', true));

        self::assertFalse(Notification::fromJson($body)?->isSignedFor(new Credentials(11, 11, 'other-key', 'api-key', true)));
        self::assertFalse(Notification::fromJson($body)?->isSignedFor(new Credentials(12, 12, 'crc-key', 'api-key', true)));
    }

    public function testATamperedAmountBreaksTheSignature(): void
    {
        $credentials = new Credentials(11, 11, 'crc-key', 'api-key', true);
        $data = json_decode(self::signed($credentials), true);
        $data['amount'] = 1;

        self::assertFalse(Notification::fromJson((string) json_encode($data))?->isSignedFor($credentials));
    }

    /** @param array<string, mixed> $override */
    private static function body(array $override = []): string
    {
        return (string) json_encode($override + [
            'merchantId' => 11,
            'posId' => 11,
            'sessionId' => 's_1',
            'amount' => 12345,
            'originAmount' => 12345,
            'currency' => 'PLN',
            'orderId' => 987654,
            'methodId' => 154,
            'statement' => 'p24-A1-B2',
            'sign' => 'x',
        ]);
    }

    private static function signed(Credentials $credentials): string
    {
        $unsigned = Notification::fromJson(self::body());
        self::assertNotNull($unsigned);

        return self::body(['sign' => Signature::forNotification($unsigned, $credentials->crc)]);
    }
}
