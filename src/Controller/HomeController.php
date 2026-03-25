<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function dashboard(RequestRepository $requestRepository): Response
    {
        $user = $this->getUser();
        if ($user instanceof User && $user->isMustChangePassword()) {
            return $this->redirectToRoute('app_force_change_password');
        }

        $pendingRequests = $requestRepository->countPendingRequests();
        $processedRequests = $requestRepository->countProcessedRequests();
        $totalRequests = $requestRepository->count([]);

        $stats = [
            [
                'title' => 'Demandes en attente',
                'value' => $pendingRequests,
            ],
            [
                'title' => 'Demandes traitées',
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
