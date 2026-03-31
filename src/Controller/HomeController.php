<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    // route pour la page d'accueil / dashboard
    // ! la route affiche des stats pour les demandes (en attente, traitées et totaux avec icones) 
    // ! + liste des 5 dernieres demandes
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
                'icon' => 'images/dashboard/logoEnCours.png',
            ],
            [
                'title' => 'Demandes traitées',
                'value' => $processedRequests,
                'icon' => 'images/dashboard/logoTraité.png',
            ],
            [
                'title' => 'Total des demandes',
                'value' => $totalRequests,
                'icon' => 'images/dashboard/logoTotaux.png',
            ],
        ];

        $recentRequests = $requestRepository->findRecentForDashboard();

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'recentRequests' => $recentRequests,
        ]);
    }
}
