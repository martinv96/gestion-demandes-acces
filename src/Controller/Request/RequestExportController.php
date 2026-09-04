<?php

namespace App\Controller\Request;

use App\Entity\Request as AccessRequest;
use App\Service\RequestExportSpreadsheetService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Actions HTTP d'export des demandes d'accès.
 */
final class RequestExportController extends AbstractController
{
    #[Route('/request/exportCsv', name: 'app_request_export_csv', methods: ['GET'])]
    public function exportXlsx(
        Request $httpRequest,
        RequestExportSpreadsheetService $requestExportSpreadsheetService
    ): Response {
        // L'export contient les données des demandes : accès réservé aux utilisateurs authentifiés.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $allowedStatuses = array_merge(AccessRequest::WORKFLOW_STATUSES, [
            'a_valider_rh',
            'a_valider_st',
            'a_valider_dsi',
            'a_valider_fin',
        ]);
        $allowedTypes = AccessRequest::TYPES;

        $status = (string) $httpRequest->query->get('status', '');
        $serviceId = (string) $httpRequest->query->get('serviceId', '');
        $type = (string) $httpRequest->query->get('type', '');
        $arrivalDate = (string) $httpRequest->query->get('arrivalDate', '');
        $departureDate = (string) $httpRequest->query->get('departureDate', '');
        $agent = trim((string) $httpRequest->query->get('agent', ''));

        // Ne transmet au service que des filtres validés, issus de la même liste que l'écran principal.
        $filters = [];

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

        // Le service construit le classeur ; le contrôleur se limite à le transmettre au navigateur.
        $spreadsheet = $requestExportSpreadsheetService->buildSpreadsheet($filters, $scope);

        $filename = sprintf('demandes_acces_%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d_H\hi'));

        // Le flux évite de créer un fichier temporaire sur le serveur.
        $response = new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');

            $spreadsheet->disconnectWorksheets();
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}