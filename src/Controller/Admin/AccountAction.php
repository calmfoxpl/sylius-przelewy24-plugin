<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Controller\Admin;

use Calmfox\SyliusPrzelewy24Plugin\Account\AccountStore;
use Calmfox\SyliusPrzelewy24Plugin\Form\Type\AccountType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Calmfox services → Przelewy24: the account every Przelewy24 payment method pays into.
 *
 * On an installation upgraded from 1.0 the form starts from the keys a method still carries, so
 * moving them here is a matter of pressing "Save"; the page says which method they come from.
 */
final readonly class AccountAction
{
    public function __construct(
        private AccountStore $store,
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $saved = $this->store->isSaved();
        $form = $this->formFactory->create(AccountType::class, $this->store->formData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $this->store->save($data);

            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('success', $saved ? 'calmfox_przelewy24.account.saved' : 'calmfox_przelewy24.account.moved');
            }

            return new RedirectResponse($this->urlGenerator->generate('calmfox_przelewy24_admin_account'));
        }

        return new Response($this->twig->render('@CalmfoxSyliusPrzelewy24Plugin/admin/account.html.twig', [
            'form' => $form->createView(),
            'saved' => $saved,
            'methods' => $this->store->methods(),
            'methods_with_own_keys' => $saved ? [] : $this->store->methodsWithOwnKeys(),
        ]), $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
