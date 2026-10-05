<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit\Account;

use Calmfox\SyliusPrzelewy24Plugin\Form\Type\AccountType;
use Calmfox\SyliusPrzelewy24Plugin\Form\Type\GatewayConfigurationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class AccountTypeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Forms::class)) {
            self::markTestSkipped('symfony/form is not installed.');
        }
    }

    public function testKeysLeftEmptyOnSaveAreKept(): void
    {
        $form = self::factory()->create(AccountType::class, [
            'sandbox' => true, 'merchant_id' => '11', 'pos_id' => '', 'crc' => 'stored-crc', 'api_key' => 'stored-key',
        ]);

        $form->submit(['sandbox' => '1', 'merchant_id' => '11', 'pos_id' => '', 'crc' => '', 'api_key' => 'new-key']);

        self::assertTrue($form->isValid());
        self::assertSame('stored-crc', $form->getData()['crc']);
        self::assertSame('new-key', $form->getData()['api_key']);
    }

    public function testTheKeysAreNeverRenderedBack(): void
    {
        $view = self::factory()->create(AccountType::class, ['crc' => 'stored-crc', 'api_key' => 'stored-key'])->createView();

        self::assertSame('', $view['crc']->vars['value']);
        self::assertSame('', $view['api_key']->vars['value']);
    }

    public function testSwitchingToProductionIsKept(): void
    {
        $form = self::factory()->create(AccountType::class, ['sandbox' => true]);
        $form->submit(['merchant_id' => '11', 'crc' => 'c', 'api_key' => 'k']);

        self::assertTrue($form->isValid());
        self::assertFalse($form->getData()['sandbox']);
    }

    public function testAnAccountWithoutKeysIsRefused(): void
    {
        $form = self::factory()->create(AccountType::class, ['sandbox' => true]);
        $form->submit(['merchant_id' => '11x', 'crc' => '', 'api_key' => '']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('merchant_id')->getErrors());
        self::assertCount(1, $form->get('crc')->getErrors());
        self::assertCount(1, $form->get('api_key')->getErrors());
    }

    /**
     * The payment method form has no account fields any more. Saving a method of an installation
     * upgraded from 1.0 must not wipe the keys it still pays with.
     */
    public function testSavingAPaymentMethodKeepsTheKeysItCarries(): void
    {
        $stored = ['sandbox' => false, 'merchant_id' => '11', 'pos_id' => '', 'crc' => 'c', 'api_key' => 'k'];
        $form = self::factory()->create(GatewayConfigurationType::class, $stored);

        $form->submit([]);

        self::assertSame($stored, $form->getData());
    }

    private static function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
