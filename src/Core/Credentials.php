<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Core;

/**
 * The account details of one Przelewy24 shop, as they are kept in the gateway configuration of a
 * payment method (which Sylius encrypts when the application has an encryption key).
 *
 * Where each one is in the Przelewy24 panel (My data → API data and configuration):
 *  - merchant_id — the merchant ID,
 *  - pos_id      — the point-of-sale ID; for a single shop it is usually the merchant ID again,
 *  - crc         — the "CRC key", which signs transactions and notifications,
 *  - api_key     — the "reports key", which is the password for the REST API (the login is pos_id).
 *
 * The sandbox is a separate account with separate keys: production keys do not work in it, and
 * sandbox keys do not work in production.
 */
final class Credentials
{
    public const SANDBOX_URL = 'https://sandbox.przelewy24.pl/';

    public const PRODUCTION_URL = 'https://secure.przelewy24.pl/';

    public function __construct(
        public readonly int $merchantId,
        public readonly int $posId,
        #[\SensitiveParameter]
        public readonly string $crc,
        #[\SensitiveParameter]
        public readonly string $apiKey,
        public readonly bool $sandbox,
    ) {
    }

    /**
     * Reads the gateway configuration leniently: a payment method that has not been filled in yet
     * gives incomplete credentials rather than an exception, and the gateway refuses to use them.
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $merchantId = self::integer($config['merchant_id'] ?? null);
        $posId = self::integer($config['pos_id'] ?? null);

        return new self(
            $merchantId,
            $posId > 0 ? $posId : $merchantId,
            self::text($config['crc'] ?? null),
            self::text($config['api_key'] ?? null),
            // Missing means sandbox: a half-configured method must never talk to production.
            !\array_key_exists('sandbox', $config) || (bool) $config['sandbox'],
        );
    }

    public function isComplete(): bool
    {
        return $this->merchantId > 0 && $this->posId > 0 && '' !== $this->crc && '' !== $this->apiKey;
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    private static function integer(mixed $value): int
    {
        $value = self::text($value);

        return 1 === preg_match('/^\d{1,10}$/', $value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
