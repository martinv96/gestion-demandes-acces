<?php

namespace App\Controller;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Repository\RessourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewRequestController extends AbstractController
{
    #[Route('/new/request', name: 'app_new_request', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager, RessourceRepository $ressourceRepository): Response
    {
        if ($request->isMethod('POST')) {
            $type = (string) $request->request->get('type', 'ouverture');

            $newRequest = new AccessRequest();

            $newRequest
                ->setType($type)
                ->setStatus('en_attente')
                ->setCommentary((string) $request->request->get('commentaire', ''))
                ->setCreationDate(new \DateTimeImmutable())
                ->setUpdateDate(new \DateTimeImmutable());

            $currentUser = $this->getUser();
            if (!$currentUser instanceof User) {
                throw $this->createAccessDeniedException('Utilisateur non authentifie.');
            }
            $newRequest->setAuthor($currentUser);

            $agent = new Agent();
            $agent
                ->setCivility('N/A')
                ->setFirstname((string) $request->request->get('prenom', ''))
                ->setLastname((string) $request->request->get('nom', ''))
                ->setJobTitle((string) $request->request->get('fonction', ''));

            $serviceCode = (string) $request->request->get('service', '');
            if ($serviceCode !== '') {
                $agent->setService($this->findOrCreateService($serviceCode, $entityManager));
            }

            $entityManager->persist($agent);
            $newRequest->setAgent($agent);

            $arrivalDate = $request->request->get('date_arrivee');
            if (is_string($arrivalDate) && $arrivalDate !== '') {
                $newRequest->setArrivalDate(new \DateTime($arrivalDate));
            } else {
                $newRequest->setArrivalDate(new \DateTime());
            }

            $departureDate = $request->request->get('date_depart');
            if (is_string($departureDate) && $departureDate !== '') {
                $newRequest->setDepartureDate(new \DateTime($departureDate));
            }

            if ($type !== 'fermeture') {
                /** @var array<string> $logiciels */
                $logiciels = $request->request->all('logiciels');
                foreach ($this->normalizeResourceNames($logiciels) as $logicielName) {
                    $ressource = $this->findOrCreateRessource($logicielName, 'logiciel', $ressourceRepository, $entityManager);
                    $newRequest->addRessource($ressource);
                }

                /** @var array<string> $materiels */
                $materiels = $request->request->all('materiel');
                foreach ($this->normalizeResourceNames($materiels) as $materielName) {
                    $ressource = $this->findOrCreateRessource($materielName, 'materiel', $ressourceRepository, $entityManager);
                    $newRequest->addRessource($ressource);
                }
            }

            $entityManager->persist($newRequest);
            $entityManager->flush();

            return $this->redirectToRoute('app_new_request', ['saved' => 1]);
        }

        return $this->render('new_request/index.html.twig', [
            'controller_name' => 'NewRequestController',
            'saved' => $request->query->getBoolean('saved'),
        ]);
    }

    private function findOrCreateRessource(
        string $name,
        string $category,
        RessourceRepository $ressourceRepository,
        EntityManagerInterface $entityManager
    ): Ressource {
        $ressource = $ressourceRepository->findOneBy(['name' => $name]);
        if ($ressource instanceof Ressource) {
            return $ressource;
        }

        $ressource = new Ressource();
        $ressource
            ->setName($name)
            ->setCategory($category)
            ->setIsActive(true);

        $entityManager->persist($ressource);

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

    private function findOrCreateService(string $serviceCode, EntityManagerInterface $entityManager): Service
    {
        $serviceName = $this->mapServiceCodeToLabel($serviceCode);

        /** @var Service|null $service */
        $service = $entityManager->getRepository(Service::class)->findOneBy(['name' => $serviceName]);
        if ($service instanceof Service) {
            return $service;
        }

        $service = new Service();
        $service
            ->setName($serviceName)
            ->setEmail(sprintf('%s@mairie.local', strtolower($serviceCode)));

        $entityManager->persist($service);

        return $service;
    }

    private function mapServiceCodeToLabel(string $serviceCode): string
    {
        return match ($serviceCode) {
            'urbanisme' => 'Service Urbanisme',
            'finances' => 'Service Finances',
            'etat-civil' => 'Service Etat Civil',
            'technique' => 'Service Technique',
            'rh' => 'Ressources Humaines',
            'dsi' => 'DSI',
            'dg' => 'Direction Generale',
            default => ucfirst($serviceCode),
        };
    }
}
