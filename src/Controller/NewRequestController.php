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
use App\Form\Model\NewRequestData;
use App\Form\NewRequestType;

final class NewRequestController extends AbstractController
{
    // route pour créer une nouvelle demande d'accès
    #[Route('/new/request', name: 'app_new_request', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $formData = new \App\Form\Model\NewRequestData();
        $form = $this->createForm(\App\Form\NewRequestType::class, $formData);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newRequest = new AccessRequest();

            $newRequest
                ->setType($formData->getType() ?? 'ouverture')
                ->setStatus('en_attente_rh')
                ->setCommentary($formData->getCommentary())
                ->setCreationDate(new \DateTimeImmutable())
                ->setUpdateDate(new \DateTimeImmutable());

            $currentUser = $this->getUser();
            if (!$currentUser instanceof User) {
                throw $this->createAccessDeniedException('Utilisateur non authentifié.');
            }

            $newRequest->setAuthor($currentUser);

            $agent = new Agent();
            $agent
                ->setCivility($formData->getCivility() ?? 'N/A')
                ->setFirstname($formData->getFirstname() ?? '')
                ->setLastname($formData->getLastname() ?? '')
                ->setJobTitle($formData->getJobTitle() ?? '')
                ->setService($formData->getService());

            $entityManager->persist($agent);
            $newRequest->setAgent($agent);

            if ($formData->getArrivalDate() instanceof \DateTime) {
                $newRequest->setArrivalDate($formData->getArrivalDate());
            } else {
                $newRequest->setArrivalDate(new \DateTime());
            }

            if ($formData->getDepartureDate() instanceof \DateTime) {
                $newRequest->setDepartureDate($formData->getDepartureDate());
            }

            if (($formData->getType() ?? 'ouverture') !== 'fermeture') {
                foreach ($formData->getLogiciels() as $logiciel) {
                    $logiciel->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $newRequest->addRessource($logiciel);
                }

                foreach ($formData->getMateriels() as $materiel) {
                    $materiel->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $newRequest->addRessource($materiel);
                }
            }

            $entityManager->persist($newRequest);
            $entityManager->flush();

            return $this->redirectToRoute('app_new_request', ['saved' => 1]);
        }

        return $this->render('new_request/index.html.twig', [
            'controller_name' => 'NewRequestController',
            'saved' => $request->query->getBoolean('saved'),
            'form' => $form->createView(),
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

    // Méthode pour mapper les codes de service aux labels affichés dans le formulaire
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
