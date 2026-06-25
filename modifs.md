possibles modifications à apporter:
préciser le numéro de bureau de l'agent dans la demande (si necessaire)

mail de rappel à l'approche de la date de fin de contrat

bloquer la modification une fois la demande validé

grossir le logo texte



----

ajouter possiblement des logiciels

peut etre ajouter des comptes non nominatifs (au cas ou quelqu'un soit absent)

```
{# 2. Type #}
<td>
    {% set type_mapping = {
        'ouverture': 'arrivée',
        'fermeture': 'départ'
    } %}
    <span class="badge border px-2.5 py-1.5 fw-medium text-capitalize my-requests-type-badge" style="font-size: 0.8rem; border-radius: 12px;">
        {{ type_mapping[req.type] | default(req.type) }}
    </span>
</td>
