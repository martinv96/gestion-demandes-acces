<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use App\Repository\ServiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Affiche l'historique complet des demandes avec filtres et pagination.
 */
final class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history', methods: ['GET'])]
    public function index(
        RequestRepository $requestRepository,
        ServiceRepository $serviceRepository,
        Request $httpRequest
    ): Response {
        // Dix lignes par page permettent de garder la consultation d'historique lisible.
        $limit = 10;
        $page = $httpRequest->query->getInt('page', 1);
        if ($page < 1) $page =1;
        $offset = ($page - 1) * $limit;


        // Liste blanche des valeurs de filtre acceptées depuis l'URL.
        $allowedStatuses = array_merge(AccessRequest::WORKFLOW_STATUSES, [
            'a_valider_rh',
            'a_valider_st',
            'a_valider_dsi',
            'a_valider_fin',
        ]);
        $allowedTypes = AccessRequest::TYPES;

        $status        = (string) $httpRequest->query->get('status', '');
        $serviceId     = (string) $httpRequest->query->get('serviceId', '');
        $type          = (string) $httpRequest->query->get('type', '');
        $arrivalDate   = (string) $httpRequest->query->get('arrivalDate', '');
        $departureDate = (string) $httpRequest->query->get('departureDate', '');
        $agent         = trim((string) $httpRequest->query->get('agent', ''));

        // Seules les valeurs contrôlées sont transmises au repository pour construire la requête.
        $filters = [];

        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $filters['status'] = $status;
        } else {
            $status = '';
        }

        if ($serviceId !== '' && ctype_digit($serviceId)) {
            $filters['serviceId'] = (int) $serviceId;
        } else {
            $serviceId = '';
        }

        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $filters['type'] = $type;
        } else {
            $type = '';
        }

        if ($arrivalDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivalDate)) {
            $filters['arrivalDate'] = $arrivalDate;
        } else {
            $arrivalDate = '';
        }

        if ($departureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate)) {
            $filters['departureDate'] = $departureDate;
        } else {
            $departureDate = '';
        }

        if ($agent !== '' && mb_strlen($agent) <= 100) {
            $filters['agent'] = $agent;
        } else {
            $agent = '';
        }

        // Les résultats de la page courante et les compteurs sont calculés séparément.
        $requests            = $requestRepository->findWithFilters($filters, $limit, $offset);
        $totalCount          = $requestRepository->countWithFilters([]);
        $totalWithFilters    = $requestRepository->countWithFilters($filters);
        $maxPages            = ceil($totalWithFilters / $limit);
        $services            = $serviceRepository->findBy([], ['name' => 'ASC']);
        $availableDates      = $requestRepository->findDistinctArrivalDates();
        $availableDepartures = $requestRepository->findDistinctDepartureDates();
        $pagesCount = max(1, (int) ceil($totalWithFilters / $limit));
        

        // Twig reçoit les filtres normalisés pour les conserver dans les liens de pagination.
        return $this->render('history/index.html.twig', [
            'requests'            => $requests,
            'services'            => $services,
            'availableDates'      => $availableDates,
            'availableDepartures' => $availableDepartures,
            'currentPage'         => $page,
            'maxPages'            => $maxPages,
            'totalCount'          => $totalCount,
            'pagesCount'          => $pagesCount, 
            'filters'             => [
                'status'        => $status,
                'serviceId'     => $serviceId,
                'type'          => $type,
                'arrivalDate'   => $arrivalDate,
                'departureDate' => $departureDate,
                'agent'         => $agent,
            ],
            'exportScope' => 'history',
            'pageTitle'    => 'Historique des demandes',
            'pageSubtitle' => 'Toutes les demandes enregistrées',
            'resetRoute'   => 'app_history',
            'pageRoute'    => 'app_history',
        ]);
    }
}