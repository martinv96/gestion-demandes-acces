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

        $pendingRequests = $requestRepository->countPendingCurrent();
        $processedRequests = $requestRepository->countProcessedCurrent();
        $totalRequests = $requestRepository->countCurrent();

        $stats = [
            [
                'title' => 'Demandes en attente',
                'value' => $pendingRequests,
                'icon' => 'images/dashboard/logoEnCours.png',
            ],
            [
                'title' => 'Demandes traitées',
                'value' => $processedRequests,
                'icon' => 'images/dashboard/logoTotaux.png',
            ],
            [
                'title' => 'Total des demandes',
                'value' => $totalRequests,
                'icon' => 'images/dashboard/logoTraité.png',
            ],
        ];

        $recentRequests = $requestRepository->findRecentCurrentForDashboard(4);

        $typeLabels = [
            'ouverture' => 'Ouverture',
            'modification' => 'Modification',
            'fermeture' => 'Fermeture',
        ];

        $countsByType = $requestRepository->countCurrentByType();
        $maxTypeCount = max(1, ...array_values($countsByType));

        $typeDistribution = [];
        foreach ($typeLabels as $type => $label) {
            $count = $countsByType[$type] ?? 0;
            $typeDistribution[] = [
                'type' => $type,
                'label' => $label,
                'count' => $count,
                'percent' => (int) round(($count / $maxTypeCount) * 100),
            ];
        }

        $currentMonthStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
        $nextMonthStart = $currentMonthStart->modify('+1 month');
        $previousMonthStart = $currentMonthStart->modify('-1 month');

        $monthLabels = [
            1 => 'Janvier',
            2 => 'Fevrier',
            3 => 'Mars',
            4 => 'Avril',
            5 => 'Mai',
            6 => 'Juin',
            7 => 'Juillet',
            8 => 'Aout',
            9 => 'Septembre',
            10 => 'Octobre',
            11 => 'Novembre',
            12 => 'Decembre',
        ];

        $previousLabel = ($monthLabels[(int) $previousMonthStart->format('n')] ?? $previousMonthStart->format('m')) . ' ' . $previousMonthStart->format('Y');
        $currentLabel = ($monthLabels[(int) $currentMonthStart->format('n')] ?? $currentMonthStart->format('m')) . ' ' . $currentMonthStart->format('Y');

        $newPrevious = $requestRepository->countCurrentCreatedInPeriod($previousMonthStart, $currentMonthStart);
        $newCurrent = $requestRepository->countCurrentCreatedInPeriod($currentMonthStart, $nextMonthStart);

        $validatedStatuses = [\App\Entity\Request::STATUS_TRAITEE];
        $validatedPrevious = $requestRepository->countCurrentCreatedInPeriod($previousMonthStart, $currentMonthStart, $validatedStatuses);
        $validatedCurrent = $requestRepository->countCurrentCreatedInPeriod($currentMonthStart, $nextMonthStart, $validatedStatuses);

        $rejectedStatuses = [
            \App\Entity\Request::STATUS_REFUSEE_RH,
            \App\Entity\Request::STATUS_REFUSEE_ST,
            \App\Entity\Request::STATUS_REFUSEE_DSI,
        ];
        $rejectedPrevious = $requestRepository->countCurrentCreatedInPeriod($previousMonthStart, $currentMonthStart, $rejectedStatuses);
        $rejectedCurrent = $requestRepository->countCurrentCreatedInPeriod($currentMonthStart, $nextMonthStart, $rejectedStatuses);

        $buildTrend = static function (int $previous, int $current, bool $reverse = false): array {
            if ($previous === 0) {
                return [
                    'label' => $current === 0 ? '0%' : '—',
                    'positive' => $reverse ? $current === 0 : $current > 0,
                ];
            }

            $delta = (int) round((($current - $previous) / $previous) * 100);
            $isPositive = $reverse ? $delta <= 0 : $delta >= 0;

            return [
                'label' => sprintf('%+d%%', $delta),
                'positive' => $isPositive,
            ];
        };

        $monthlyCards = [
            [
                'title' => 'Nouvelles demandes',
                'previous' => $newPrevious,
                'current' => $newCurrent,
                'trend' => $buildTrend($newPrevious, $newCurrent),
            ],
            [
                'title' => 'Demandes validées',
                'previous' => $validatedPrevious,
                'current' => $validatedCurrent,
                'trend' => $buildTrend($validatedPrevious, $validatedCurrent),
            ],
            [
                'title' => 'Demandes rejetées',
                'previous' => $rejectedPrevious,
                'current' => $rejectedCurrent,
                'trend' => $buildTrend($rejectedPrevious, $rejectedCurrent, true),
            ],
        ];

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'recentRequests' => $recentRequests,
            'typeDistribution' => $typeDistribution,
            'monthlyCards' => $monthlyCards,
            'previousMonthLabel' => $previousLabel,
            'currentMonthLabel' => $currentLabel,
        ]);
    }
}
