<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Account;

use Calmfox\SyliusPrzelewy24Plugin\Account\AccountStore;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Controller\Admin\CheckAction;
use Calmfox\SyliusPrzelewy24Plugin\Entity\Przelewy24Account;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\SyliusTestCase;

final class CheckActionTest extends SyliusTestCase
{
    public function testAcceptedKeysAreReportedForTheirMode(): void
    {
        self::assertSame(['success' => ['calmfox_przelewy24.account.check_ok_sandbox']], $this->check(new MockResponse('{"data":true}')));
    }

    /** Przelewy24 answers wrong keys with 401, which is a refusal, not an outage. */
    public function testRefusedKeysAreToldApartFromAnOutage(): void
    {
        self::assertSame(['error' => ['calmfox_przelewy24.account.check_refused']], $this->check(new MockResponse('{"error":"Incorrect authentication"}', ['http_code' => 401])));
        self::assertSame(['error' => ['calmfox_przelewy24.account.check_unreachable']], $this->check(new MockResponse('Bad gateway', ['http_code' => 502])));
    }

    public function testNothingIsAskedWithoutAWholeAccount(): void
    {
        self::assertSame(['error' => ['calmfox_przelewy24.account.check_incomplete']], $this->check(null, ''));
    }

    public function testAForgedRequestIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->check(new MockResponse('{"data":true}'), 'crc', false);
    }

    /** @return array<string, list<string>> the flash messages */
    private function check(?MockResponse $response, string $crc = 'crc', bool $validToken = true): array
    {
        $account = new Przelewy24Account();
        $account->setMerchantId('11');
        $account->setCrc($crc);
        $account->setApiKey('key');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($account);
        $store = new AccountStore($entityManager, $this->createMock(PaymentMethodRepositoryInterface::class), new NullLogger());

        $client = new Client(new MockHttpClient(static function () use ($response): MockResponse {
            self::assertNotNull($response, 'Przelewy24 was asked although the account is incomplete');

            return $response;
        }));
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($validToken);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/admin/przelewy24');

        $request = new Request(request: ['_token' => 't']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        (new CheckAction($store, $client, $csrf, $urls, new NullLogger()))($request);

        return $session->getFlashBag()->all();
    }
}
