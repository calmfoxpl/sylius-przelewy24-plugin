<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Handler;

use Calmfox\SyliusPrzelewy24Plugin\Core\Notification;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\PaymentSettlement;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\NotifyPaymentRequest;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * A notification from Przelewy24 that the customer has paid.
 *
 * The signature is checked with the CRC key, so a forged notification completes nothing — and a
 * genuine one completes the payment only after `transaction/verify`.
 *
 * A failure to reach the API while verifying is let through as an exception: the response is a
 * 500 and Przelewy24 sends the notification again. A rejected notification (bad signature, wrong
 * amount) ends quietly, because sending it again would not change the answer.
 */
final class NotifyHandler
{
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
        private readonly PaymentSettlement $settlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $credentials = Przelewy24Gateway::credentials($paymentRequest->getMethod());

        $request = $paymentRequest->getPayload()['http_request'] ?? null;
        $body = \is_array($request) && \is_string($request['content'] ?? null) ? $request['content'] : '';
        $notification = Notification::fromJson($body);

        if (null === $notification || !$credentials->isComplete() || !$notification->isSignedFor($credentials)) {
            $this->logger->warning('Przelewy24: a notification was rejected — unreadable, or not signed with this shop\'s CRC key.', [
                'payment' => $payment->getId(),
                'client_ip' => \is_array($request) ? ($request['clientIp'] ?? null) : null,
            ]);
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        $completed = $this->settlement->settle(
            $payment,
            $credentials,
            $notification->sessionId,
            $notification->orderId,
            $notification->amount,
            $notification->currency,
        );

        $payment->setDetails(array_merge($payment->getDetails(), [
            'przelewy24_method_id' => $notification->methodId,
            'przelewy24_statement' => $notification->statement,
        ]));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            $completed ? PaymentRequestTransitions::TRANSITION_COMPLETE : PaymentRequestTransitions::TRANSITION_FAIL,
        );
    }
}
