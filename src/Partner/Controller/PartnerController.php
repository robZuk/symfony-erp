<?php

namespace App\Partner\Controller;

use App\Partner\Dto\PartnerFilter;
use App\Partner\Entity\Partner;
use App\Partner\Form\PartnerType;
use App\Partner\Repository\PartnerRepository;
use App\Partner\Vies\VatRegistry;
use App\Partner\Vies\ViesUnavailable;
use App\Shared\Audit\ActivityLog;
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
            $partner->isActive() ? 'Przywrócono kontrahenta %s.' : 'Zarchiwizowano kontrahenta %s. Znajdziesz go pod filtrem „Zarchiwizowani”.',
            $partner->getName(),
        ));

        return $this->redirectToRoute('partner_index', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Asks the EU VAT register (VIES) whether the partner's NIP is registered for intra-Community
     * transactions (VAT-UE), and keeps the answer on the partner.
     */
    #[Route('/{id}/vat-eu', name: 'vat_eu', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER_EDIT')]
    #[IsCsrfTokenValid('partner_vat_eu')]
    public function checkVatEu(Partner $partner, VatRegistry $registry, ActivityLog $activity): Response
    {
        $back = $this->redirectToRoute('partner_edit', ['id' => $partner->getId()], Response::HTTP_SEE_OTHER);
        if (null === $partner->getNip()) {
            $this->addFlash('error', 'Kontrahent nie ma NIP-u, nie ma czego sprawdzać.');

            return $back;
        }

        try {
            $check = $registry->check('PL', $partner->getNip());
        } catch (ViesUnavailable $exception) {
            // No answer is not a "no": the partner keeps what was known before
            $this->addFlash('error', $exception->getMessage().' Spróbuj ponownie za chwilę.');

            return $back;
        }

        $partner->recordVatEuCheck($check);
        $this->partners->save($partner);
        $activity->record(
            'partner.vat_eu.checked',
            \sprintf('Sprawdzono w VIES kontrahenta %s: %s.', $partner->getName(), $check->valid ? 'aktywny VAT-UE' : 'brak rejestracji VAT-UE'),
            'partner_edit',
            ['id' => (int) $partner->getId()],
        );

        $this->addFlash(
            $check->valid ? 'success' : 'error',
            $check->valid
                ? \sprintf('VIES: PL%s jest aktywny w VAT-UE%s.', $check->vatNumber, null !== $check->name ? ' jako „'.$check->name.'”' : '')
                // Being absent from VIES only means no intra-Community trade; the company may well be an active VAT payer at home
                : \sprintf('VIES: PL%s nie jest zarejestrowany do transakcji wewnątrzwspólnotowych (VAT-UE). To nie znaczy, że firma nie istnieje ani że nie jest czynnym podatnikiem VAT w Polsce.', $check->vatNumber),
        );

        return $back;
    }
}
