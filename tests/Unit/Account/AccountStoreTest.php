<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Account;

use Calmfox\SyliusPrzelewy24Plugin\Account\AccountStore;
use Calmfox\SyliusPrzelewy24Plugin\Entity\Przelewy24Account;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\Shop;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\SyliusTestCase;

final class AccountStoreTest extends SyliusTestCase
{
    public function testNothingSavedMeansNoAccount(): void
    {
        $store = $this->store(null, []);

        self::assertNull($store->credentials());
        self::assertFalse($store->isSaved());
        self::assertSame(['sandbox' => true], $store->formData());
    }

    /** A missing table (migration not run) must not stop checkout: the methods keep their keys. */
    public function testAnUnreadableTableReadsAsNoAccount(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willThrowException(new \RuntimeException('Table not found'));

        self::assertNull($this->store(null, [], $entityManager)->credentials());
    }

    /** The first visit to the page offers the keys a method of a 1.0 installation still carries. */
    public function testTheFormStartsFromTheKeysAMethodCarries(): void
    {
        $method = self::method(['sandbox' => false, 'merchant_id' => '11', 'pos_id' => '', 'crc' => 'crc-key', 'api_key' => 'api-key']);
        $store = $this->store(null, [$method]);

        self::assertSame([$method], $store->methodsWithOwnKeys());
        self::assertSame(
            ['sandbox' => false, 'merchant_id' => '11', 'pos_id' => '', 'crc' => 'crc-key', 'api_key' => 'api-key'],
            $store->formData(),
        );
    }

    public function testSavingEncryptsTheKeysAndTakesThemOutOfTheMethods(): void
    {
        $method = self::method(['factory' => 'przelewy24', 'sandbox' => true, 'merchant_id' => '11', 'crc' => 'old', 'api_key' => 'old']);
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });
        $entityManager->expects(self::once())->method('flush');

        $store = $this->store(null, [$method], $entityManager);
        $store->save(['sandbox' => false, 'merchant_id' => ' 22 ', 'pos_id' => '', 'crc' => 'new-crc', 'api_key' => 'new-key']);

        self::assertInstanceOf(Przelewy24Account::class, $persisted);
        self::assertSame('22', $persisted->getMerchantId());
        self::assertSame('enc(new-crc)', $persisted->getCrc());
        self::assertSame('enc(new-key)', $persisted->getApiKey());
        self::assertSame(['factory' => 'przelewy24'], $method->getGatewayConfig()?->getConfig());

        $credentials = $store->credentials();
        self::assertNotNull($credentials);
        self::assertSame('new-crc', $credentials->crc);
        self::assertSame(22, $credentials->posId);
        self::assertFalse($credentials->sandbox);
    }

    /** A key that the current encryption key cannot open reads as missing, never as garbage. */
    public function testAKeyThatCannotBeDecryptedReadsAsMissing(): void
    {
        $account = new Przelewy24Account();
        $account->setMerchantId('22');
        $account->setCrc('enc(crc)');
        $account->setApiKey('unreadable');

        $credentials = $this->store($account, [])->credentials();

        self::assertNotNull($credentials);
        self::assertSame('crc', $credentials->crc);
        self::assertSame('', $credentials->apiKey);
        self::assertFalse($credentials->isComplete());
    }

    /** @param list<PaymentMethod> $methods */
    private function store(?Przelewy24Account $account, array $methods, ?EntityManagerInterface $entityManager = null): AccountStore
    {
        if (null === $entityManager) {
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->method('find')->willReturn($account);
        }
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findAll')->willReturn($methods);

        return new AccountStore($entityManager, $repository, new NullLogger(), new class() implements EncrypterInterface {
            public function encrypt(string $data): string
            {
                return 'enc(' . $data . ')';
            }

            public function decrypt(string $data): string
            {
                if (1 !== preg_match('/^enc\((.*)\)$/', $data, $m)) {
                    throw new \RuntimeException('Cannot decrypt.');
                }

                return $m[1];
            }
        });
    }

    /** @param array<string, mixed> $config */
    private static function method(array $config): PaymentMethod
    {
        $method = Shop::payment($config)->getMethod();
        \assert($method instanceof PaymentMethod);

        return $method;
    }
}
