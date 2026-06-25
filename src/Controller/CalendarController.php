<?php

namespace App\Controller;

use App\Repository\RequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends AbstractController
{
    #[Route('/calendar', name: 'app_calendar')]
    public function index(): Response
    {
        //pour tous les users
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        return $this->render('calendar/calendar.html.twig');
    }

    #[Route('/calendar/events', name: 'app_calendar_events', methods: ['GET'])]
    public function getEvents(RequestRepository $requestRepository, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $startStr = $request->query->get('start');
        $endStr = $request->query->get('end');

        $startDate = $startStr ? new \DateTime($startStr) : new \DateTime('first day of this month');
        $endDate = $endStr ? new \DateTime($endStr) : new \DateTime('last day of this month');

        $requests = $requestRepository->findRequestsBetweenDates($startDate, $endDate);

        $events = [];
        foreach ($requests as $req) {
            $agent = $req->getAgent();
            if (!$agent) {
                continue;
            }

            $agentName = trim($agent->getFirstname() . ' ' . $agent->getLastname());

            $serviceName ="N/C";
            if ($agent->getService()) {
                $serviceName = $agent->getService()->getName();
            }

            //evenement d'arrivée
            if ($req->getArrivalDate()) {
                $events[] = [
                    'id' => 'arrival' . $req->getId(),
                    'title' => $agentName,
                    'start' => $req->getArrivalDate()->format('Y-m-d'),
                    'className' => 'event-arrival',
                    'extendedProps' => [
                        'type' => 'arrivée',
                        'agent' => $agentName,
                        'service' => $serviceName,
                        'urlShow' => $this->generateUrl('app_request_show', ['id' => $req->getId()])
                    ]
                ];
            }

            //évènement départ
            if ($req->getDepartureDate()) {
                $events[] = [
                    'id' => 'departure_' . $req->getId(),
                    'title' => $agentName,
                    'start' => $req->getDepartureDate()->format('Y-m-d'),
                    'className' => 'event-departure',
                    'extendedProps' => [
                        'type' => 'départ',
                        'agent' => $agentName,
                        'service' => $serviceName,
                        'urlShow' => $this->generateUrl('app_request_show', ['id' => $req->getId()])
                    ]
                ];
            }
        }

        return new JsonResponse($events);
    }
}
