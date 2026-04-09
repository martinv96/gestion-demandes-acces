<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\Service;
use Doctrine\ORM\EntityManagerInterface;

class RequestUpdateInfoService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array{
     *     type?: string,
     *     civilite?: string,
     *     prenom?: string,
     *     nom?: string,
     *     fonction?: string,
     *     service?: int,
     *     date_arrivee?: string,
     *     date_depart?: string,
     *     commentaire?: string,
     *     logiciels?: array<string>,
     *     materiel?: array<string>
     * } $payload
     */
    public function update(AccessRequest $requestEntity, array $payload): void
    {
        $type = (string) ($payload['type'] ?? $requestEntity->getType() ?? AccessRequest::TYPE_OUVERTURE);
        if (!in_array($type, AccessRequest::TYPES, true)) {
            throw new \InvalidArgumentException('Type de demande invalide.');
        }

        $requestEntity->setType($type);

        $agent = $requestEntity->getAgent();
        if ($agent === null) {
            throw new \LogicException('Aucun agent associé à la demande.');
        }

        $agent
            ->setCivility((string) ($payload['civilite'] ?? $agent->getCivility() ?? 'N/A'))
            ->setFirstname((string) ($payload['prenom'] ?? $agent->getFirstname() ?? ''))
            ->setLastname((string) ($payload['nom'] ?? $agent->getLastname() ?? ''))
            ->setJobTitle((string) ($payload['fonction'] ?? $agent->getJobTitle() ?? ''));

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
            $newEntry = sprintf('[%s] RH: %s', $timestamp, $newCommentary);

            $requestEntity->setCommentary(
                $existingCommentary === ''
                    ? $newEntry
                    : $existingCommentary . "\n" . $newEntry
            );
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