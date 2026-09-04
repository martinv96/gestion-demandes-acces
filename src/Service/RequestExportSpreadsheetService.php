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

/**
 * Construit le classeur Excel des demandes, de leur historique et de leurs délais de traitement.
 */
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
        // "history" exporte toutes les demandes ; les autres périmètres exportent les demandes courantes.
        $requests = $scope === 'history'
            ? $this->requestRepository->findWithFilters($filters)
            : $this->requestRepository->findCurrentWithFilters($filters);

        // Précharge l'historique pour éviter une requête par demande pendant la construction du fichier.
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

        // Libellés lisibles utilisés dans les deux onglets Excel.
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

        // Couleurs associées aux statuts et types pour faciliter la lecture du classeur.
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

        // Aptos est la police standard des versions récentes d'Excel.
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Aptos')->setSize(11);

        // ==========================================
        // 1. ONGLET PRINCIPAL : DEMANDES (SANS DÉLAIS)
        // ==========================================
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
        ];

        $summarySheet->fromArray($headers, null, 'A1');
        $this->applyExportHeaderStyle($summarySheet, 'A1:I1');

        // Les lignes sont d'abord collectées, puis écrites en une seule opération dans la feuille.
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

            $summarySheet->getStyle('A2:I' . $summaryLastRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFE5E7EB');

            $summarySheet->getStyle('A2:A' . $summaryLastRow)->getFont()->setBold(true)->getColor()->setARGB('FF0F4C81');
            $summarySheet->getStyle('A2:A' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $summarySheet->getStyle('B2:C' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $summarySheet->getStyle('F2:G' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $summarySheet->getStyle('I2:I' . $summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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

        foreach (range('A', 'I') as $columnID) {
            $summarySheet->getColumnDimension($columnID)->setAutoSize(true);
        }
        $summarySheet->setAutoFilter('A1:I1');
        $summarySheet->freezePane('A2');
        $summarySheet->setSelectedCell('A1');


        // ==========================================
        // 2. ONGLET SECONDAIRE : DÉTAILS (AVEC DÉLAIS)
        // ==========================================
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
            'Délais de traitement RH',
            'Délais de traitement ST',
            'Délais de traitement DSI',
            'Délais de traitement FINANCES',
        ];

        $detailSheet->fromArray($detailHeaders, null, 'A1');
        $this->applyExportHeaderStyle($detailSheet, 'A1:S1');

        // L'onglet de détail contient une ligne par action d'historique d'une demande.
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

            // --- CALCULS DES DÉLAIS PAR SERVICE DEPUIS LA CRÉATION ---
            $delaisRH = '-';
            $delaisST = '-';
            $delaisDSI = '-';
            $delaisFIN = '-';

            $allRequestHistories = ($requestId !== null && isset($historyByRequestId[$requestId])) ? $historyByRequestId[$requestId] : [];

            // Tri chronologique des historiques pour isoler proprement les premières validations
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

                // Validation RH
                if (($oldStatus === 'en_attente_validation' || $oldStatus === AccessRequest::STATUS_EN_ATTENTE_RH)
                    && $newStatus !== 'en_attente_validation' && $newStatus !== AccessRequest::STATUS_EN_ATTENTE_RH) {
                    if ($dateValidationRH === null) {
                        $dateValidationRH = $entry->getDate();
                    }
                }
                
                // Validation ST
                if ($oldStatus === AccessRequest::STATUS_EN_ATTENTE_ST && $newStatus !== AccessRequest::STATUS_EN_ATTENTE_ST) {
                    if ($dateValidationST === null) {
                        $dateValidationST = $entry->getDate();
                    }
                }
                
                // Validation DSI
                if ($oldStatus === AccessRequest::STATUS_EN_ATTENTE_DSI && $newStatus !== AccessRequest::STATUS_EN_ATTENTE_DSI) {
                    if ($dateValidationDSI === null) {
                        $dateValidationDSI = $entry->getDate();
                    }
                }
                
                // Validation FINANCES / Traitement final
                if (in_array($newStatus, [AccessRequest::STATUS_TRAITEE, AccessRequest::STATUS_REFUSEE_DSI, AccessRequest::STATUS_REFUSEE_ST, AccessRequest::STATUS_REFUSEE_RH])) {
                    if ($dateValidationFIN === null) {
                        $dateValidationFIN = $entry->getDate();
                    }
                }
            }

            $dateCreation = $requestEntity->getCreationDate();
            $now = new \DateTimeImmutable();

            if ($dateCreation !== null) {
                $finRH = $dateValidationRH ?? $now;
                $delaisRH = ($finRH < $dateCreation) ? '0 j' : $dateCreation->diff($finRH)->days . ' j';

                $finST = $dateValidationST ?? $now;
                $delaisST = ($finST < $dateCreation) ? '0 j' : $dateCreation->diff($finST)->days . ' j';

                $finDSI = $dateValidationDSI ?? $now;
                $delaisDSI = ($finDSI < $dateCreation) ? '0 j' : $dateCreation->diff($finDSI)->days . ' j';

                $finFIN = $dateValidationFIN ?? $now;
                $delaisFIN = ($finFIN < $dateCreation) ? '0 j' : $dateCreation->diff($finFIN)->days . ' j';
            }

            // Extraction et tri des ressources affectées
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

            // Gestion de l'affichage s'il n'existe aucun historique pour le moment
            $requestHistories = ($requestId !== null && isset($historyByRequestId[$requestId])) ? $historyByRequestId[$requestId] : [];
            if ($requestHistories === []) {
                $requestHistories = [null];
            }

            // Génération d'une ligne pour chaque action historique
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
                    $delaisRH,
                    $delaisST,
                    $delaisDSI,
                    $delaisFIN,
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

            $detailSheet->getStyle('A2:S' . $detailLastRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFE5E7EB');

            $detailSheet->getStyle('A2:A' . $detailLastRow)->getFont()->setBold(true)->getColor()->setARGB('FF0F4C81');
            $detailSheet->getStyle('A2:A' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('B2:C' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('F2:G' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $detailSheet->getStyle('O2:S' . $detailLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
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

        foreach (range('A', 'S') as $columnID) {
            $detailSheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $detailSheet->getColumnDimension('H')->setWidth(28);
        $detailSheet->getColumnDimension('I')->setWidth(28);
        $detailSheet->getColumnDimension('J')->setWidth(36);
        $detailSheet->getColumnDimension('N')->setWidth(42);

        $detailSheet->setAutoFilter('A1:S1');
        $detailSheet->freezePane('A2');
        $detailSheet->setSelectedCell('A1');

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function applyExportHeaderStyle(Worksheet $sheet, string $range): void
    {
        // Style commun à toutes les lignes d'en-tête pour garder les deux feuilles cohérentes.
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
        // Met en évidence le type ou statut par une couleur de texte et une bordure gauche.
        $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setARGB($fontColor);
        $sheet->getStyle($cell)->getBorders()->getLeft()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()->setARGB($borderColor);
    }

    private function formatAgentFullName(?string $firstname, ?string $lastname): string
    {
        // Un tiret est exporté lorsqu'aucune identité d'agent n'est disponible.
        $fullName = trim($this->toTitleCase($firstname) . ' ' . $this->toTitleCase($lastname));

        return $fullName === '' ? '-' : $fullName;
    }

    private function toTitleCase(?string $value): string
    {
        // Normalise les noms provenant de la base avant de les afficher dans le fichier Excel.
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($normalized), MB_CASE_TITLE, 'UTF-8');
    }
}