<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Handler;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Core\TransactionStatus;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\PaymentSettlement;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\StatusPaymentRequest;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * The customer is back from the payment page. Przelewy24 is asked how the transaction stands,
 * and money that is there is settled here rather than waiting for the notification — which may be
 * late, or kept out altogether by a firewall.
 *
 * An API error does not stop the customer: they land on the order page, and the notification
 * settles the payment when it comes.
 */
final class StatusHandler
{
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
        private readonly Client $client,
        private readonly PaymentSettlement $settlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $credentials = Przelewy24Gateway::credentials($paymentRequest->getMethod());
        $sessionId = $payment->getDetails()[Przelewy24Gateway::DETAILS_SESSION_ID] ?? null;

        if (PaymentInterface::STATE_COMPLETED !== $payment->getState() && \is_string($sessionId) && $credentials->isComplete()) {
            try {
                $transaction = $this->client->findBySessionId($credentials, $sessionId);
                if (null !== $transaction && $transaction->status->isPaid()) {
                    $this->settlement->settle(
                        $payment,
                        $credentials,
                        $transaction->sessionId,
                        $transaction->orderId,
                        $transaction->amount,
                        $transaction->currency,
                        TransactionStatus::Verified === $transaction->status,
                    );
                }
            } catch (ApiException $e) {
                $this->logger->warning('Przelewy24: the transaction could not be checked on the customer\'s return.', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }
}
