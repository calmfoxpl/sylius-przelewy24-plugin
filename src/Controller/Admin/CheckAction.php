<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Controller\Admin;

use Calmfox\SyliusPrzelewy24Plugin\Account\AccountStore;
use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Check the connection": asks Przelewy24 whether it accepts the saved account (testAccess),
 * the same question `calmfox:przelewy24:status` asks from the console.
 *
 * It answers about access to the API only. Whether a customer can pay also depends on the
 * payment channels Przelewy24 has switched on for the merchant, and the page does not pretend
 * to know that.
 */
final readonly class CheckAction
{
    public const CSRF_ID = 'calmfox_przelewy24_check';

    public function __construct(
        private AccountStore $store,
        private Client $client,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token', '')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        [$type, $message] = $this->check($this->store->credentials() ?? Credentials::fromArray($this->store->formData()));

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        return new RedirectResponse($this->urlGenerator->generate('calmfox_przelewy24_admin_account'));
    }

    /** @return array{string, string} */
    private function check(Credentials $credentials): array
    {
        if (!$credentials->isComplete()) {
            return ['error', 'calmfox_przelewy24.account.check_incomplete'];
        }

        try {
            return $this->client->testAccess($credentials)
                ? ['success', $credentials->sandbox ? 'calmfox_przelewy24.account.check_ok_sandbox' : 'calmfox_przelewy24.account.check_ok']
                : ['error', 'calmfox_przelewy24.account.check_refused'];
        } catch (ApiException $e) {
            $this->logger->warning('Przelewy24: the connection check failed.', ['exception' => $e]);

            // Wrong keys come back as 401, not as `data: false`.
            return ['error', \in_array($e->statusCode, [401, 403], true) ? 'calmfox_przelewy24.account.check_refused' : 'calmfox_przelewy24.account.check_unreachable'];
        }
    }
}
