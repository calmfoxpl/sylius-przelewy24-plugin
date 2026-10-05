<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Gateway;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Api\Transaction;
use Calmfox\SyliusPrzelewy24Plugin\Core\TransactionStatus;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\PaymentSettlement;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\CapturePaymentRequest;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\StatusPaymentRequest;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\CommandProvider;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Handler\CaptureHandler;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Handler\StatusHandler;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\HttpResponseProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\Shop;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\StateMachine;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\SyliusTestCase;

final class CaptureAndReturnTest extends SyliusTestCase
{
    private Client&MockObject $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(Client::class);
        $this->client->method('paymentUrl')->willReturnCallback(static fn ($credentials, string $token): string => 'https://sandbox.przelewy24.pl/trnRequest/' . $token);
    }

    public function testRegistersTheTransactionAndSendsTheCustomerToPrzelewy24(): void
    {
        $payment = Shop::payment();
        $capture = Shop::request($payment, PaymentRequestInterface::ACTION_CAPTURE);
        $this->client->expects(self::once())->method('register')->willReturnCallback(function ($credentials, array $transaction): string {
            self::assertSame(Shop::HASH . '_1', $transaction['sessionId']);
            self::assertSame(12345, $transaction['amount']);
            self::assertSame('PLN', $transaction['currency']);
            self::assertSame('jan@example.com', $transaction['email']);
            self::assertSame('Jan Kowalski', $transaction['client']);
            self::assertSame('pl', $transaction['language']);
            self::assertSame('Zamówienie nr 000123', $transaction['description']);
            self::assertSame('https://shop.test/pl_PL/order/after-pay/' . Shop::HASH, $transaction['urlReturn']);
            self::assertSame('https://shop.test/payment-methods/przelewy24', $transaction['urlStatus']);
            self::assertTrue($transaction['waitForResult']);

            return 'TOKEN-1';
        });

        $this->capture($capture);

        self::assertSame('processing', $capture->getState());
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/TOKEN-1', $capture->getResponseData()['redirect_url']);
        self::assertSame(Shop::HASH . '_1', $payment->getDetails()[Przelewy24Gateway::DETAILS_SESSION_ID]);

        $response = (new HttpResponseProvider($this->urls()))->getResponse($this->configuration(), $capture);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/TOKEN-1', $response->getTargetUrl());
    }

    /** A token expires and a session ID is single-use, so every visit registers anew. */
    public function testEveryVisitToThePaymentPageIsANewTransaction(): void
    {
        $capture = Shop::request(Shop::payment(), PaymentRequestInterface::ACTION_CAPTURE);
        $this->client->method('register')->willReturn('TOKEN');

        $this->capture($capture);
        $this->capture($capture);

        self::assertSame(2, $capture->getResponseData()['attempt']);
        self::assertSame(Shop::HASH . '_2', $capture->getResponseData()['session_id']);
    }

    public function testAMethodWithoutKeysFailsWithoutCallingPrzelewy24(): void
    {
        $capture = Shop::request(Shop::payment(['sandbox' => true, 'merchant_id' => '11']), PaymentRequestInterface::ACTION_CAPTURE);
        $this->client->expects(self::never())->method('register');

        $this->capture($capture);

        self::assertSame('failed', $capture->getState());
    }

    public function testARefusedRegistrationSendsTheCustomerBackToTheOrderWithAMessage(): void
    {
        $capture = Shop::request(Shop::payment(), PaymentRequestInterface::ACTION_CAPTURE);
        $this->client->method('register')->willThrowException(new ApiException('Incorrect authentication', 401));

        $this->capture($capture);
        $configuration = $this->configuration();
        $response = (new HttpResponseProvider($this->urls()))->getResponse($configuration, $capture);

        self::assertSame('failed', $capture->getState());
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/pl_PL/order/order-token', $response->getTargetUrl());
        $session = $configuration->getRequest()->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(['calmfox_przelewy24.flash.unavailable'], $session->getFlashBag()->get('error'));
    }

    public function testTheReturnSettlesMoneyThatIsAlreadyThere(): void
    {
        $payment = Shop::payment();
        $payment->setDetails([Przelewy24Gateway::DETAILS_SESSION_ID => Shop::HASH . '_1']);
        $status = Shop::request($payment, PaymentRequestInterface::ACTION_STATUS, '0199a1b2-c3d4-7e5f-8a9b-000000000003');
        $this->client->method('findBySessionId')->willReturn(new Transaction(Shop::HASH . '_1', 987, 12345, 'PLN', TransactionStatus::AwaitingVerification));
        $this->client->expects(self::once())->method('verify');

        $this->returnFromPrzelewy24($status);

        self::assertSame('completed', $payment->getState());
        self::assertSame('completed', $status->getState());
    }

    public function testTheReturnWithoutMoneyLeavesThePaymentToBePaid(): void
    {
        $payment = Shop::payment();
        $payment->setDetails([Przelewy24Gateway::DETAILS_SESSION_ID => Shop::HASH . '_1']);
        $status = Shop::request($payment, PaymentRequestInterface::ACTION_STATUS);
        $this->client->method('findBySessionId')->willReturn(new Transaction(Shop::HASH . '_1', 987, 12345, 'PLN', TransactionStatus::NoPayment));
        $this->client->expects(self::never())->method('verify');

        $this->returnFromPrzelewy24($status);

        self::assertSame('new', $payment->getState());
        self::assertSame('completed', $status->getState());
    }

    public function testAnApiErrorOnTheReturnDoesNotStopTheCustomer(): void
    {
        $payment = Shop::payment();
        $payment->setDetails([Przelewy24Gateway::DETAILS_SESSION_ID => Shop::HASH . '_1']);
        $status = Shop::request($payment, PaymentRequestInterface::ACTION_STATUS);
        $this->client->method('findBySessionId')->willThrowException(new ApiException('timeout'));

        $this->returnFromPrzelewy24($status);

        self::assertSame('completed', $status->getState());
    }

    public function testTheCommandProviderCoversTheThreeActions(): void
    {
        $provider = new CommandProvider();
        $payment = Shop::payment();

        self::assertInstanceOf(CapturePaymentRequest::class, $provider->provide(Shop::request($payment, PaymentRequestInterface::ACTION_CAPTURE)));
        self::assertInstanceOf(StatusPaymentRequest::class, $provider->provide(Shop::request($payment, PaymentRequestInterface::ACTION_STATUS)));
        self::assertTrue($provider->supports(Shop::request($payment, PaymentRequestInterface::ACTION_NOTIFY)));
        self::assertFalse($provider->supports(Shop::request($payment, PaymentRequestInterface::ACTION_REFUND)));
    }

    private function capture(PaymentRequestInterface $request): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters): string => 'Zamówienie nr ' . $parameters['%number%']);

        (new CaptureHandler($this->provider($request), new StateMachine(), $this->client, $this->urls(), $translator, new NullLogger()))(
            new CapturePaymentRequest($request->getId()),
        );
    }

    private function returnFromPrzelewy24(PaymentRequestInterface $request): void
    {
        $stateMachine = new StateMachine();
        $settlement = new PaymentSettlement($this->client, $stateMachine, $this->createMock(EntityManagerInterface::class), new NullLogger());

        (new StatusHandler($this->provider($request), $stateMachine, $this->client, $settlement, new NullLogger()))(
            new StatusPaymentRequest($request->getId()),
        );
    }

    private function provider(PaymentRequestInterface $request): PaymentRequestProviderInterface
    {
        $provider = $this->createMock(PaymentRequestProviderInterface::class);
        $provider->method('provide')->willReturn($request);

        return $provider;
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $route, array $parameters, int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string {
            $path = match ($route) {
                'sylius_shop_order_after_pay' => sprintf('/%s/order/after-pay/%s', $parameters['_locale'], $parameters['hash']),
                'sylius_payment_method_notify' => '/payment-methods/' . $parameters['code'],
                'sylius_shop_order_show' => sprintf('/%s/order/%s', $parameters['_locale'], $parameters['tokenValue']),
                default => throw new \LogicException('Unexpected route ' . $route),
            };

            return UrlGeneratorInterface::ABSOLUTE_URL === $type ? 'https://shop.test' . $path : $path;
        });

        return $urls;
    }

    private function configuration(): RequestConfiguration
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $configuration = $this->createMock(RequestConfiguration::class);
        $configuration->method('getRequest')->willReturn($request);

        return $configuration;
    }
}
