<?php

namespace App\Dashboard\Controller;

use App\Dashboard\Service\DashboardReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(DashboardReport $report): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'sales' => $report->monthlySales(),
            'queue' => $report->orderQueue(),
            'low_stock' => $report->lowStock(),
            'latest_orders' => $report->latestOrders(),
        ]);
    }
}
