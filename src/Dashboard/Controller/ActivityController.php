<?php

namespace App\Dashboard\Controller;

use App\Shared\Audit\ActivityEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The activity log: who did what in the system, newest first.
 */
final class ActivityController extends AbstractController
{
    /** First part of the action code => what the area is called in the filter. */
    private const array AREAS = [
        'identity' => 'Konta i logowania',
        'catalog' => 'Produkty',
        'partner' => 'Kontrahenci',
        'inventory' => 'Magazyn',
        'sales' => 'Sprzedaż',
        'invoicing' => 'Faktury i dane firmy',
    ];

    #[Route('/admin/activity', name: 'app_activity', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        ActivityEntryRepository $entries,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] string $area = '',
        #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1,
    ): Response {
        if ('' !== $area && !isset(self::AREAS[$area])) {
            throw $this->createNotFoundException();
        }

        return $this->render('dashboard/activity.html.twig', [
            'q' => $q,
            'area' => $area,
            'areas' => self::AREAS,
            'page' => $entries->search(mb_substr($q, 0, 100), $area, $page),
        ]);
    }
}
