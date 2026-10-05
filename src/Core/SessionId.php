<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Core;

/**
 * The `sessionId` of a Przelewy24 transaction: the hash of the Sylius capture request it belongs
 * to, and the number of the attempt.
 *
 * Every visit to the payment page registers a new transaction, because a Przelewy24 token
 * expires and a `sessionId` may be used only once. Keeping the capture hash at the front is what
 * lets a notification for any of those attempts — including one paid in a tab the customer left
 * open — find its way back to the right payment.
 */
final class SessionId
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function for(string $captureHash, int $attempt): string
    {
        if (1 !== preg_match(self::UUID, $captureHash) || $attempt < 1) {
            throw new \InvalidArgumentException('A session ID is built from a payment request hash (UUID) and an attempt from 1.');
        }

        return strtolower($captureHash) . '_' . $attempt;
    }

    /** The capture request hash a session ID was built from; null for anything else. */
    public static function captureHashOf(string $sessionId): ?string
    {
        $parts = explode('_', $sessionId);
        if (2 !== \count($parts) || !ctype_digit($parts[1]) || 1 !== preg_match(self::UUID, $parts[0])) {
            return null;
        }

        return strtolower($parts[0]);
    }
}
