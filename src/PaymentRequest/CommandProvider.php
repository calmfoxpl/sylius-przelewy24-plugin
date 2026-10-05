<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\PaymentRequest;

use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\CapturePaymentRequest;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\NotifyPaymentRequest;
use Calmfox\SyliusPrzelewy24Plugin\PaymentRequest\Command\StatusPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Bundle\PaymentBundle\Exception\PaymentRequestNotSupportedException;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/** Turns a Sylius payment request into the command one of the handlers in Handler/ takes. */
final class CommandProvider implements PaymentRequestCommandProviderInterface
{
    private const COMMANDS = [
        PaymentRequestInterface::ACTION_CAPTURE => CapturePaymentRequest::class,
        PaymentRequestInterface::ACTION_STATUS => StatusPaymentRequest::class,
        PaymentRequestInterface::ACTION_NOTIFY => NotifyPaymentRequest::class,
    ];

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return isset(self::COMMANDS[$paymentRequest->getAction()]);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $command = self::COMMANDS[$paymentRequest->getAction()] ?? throw new PaymentRequestNotSupportedException(
            sprintf('The Przelewy24 gateway does not handle the "%s" action.', $paymentRequest->getAction()),
        );

        return new $command($paymentRequest->getId());
    }
}
