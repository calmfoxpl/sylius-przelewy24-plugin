<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Gateway;

use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\PaymentSettlement;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\NotifyPaymentRequest;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Handler\NotifyHandler;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\NotifyPaymentProvider;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\NotifyResponseProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\Shop;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\StateMachine;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\SyliusTestCase;

final class NotifyTest extends SyliusTestCase
{
    public function testFindsThePaymentThroughTheSessionId(): void
    {
        $payment = Shop::payment();
        $capture = Shop::request($payment, PaymentRequestInterface::ACTION_CAPTURE);
        $repository = $this->createMock(PaymentRequestRepositoryInterface::class);
        $repository->method('find')->with(Shop::HASH)->willReturn($capture);
        $provider = new NotifyPaymentProvider($repository);

        self::assertTrue($provider->supports(new Request(), $payment->getMethod()));
        self::assertSame($payment, $provider->getPayment(new Request(content: Shop::notification()), $payment->getMethod()));
    }

    public function testANotificationAboutAnUnknownTransactionIsNotFound(): void
    {
        $payment = Shop::payment();
        $provider = new NotifyPaymentProvider($this->createMock(PaymentRequestRepositoryInterface::class));

        $this->expectException(NotFoundHttpException::class);
        $provider->getPayment(new Request(content: Shop::notification(['sessionId' => 'elsewhere_1'])), $payment->getMethod());
    }

    public function testASignedNotificationCompletesThePayment(): void
    {
        $payment = Shop::payment();
        $notify = Shop::withPayload(Shop::request($payment, PaymentRequestInterface::ACTION_NOTIFY, '0199a1b2-c3d4-7e5f-8a9b-000000000002'), Shop::notification());
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('verify')->with(self::anything(), Shop::HASH . '_1', 987654, 12345, 'PLN');

        $this->handler($notify, $client)(new NotifyPaymentRequest($notify->getId()));

        self::assertSame('completed', $payment->getState());
        self::assertSame('completed', $notify->getState());
        self::assertSame('p24-A1-B2', $payment->getDetails()['przelewy24_statement']);
    }

    public function testANotificationSignedWithAnotherKeyCompletesNothing(): void
    {
        $payment = Shop::payment();
        $notify = Shop::withPayload(Shop::request($payment, PaymentRequestInterface::ACTION_NOTIFY), Shop::notification(crc: 'forged'));
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('verify');

        $this->handler($notify, $client)(new NotifyPaymentRequest($notify->getId()));

        self::assertSame('new', $payment->getState());
        self::assertSame('failed', $notify->getState());
    }

    public function testPrzelewy24IsAnsweredWithA200(): void
    {
        $payment = Shop::payment();
        $inner = $this->createMock(NotifyResponseProviderInterface::class);
        $inner->expects(self::never())->method('provide');

        $response = (new NotifyResponseProvider($inner))->provide(Shop::request($payment, PaymentRequestInterface::ACTION_NOTIFY));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testOtherGatewaysKeepTheirAnswer(): void
    {
        $payment = Shop::payment();
        $payment->getMethod()?->getGatewayConfig()?->setFactoryName('offline');
        $inner = $this->createMock(NotifyResponseProviderInterface::class);
        $inner->method('provide')->willReturn(new Response('', 204));

        self::assertSame(204, (new NotifyResponseProvider($inner))->provide(Shop::request($payment, PaymentRequestInterface::ACTION_NOTIFY))->getStatusCode());
    }

    private function handler(PaymentRequestInterface $request, Client $client): NotifyHandler
    {
        $provider = $this->createMock(PaymentRequestProviderInterface::class);
        $provider->method('provide')->willReturn($request);
        $stateMachine = new StateMachine();

        return new NotifyHandler(
            $provider,
            $stateMachine,
            new PaymentSettlement($client, $stateMachine, $this->createMock(EntityManagerInterface::class), new NullLogger()),
            new NullLogger(),
            Shop::resolver(),
        );
    }
}
