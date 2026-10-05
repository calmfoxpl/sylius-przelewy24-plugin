<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest;

use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sylius answers notifications with "204 No Content". Przelewy24 documents a 200 as the sign that
 * a notification was received, so this gateway's notifications get a plain "200 OK"; every other
 * gateway keeps the answer Sylius gives.
 */
final class NotifyResponseProvider implements NotifyResponseProviderInterface
{
    public function __construct(
        private readonly NotifyResponseProviderInterface $decorated,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): Response
    {
        if (Przelewy24Gateway::supports($paymentRequest->getMethod())) {
            return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        return $this->decorated->provide($paymentRequest);
    }
}
