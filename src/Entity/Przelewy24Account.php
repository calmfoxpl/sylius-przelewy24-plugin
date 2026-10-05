<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The shop's Przelewy24 account, as entered in Calmfox services → Przelewy24. One row, always.
 *
 * The account is set up once for the shop, not once per payment method, so it is kept apart from
 * the methods: a method keeps its name, channels and position where Sylius has them, and every
 * Przelewy24 method pays into this account.
 *
 * The two keys are stored encrypted with Sylius's payment encryption key, the same one that
 * protected them in the gateway configuration before they moved here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calmfox_przelewy24_account')]
class Przelewy24Account
{
    /** There is one account per shop, so there is one row, and it always has this id. */
    public const ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID;

    #[ORM\Column(type: 'boolean')]
    private bool $sandbox = true;

    #[ORM\Column(name: 'merchant_id', type: 'string', length: 10)]
    private string $merchantId = '';

    #[ORM\Column(name: 'pos_id', type: 'string', length: 10)]
    private string $posId = '';

    /** Ciphertext, or plain text where Sylius has no encryption key. Never logged. */
    #[ORM\Column(type: 'text')]
    private string $crc = '';

    /** Ciphertext, or plain text where Sylius has no encryption key. Never logged. */
    #[ORM\Column(name: 'api_key', type: 'text')]
    private string $apiKey = '';

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function setSandbox(bool $sandbox): void
    {
        $this->sandbox = $sandbox;
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function setMerchantId(string $merchantId): void
    {
        $this->merchantId = $merchantId;
    }

    public function getPosId(): string
    {
        return $this->posId;
    }

    public function setPosId(string $posId): void
    {
        $this->posId = $posId;
    }

    public function getCrc(): string
    {
        return $this->crc;
    }

    public function setCrc(string $crc): void
    {
        $this->crc = $crc;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
