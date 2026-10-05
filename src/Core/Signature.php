<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Core;

/**
 * Signatures of the Przelewy24 REST API: SHA-384 of a JSON object holding the documented fields in
 * the documented order, with the CRC key last.
 *
 * Order and types both matter — `"amount":100` and `"amount":"100"` give different hashes — so
 * amounts and identifiers go in as integers, and the JSON is written without escaped slashes or
 * escaped non-ASCII characters, exactly as the API computes it on its side.
 */
final class Signature
{
    /** @param array<string, int|string> $fields */
    public static function of(array $fields, #[\SensitiveParameter] string $crc): string
    {
        $fields['crc'] = $crc;

        return hash('sha384', json_encode($fields, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    public static function forRegistration(string $sessionId, int $merchantId, int $amount, string $currency, #[\SensitiveParameter] string $crc): string
    {
        return self::of(['sessionId' => $sessionId, 'merchantId' => $merchantId, 'amount' => $amount, 'currency' => $currency], $crc);
    }

    public static function forVerification(string $sessionId, int $orderId, int $amount, string $currency, #[\SensitiveParameter] string $crc): string
    {
        return self::of(['sessionId' => $sessionId, 'orderId' => $orderId, 'amount' => $amount, 'currency' => $currency], $crc);
    }

    public static function forNotification(Notification $notification, #[\SensitiveParameter] string $crc): string
    {
        return self::of([
            'merchantId' => $notification->merchantId,
            'posId' => $notification->posId,
            'sessionId' => $notification->sessionId,
            'amount' => $notification->amount,
            'originAmount' => $notification->originAmount,
            'currency' => $notification->currency,
            'orderId' => $notification->orderId,
            'methodId' => $notification->methodId,
            'statement' => $notification->statement,
        ], $crc);
    }
}
