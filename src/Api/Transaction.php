<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Api;

use Calmfox\SyliusPrzelewy24Plugin\Core\TransactionStatus;

/** What `transaction/by/sessionId` says about a transaction — the part the gateway uses. */
final class Transaction
{
    public function __construct(
        public readonly string $sessionId,
        public readonly int $orderId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly TransactionStatus $status,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = TransactionStatus::tryFrom((int) ($data['status'] ?? 0));
        if (null === $status || !isset($data['sessionId'], $data['orderId'], $data['amount'], $data['currency'])) {
            throw new ApiException('Przelewy24 described a transaction without the expected fields.');
        }

        return new self(
            (string) $data['sessionId'],
            (int) $data['orderId'],
            (int) $data['amount'],
            (string) $data['currency'],
            $status,
        );
    }
}
