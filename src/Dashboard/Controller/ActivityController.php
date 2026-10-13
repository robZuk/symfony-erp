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
    #[Route('/admin/activity', name: 'app_activity', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        ActivityEntryRepository $entries,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1,
    ): Response {
        return $this->render('dashboard/activity.html.twig', [
            'q' => $q,
            'page' => $entries->search(mb_substr($q, 0, 100), $page),
        ]);
    }
}
