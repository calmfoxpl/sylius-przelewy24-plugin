<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Console;

use Calmfox\SyliusPrzelewy24Plugin\Api\ApiException;
use Calmfox\SyliusPrzelewy24Plugin\Api\Client;
use Calmfox\SyliusPrzelewy24Plugin\Gateway\Przelewy24Gateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Checks every Przelewy24 payment method: whether its account details are complete and whether
 * Przelewy24 accepts them (GET /api/v1/testAccess). Meant for the moment after the keys have been
 * entered and before the method is switched on for customers, and for a deployment pipeline:
 *
 *     bin/console calmfox:przelewy24:status
 *
 * Exits with 1 when any ENABLED method does not work.
 */
#[AsCommand(name: 'calmfox:przelewy24:status', description: 'Checks the Przelewy24 account details of the payment methods')]
final class StatusCommand extends Command
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly Client $client,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $methods = array_values(array_filter(
            $this->paymentMethodRepository->findAll(),
            static fn (PaymentMethodInterface $method): bool => Przelewy24Gateway::supports($method),
        ));

        if ([] === $methods) {
            $io->warning('No payment method uses the Przelewy24 gateway.');

            return Command::SUCCESS;
        }

        $broken = false;
        foreach ($methods as $method) {
            $credentials = Przelewy24Gateway::credentials($method);
            $label = sprintf(
                '%s (%s, %s, merchant %s)',
                (string) $method->getCode(),
                $method->isEnabled() ? 'enabled' : 'disabled',
                $credentials->sandbox ? 'SANDBOX' : 'PRODUCTION',
                $credentials->merchantId > 0 ? (string) $credentials->merchantId : '—',
            );

            $problem = null;
            if (!$credentials->isComplete()) {
                $problem = 'the merchant ID, the CRC key or the reports key is missing.';
            } else {
                try {
                    if (!$this->client->testAccess($credentials)) {
                        $problem = 'Przelewy24 does not accept these account details.';
                    }
                } catch (ApiException $e) {
                    $problem = $e->getMessage();
                }
            }

            if (null === $problem) {
                $io->success(sprintf('%s: Przelewy24 accepts the account details.', $label));
            } else {
                $io->error(sprintf('%s: %s', $label, $problem));
                $broken = $broken || $method->isEnabled();
            }

            $io->text(sprintf('Notifications go to %s', $this->urlGenerator->generate(
                'sylius_payment_method_notify',
                ['code' => $method->getCode()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )));
        }

        return $broken ? Command::FAILURE : Command::SUCCESS;
    }
}
