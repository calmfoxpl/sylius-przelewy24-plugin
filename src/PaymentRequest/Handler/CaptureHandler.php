<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Handler;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Core\SessionId;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\CapturePaymentRequest;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers a transaction with Przelewy24 and keeps the payment page address in the request.
 *
 * The request stays "processing": money arrives later, through the notification or the return,
 * and each further visit to the payment page registers a fresh transaction under the same
 * request (a token expires, and a session ID may be used once).
 */
final class CaptureHandler
{
    /** The languages of the Przelewy24 payment page; anything else gets Polish. */
    private const LANGUAGES = ['bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu', 'it', 'nl', 'pl', 'pt', 'se', 'sk', 'ro', 'uk'];

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
        private readonly Client $client,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $method = $paymentRequest->getMethod();

        if (PaymentInterface::STATE_COMPLETED === $payment->getState()) {
            $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);

            return;
        }

        $credentials = Przelewy24Gateway::credentials($method);
        if (!$credentials->isComplete()) {
            $this->logger->error('Przelewy24: the payment method is missing its merchant ID, CRC key or reports key.', ['method' => $method->getCode()]);
            $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        /** @var OrderInterface $order */
        $order = $payment->getOrder();
        $attempt = (int) ($paymentRequest->getResponseData()['attempt'] ?? 0) + 1;
        $sessionId = SessionId::for((string) $paymentRequest->getId(), $attempt);

        try {
            $token = $this->client->register($credentials, $this->transaction($paymentRequest, $order, $payment, $sessionId));
        } catch (ApiException $e) {
            $this->logger->error('Przelewy24: the transaction could not be registered.', [
                'order' => $order->getNumber(),
                'error' => $e->getMessage(),
            ]);
            $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        $payment->setDetails(array_merge($payment->getDetails(), [
            Przelewy24Gateway::DETAILS_SESSION_ID => $sessionId,
            'przelewy24_status' => 'registered',
            'przelewy24_sandbox' => $credentials->sandbox,
        ]));

        $paymentRequest->setResponseData([
            'attempt' => $attempt,
            'session_id' => $sessionId,
            'redirect_url' => $this->client->paymentUrl($credentials, $token),
        ]);

        $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_PROCESS);
    }

    /** @return array<string, mixed> */
    private function transaction(PaymentRequestInterface $paymentRequest, OrderInterface $order, PaymentInterface $payment, string $sessionId): array
    {
        $billing = $order->getBillingAddress();
        $locale = (string) $order->getLocaleCode();
        $language = strtolower(substr($locale, 0, 2));

        return array_filter([
            'sessionId' => $sessionId,
            'amount' => (int) $payment->getAmount(),
            'currency' => (string) $payment->getCurrencyCode(),
            // Shown on the payment page and in the customer's bank history.
            'description' => $this->translator->trans('calmfox_przelewy24.transaction.description', [
                '%number%' => (string) $order->getNumber(),
            ], 'messages', $locale),
            'email' => (string) $order->getCustomer()?->getEmail(),
            'client' => null !== $billing ? trim((string) $billing->getFullName()) : null,
            'country' => $billing?->getCountryCode() ?? 'PL',
            'language' => \in_array($language, self::LANGUAGES, true) ? $language : 'pl',
            'urlReturn' => $this->urlGenerator->generate('sylius_shop_order_after_pay', [
                '_locale' => $locale,
                'hash' => $paymentRequest->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
            'urlStatus' => $this->urlGenerator->generate('sylius_payment_method_notify', [
                'code' => $paymentRequest->getMethod()->getCode(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
            // Przelewy24 waits for the bank's answer before sending the customer back, so the
            // return usually finds the money already there.
            'waitForResult' => true,
            'encoding' => 'UTF-8',
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    private function transition(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }
}
