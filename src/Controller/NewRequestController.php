<?php

namespace App\Controller;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Form\Model\NewRequestData;
use App\Form\NewRequestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewRequestController extends AbstractController
{
    // route pour créer une nouvelle demande d'accès
    #[Route('/new/request', name: 'app_new_request', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $formData = new NewRequestData();
        $form = $this->createForm(NewRequestType::class, $formData);

        $form->handleRequest($request);

        // Si le formulaire est soumis et valide, créer la demande d'accès
        if ($form->isSubmitted() && $form->isValid()) {
            $requestType = $formData->getType() ?? 'ouverture';
            $initialStatus = ($requestType === 'modification' || $requestType === 'fermeture') ? 'traitee' : 'en_attente_rh';

            $newRequest = new AccessRequest();

            $newRequest
                ->setType($requestType)
                ->setStatus($initialStatus)
                ->setCommentary($formData->getCommentary())
                ->setCreationDate(new \DateTimeImmutable())
                ->setUpdateDate(new \DateTimeImmutable());

            $currentUser = $this->getUser();

            // si l'user est null, c'est qu'il n'est pas authentifié, on bloque l'accès
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
                ->setEmail($formData->getEmail())
                ->setService($formData->getService());

            $entityManager->persist($agent);
            $newRequest->setAgent($agent);

            $parentRequest = $formData->getParentRequest();
            if ($parentRequest instanceof AccessRequest) {
                $newRequest->setParentRequest($parentRequest);
            }


            // si date arrivée donnée, on la set, sinon null (selon ouverture ou fermeture)
            // ouverture : date arrivée obligatoire, sinon la validation échouera (validation dans NewRequestData)
            if ($formData->getArrivalDate() instanceof \DateTime) {
                $newRequest->setArrivalDate($formData->getArrivalDate());
            } else {
                $newRequest->setArrivalDate(new \DateTime());
            }

            if ($formData->getDepartureDate() instanceof \DateTime) {
                $newRequest->setDepartureDate($formData->getDepartureDate());
            }

            // Si c'est une fermeture, on copie les ressources de la demande d'origine, sinon on prend celles du formulaire
            if ($requestType === 'fermeture') {
                if ($parentRequest instanceof AccessRequest) {
                    foreach ($parentRequest->getRessources() as $ressource) {
                        $newRequest->addRessource($ressource);
                    }
                }
            } else {
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

            // Si c'est une modification ou une fermeture, on copie l'historique de la demande d'origine
            if (($requestType === 'modification' || $requestType === 'fermeture') && $parentRequest instanceof AccessRequest) {
                foreach ($parentRequest->getRequestId() as $parentHistory) {
                    $historyCopy = new WorkflowHistory();
                    $historyCopy
                        ->setRequest($newRequest)
                        ->setUser($parentHistory->getUser() ?? $currentUser)
                        ->setOldStatus($parentHistory->getOldStatus() ?? '')
                        ->setNewStatus($parentHistory->getNewStatus() ?? '')
                        ->setCommentary($parentHistory->getCommentary() ?? '')
                        ->setDate($parentHistory->getDate() ?? new \DateTimeImmutable());

                    $entityManager->persist($historyCopy);
                }
            }

            $entityManager->flush();

            return $this->redirectToRoute('app_new_request', ['saved' => 1]);
        }

        return $this->render('new_request/index.html.twig', [
            'saved' => $request->query->getBoolean('saved'),
            'form' => $form->createView(),
        ]);
    }
}
