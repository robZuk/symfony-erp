<?php

namespace App\Partner\Controller;

use App\Partner\Dto\PartnerFilter;
use App\Partner\Entity\Partner;
use App\Partner\Form\PartnerType;
use App\Partner\Repository\PartnerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/partners', name: 'partner_')]
final class PartnerController extends AbstractController
{
    public function __construct(private readonly PartnerRepository $partners)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapQueryString] PartnerFilter $filter = new PartnerFilter()): Response
    {
        return $this->render('partner/index.html.twig', [
            'filter' => $filter,
            'page' => $this->partners->search($filter),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PARTNER_EDIT')]
    public function new(Request $request): Response
    {
        return $this->edit($request, new Partner());
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PARTNER_EDIT')]
    public function edit(Request $request, Partner $partner): Response
    {
        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->partners->save($partner);
            $this->addFlash('success', \sprintf('Zapisano kontrahenta %s.', $partner->getName()));

            return $this->redirectToRoute('partner_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('partner/form.html.twig', [
            'form' => $form,
            'partner' => $partner,
        ]);
    }

    /**
     * Partners are never deleted, because documents refer to them. They are archived instead.
     */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER_EDIT')]
    #[IsCsrfTokenValid('partner_toggle')]
    public function toggle(Partner $partner): Response
    {
        $partner->setActive(!$partner->isActive());
        $this->partners->save($partner);
        $this->addFlash('success', \sprintf(
            $partner->isActive() ? 'Przywrócono kontrahenta %s.' : 'Zarchiwizowano kontrahenta %s.',
            $partner->getName(),
        ));

        return $this->redirectToRoute('partner_index', status: Response::HTTP_SEE_OTHER);
    }
}
