<?php

namespace App\Controller;

use App\Repository\RequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function dashboard(RequestRepository $requestRepository): Response
    {
        $pendingRequests = $requestRepository->countPendingRequests();
        $processedRequests = $requestRepository->countProcessedRequests();
        $totalRequests = $requestRepository->count([]);

        $stats = [
            [
                'title' => 'Demandes en attente',
                'value' => $pendingRequests,
            ],
            [
                'title' => 'Demandes traitees',
                'value' => $processedRequests,
            ],
            [
                'title' => 'Total des demandes',
                'value' => $totalRequests,
            ],
        ];

        $recentRequests = $requestRepository->findRecentForDashboard();

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'recentRequests' => $recentRequests,
        ]);
    }
}
