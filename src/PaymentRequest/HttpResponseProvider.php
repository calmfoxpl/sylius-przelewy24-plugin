<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest;

use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the customer to the Przelewy24 payment page once the transaction is registered. When
 * registering failed, the customer goes back to their order with a message, and can try again
 * from there or choose another way to pay.
 *
 * `status` requests (the return from Przelewy24) are left to Sylius, which sends the customer to
 * the thank-you page when the payment is completed and to the order page when it is not.
 */
final class HttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(RequestConfiguration $requestConfiguration, PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_CAPTURE === $paymentRequest->getAction() &&
            PaymentRequestInterface::STATE_COMPLETED !== $paymentRequest->getState();
    }

    public function getResponse(RequestConfiguration $requestConfiguration, PaymentRequestInterface $paymentRequest): Response
    {
        $redirectUrl = $paymentRequest->getResponseData()['redirect_url'] ?? null;
        if (PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState() && \is_string($redirectUrl)) {
            return new RedirectResponse($redirectUrl);
        }

        $request = $requestConfiguration->getRequest();
        if ($request->hasSession() && ($session = $request->getSession()) instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'calmfox_przelewy24.flash.unavailable');
        }

        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        return new RedirectResponse($this->urlGenerator->generate('sylius_shop_order_show', [
            '_locale' => $order->getLocaleCode(),
            'tokenValue' => $order->getTokenValue(),
        ]));
    }
}
