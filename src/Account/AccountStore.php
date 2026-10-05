<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Account;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Entity\Przelewy24Account;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;

/**
 * The one account row: read for every payment, written from Calmfox services → Przelewy24.
 *
 * Reads are wrapped because they sit on the path of every payment. A migration that has not been
 * run yet must not turn into a checkout that fails: with no readable row the methods fall back to
 * the keys stored with them, exactly as before the account page existed.
 */
final class AccountStore implements SavedAccount
{
    /** The fields of the account, as they are named in the gateway configuration too. */
    public const FIELDS = ['sandbox', 'merchant_id', 'pos_id', 'crc', 'api_key'];

    private bool $loaded = false;

    private ?Przelewy24Account $account = null;

    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly LoggerInterface $logger,
        private readonly ?EncrypterInterface $encrypter = null,
    ) {
    }

    public function credentials(): ?Credentials
    {
        $account = $this->account();

        return null !== $account ? Credentials::fromArray($this->toArray($account)) : null;
    }

    public function isSaved(): bool
    {
        return null !== $this->account();
    }

    /**
     * The Przelewy24 methods that still carry keys of their own, which is what an installation
     * upgraded from 1.0 has until the account is saved here.
     *
     * @return list<PaymentMethodInterface>
     */
    public function methodsWithOwnKeys(): array
    {
        return array_values(array_filter(
            $this->methods(),
            static fn (PaymentMethodInterface $method): bool => Przelewy24Gateway::credentials($method)->isComplete(),
        ));
    }

    /** @return list<PaymentMethodInterface> */
    public function methods(): array
    {
        try {
            return array_values(array_filter(
                $this->paymentMethodRepository->findAll(),
                static fn (PaymentMethodInterface $method): bool => Przelewy24Gateway::supports($method),
            ));
        } catch (\Throwable $error) {
            $this->logger->warning('Przelewy24: the payment methods could not be read.', ['exception' => $error]);

            return [];
        }
    }

    /**
     * What the form starts from: the saved account, or else the keys of the first method that has
     * its own, so that moving them here is one click on "Save". The keys go into the form data but
     * never into the page, because the key fields do not render their value.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $account = $this->account();
        if (null !== $account) {
            return $this->toArray($account);
        }

        $method = $this->methodsWithOwnKeys()[0] ?? null;
        $config = $method?->getGatewayConfig()?->getConfig() ?? [];
        $credentials = Credentials::fromArray($config);

        return null === $method ? ['sandbox' => true] : [
            'sandbox' => $credentials->sandbox,
            'merchant_id' => (string) $credentials->merchantId,
            'pos_id' => $credentials->posId !== $credentials->merchantId ? (string) $credentials->posId : '',
            'crc' => $credentials->crc,
            'api_key' => $credentials->apiKey,
        ];
    }

    /**
     * Saves the account and takes the keys out of the methods that carried their own.
     *
     * Leaving them there would keep a second copy of the shop's payment keys in a place nobody
     * looks at any more, to be read by whoever gets a database dump. The methods keep their name,
     * channels and everything else; only the account fields go.
     *
     * @param array<array-key, mixed> $data
     */
    public function save(array $data): void
    {
        $account = $this->account() ?? new Przelewy24Account();
        $account->setSandbox((bool) ($data['sandbox'] ?? true));
        $account->setMerchantId(self::text($data['merchant_id'] ?? null));
        $account->setPosId(self::text($data['pos_id'] ?? null));
        $account->setCrc($this->encrypt(self::text($data['crc'] ?? null)));
        $account->setApiKey($this->encrypt(self::text($data['api_key'] ?? null)));
        $account->touch();

        foreach ($this->methods() as $method) {
            $gatewayConfig = $method->getGatewayConfig();
            if (null === $gatewayConfig) {
                continue;
            }
            $config = $gatewayConfig->getConfig();
            if ([] !== array_intersect_key($config, array_flip(self::FIELDS))) {
                $gatewayConfig->setConfig(array_diff_key($config, array_flip(self::FIELDS)));
            }
        }

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->account = $account;
        $this->loaded = true;
    }

    private function account(): ?Przelewy24Account
    {
        if ($this->loaded) {
            return $this->account;
        }

        try {
            $this->account = $this->entityManager->find(Przelewy24Account::class, Przelewy24Account::ID);
        } catch (\Throwable $error) {
            $this->logger->warning('Przelewy24: the account could not be read; payment methods use the keys stored with them.', ['exception' => $error]);
            $this->account = null;
        }
        $this->loaded = true;

        return $this->account;
    }

    /** @return array<string, mixed> */
    private function toArray(Przelewy24Account $account): array
    {
        return [
            'sandbox' => $account->isSandbox(),
            'merchant_id' => $account->getMerchantId(),
            'pos_id' => $account->getPosId(),
            'crc' => $this->decrypt($account->getCrc()),
            'api_key' => $this->decrypt($account->getApiKey()),
        ];
    }

    /**
     * With Sylius's payment encryption key, as the gateway configuration was. Where Sylius cannot
     * encrypt (no key has been generated), the key is kept as it is rather than lost, which is
     * also what Sylius does with a gateway configuration it is told not to encrypt.
     */
    private function encrypt(string $plain): string
    {
        if ('' === $plain || null === $this->encrypter) {
            return $plain;
        }

        try {
            return $this->encrypter->encrypt($plain);
        } catch (\Throwable $error) {
            $this->logger->warning('Przelewy24: the account keys are stored unencrypted, because Sylius could not encrypt them.', ['exception' => $error]);

            return $plain;
        }
    }

    /**
     * A key that cannot be decrypted (the encryption key was replaced) reads as missing: the
     * gateway then refuses to take payments with half an account, and the page asks for it again.
     */
    private function decrypt(string $stored): string
    {
        if ('' === $stored || null === $this->encrypter) {
            return $stored;
        }

        try {
            return $this->encrypter->decrypt($stored);
        } catch (\Throwable $error) {
            $this->logger->error('Przelewy24: a saved account key could not be decrypted.', ['exception' => $error]);

            return '';
        }
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
