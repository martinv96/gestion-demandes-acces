<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use Doctrine\ORM\EntityManagerInterface;

class RequestUpdateInfoService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array{
     * type?: string,
     * civilite?: string,
     * prenom?: string,
     * nom?: string,
     * email?: string,
     * fonction?: string,
    * replacee_par?: string,
     * service?: int,
     * taille_vetements?: string,
     * taille_chaussures?: string,
     * date_arrivee?: string,
     * date_depart?: string,
     * commentaire?: string,
     * logiciels?: array<string>,
     * materiel?: array<string>
     * } $payload
     */
    public function update(AccessRequest $requestEntity, array $payload, ?User $actor = null): void
    {
        $type = (string) ($payload['type'] ?? $requestEntity->getType() ?? AccessRequest::TYPE_OUVERTURE);
        if (!in_array($type, AccessRequest::TYPES, true)) {
            throw new \InvalidArgumentException('Type de demande invalide.');
        }

        $requestEntity->setType($type);
        $requestEntity->setReplaceePar(trim((string) ($payload['replacee_par'] ?? $requestEntity->getReplaceePar() ?? '')) ?: null);

        $agent = $requestEntity->getAgent();
        if ($agent === null) {
            throw new \LogicException('Aucun agent associé à la demande.');
        }

        $agent
            ->setCivility((string) ($payload['civilite'] ?? $agent->getCivility() ?? 'N/A'))
            ->setFirstname((string) ($payload['prenom'] ?? $agent->getFirstname() ?? ''))
            ->setLastname((string) ($payload['nom'] ?? $agent->getLastname() ?? ''))
            ->setJobTitle((string) ($payload['fonction'] ?? $agent->getJobTitle() ?? ''))
            ->setClothingSize(trim((string) ($payload['taille_vetements'] ?? $agent->getClothingSize() ?? '')) ?: null)
            ->setShoeSize(trim((string) ($payload['taille_chaussures'] ?? $agent->getShoeSize() ?? '')) ?: null);

        // Logique pour l'email
        $rawEmail = trim((string) ($payload['email'] ?? ''));
        if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }

        $agent->setEmail($rawEmail !== '' ? mb_strtolower($rawEmail) : null);

        $serviceId = (int) ($payload['service'] ?? 0);
        if ($serviceId > 0) {
            $service = $this->entityManager->getRepository(Service::class)->find($serviceId);
            if (!$service instanceof Service) {
                throw new \InvalidArgumentException('Service invalide.');
            }

            $agent->setService($service);
        }

        $arrivalDate = (string) ($payload['date_arrivee'] ?? '');
        if ($arrivalDate !== '') {
            $requestEntity->setArrivalDate(new \DateTime($arrivalDate));
        }

        $departureDate = (string) ($payload['date_depart'] ?? '');
        $requestEntity->setDepartureDate($departureDate !== '' ? new \DateTime($departureDate) : null);

        $newCommentary = trim((string) ($payload['commentaire'] ?? ''));
        if ($newCommentary !== '') {
            $existingCommentary = trim((string) ($requestEntity->getCommentary() ?? ''));
            $timestamp = (new \DateTimeImmutable())->format('d/m/Y H:i');
            $actorLabel = $actor?->getDisplayName() ?: 'Utilisateur';
            $newEntry = sprintf('[%s] %s : %s', $timestamp, $actorLabel, $newCommentary);

            $requestEntity->setCommentary(
                $existingCommentary === ''
                    ? $newEntry
                    : $existingCommentary . "\n" . $newEntry
            );

            if ($actor instanceof User) {
                $currentStatus = (string) ($requestEntity->getStatus() ?? '');
                $history = new WorkflowHistory();
                $history
                    ->setRequest($requestEntity)
                    ->setUser($actor)
                    ->setOldStatus($currentStatus)
                    ->setNewStatus($currentStatus)
                    ->setCommentary('Modification des informations : ' . $newCommentary)
                    ->setDate(new \DateTimeImmutable());

                $requestEntity->addRequestId($history);
                $this->entityManager->persist($history);
            }
        }

        foreach ($requestEntity->getRessources()->toArray() as $existingResource) {
            $requestEntity->removeRessource($existingResource);
        }

        if ($type !== AccessRequest::TYPE_FERMETURE) {
            foreach ($this->normalizeResourceNames($payload['logiciels'] ?? []) as $logicielName) {
                $ressource = $this->findOrCreateRessource($logicielName, 'logiciel');
                $ressource->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                $requestEntity->addRessource($ressource);
            }

            foreach ($this->normalizeResourceNames($payload['materiel'] ?? []) as $materielName) {
                $ressource = $this->findOrCreateRessource($materielName, 'materiel');
                $ressource->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                $requestEntity->addRessource($ressource);
            }
        }

        $requestEntity->setUpdateDate(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    private function findOrCreateRessource(string $name, string $category): Ressource
    {
        /** @var Ressource|null $ressource */
        $ressource = $this->entityManager->getRepository(Ressource::class)->findOneBy(['name' => $name]);
        if ($ressource instanceof Ressource) {
            return $ressource;
        }

        $ressource = new Ressource();
        $ressource
            ->setName($name)
            ->setCategory($category)
            ->setAssignmentStatus(Ressource::ASSIGNMENT_NON_ATTRIBUE)
            ->setIsActive(true);

        $this->entityManager->persist($ressource);

        return $ressource;
    }

    /**
     * @param array<string> $rawNames
     *
     * @return array<string>
     */
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
}