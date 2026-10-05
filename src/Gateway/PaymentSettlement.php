<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Gateway;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Core\TransactionStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Completes a payment once Przelewy24 has the money: verifies the transaction with the API and
 * moves the payment to "completed".
 *
 * Both the notification and the customer's return call it, usually within the same second;
 * whichever comes first settles the payment and the other finds it settled.
 */
class PaymentSettlement
{
    public function __construct(
        private readonly Client $client,
        private readonly StateMachineInterface $stateMachine,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $verified true when Przelewy24 already reports the money as verified, so it is
     *                       not verified a second time
     *
     * @return bool whether the payment is completed
     */
    public function settle(
        PaymentInterface $payment,
        Credentials $credentials,
        string $sessionId,
        int $orderId,
        int $amount,
        string $currency,
        bool $verified = false,
    ): bool {
        // Lock the payment row, so that the notification and the return cannot both complete it
        // and send the confirmation twice. The handlers run inside a transaction: the payment
        // request bus has Doctrine's transaction middleware.
        if (null !== $payment->getId() && $this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
        }

        if (PaymentInterface::STATE_COMPLETED === $payment->getState()) {
            return true;
        }

        if ($amount !== $payment->getAmount() || $currency !== $payment->getCurrencyCode()) {
            $this->logger->error('Przelewy24: the amount paid does not match the payment, which stays open.', [
                'payment' => $payment->getId(),
                'session_id' => $sessionId,
                'paid' => sprintf('%d %s', $amount, $currency),
                'expected' => sprintf('%d %s', (int) $payment->getAmount(), (string) $payment->getCurrencyCode()),
            ]);

            return false;
        }

        if (!$verified) {
            try {
                $this->client->verify($credentials, $sessionId, $orderId, $amount, $currency);
            } catch (ApiException $e) {
                // The other side — notification or return — may have verified a moment earlier.
                if (TransactionStatus::Verified !== $this->client->findBySessionId($credentials, $sessionId)?->status) {
                    throw $e;
                }
            }
        }

        $payment->setDetails(array_merge($payment->getDetails(), [
            Przelewy24Gateway::DETAILS_SESSION_ID => $sessionId,
            'przelewy24_order_id' => $orderId,
            'przelewy24_status' => 'verified',
        ]));

        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        }

        $this->logger->info('Przelewy24: payment completed.', ['payment' => $payment->getId(), 'przelewy24_order_id' => $orderId]);

        return PaymentInterface::STATE_COMPLETED === $payment->getState();
    }
}
