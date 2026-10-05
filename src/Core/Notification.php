<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Core;

/**
 * The notification Przelewy24 POSTs (as JSON) to a transaction's `urlStatus` once the customer
 * has paid.
 *
 * A notification settles nothing by itself. Only `transaction/verify` accepts the money — and a
 * payment that is never verified is returned to the customer by Przelewy24 after a while.
 */
final class Notification
{
    public function __construct(
        public readonly int $merchantId,
        public readonly int $posId,
        public readonly string $sessionId,
        public readonly int $amount,
        public readonly int $originAmount,
        public readonly string $currency,
        public readonly int $orderId,
        public readonly int $methodId,
        public readonly string $statement,
        public readonly string $sign,
    ) {
    }

    /** Null when the body is not a Przelewy24 notification: broken JSON, or a field missing. */
    public static function fromJson(string $body): ?self
    {
        try {
            $data = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        foreach (['merchantId', 'posId', 'amount', 'originAmount', 'orderId', 'methodId'] as $key) {
            if (!\is_int($data[$key] ?? null) && !(\is_string($data[$key] ?? null) && ctype_digit($data[$key]))) {
                return null;
            }
        }
        foreach (['sessionId', 'currency', 'statement', 'sign'] as $key) {
            if (!\is_string($data[$key] ?? null)) {
                return null;
            }
        }

        return new self(
            (int) $data['merchantId'],
            (int) $data['posId'],
            $data['sessionId'],
            (int) $data['amount'],
            (int) $data['originAmount'],
            $data['currency'],
            (int) $data['orderId'],
            (int) $data['methodId'],
            $data['statement'],
            $data['sign'],
        );
    }

    /** True when the notification was signed with this shop's CRC key and is addressed to it. */
    public function isSignedFor(Credentials $credentials): bool
    {
        return $this->merchantId === $credentials->merchantId &&
            $this->posId === $credentials->posId &&
            hash_equals(Signature::forNotification($this, $credentials->crc), $this->sign);
    }
}
