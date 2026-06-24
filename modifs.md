possibles modifications à apporter:
préciser le numéro de bureau de l'agent dans la demande (si necessaire)

mail de rappel à l'approche de la date de fin de contrat

bloquer la modification une fois la demande validé

grossir le logo texte



----

ajouter possiblement des logiciels

peut etre ajouter des comptes non nominatifs (au cas ou quelqu'un soit absent)


suppression :

dans listRequestController, nouvelle methode :

#[Route('/request/{id}/delete', name: 'app_request_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
public function deleteRequest(
    AccessRequest $requestEntity,
    Request $httpRequest,
    EntityManagerInterface $entityManager
): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    // Autorise seulement ADMIN ou RH
    if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_RH')) {
        throw $this->createAccessDeniedException('Vous n\'avez pas les droits pour supprimer cette demande.');
    }

    // Protection CSRF
    if (!$this->isCsrfTokenValid('request_delete_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
        $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');
        return $this->redirectToRoute('app_my_requests');
    }

    try {
        $entityManager->remove($requestEntity);
        $entityManager->flush();

        $this->addRequestFlash($httpRequest, 'success', 'La demande a bien été supprimée.');
    } catch (\Throwable $e) {
        $this->addRequestFlash($httpRequest, 'danger', 'Suppression impossible pour le moment.');
    }

    return $this->redirectToRoute('app_my_requests');
}

```
dans la vue, 

à la place de :
<td class="pe-4 text-end">
    <a href="{{ path('app_request_show', {'id': req.id}) }}" class="btn btn-sm btn-outline-secondary border fw-medium px-2.5 py-1.5 shadow-sm my-requests-detail-btn" style="border-radius: 6px; font-size: 0.8rem;">
        <i class="far fa-eye me-1"></i> Voir le détail
    </a>
</td>

ce code : 
<td class="pe-4 text-end">
    <div class="d-inline-flex gap-2">
        <a href="{{ path('app_request_show', {'id': req.id}) }}" class="btn btn-sm btn-outline-secondary border fw-medium px-2.5 py-1.5 shadow-sm my-requests-detail-btn" style="border-radius: 6px; font-size: 0.8rem;">
            <i class="far fa-eye me-1"></i> Voir le détail
        </a>

        {% if is_granted('ROLE_ADMIN') or is_granted('ROLE_RH') %}
            <form method="post"
                  action="{{ path('app_request_delete', {'id': req.id}) }}"
                  class="d-inline"
                  onsubmit="return confirm('Confirmer la suppression de cette demande ?');">
                <input type="hidden" name="_token" value="{{ csrf_token('request_delete_' ~ req.id) }}">
                <button type="submit" class="btn btn-sm btn-outline-danger fw-medium px-2.5 py-1.5 shadow-sm" style="border-radius: 6px; font-size: 0.8rem;">
                    <i class="far fa-trash-alt me-1"></i> Supprimer
                </button>
            </form>
        {% endif %}
    </div>
</td>


