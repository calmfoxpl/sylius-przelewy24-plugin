<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Api;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Calmfox\SyliusPrzelewy24Plugin\Core\Signature;
use Calmfox\SyliusPrzelewy24Plugin\Core\TransactionStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('symfony/http-client is not installed.');
        }
    }

    public function testRegistersASignedTransactionWithBasicAuth(): void
    {
        $client = $this->client(new MockResponse('{"data":{"token":"TOKEN-1"},"responseCode":0}'));
        $credentials = self::credentials();

        $token = $client->register($credentials, ['sessionId' => 's_1', 'amount' => 12345, 'currency' => 'PLN', 'email' => 'a@b.pl']);

        self::assertSame('TOKEN-1', $token);
        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame('https://sandbox.przelewy24.pl/api/v1/transaction/register', $this->sent[0]['url']);
        self::assertContains('Authorization: Basic ' . base64_encode('12:api-key'), $this->sent[0]['options']['headers'] ?? []);

        $body = json_decode((string) $this->sent[0]['options']['body'], true);
        self::assertSame(11, $body['merchantId']);
        self::assertSame(12, $body['posId']);
        self::assertSame(Signature::forRegistration('s_1', 11, 12345, 'PLN', 'crc-key'), $body['sign']);
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/TOKEN-1', $client->paymentUrl($credentials, $token));
    }

    public function testProductionGoesToTheProductionHost(): void
    {
        $client = $this->client(new MockResponse('{"data":true}'));

        self::assertTrue($client->testAccess(new Credentials(11, 11, 'c', 'k', false)));
        self::assertSame('https://secure.przelewy24.pl/api/v1/testAccess', $this->sent[0]['url']);
    }

    public function testVerifiesWithASignature(): void
    {
        $client = $this->client(new MockResponse('{"data":{"status":"success"},"responseCode":0}'));

        $client->verify(self::credentials(), 's_1', 987, 12345, 'PLN');

        $body = json_decode((string) $this->sent[0]['options']['body'], true);
        self::assertSame('PUT', $this->sent[0]['method']);
        self::assertSame(987, $body['orderId']);
        self::assertSame(Signature::forVerification('s_1', 987, 12345, 'PLN', 'crc-key'), $body['sign']);
    }

    public function testAnErrorCarriesWhatPrzelewy24Said(): void
    {
        $client = $this->client(new MockResponse('{"error":"Incorrect authentication","code":401}', ['http_code' => 401]));

        try {
            $client->testAccess(self::credentials());
            self::fail('An exception was expected.');
        } catch (ApiException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('Incorrect authentication', $e->getMessage());
        }
    }

    public function testAnUnknownSessionIsNoTransactionRatherThanAnError(): void
    {
        self::assertNull($this->client(new MockResponse('{"error":"Transaction not found"}', ['http_code' => 404]))
            ->findBySessionId(self::credentials(), 's_1'));
    }

    public function testFindsATransaction(): void
    {
        $transaction = $this->client(new MockResponse('{"data":{"sessionId":"s_1","orderId":987,"amount":12345,"currency":"PLN","status":1}}'))
            ->findBySessionId(self::credentials(), 's_1');

        self::assertSame(987, $transaction?->orderId);
        self::assertSame(TransactionStatus::AwaitingVerification, $transaction->status);
    }

    public function testAVerificationThatIsNotConfirmedIsAnError(): void
    {
        $this->expectException(ApiException::class);

        $this->client(new MockResponse('{"data":{"status":"failed"}}'))->verify(self::credentials(), 's_1', 987, 12345, 'PLN');
    }

    private function client(MockResponse ...$responses): Client
    {
        $responses = array_values($responses);

        return new Client(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        }));
    }

    private static function credentials(): Credentials
    {
        return new Credentials(11, 12, 'crc-key', 'api-key', true);
    }
}
