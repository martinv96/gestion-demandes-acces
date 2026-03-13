<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListRequestController extends AbstractController
{
    #[Route('/list/request', name: 'app_list_request', methods: ['GET'])]
    #[Route('/lost/request', name: 'app_lost_request', methods: ['GET'])]
    public function index(RequestRepository $requestRepository): Response
    {
        $requests = $requestRepository->findLatestWithRelations();

        return $this->render('list_request/index.html.twig', [
            'requests' => $requests,
        ]);
    }

    #[Route('/request/{id}', name: 'app_request_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(AccessRequest $requestEntity): Response
    {
        return $this->render('list_request/show.html.twig', [
            'requestEntity' => $requestEntity,
        ]);
    }
}
