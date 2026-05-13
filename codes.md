{# accès matériel #}
<div class="card request-form-card mb-4" id="materiel-card">
    <div class="card-header">Matériel</div>
    <div class="card-body">
        {# Section visible uniquement pour le service technique #}
        {% if is_granted('ROLE_ST') %}
            <h6 class="mb-2">Service technique (EPI, badge, jeu de clés)</h6>
            <div class="request-checkbox-grid mb-3">
                {% for child in form.materiels %}
                    {% set materialName = (child.vars.label ?? '')|lower %}
                    {% set isStMaterial = 'badge' in materialName
                        or 'clé' in materialName
                        or 'cle' in materialName
                        or 'casque' in materialName
                        or 'gilet' in materialName
                        or 'chaussure' in materialName
                        or 'pantalon' in materialName
                        or 'veste' in materialName
                        or 'gant' in materialName
                        or 'lunette' in materialName
                        or 'harnais' in materialName
                        or 'masque' in materialName
                        or 'protection' in materialName %}
                    {% if isStMaterial %}
                        {{ form_row(child) }}
                    {% endif %}
                {% endfor %}
            </div>
        {% endif %}

        <h6 class="mb-2">Autres matériels</h6>
        <div class="request-checkbox-grid">
            {% for child in form.materiels %}
                {% set materialName = (child.vars.label ?? '')|lower %}
                {% set isStMaterial = 'badge' in materialName
                    or 'clé' in materialName
                    or 'cle' in materialName
                    or 'casque' in materialName
                    or 'gilet' in materialName
                    or 'chaussure' in materialName
                    or 'pantalon' in materialName
                    or 'veste' in materialName
                    or 'gant' in materialName
                    or 'lunette' in materialName
                    or 'harnais' in materialName
                    or 'masque' in materialName
                    or 'protection' in materialName %}
                {% if not isStMaterial %}
                    {{ form_row(child) }}
                {% endif %}
            {% endfor %}
        </div>
        <div class="field-errors">{{ form_errors(form.materiels) }}</div>
    </div>
</div>