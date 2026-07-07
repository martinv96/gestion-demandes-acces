<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use App\Repository\ServiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history', methods: ['GET'])]
    public function index(
        RequestRepository $requestRepository,
        ServiceRepository $serviceRepository,
        Request $httpRequest
    ): Response {

        $limit = 10;
        $page = $httpRequest->query->getInt('page', 1);
        if ($page < 1) $page =1;
        $offset = ($page - 1) * $limit;


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

        $requests            = $requestRepository->findWithFilters($filters, $limit, $offset);
        $totalCount          = $requestRepository->countWithFilters([]);
        $totalWithFilters    = $requestRepository->countWithFilters($filters);
        $maxPages            = ceil($totalWithFilters / $limit);
        $services            = $serviceRepository->findBy([], ['name' => 'ASC']);
        $availableDates      = $requestRepository->findDistinctArrivalDates();
        $availableDepartures = $requestRepository->findDistinctDepartureDates();
        

        return $this->render('history/index.html.twig', [
            'requests'            => $requests,
            'services'            => $services,
            'availableDates'      => $availableDates,
            'availableDepartures' => $availableDepartures,
            'currentPage'         => $page,
            'maxPages'            => $maxPages,
            'totalCount'          => $totalCount,
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