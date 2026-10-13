<?php

namespace App\Identity\Controller;

use App\Identity\Dto\UserFilter;
use App\Identity\Entity\User;
use App\Identity\Form\NewPasswordType;
use App\Identity\Form\UserType;
use App\Identity\Repository\UserRepository;
use App\Identity\Service\UserAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User accounts, for administrators only.
 */
#[Route('/admin/users', name: 'identity_user_')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAdministration $administration,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapQueryString] UserFilter $filter = new UserFilter()): Response
    {
        return $this->render('identity/user/index.html.twig', [
            'filter' => $filter,
            'page' => $this->users->search($filter->q, $filter->status, $filter->page),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(UserType::class, ['roles' => []], ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, password: string, fullName: string, roles: list<string>} $data */
            $data = $form->getData();

            try {
                $user = $this->administration->create($data['email'], $data['fullName'], $data['roles'], $data['password']);
                $this->addFlash('success', \sprintf('Założono konto %s.', $user->getEmail()));

                return $this->redirectToRoute('identity_user_index', status: Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('identity/user/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, User $user, #[CurrentUser] User $actor): Response
    {
        $form = $this->createForm(UserType::class, ['fullName' => $user->getFullName(), 'roles' => $user->getAssignedRoles()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{fullName: string, roles: list<string>} $data */
            $data = $form->getData();

            try {
                $this->administration->update($user, $data['fullName'], $data['roles'], $actor);
                $this->addFlash('success', \sprintf('Zapisano konto %s.', $user->getEmail()));

                return $this->redirectToRoute('identity_user_index', status: Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('identity/user/edit.html.twig', [
            'form' => $form,
            'password_form' => $this->createForm(NewPasswordType::class, null, [
                'action' => $this->generateUrl('identity_user_password', ['id' => $user->getId()]),
            ]),
            'user' => $user,
        ]);
    }

    #[Route('/{id}/password', name: 'password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function password(Request $request, User $user): Response
    {
        $form = $this->createForm(NewPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{password: string} $data */
            $data = $form->getData();
            $this->administration->resetPassword($user, $data['password']);
            $this->addFlash('success', \sprintf('Ustawiono nowe hasło dla %s. Użytkownik został wylogowany.', $user->getEmail()));
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('identity_user_edit', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Accounts are blocked, never deleted: documents and the activity log keep pointing at them.
     */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('identity_user_toggle')]
    public function toggle(User $user, #[CurrentUser] User $actor): Response
    {
        try {
            if ($user->isActive()) {
                $this->administration->block($user, $actor);
                $this->addFlash('success', \sprintf('Zablokowano konto %s.', $user->getEmail()));
            } else {
                $this->administration->unblock($user);
                $this->addFlash('success', \sprintf('Odblokowano konto %s.', $user->getEmail()));
            }
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('identity_user_index', status: Response::HTTP_SEE_OTHER);
    }
}
