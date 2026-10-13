<?php

namespace App\Identity\Controller;

use App\Identity\Entity\User;
use App\Identity\Form\NewPasswordType;
use App\Identity\Service\UserAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What every signed-in user can do with their own account.
 */
final class AccountController extends AbstractController
{
    #[Route('/account', name: 'identity_account', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user, UserAdministration $administration, Security $security): Response
    {
        $form = $this->createForm(NewPasswordType::class, null, ['ask_current' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{password: string} $data */
            $data = $form->getData();
            $administration->changeOwnPassword($user, $data['password']);

            // A changed password ends every session of the account; this one is started again straight away
            $security->login($user);
            $this->addFlash('success', 'Hasło zostało zmienione.');

            return $this->redirectToRoute('identity_account', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('identity/account.html.twig', ['form' => $form, 'user' => $user]);
    }
}
