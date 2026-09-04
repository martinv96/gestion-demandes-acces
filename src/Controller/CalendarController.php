<?php

namespace App\Controller;

use App\Repository\RequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Affiche le calendrier et fournit ses évènements au format JSON.
 */
class CalendarController extends AbstractController
{
    #[Route('/calendar', name: 'app_calendar')]
    public function index(): Response
    {
        // Le calendrier est disponible pour tout utilisateur connecté.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // La vue charge ensuite les évènements par l'URL /calendar/events.
        return $this->render('calendar/calendar.html.twig');
    }

    #[Route('/calendar/events', name: 'app_calendar_events', methods: ['GET'])]
    public function getEvents(RequestRepository $requestRepository, Request $request): JsonResponse
    {
        // Cette route est appelée par JavaScript ; elle ne rend pas de template Twig.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $startStr = $request->query->get('start');
        $endStr = $request->query->get('end');

        // FullCalendar transmet la période visible. Sans paramètres, le mois courant est utilisé.
        $startDate = $startStr ? new \DateTime($startStr) : new \DateTime('first day of this month');
        $endDate = $endStr ? new \DateTime($endStr) : new \DateTime('last day of this month');

        // Le repository récupère les demandes ayant une arrivée ou un départ dans cette période.
        $requests = $requestRepository->findRequestsBetweenDates($startDate, $endDate);

        $events = [];
        foreach ($requests as $req) {
            $agent = $req->getAgent();
            // Une demande sans agent ne peut pas être représentée clairement dans le calendrier.
            if (!$agent) {
                continue;
            }

            $agentName = trim($agent->getFirstname() . ' ' . $agent->getLastname());

            // "N/C" est le libellé de secours lorsqu'aucun service n'est renseigné.
            $serviceName ="N/C";
            if ($agent->getService()) {
                $serviceName = $agent->getService()->getName();
            }

            // Une même demande peut créer un évènement d'arrivée et un évènement de départ.
            if ($req->getArrivalDate()) {
                $events[] = [
                    'id' => 'arrival' . $req->getId(),
                    'title' => $agentName,
                    'start' => $req->getArrivalDate()->format('Y-m-d'),
                    'className' => 'event-arrival',
                    // extendedProps est lu par le script du calendrier pour afficher le détail et le lien.
                    'extendedProps' => [
                        'type' => 'arrivée',
                        'agent' => $agentName,
                        'service' => $serviceName,
                        'urlShow' => $this->generateUrl('app_request_show', ['id' => $req->getId()])
                    ]
                ];
            }

            // Le préfixe différent garantit un identifiant unique lorsqu'arrivée et départ partagent la même demande.
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

        // La réponse est consommée directement par FullCalendar.
        return new JsonResponse($events);
    }
}
