<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A Symfony plugin is assembled out of YAML, and a mistake there only shows on a live
 * installation: a service naming a class nobody wrote, a hook pointing at a template that is not
 * there, a gateway registered under one name and looked up under another. All of it is caught by
 * reading the files against each other.
 *
 * The files are read as text rather than through Symfony's container, so this runs with no vendor
 * directory.
 */
final class WiringTest extends TestCase
{
    public function testEveryClassNamedInTheContainerExists(): void
    {
        preg_match_all('/class: (Calmfox\\\\SyliusPrzelewy24Plugin\\\\[A-Za-z0-9_\\\\]+)/', self::read('config/services.yaml'), $matches);
        self::assertGreaterThan(8, \count($matches[1]));

        foreach (array_unique($matches[1]) as $class) {
            $path = \dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, \strlen('Calmfox\\SyliusPrzelewy24Plugin\\'))) . '.php';
            self::assertFileExists($path, sprintf('services.yaml names %s, which has no file', $class));
        }
    }

    public function testEveryServiceReferencedIsDefined(): void
    {
        $services = self::read('config/services.yaml');
        preg_match_all('/^    (calmfox_przelewy24\.[a-z0-9_.]+):$/m', $services, $defined);
        preg_match_all('/@(calmfox_przelewy24\.[a-z0-9_.]+)/', $services, $referenced);

        self::assertNotEmpty($defined[1]);
        foreach (array_unique($referenced[1]) as $service) {
            self::assertContains($service, $defined[1], sprintf('%s is referenced but never defined', $service));
        }
    }

    /** Every handler has to be on Sylius's payment request bus, or the command is never handled. */
    public function testEveryHandlerIsOnThePaymentRequestBus(): void
    {
        $services = self::read('config/services.yaml');
        $handlers = glob(\dirname(__DIR__, 2) . '/src/PaymentRequest/Handler/*.php') ?: [];
        self::assertCount(3, $handlers);

        foreach ($handlers as $file) {
            $class = 'Calmfox\\SyliusPrzelewy24Plugin\\PaymentRequest\\Handler\\' . basename($file, '.php');
            $definition = self::definitionOf($services, $class);
            self::assertNotNull($definition, sprintf('%s is not in services.yaml', $class));
            self::assertStringContainsString('{ name: messenger.message_handler, bus: sylius.payment_request.command_bus }', $definition);
        }
    }

    /** The gateway factory name has to be the same wherever Sylius looks it up. */
    public function testTheGatewayIsRegisteredUnderOneName(): void
    {
        $services = self::read('config/services.yaml');
        self::assertStringContainsString("public const FACTORY = 'przelewy24';", self::read('src/Gateway/Przelewy24Gateway.php'));
        self::assertSame(2, substr_count($services, 'gateway_factory: przelewy24 }'));
        self::assertStringContainsString('type: przelewy24,', $services);

        $hooks = self::read('config/twig_hooks/payment_method.yaml');
        self::assertStringContainsString("'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.przelewy24'", $hooks);
        self::assertStringContainsString("'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.przelewy24'", $hooks);
    }

    public function testEveryTemplateAHookNamesExists(): void
    {
        preg_match_all("/template: '@CalmfoxSyliusPrzelewy24Plugin\\/([^']+)'/", self::read('config/twig_hooks/payment_method.yaml'), $matches);
        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $template) {
            self::assertFileExists(\dirname(__DIR__, 2) . '/templates/' . $template);
        }
    }

    /** Every field of the account form is rendered by the page, or it would silently not be saved. */
    public function testTheAccountPageRendersEveryFieldOfTheForm(): void
    {
        preg_match_all("/->add\\('([a-z_]+)'/", self::read('src/Form/Type/AccountType.php'), $fields);
        $template = self::read('templates/admin/account.html.twig');

        self::assertCount(5, $fields[1]);
        foreach ($fields[1] as $field) {
            self::assertStringContainsString(sprintf('form_row(form.%s)', $field), $template);
        }
    }

    /** The account fields live on the account page only, never again on the payment method. */
    public function testThePaymentMethodFormHasNoAccountFields(): void
    {
        self::assertStringNotContainsString('->add(', self::read('src/Form/Type/GatewayConfigurationType.php'));
        self::assertStringNotContainsString('form_row(', self::read('templates/admin/payment_method/gateway_configuration.html.twig'));
    }

    /** Routes named by the menu, the controllers and the templates are the ones the plugin defines. */
    public function testEveryRouteOfThePluginIsDefined(): void
    {
        preg_match_all('/^(calmfox_przelewy24_[a-z0-9_]+):$/m', self::read('config/routes/admin.yaml'), $defined);
        self::assertCount(2, $defined[1]);

        $sources = self::read('src/Menu/AdminMenuListener.php') . self::read('src/Controller/Admin/AccountAction.php') .
            self::read('src/Controller/Admin/CheckAction.php') . self::read('templates/admin/account.html.twig') .
            self::read('templates/admin/payment_method/gateway_configuration.html.twig');
        preg_match_all("/'(calmfox_przelewy24_[a-z0-9_]+)'/", $sources, $used);
        self::assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $route) {
            if ('calmfox_przelewy24_check' === $route) {
                continue; // the CSRF token id, not a route
            }
            self::assertContains($route, $defined[1], sprintf('%s is used but not defined', $route));
        }

        preg_match_all('/_controller: (calmfox_przelewy24\.[a-z0-9_.]+)/', self::read('config/routes/admin.yaml'), $controllers);
        foreach ($controllers[1] as $controller) {
            self::assertMatchesRegularExpression('/^    ' . preg_quote($controller, '/') . ":\n(?:        .*\n)*?        public: true\n/m", self::read('config/services.yaml'));
        }
    }

    private static function definitionOf(string $services, string $class): ?string
    {
        foreach (preg_split('/\n(?=    [a-z_.]+:\n)/', $services) ?: [] as $block) {
            if (str_contains($block, 'class: ' . $class . "\n")) {
                return $block;
            }
        }

        return null;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents);

        return $contents;
    }
}
