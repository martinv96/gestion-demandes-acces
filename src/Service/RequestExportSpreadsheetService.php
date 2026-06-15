<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use App\Repository\WorkflowHistoryRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RequestExportSpreadsheetService
{
    public function __construct(
        private RequestRepository $requestRepository,
        private WorkflowHistoryRepository $historyRepository,
    ) {}

    /**
     * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     */
    public function buildSpreadsheet(array $filters, string $scope): Spreadsheet
    {
        $requests = $scope === 'history'
            ? $this->requestRepository->findWithFilters($filters)
            : $this->requestRepository->findCurrentWithFilters($filters);
        $historyByRequestId = $this->historyRepository->findByRequests($requests);

        $latestHistoryByRequestId = [];
        foreach ($historyByRequestId as $requestId => $histories) {
            if ($histories === []) {
                continue;
            }

            $lastHistory = $histories[array_key_last($histories)];
            if ($lastHistory !== null) {
                $latestHistoryByRequestId[$requestId] = $lastHistory;
            }
        }

        $statusLabels = [
            AccessRequest::STATUS_EN_ATTENTE_RH => 'En attente RH',
            AccessRequest::STATUS_EN_ATTENTE_ST => 'En attente ST',
            AccessRequest::STATUS_EN_ATTENTE_DSI => 'En attente DSI',
            AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT => 'En attente Traitement',
            AccessRequest::STATUS_TRAITEE => 'Traitée',
            AccessRequest::STATUS_REFUSEE_RH => 'Refusée RH',
            AccessRequest::STATUS_REFUSEE_ST => 'Refusée ST',
            AccessRequest::STATUS_REFUSEE_DSI => 'Refusée DSI',
        ];

        $typeLabels = [
            AccessRequest::TYPE_OUVERTURE => 'Arrivée',
            AccessRequest::TYPE_MODIFICATION => 'Changement de poste / Droits',
            AccessRequest::TYPE_FERMETURE => 'Départ',
        ];

        $statusStyleMap = [
            AccessRequest::STATUS_EN_ATTENTE_RH => ['font' => 'FF9A6700', 'border' => 'FFF59E0B'],
            AccessRequest::STATUS_EN_ATTENTE_ST => ['font' => 'FF9A6700', 'border' => 'FFF59E0B'],
            AccessRequest::STATUS_EN_ATTENTE_DSI => ['font' => 'FF1D4ED8', 'border' => 'FF60A5FA'],
            AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT => ['font' => 'FF1D5ED8', 'border' => 'FF60A5FA'],
            AccessRequest::STATUS_TRAITEE => ['font' => 'FF15803D', 'border' => 'FF4ADE80'],
            AccessRequest::STATUS_REFUSEE_RH => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
            AccessRequest::STATUS_REFUSEE_ST => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
            AccessRequest::STATUS_REFUSEE_DSI => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
        ];

        $typeStyleMap = [
            AccessRequest::TYPE_OUVERTURE => ['font' => 'FF0F766E', 'border' => 'FF2DD4BF'],
            AccessRequest::TYPE_MODIFICATION => ['font' => 'FF1D4ED8', 'border' => 'FF60A5FA'],
            AccessRequest::TYPE_FERMETURE => ['font' => 'FFB91C1C', 'border' => 'FFF87171'],
        ];

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Aptos')->setSize(11);

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Demandes');

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
            'Délais de traitement RH',
            'Délais de traitement ST',
            'Délais de traitement DSI',
            'Délais de traitement FINANCES',
        ];

        $summarySheet->fromArray($headers, null, 'A1');
        $this->applyExportHeaderStyle($summarySheet, 'A1:M1');

        $summaryRows = [];
        $summaryStyleMetaByRow = [];
        $row = 2;
        foreach ($requests as $requestEntity) {
            $requestId = $requestEntity->getId();
            $agentEntity = $requestEntity->getAgent();
            $serviceEntity = $agentEntity?->getService();
            $requestStatus = $requestEntity->getStatus() ?? '';
            $requestType = $requestEntity->getType() ?? '';
            $history = ($requestId !== null && isset($latestHistoryByRequestId[$requestId])) ? $latestHistoryByRequestId[$requestId] : null;

            $agentFullName = $this->formatAgentFullName(
                $agentEntity?->getFirstname(),
                $agentEntity?->getLastname(),
            );

            // --- CALCULS DES DÉLAIS PAR SERVICE DEPUIS LA CRÉATION ---
            $delaisRH = '-';
            $delaisST = '-';
            $delaisDSI = '-';
            $delaisFIN = '-';

            $allRequestHistories = ($requestId !== null && isset($historyByRequestId[$requestId])) ? $historyByRequestId[$requestId] : [];

            // 1. Tri chronologique pour trouver les premières ou dernières validations
            usort($allRequestHistories, static function ($a, $b) {
                return ($a->getDate() <=> $b->getDate());
            });

            $dateValidationRH = null;
            $dateValidationST = null;
            $dateValidationDSI = null;
            $dateValidationFIN = null;

            foreach ($allRequestHistories as $entry) {
                $oldStatus = $entry->getOldStatus();
                $newStatus = $entry->getNewStatus();

                // Validation RH : Quand le ticket quitte l'environnement RH pour la première fois
                if (($oldStatus === 'en_attente_validation' || $oldStatus === AccessRequest::STATUS_EN_ATTENTE_RH)
                    && $newStatus !== 'en_attente_validation' && $newStatus !== AccessRequest::STATUS_EN_ATTENTE_RH) {
                    if ($dateValidationRH === null) {
                        $dateValidationRH = $entry->getDate();
                    }
                }
                
                // Validation ST : quand le pôle ST quitte son statut d'attente
                if ($oldStatus === AccessRequest::STATUS_EN_ATTENTE_ST && $newStatus !== AccessRequest::STATUS_EN_ATTENTE_ST) {
                    if ($dateValidationST === null) {
                        $dateValidationST = $entry->getDate();
                    }
                }
                
                // Validation DSI : quand le pôle DSI quitte son statut d'attente
                if ($oldStatus === AccessRequest::STATUS_EN_ATTENTE_DSI && $newStatus !== AccessRequest::STATUS_EN_ATTENTE_DSI) {
                    if ($dateValidationDSI === null) {
                        $dateValidationDSI = $entry->getDate();
                    }
                }
                
                // Validation FINANCES / Traitement : premier passage à un statut final
                if (in_array($newStatus, [AccessRequest::STATUS_TRAITEE, AccessRequest::STATUS_REFUSEE_DSI, AccessRequest::STATUS_REFUSEE_ST, AccessRequest::STATUS_REFUSEE_RH])) {
                    if ($dateValidationFIN === null) {
                        $dateValidationFIN = $entry->getDate();
                    }
                }
            }

            $dateCreation = $requestEntity->getCreationDate();
            $now = new \DateTimeImmutable();

            if ($dateCreation !== null) {
                // Règle demandée : création -> validation du service, sinon création -> aujourd'hui.
                $finRH = $dateValidationRH ?? $now;
                $delaisRH = ($finRH < $dateCreation) ? '0 j' : $dateCreation->diff($finRH)->days . ' j';

                $finST = $dateValidationST ?? $now;
                $delaisST = ($finST < $dateCreation) ? '0 j' : $dateCreation->diff($finST)->days . ' j';

                $finDSI = $dateValidationDSI ?? $now;
                $delaisDSI = ($finDSI < $dateCreation) ? '0 j' : $dateCreation->diff($finDSI)->days . ' j';

                $finFIN = $dateValidationFIN ?? $now;
                $delaisFIN = ($finFIN < $dateCreation) ? '0 j' : $dateCreation->diff($finFIN)->days . ' j';
            }

            // [Le code continue ici sur votre tableau d'export classique...]
            $summaryRows[] = [
                $requestEntity->getReference(),
                $typeLabels[$requestType] ?? $requestType,
                $statusLabels[$requestStatus] ?? $requestStatus,
                $agentFullName,
                $serviceEntity?->getName() ?? '-',
                $requestEntity->getArrivalDate()?->format('d/m/Y') ?? '-',
                $requestEntity->getDepartureDate()?->format('d/m/Y') ?? '-',
                $history?->getCommentary() ?? '-',
                $history?->getDate()?->format('d/m/Y H:i') ?? '-',
                $delaisRH,
                $delaisST,
                $delaisDSI,
                $delaisFIN,
            ];
            $summaryStyleMetaByRow[$row] = [
                'type' => $requestType,
                'status' => $requestStatus,
            ];

            $row++;
        }


        if ($summaryRows !== []) {
            $summarySheet->fromArray($summaryRows, null, 'A2');
            $summaryLastRow = 1 + count($summaryRows);

            $summarySheet->getStyle('A2:M' . $summaryLastRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFE5E7EB');

            $summarySheet->getStyle('A2:A' . $summaryLastRow)->getFont()->setBold(true)->getColor()->setARGB('FF0F4C81');
            $summarySheet->getStyle('A2:A' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $summarySheet->getStyle('B2:C' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $summarySheet->getStyle('F2:G' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $summarySheet->getStyle('I2:M' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            foreach ($summaryStyleMetaByRow as $summaryRow => $meta) {
                $requestType = $meta['type'];
                $requestStatus = $meta['status'];

                if (isset($typeStyleMap[$requestType])) {
                    $this->applyAccentCellStyle($summarySheet, 'B' . $summaryRow, $typeStyleMap[$requestType]['font'], $typeStyleMap[$requestType]['border']);
                }

                if (isset($statusStyleMap[$requestStatus])) {
                    $this->applyAccentCellStyle($summarySheet, 'C' . $summaryRow, $statusStyleMap[$requestStatus]['font'], $statusStyleMap[$requestStatus]['border']);
                }
            }
        }


        foreach (range('A', 'M') as $columnID) {
            $summarySheet->getColumnDimension($columnID)->setAutoSize(true);
        }
        $summarySheet->setAutoFilter('A1:M1');
        $summarySheet->freezePane('A2');
        $summarySheet->setSelectedCell('A1');

        $detailSheet = $spreadsheet->createSheet();
        $detailSheet->setTitle('Détails');

        $detailHeaders = [
            'Référence',
            'Type',
            'Statut actuel',
            'Agent',
            'Service',
            'Date d\'arrivée',
            'Date de départ',
            'Logiciels attribués',
            'Matériels attribués',
            'Commentaire demande',
            'Acteur historique',
            'Ancien statut',
            'Nouveau statut',
            'Commentaire historique',
            'Date action',
        ];

        $detailSheet->fromArray($detailHeaders, null, 'A1');
        $this->applyExportHeaderStyle($detailSheet, 'A1:O1');

        $detailRows = [];
        $detailStyleMetaByRow = [];
        $detailRow = 2;
        foreach ($requests as $requestEntity) {
            $requestId = $requestEntity->getId();
            $agentEntity = $requestEntity->getAgent();
            $serviceEntity = $agentEntity?->getService();
            $requestStatus = $requestEntity->getStatus() ?? '';
            $requestType = $requestEntity->getType() ?? '';
            $agentFullName = $this->formatAgentFullName(
                $agentEntity?->getFirstname(),
                $agentEntity?->getLastname(),
            );

            $logiciels = [];
            $materiels = [];
            foreach ($requestEntity->getRessources() as $ressource) {
                if ($ressource->getCategory() === 'logiciel') {
                    $logiciels[] = (string) $ressource->getName();
                }

                if ($ressource->getCategory() === 'materiel') {
                    $materiels[] = (string) $ressource->getName();
                }
            }

            sort($logiciels);
            sort($materiels);

            $requestHistories = ($requestId !== null && isset($historyByRequestId[$requestId])) ? $historyByRequestId[$requestId] : [];
            if ($requestHistories === []) {
                $requestHistories = [null];
            }

            foreach ($requestHistories as $history) {
                $historyOldStatus = $history?->getOldStatus() ?? '';
                $historyNewStatus = $history?->getNewStatus() ?? '';

                $detailRows[] = [
                    $requestEntity->getReference(),
                    $typeLabels[$requestType] ?? $requestType,
                    $statusLabels[$requestStatus] ?? $requestStatus,
                    $agentFullName,
                    $serviceEntity?->getName() ?? '-',
                    $requestEntity->getArrivalDate()?->format('d/m/Y') ?? '-',
                    $requestEntity->getDepartureDate()?->format('d/m/Y') ?? '-',
                    $logiciels !== [] ? implode("\n", $logiciels) : '-',
                    $materiels !== [] ? implode("\n", $materiels) : '-',
                    $requestEntity->getCommentary() ?: '-',
                    $history?->getUser()?->getDisplayName() ?? '-',
                    $history ? ($statusLabels[$historyOldStatus] ?? $historyOldStatus) : '-',
                    $history ? ($statusLabels[$historyNewStatus] ?? $historyNewStatus) : '-',
                    $history?->getCommentary() ?? '-',
                    $history?->getDate()?->format('d/m/Y H:i') ?? '-',
                ];

                $detailStyleMetaByRow[$detailRow] = [
                    'type' => $requestType,
                    'status' => $requestStatus,
                    'historyOldStatus' => $historyOldStatus,
                    'historyNewStatus' => $historyNewStatus,
                ];

                $detailRow++;
            }
        }

        if ($detailRows !== []) {
            $detailSheet->fromArray($detailRows, null, 'A2');
            $detailLastRow = 1 + count($detailRows);

            $detailSheet->getStyle('A2:O' . $detailLastRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFE5E7EB');

            $detailSheet->getStyle('A2:A' . $detailLastRow)->getFont()->setBold(true)->getColor()->setARGB('FF0F4C81');
            $detailSheet->getStyle('A2:A' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('B2:C' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('F2:G' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('O2:O' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('H2:J' . $detailLastRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $detailSheet->getStyle('N2:N' . $detailLastRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            foreach ($detailStyleMetaByRow as $rowNumber => $meta) {
                $requestType = $meta['type'];
                $requestStatus = $meta['status'];
                $historyOldStatus = $meta['historyOldStatus'];
                $historyNewStatus = $meta['historyNewStatus'];

                if (isset($typeStyleMap[$requestType])) {
                    $this->applyAccentCellStyle($detailSheet, 'B' . $rowNumber, $typeStyleMap[$requestType]['font'], $typeStyleMap[$requestType]['border']);
                }

                if (isset($statusStyleMap[$requestStatus])) {
                    $this->applyAccentCellStyle($detailSheet, 'C' . $rowNumber, $statusStyleMap[$requestStatus]['font'], $statusStyleMap[$requestStatus]['border']);
                }

                if (isset($statusStyleMap[$historyOldStatus])) {
                    $this->applyAccentCellStyle($detailSheet, 'L' . $rowNumber, $statusStyleMap[$historyOldStatus]['font'], $statusStyleMap[$historyOldStatus]['border']);
                }

                if (isset($statusStyleMap[$historyNewStatus])) {
                    $this->applyAccentCellStyle($detailSheet, 'M' . $rowNumber, $statusStyleMap[$historyNewStatus]['font'], $statusStyleMap[$historyNewStatus]['border']);
                }
            }
        }

        foreach (range('A', 'O') as $columnID) {
            $detailSheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $detailSheet->getColumnDimension('H')->setWidth(28);
        $detailSheet->getColumnDimension('I')->setWidth(28);
        $detailSheet->getColumnDimension('J')->setWidth(36);
        $detailSheet->getColumnDimension('N')->setWidth(42);

        $detailSheet->setAutoFilter('A1:O1');
        $detailSheet->freezePane('A2');
        $detailSheet->setSelectedCell('A1');

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function applyExportHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FF1F2937');
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FFCBD5E1');
        $sheet->getRowDimension(1)->setRowHeight(24);
    }

    private function applyAccentCellStyle(Worksheet $sheet, string $cell, string $fontColor, string $borderColor): void
    {
        $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setARGB($fontColor);
        $sheet->getStyle($cell)->getBorders()->getLeft()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()->setARGB($borderColor);
    }

    private function formatAgentFullName(?string $firstname, ?string $lastname): string
    {
        $fullName = trim($this->toTitleCase($firstname) . ' ' . $this->toTitleCase($lastname));

        return $fullName === '' ? '-' : $fullName;
    }

    private function toTitleCase(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($normalized), MB_CASE_TITLE, 'UTF-8');
    }
}
