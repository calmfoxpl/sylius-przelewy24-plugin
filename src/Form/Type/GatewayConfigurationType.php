<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The account details in the panel: Configuration → Payment methods → (a Przelewy24 method).
 *
 * The two keys never travel back into the HTML. A key field left empty on save means "keep the
 * one I have", so changing a method's name or channels does not mean pasting the keys again.
 */
final class GatewayConfigurationType extends AbstractType
{
    /** @var list<string> */
    public const SECRETS = ['crc', 'api_key'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $digits = new Regex(pattern: '/^\d{1,10}$/', message: 'calmfox_przelewy24.digits_only', groups: ['sylius']);

        $builder
            ->add('sandbox', CheckboxType::class, [
                'label' => 'calmfox_przelewy24.form.sandbox',
                'help' => 'calmfox_przelewy24.form.sandbox_help',
                'required' => false,
            ])
            ->add('merchant_id', TextType::class, [
                'label' => 'calmfox_przelewy24.form.merchant_id',
                'constraints' => [new NotBlank(message: 'calmfox_przelewy24.merchant_id.not_blank', groups: ['sylius']), $digits],
            ])
            ->add('pos_id', TextType::class, [
                'label' => 'calmfox_przelewy24.form.pos_id',
                'help' => 'calmfox_przelewy24.form.pos_id_help',
                'required' => false,
                'constraints' => [$digits],
            ])
            ->add('crc', PasswordType::class, [
                'label' => 'calmfox_przelewy24.form.crc',
                'help' => 'calmfox_przelewy24.form.secret_help',
                'required' => false,
                'constraints' => [new NotBlank(message: 'calmfox_przelewy24.crc.not_blank', groups: ['sylius'])],
            ])
            ->add('api_key', PasswordType::class, [
                'label' => 'calmfox_przelewy24.form.api_key',
                'help' => 'calmfox_przelewy24.form.secret_help',
                'required' => false,
                'constraints' => [new NotBlank(message: 'calmfox_przelewy24.api_key.not_blank', groups: ['sylius'])],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, [self::class, 'keepStoredSecrets'])
        ;
    }

    /** An empty key field on save keeps the stored key instead of erasing it. */
    public static function keepStoredSecrets(FormEvent $event): void
    {
        $submitted = $event->getData();
        $stored = $event->getForm()->getData();
        if (!\is_array($submitted) || !\is_array($stored)) {
            return;
        }

        foreach (self::SECRETS as $field) {
            $value = $submitted[$field] ?? '';
            $kept = $stored[$field] ?? '';
            if ((!\is_string($value) || '' === trim($value)) && \is_string($kept) && '' !== $kept) {
                $submitted[$field] = $kept;
            }
        }

        $event->setData($submitted);
    }
}
