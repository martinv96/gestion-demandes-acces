<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $stats = [
            [
                "title" => "En attente validation technique",
                "value" => 8
            ],
            [
                "title" => "En attente validation DSI",
                "value" => 5
            ],
            [
                "title" => "Demandes traitées",
                "value" => 124
            ]
        ];

        $recentRequests = [
            [
                "id" => "REQ-2026-042",
                "agent" => "Sophie Martin",
                "service" => "Service Urbanisme",
                "type" => "Ouverture",
                "statut" => "En attente validation service technique",
                "date" => "12/03/2026"
            ],
            [
                "id" => "REQ-2026-041",
                "agent" => "Thomas Dubois",
                "service" => "Service Finances",
                "type" => "Modification",
                "statut" => "En attente validation service informatique",
                "date" => "11/03/2026"
            ],
            [
                "id" => "REQ-2026-040",
                "agent" => "Claire Bernard",
                "service" => "Service État Civil",
                "type" => "Ouverture",
                "statut" => "Validée",
                "date" => "10/03/2026"
            ]
        ];

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'recentRequests' => $recentRequests
        ]);
    }
}
