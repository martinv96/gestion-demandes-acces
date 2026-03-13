<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListRequestController extends AbstractController
{
    #[Route('/list/request', name: 'app_list_request')]
    public function index(): Response
    {
        return $this->render('list_request/index.html.twig', [
            'controller_name' => 'ListRequestController',
        ]);
    }
}
