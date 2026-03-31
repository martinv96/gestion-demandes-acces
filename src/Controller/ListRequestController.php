<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Repository\RessourceRepository;
use App\Repository\RequestRepository;
use App\Repository\ServiceRepository;
use App\Repository\WorkflowHistoryRepository;
use App\Service\WorkflowService;
use App\Security\Voter\RequestVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;



final class ListRequestController extends AbstractController
{
    // route pour afficher la liste des demandes
    // ! route qui affiche la liste de toutes les demandes d'accès (avec status type et date)
    // ! + un lien vers la page de détail de chaque demande
    #[Route('/list/request', name: 'app_list_request', methods: ['GET'])]
    public function index(RequestRepository $requestRepository, ServiceRepository $serviceRepository, Request $httpRequest): Response
    {
        // ! gestion des filtres de recherche : status, service, type et date d'arrivée
        $allowedStatuses = ['en_attente_rh', 'en_attente_st', 'en_attente_dsi', 'traitee', 'refusee_rh', 'refusee_st', 'refusee_dsi'];
        $allowedTypes = ['ouverture', 'modification', 'fermeture'];

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

        $requests            = $requestRepository->findCurrentWithFilters($filters);
        $services            = $serviceRepository->findBy([], ['name' => 'ASC']);
        $availableDates      = $requestRepository->findDistinctCurrentArrivalDates();
        $availableDepartures = $requestRepository->findDistinctCurrentDepartureDates();
        $totalCount          = $requestRepository->countCurrent();

        return $this->render('list_request/index.html.twig', [
            'requests'            => $requests,
            'services'            => $services,
            'availableDates'      => $availableDates,
            'availableDepartures' => $availableDepartures,
            'totalCount'          => $totalCount,
            'filters'             => [
                'status'        => $status,
                'serviceId'     => $serviceId,
                'type'          => $type,
                'arrivalDate'   => $arrivalDate,
                'departureDate' => $departureDate,
                'agent'         => $agent,
            ],
            'exportScope' => 'current',
        ]);
    }

    // route pour afficher les détails d'une demande
    // ! route qui affiche les détails d'une demande d'accès spécifique
    #[Route('/request/{id}', name: 'app_request_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(
        AccessRequest $requestEntity,
        WorkflowService $workflowService,
        RequestRepository $requestRepository,
        RessourceRepository $ressourceRepository,
        ServiceRepository $serviceRepository
    ): Response {
        $canEditRequestInfo = $this->isGranted(RequestVoter::EDIT_INFO, $requestEntity);

        return $this->render('list_request/show.html.twig', [
            'requestEntity' => $requestEntity,

            'canValidate'   => $this->isGranted(RequestVoter::VALIDATE, $requestEntity),
            'canRefuse'     => $this->isGranted(RequestVoter::REFUSE, $requestEntity),
            'canEditRequestInfo' => $canEditRequestInfo,
            'selectedServiceId' => $requestEntity->getAgent()?->getService()?->getId(),
            'availableServices' => $canEditRequestInfo
                ? $serviceRepository->findBy([], ['name' => 'ASC'])
                : [],
            'availableLogiciels' => $canEditRequestInfo
                ? $ressourceRepository->findBy(['category' => 'logiciel', 'isActive' => true], ['name' => 'ASC'])
                : [],
            'availableMateriels' => $canEditRequestInfo
                ? $ressourceRepository->findBy(['category' => 'materiel', 'isActive' => true], ['name' => 'ASC'])
                : [],
        ]);
    }

    // route pour valider une demande
    // ! route qui permet de valider une demande d'accès spécifique
    #[Route('/request/{id}/validate', name: 'app_request_validate', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function validate(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(RequestVoter::VALIDATE, $requestEntity);

        if (!$this->isCsrfTokenValid('workflow_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->validate($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addFlash('success', 'La demande a été validée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    // route pour refuser une demande
    // ! route qui permet de refuser une demande d'accès spécifique
    #[Route('/request/{id}/refuse', name: 'app_request_refuse', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function refuse(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(RequestVoter::REFUSE, $requestEntity);

        if (!$this->isCsrfTokenValid('workflow_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->refuse($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addFlash('warning', 'La demande a été refusée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    // ! route pour modifier les informations d'une demande (après refus RH ou pour corriger une erreur)
    // ! elle permet de mettre à jour les informations d'une demande d'accès spécifique
    #[Route('/request/{id}/update-info', name: 'app_request_update_info', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function updateInfo(
        AccessRequest $requestEntity,
        Request $httpRequest,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(RequestVoter::EDIT_INFO, $requestEntity);

        // ! vérification du token CSRF pour sécuriser la requête de mise à jour des informations de la demande
        if (!$this->isCsrfTokenValid('request_edit_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        // ! récupération et validation des données du formulaire de mise à jour des informations de la demande
        try {
            $type = (string) $httpRequest->request->get('type', $requestEntity->getType() ?? 'ouverture');
            if (!in_array($type, ['ouverture', 'modification', 'fermeture'], true)) {
                throw new \InvalidArgumentException('Type de demande invalide.');
            }

            $requestEntity->setType($type);

            $agent = $requestEntity->getAgent();
            // ! si demande n'a pas d'agent associé, on ne peut pas mettre à jour les informations de l'agent, donc on lance une exception
            if ($agent === null) {
                throw new \LogicException('Aucun agent associé à la demande.');
            }

            $agent
                ->setCivility((string) $httpRequest->request->get('civilite', $agent->getCivility() ?? 'N/A'))
                ->setFirstname((string) $httpRequest->request->get('prenom', $agent->getFirstname() ?? ''))
                ->setLastname((string) $httpRequest->request->get('nom', $agent->getLastname() ?? ''))
                ->setJobTitle((string) $httpRequest->request->get('fonction', $agent->getJobTitle() ?? ''));

            $serviceId = (int) $httpRequest->request->get('service', 0);

            // ! si un service est sélectionné (id > 0), on le récupère et on l'associe à l'agent, sinon on laisse le service actuel de l'agent
            if ($serviceId > 0) {
                $service = $entityManager->getRepository(Service::class)->find($serviceId);
                if (!$service instanceof Service) {
                    throw new \InvalidArgumentException('Service invalide.');
                }
                $agent->setService($service);
            }

            $arrivalDate = (string) $httpRequest->request->get('date_arrivee', '');

            // ! si date arrivé fournie, on la convertit en DateTime 
            // !et on la set sur la demande, sinon on laisse la date d'arrivée actuelle de la demande
            if ($arrivalDate !== '') {
                $requestEntity->setArrivalDate(new \DateTime($arrivalDate));
            }

            $departureDate = (string) $httpRequest->request->get('date_depart', '');
            $requestEntity->setDepartureDate($departureDate !== '' ? new \DateTime($departureDate) : null);

            $requestEntity->setCommentary((string) $httpRequest->request->get('commentaire', ''));

            // ! mise à jour des ressources associées à la demande : 
            // ! on supprime d'abord toutes les ressources existantes, 
            // ! puis on ajoute celles sélectionnées dans le formulaire
            foreach ($requestEntity->getRessources()->toArray() as $existingResource) {
                $requestEntity->removeRessource($existingResource);
            }

            if ($type !== 'fermeture') {
                /** @var array<string> $logiciels */
                $logiciels = $httpRequest->request->all('logiciels');
                foreach ($this->normalizeResourceNames($logiciels) as $logicielName) {
                    $ressource = $this->findOrCreateRessource($logicielName, 'logiciel', $entityManager);
                    $ressource->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $requestEntity->addRessource($ressource);
                }

                /** @var array<string> $materiels */
                $materiels = $httpRequest->request->all('materiel');
                foreach ($this->normalizeResourceNames($materiels) as $materielName) {
                    $ressource = $this->findOrCreateRessource($materielName, 'materiel', $entityManager);
                    $ressource->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $requestEntity->addRessource($ressource);
                }
            }

            // ! Si la demande était refusée par RH, la repasser à "en_attente_rh" après modification
            if ($requestEntity->getStatus() === 'refusee_rh') {
                $requestEntity->setStatus('en_attente_rh');
            }

            $requestEntity->setUpdateDate(new \DateTimeImmutable());

            $entityManager->flush();
            $this->addFlash('success', 'Les informations de la demande ont été mises à jour.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    // ! Méthode utilitaire pour trouver ou créer une ressource
    private function findOrCreateRessource(
        string $name,
        string $category,
        EntityManagerInterface $entityManager
    ): Ressource {
        /** @var Ressource|null $ressource */
        $ressource = $entityManager->getRepository(Ressource::class)->findOneBy(['name' => $name]);
        if ($ressource instanceof Ressource) {
            return $ressource;
        }

        $ressource = new Ressource();
        $ressource
            ->setName($name)
            ->setCategory($category)
            ->setAssignmentStatus(Ressource::ASSIGNMENT_NON_ATTRIBUE)
            ->setIsActive(true);

        $entityManager->persist($ressource);

        return $ressource;
    }

    /**
     * @param array<string> $rawNames
     *
     * @return array<string>
     */
    // ! Méthode utilitaire pour normaliser les noms de ressources (trim, unique, etc.)
    private function normalizeResourceNames(array $rawNames): array
    {
        $normalized = [];

        foreach ($rawNames as $rawName) {
            if (!is_string($rawName)) {
                continue;
            }

            $name = trim($rawName);
            if ($name === '') {
                continue;
            }

            $normalized[] = $name;
        }

        return array_values(array_unique($normalized));
    }


    // ! route pour exporter la liste des demandes au format CSV
    #[Route('/request/exportCsv', name: 'app_request_export_csv', methods: ['GET'])]
    public function exportXlsx(
        Request $httpRequest,
        RequestRepository $requestRepository,
        WorkflowHistoryRepository $historyRepository
    ): Response {
        // ! vérification que l'utilisateur est authentifié avant de permettre l'exportation de la liste des demandes
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Reprend la logique de filtres de ta liste
        $allowedStatuses = ['en_attente_rh', 'en_attente_st', 'en_attente_dsi', 'traitee', 'refusee_rh', 'refusee_st', 'refusee_dsi'];
        $allowedTypes = ['ouverture', 'modification', 'fermeture'];

        $status = (string) $httpRequest->query->get('status', '');
        $serviceId = (string) $httpRequest->query->get('serviceId', '');
        $type = (string) $httpRequest->query->get('type', '');
        $arrivalDate = (string) $httpRequest->query->get('arrivalDate', '');
        $departureDate = (string) $httpRequest->query->get('departureDate', '');
        $agent = trim((string) $httpRequest->query->get('agent', ''));

        // partie filtrage des demandes en fonction des critères de recherche fournis dans la requête HTTP
        $filters = [];

        // ! pour chaque critère de filtre (status, serviceId, type, arrivalDate, departureDate, agent), 
        // ! on vérifie s'il est présent et valide dans la requête, 
        // ! puis on l'ajoute au tableau de filtres qui sera utilisé pour interroger la base de données
        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $filters['status'] = $status;
        }

        if ($serviceId !== '' && ctype_digit($serviceId)) {
            $filters['serviceId'] = (int) $serviceId;
        }

        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $filters['type'] = $type;
        }

        if ($arrivalDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivalDate)) {
            $filters['arrivalDate'] = $arrivalDate;
        }

        if ($departureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate)) {
            $filters['departureDate'] = $departureDate;
        }

        if ($agent !== '' && mb_strlen($agent) <= 100) {
            $filters['agent'] = $agent;
        }

        $scope = (string) $httpRequest->query->get('scope', 'current');

        // ! récupération des demandes filtrées à partir du repository, ainsi que de l'historique le plus récent pour chaque demande
        $requests = $scope === 'history'
            ? $requestRepository->findWithFilters($filters)
            : $requestRepository->findCurrentWithFilters($filters);
        $latestHistoryByRequestId = $historyRepository->findLatestByRequests($requests);

        // ! définition des labels lisibles pour les statuts et types de demandes, qui seront utilisés dans l'export Excel
        $statusLabels = [
            'en_attente_rh' => 'En attente RH',
            'en_attente_st' => 'En attente DGA-ST',
            'en_attente_dsi' => 'En attente DSI',
            'traitee' => 'Traitée',
            'refusee_rh' => 'Refusée RH',
            'refusee_st' => 'Refusée DGA-ST',
            'refusee_dsi' => 'Refusée DSI',
        ];

        $typeLabels = [
            'ouverture' => 'Ouverture',
            'modification' => 'Modification',
            'fermeture' => 'Fermeture',
        ];

        // ! création d'un nouveau classeur Excel et configuration de la feuille de calcul pour l'export des demandes
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Demandes');

        // ! définition de styles de couleurs pour les différentes valeurs de statut et de type de demandes,
        // ! qui seront appliqués aux cellules correspondantes dans l'export Excel pour une meilleure lisibilité
        $statusStyleMap = [
            'en_attente_rh' => ['font' => 'FF9A6700', 'border' => 'FFF59E0B'],
            'en_attente_st' => ['font' => 'FF9A6700', 'border' => 'FFF59E0B'],
            'en_attente_dsi' => ['font' => 'FF1D4ED8', 'border' => 'FF60A5FA'],
            'traitee' => ['font' => 'FF15803D', 'border' => 'FF4ADE80'],
            'refusee_rh' => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
            'refusee_st' => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
            'refusee_dsi' => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
        ];

        $typeStyleMap = [
            'ouverture' => ['font' => 'FF0F766E', 'border' => 'FF2DD4BF'],
            'modification' => ['font' => 'FF1D4ED8', 'border' => 'FF60A5FA'],
            'fermeture' => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
        ];

        // En-tetes
        $headers = [
            'Référence',
            'Type',
            'Statut',
            'Agent',
            'Service',
            'Date d\'arrivée',
            'Date de départ',
            'Dernier commentaire',
            'Date de dernière action',
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Style en-tete
        $spreadsheet->getDefaultStyle()->getFont()->setName('Aptos')->setSize(11);
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1F2937');
        $sheet->getStyle('A1:I1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');
        $sheet->getStyle('A1:I1')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FFCBD5E1');
        $sheet->getRowDimension(1)->setRowHeight(24);

        $row = 2;

        // ! boucle pour remplir les lignes de la feuille Excel avec les données des demandes, 
        // ! en appliquant les styles définis en fonction du statut et du type de chaque demande
        foreach ($requests as $requestEntity) {
            $requestId = $requestEntity->getId();
            $agentEntity = $requestEntity->getAgent();
            $serviceEntity = $agentEntity?->getService();
            $requestStatus = $requestEntity->getStatus() ?? '';
            $requestType = $requestEntity->getType() ?? '';
            $history = ($requestId !== null && isset($latestHistoryByRequestId[$requestId])) ? $latestHistoryByRequestId[$requestId] : null;

            // ! construction du nom complet de l'agent (prénom + nom), ou '-' si les informations sont manquantes
            $agentFullName = trim((string) $agentEntity?->getFirstname() . ' ' . (string) $agentEntity?->getLastname());
            if ($agentFullName === '') {
                $agentFullName = '-';
            }

            // ! remplissage des cellules de la ligne avec les données de la demande, en utilisant les labels lisibles pour le statut et le type
            $sheet->setCellValue('A' . $row, $requestEntity->getReference());
            $sheet->setCellValue('B' . $row, $typeLabels[$requestEntity->getType() ?? ''] ?? (string) $requestEntity->getType());
            $sheet->setCellValue('C' . $row, $statusLabels[$requestEntity->getStatus() ?? ''] ?? (string) $requestEntity->getStatus());
            $sheet->setCellValue('D' . $row, $agentFullName);
            $sheet->setCellValue('E' . $row, $serviceEntity?->getName() ?? '-');
            $sheet->setCellValue('F' . $row, $requestEntity->getArrivalDate()?->format('d/m/Y') ?? '-');
            $sheet->setCellValue('G' . $row, $requestEntity->getDepartureDate()?->format('d/m/Y') ?? '-');
            $sheet->setCellValue('H' . $row, $history?->getCommentary() ?? '-');
            $sheet->setCellValue('I' . $row, $history?->getDate()?->format('d/m/Y H:i') ?? '-');

            // ! application de styles conditionnels pour la ligne en fonction du statut et du type de la demande,
            // ! ainsi que pour l'amélioration de la lisibilité (bordures, couleurs de fond alternées, alignement, etc.)
            $sheet->getStyle('A' . $row . ':I' . $row)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFE5E7EB');

            if ($row % 2 === 0) {
                $sheet->getStyle('A' . $row . ':I' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF8FAFC');
            }

            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setARGB('FF0F4C81');
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle('B' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            if (isset($typeStyleMap[$requestType])) {
                $sheet->getStyle('B' . $row)->getFont()->setBold(true)->getColor()->setARGB($typeStyleMap[$requestType]['font']);
                $sheet->getStyle('B' . $row)->getBorders()->getLeft()
                    ->setBorderStyle(Border::BORDER_MEDIUM)
                    ->getColor()->setARGB($typeStyleMap[$requestType]['border']);
            }

            if (isset($statusStyleMap[$requestStatus])) {
                $sheet->getStyle('C' . $row)->getFont()->setBold(true)->getColor()->setARGB($statusStyleMap[$requestStatus]['font']);
                $sheet->getStyle('C' . $row)->getBorders()->getLeft()
                    ->setBorderStyle(Border::BORDER_MEDIUM)
                    ->getColor()->setARGB($statusStyleMap[$requestStatus]['border']);
            }

            $sheet->getStyle('F' . $row . ':G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(22);

            $row++;
        }

        // ! Lisibilité : ajustement des colonnes, filtre et gel de la première ligne
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(24);
        $sheet->getColumnDimension('E')->setWidth(24);
        $sheet->getColumnDimension('F')->setWidth(16);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(42);
        $sheet->getColumnDimension('I')->setWidth(22);
        $sheet->setAutoFilter('A1:I1');
        $sheet->freezePane('A2');
        $sheet->setSelectedCell('A1');

        $filename = sprintf('demandes_acces_%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d_His'));

        $response = new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}
