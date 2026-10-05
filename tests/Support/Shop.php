<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Support;

use Calmfox\SyliusPrzelewy24Plugin\Account\CredentialsResolver;
use Calmfox\SyliusPrzelewy24Plugin\Account\SavedAccount;
use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Core\Notification;
use Calmfox\SyliusPrzelewy24Plugin\Core\SessionId;
use Calmfox\SyliusPrzelewy24Plugin\Core\Signature;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Uid\Uuid;

/** A small order with a Przelewy24 payment, built from the real Sylius models. */
final class Shop
{
    public const HASH = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public const CONFIG = ['sandbox' => true, 'merchant_id' => '11', 'pos_id' => '', 'crc' => 'crc-key', 'api_key' => 'api-key'];

    /** @param array<string, mixed> $config */
    public static function payment(array $config = self::CONFIG, string $state = 'new'): Payment
    {
        $gateway = new GatewayConfig();
        $gateway->setFactoryName('przelewy24');
        $gateway->setGatewayName('przelewy24');
        $gateway->setConfig($config);

        $method = new PaymentMethod();
        $method->setCode('przelewy24');
        $method->setGatewayConfig($gateway);

        $customer = new Customer();
        $customer->setEmail('jan@example.com');

        $address = new Address();
        $address->setFirstName('Jan');
        $address->setLastName('Kowalski');
        $address->setCountryCode('PL');

        $order = new Order();
        $order->setNumber('000123');
        $order->setLocaleCode('pl_PL');
        $order->setCustomer($customer);
        $order->setBillingAddress($address);
        $order->setTokenValue('order-token');

        $payment = new Payment();
        $payment->setMethod($method);
        $payment->setAmount(12345);
        $payment->setCurrencyCode('PLN');
        $payment->setState($state);
        $order->addPayment($payment);

        return $payment;
    }

    public static function request(Payment $payment, string $action, string $hash = self::HASH): PaymentRequest
    {
        $method = $payment->getMethod();
        \assert(null !== $method);
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        (new \ReflectionProperty(PaymentRequest::class, 'hash'))->setValue($request, Uuid::fromString($hash));

        return $request;
    }

    /** @param array<string, mixed> $override */
    public static function notification(array $override = [], string $crc = 'crc-key'): string
    {
        $fields = $override + [
            'merchantId' => 11,
            'posId' => 11,
            'sessionId' => SessionId::for(self::HASH, 1),
            'amount' => 12345,
            'originAmount' => 12345,
            'currency' => 'PLN',
            'orderId' => 987654,
            'methodId' => 154,
            'statement' => 'p24-A1-B2',
            'sign' => '',
        ];
        $unsigned = Notification::fromJson((string) json_encode($fields));
        \assert(null !== $unsigned);
        if ('' === $fields['sign']) {
            $fields['sign'] = Signature::forNotification($unsigned, $crc);
        }

        return (string) json_encode($fields);
    }

    public static function credentials(): Credentials
    {
        return Credentials::fromArray(self::CONFIG);
    }

    /** Credentials as the handlers get them: from the saved account if given, else the method. */
    public static function resolver(?Credentials $account = null): CredentialsResolver
    {
        return new CredentialsResolver(new class($account) implements SavedAccount {
            public function __construct(private readonly ?Credentials $account)
            {
            }

            public function credentials(): ?Credentials
            {
                return $this->account;
            }
        });
    }

    public static function withPayload(PaymentRequestInterface $request, string $body): PaymentRequestInterface
    {
        $request->setPayload(['http_request' => ['content' => $body, 'clientIp' => '127.0.0.1']]);

        return $request;
    }
}
