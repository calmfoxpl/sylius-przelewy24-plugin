<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Gateway;

use Calmfox\SyliusPrzelewy24Plugin\Form\Type\GatewayConfigurationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class GatewayConfigurationTypeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Forms::class)) {
            self::markTestSkipped('symfony/form is not installed.');
        }
    }

    public function testKeysLeftEmptyOnSaveAreKept(): void
    {
        $form = self::factory()->create(GatewayConfigurationType::class, [
            'sandbox' => true, 'merchant_id' => '11', 'pos_id' => '', 'crc' => 'stored-crc', 'api_key' => 'stored-key',
        ]);

        $form->submit(['sandbox' => '1', 'merchant_id' => '11', 'pos_id' => '', 'crc' => '', 'api_key' => 'new-key']);

        self::assertSame('stored-crc', $form->getData()['crc']);
        self::assertSame('new-key', $form->getData()['api_key']);
    }

    public function testTheKeysAreNeverRenderedBack(): void
    {
        $view = self::factory()->create(GatewayConfigurationType::class, ['crc' => 'stored-crc', 'api_key' => 'stored-key'])->createView();

        self::assertSame('', $view['crc']->vars['value']);
        self::assertSame('', $view['api_key']->vars['value']);
    }

    public function testSwitchingToProductionIsKept(): void
    {
        $form = self::factory()->create(GatewayConfigurationType::class, ['sandbox' => true]);
        $form->submit(['merchant_id' => '11', 'crc' => 'c', 'api_key' => 'k']);

        self::assertFalse($form->getData()['sandbox']);
    }

    private static function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
