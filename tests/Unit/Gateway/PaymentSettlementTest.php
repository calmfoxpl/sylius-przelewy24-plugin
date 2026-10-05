<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Gateway;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Api\Transaction;
use Calmfox\SyliusPrzelewy24Plugin\Core\TransactionStatus;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\PaymentSettlement;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\Shop;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\StateMachine;
use Tests\Calmfox\SyliusPrzelewy24Plugin\Support\SyliusTestCase;

final class PaymentSettlementTest extends SyliusTestCase
{
    private Client&MockObject $client;

    private PaymentSettlement $settlement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(Client::class);
        $this->settlement = new PaymentSettlement($this->client, new StateMachine(), $this->createMock(EntityManagerInterface::class), new NullLogger());
    }

    public function testVerifiesAndCompletesThePayment(): void
    {
        $payment = Shop::payment();
        $this->client->expects(self::once())->method('verify')->with(self::anything(), 's_1', 987, 12345, 'PLN');

        self::assertTrue($this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 12345, 'PLN'));
        self::assertSame('completed', $payment->getState());
        self::assertSame(987, $payment->getDetails()['przelewy24_order_id']);
    }

    public function testMoneyAlreadyVerifiedIsNotVerifiedAgain(): void
    {
        $payment = Shop::payment();
        $this->client->expects(self::never())->method('verify');

        self::assertTrue($this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 12345, 'PLN', true));
        self::assertSame('completed', $payment->getState());
    }

    public function testADifferentAmountLeavesThePaymentOpen(): void
    {
        $payment = Shop::payment();
        $this->client->expects(self::never())->method('verify');

        self::assertFalse($this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 100, 'PLN'));
        self::assertFalse($this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 12345, 'EUR'));
        self::assertSame('new', $payment->getState());
    }

    public function testACompletedPaymentIsLeftAlone(): void
    {
        $payment = Shop::payment(state: 'completed');
        $this->client->expects(self::never())->method('verify');

        self::assertTrue($this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 12345, 'PLN'));
    }

    /** The notification and the return race; the loser's verify fails on a transaction already verified. */
    public function testAVerificationLostToTheOtherSideStillCompletes(): void
    {
        $payment = Shop::payment();
        $this->client->method('verify')->willThrowException(new ApiException('already verified', 400));
        $this->client->method('findBySessionId')->willReturn(new Transaction('s_1', 987, 12345, 'PLN', TransactionStatus::Verified));

        self::assertTrue($this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 12345, 'PLN'));
    }

    public function testAFailedVerificationOfMoneyNotVerifiedIsAnError(): void
    {
        $payment = Shop::payment();
        $this->client->method('verify')->willThrowException(new ApiException('timeout'));
        $this->client->method('findBySessionId')->willReturn(new Transaction('s_1', 987, 12345, 'PLN', TransactionStatus::AwaitingVerification));

        try {
            $this->settlement->settle($payment, Shop::credentials(), 's_1', 987, 12345, 'PLN');
            self::fail('An exception was expected, so that Przelewy24 sends the notification again.');
        } catch (ApiException) {
            self::assertSame('new', $payment->getState());
        }
    }
}
