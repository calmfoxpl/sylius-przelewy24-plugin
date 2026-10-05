<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest;

use Calmfox\SyliusPrzelewy24Plugin\Core\Notification;
use Calmfox\SyliusPrzelewy24Plugin\Core\SessionId;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Notifications arrive at the address of the payment method (/payment-methods/{code}), and the
 * payment is found through the session ID, which carries the hash of the capture request.
 *
 * The signature is not checked here but in the notify handler, which has the method's keys.
 */
final class NotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return Przelewy24Gateway::supports($paymentMethod);
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        $notification = Notification::fromJson($request->getContent());
        $hash = null !== $notification ? SessionId::captureHashOf($notification->sessionId) : null;
        $capture = null !== $hash ? $this->paymentRequestRepository->find($hash) : null;

        if (!$capture instanceof PaymentRequestInterface || $capture->getMethod()->getCode() !== $paymentMethod->getCode()) {
            throw new NotFoundHttpException('Przelewy24 sent a notification about a transaction this shop does not know.');
        }

        return $capture->getPayment();
    }
}
