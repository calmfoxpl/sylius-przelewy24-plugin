<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Api;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Core\Signature;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Przelewy24 REST API, version 1 (https://developers.przelewy24.pl) — the four calls a shop
 * needs to take a payment. Authentication is HTTP Basic: the point-of-sale ID as the login and
 * the reports key as the password.
 */
class Client
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /** Whether Przelewy24 accepts these credentials in the environment they are set for. */
    public function testAccess(Credentials $credentials): bool
    {
        return true === ($this->request($credentials, 'GET', 'api/v1/testAccess')['data'] ?? null);
    }

    /**
     * Registers a transaction and returns its token, with which the customer is sent to the
     * payment page ({@see paymentUrl()}).
     *
     * @param array<string, mixed> $transaction the transaction fields, without merchantId, posId and sign;
     *                                          sessionId, amount and currency are required
     */
    public function register(Credentials $credentials, array $transaction): string
    {
        $sessionId = $transaction['sessionId'] ?? null;
        $amount = $transaction['amount'] ?? null;
        $currency = $transaction['currency'] ?? null;
        if (!\is_string($sessionId) || !\is_int($amount) || !\is_string($currency)) {
            throw new \InvalidArgumentException('A transaction needs a string sessionId, an integer amount and a string currency.');
        }

        $body = ['merchantId' => $credentials->merchantId, 'posId' => $credentials->posId] + $transaction;
        $body['sign'] = Signature::forRegistration($sessionId, $credentials->merchantId, $amount, $currency, $credentials->crc);

        $data = $this->request($credentials, 'POST', 'api/v1/transaction/register', $body);
        $token = \is_array($data['data'] ?? null) ? ($data['data']['token'] ?? null) : null;
        if (!\is_string($token) || '' === $token) {
            throw new ApiException('Przelewy24 registered the transaction but returned no token.');
        }

        return $token;
    }

    public function paymentUrl(Credentials $credentials, string $token): string
    {
        return $credentials->baseUrl() . 'trnRequest/' . rawurlencode($token);
    }

    /** Accepts the money. A transaction that is never verified is returned to the customer. */
    public function verify(Credentials $credentials, string $sessionId, int $orderId, int $amount, string $currency): void
    {
        $data = $this->request($credentials, 'PUT', 'api/v1/transaction/verify', [
            'merchantId' => $credentials->merchantId,
            'posId' => $credentials->posId,
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderId,
            'sign' => Signature::forVerification($sessionId, $orderId, $amount, $currency, $credentials->crc),
        ]);

        $status = \is_array($data['data'] ?? null) ? ($data['data']['status'] ?? null) : null;
        if ('success' !== $status) {
            throw new ApiException('Przelewy24 did not confirm the verification of the transaction.');
        }
    }

    /** Null when Przelewy24 does not know the session — the customer never reached the payment page. */
    public function findBySessionId(Credentials $credentials, string $sessionId): ?Transaction
    {
        try {
            $data = $this->request($credentials, 'GET', 'api/v1/transaction/by/sessionId/' . rawurlencode($sessionId));
        } catch (ApiException $e) {
            if (404 === $e->statusCode) {
                return null;
            }

            throw $e;
        }

        return \is_array($data['data'] ?? null) ? Transaction::fromArray($data['data']) : null;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<array-key, mixed>
     */
    private function request(Credentials $credentials, string $method, string $path, ?array $json = null): array
    {
        $options = [
            'auth_basic' => [(string) $credentials->posId, $credentials->apiKey],
            'timeout' => 20,
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $credentials->baseUrl() . $path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportException $e) {
            throw new ApiException(sprintf('Przelewy24 could not be reached: %s', $e->getMessage()), 0, $e);
        }

        $data = json_decode($content, true);
        if ($status >= 300) {
            throw new ApiException(sprintf('Przelewy24 answered %d to %s %s: %s', $status, $method, $path, self::errorOf($data, $content)), $status);
        }
        if (!\is_array($data)) {
            throw new ApiException(sprintf('Przelewy24 answered %s %s with something that is not JSON.', $method, $path), $status);
        }

        return $data;
    }

    private static function errorOf(mixed $data, string $content): string
    {
        if (\is_array($data) && isset($data['error'])) {
            return \is_string($data['error']) ? $data['error'] : (string) json_encode($data['error'], \JSON_UNESCAPED_UNICODE);
        }

        return mb_substr($content, 0, 300);
    }
}
