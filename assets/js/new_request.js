document.addEventListener('DOMContentLoaded', function () {
    const requestForm = document.querySelector('.request-form[data-new-request="1"]');
    if (!requestForm) {
        return;
    }

    const ds = requestForm.dataset;

    const typeFieldName = ds.typeName || '';
    const typeRadios = typeFieldName ? requestForm.querySelectorAll(`input[name="${typeFieldName}"]`) : [];

    const logicielsCard = document.getElementById('logiciels-card');
    const materielCard = document.getElementById('materiel-card');
    const parentRequestInput = document.getElementById(ds.parentRequestId || '');
    const confirmParentRequestBtn = document.getElementById('confirmParentRequestBtn');
    const modalElement = document.getElementById('parentRequestModal');

    const fieldCivility = document.getElementById(ds.civilityId || '');
    const fieldFirstname = document.getElementById(ds.firstnameId || '');
    const fieldLastname = document.getElementById(ds.lastnameId || '');
    const fieldEmail = document.getElementById(ds.emailId || '');
    const fieldService = document.getElementById(ds.serviceId || '');
    const fieldJobTitle = document.getElementById(ds.jobTitleId || '');
    const fieldArrivalDate = document.getElementById(ds.arrivalDateId || '');
    const fieldDepartureDate = document.getElementById(ds.departureDateId || '');
    const fieldCommentary = document.getElementById(ds.commentaryId || '');
    const fieldClothingSize = document.getElementById(ds.clothingSizeId || '');
    const fieldShoeSize = document.getElementById(ds.shoeSizeId || '');

    const fieldPhoneTypeName = ds.phoneTypeName || '';
    const fieldPhoneTypeInputs = fieldPhoneTypeName
        ? requestForm.querySelectorAll(`input[name="${fieldPhoneTypeName}"]`)
        : [];
    const phoneTypeWrapper = document.getElementById('phone-type-wrapper');

    const fileInput = requestForm.querySelector('.custom-file-input');
    const fileNameLabel = document.getElementById('file-name');

    const parentModal = (window.bootstrap && modalElement)
        ? new window.bootstrap.Modal(modalElement)
        : null;

    function splitCsv(value) {
        if (!value) {
            return [];
        }

        return value.split(',').map(function (v) {
            return v.trim();
        }).filter(Boolean);
    }

    function getSelectedParentOption() {
        if (!parentRequestInput) {
            return null;
        }

        const option = parentRequestInput.options[parentRequestInput.selectedIndex];
        return (option && option.value) ? option : null;
    }

    function setCardBodyOpacity(card, disabled) {
        if (!card) {
            return;
        }

        const body = card.querySelector('.card-body');
        if (body) {
            body.classList.toggle('opacity-50', disabled);
        }
    }

    function setResourceCheckboxes(card, selectedIds, disabled) {
        if (!card) {
            return;
        }

        card.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.checked = selectedIds.includes(checkbox.value);
            checkbox.disabled = disabled;
        });

        setCardBodyOpacity(card, disabled);
        updatePhoneTypeVisibility();
    }

    function normalizeLabel(value) {
        return (value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    function findTelephoneCheckbox() {
        if (!materielCard) {
            return null;
        }

        const checkboxes = materielCard.querySelectorAll('input[type="checkbox"]');
        for (const checkbox of checkboxes) {
            const label = materielCard.querySelector(`label[for="${checkbox.id}"]`);
            const labelText = normalizeLabel(label ? label.textContent : '');

            if (labelText.includes('telephone')) {
                return checkbox;
            }
        }

        return null;
    }

    function updatePhoneTypeVisibility() {
        if (!phoneTypeWrapper) {
            return;
        }

        const telephoneCheckbox = findTelephoneCheckbox();
        const hasTelephone = Boolean(telephoneCheckbox && telephoneCheckbox.checked);

        phoneTypeWrapper.classList.toggle('d-none', !hasTelephone);

        if (!hasTelephone) {
            fieldPhoneTypeInputs.forEach(function (input) {
                input.checked = false;
                input.disabled = false;
            });
            return;
        }

        const shouldDisable = Boolean(telephoneCheckbox && telephoneCheckbox.disabled);
        fieldPhoneTypeInputs.forEach(function (input) {
            input.disabled = shouldDisable;
        });
    }

    function setFieldOpacity(field, disabled) {
        if (!field) {
            return;
        }

        const col = field.closest('.col-md-6') || field.closest('.col-12') || field.parentElement;
        if (col) {
            col.classList.toggle('opacity-50', disabled);
        }
    }

    function disableAgentSection(disabled) {
        [
            fieldCivility,
            fieldFirstname,
            fieldLastname,
            fieldEmail,
            fieldService,
            fieldJobTitle,
            fieldArrivalDate,
            fieldClothingSize,
            fieldShoeSize
        ].forEach(function (el) {
            if (el) {
                el.disabled = disabled;
                setFieldOpacity(el, disabled);
            }
        });

        if (fieldCommentary) {
            fieldCommentary.disabled = disabled;
            setCardBodyOpacity(fieldCommentary.closest('.card'), disabled);
        }
    }

    function prefillFromParent() {
        const option = getSelectedParentOption();
        if (!option) {
            return null;
        }

        const d = option.dataset;

        if (fieldCivility) fieldCivility.value = d.civility || '';
        if (fieldFirstname) fieldFirstname.value = d.firstname || '';
        if (fieldLastname) fieldLastname.value = d.lastname || '';
        if (fieldEmail) fieldEmail.value = d.email || '';
        if (fieldService) fieldService.value = d.serviceId || '';
        if (fieldJobTitle) fieldJobTitle.value = d.jobTitle || '';
        if (fieldClothingSize) fieldClothingSize.value = d.clothingSize || '';
        if (fieldShoeSize) fieldShoeSize.value = d.shoeSize || '';
        if (fieldArrivalDate) fieldArrivalDate.value = d.arrivalDateInput || '';
        if (fieldDepartureDate) fieldDepartureDate.value = d.departureDateInput || '';
        if (fieldCommentary) fieldCommentary.value = d.commentary || '';

        if (d.phoneTypes) {
            const selectedPhoneTypes = splitCsv(d.phoneTypes);
            fieldPhoneTypeInputs.forEach(function (input) {
                input.checked = selectedPhoneTypes.includes(input.value);
            });
        }

        updatePhoneTypeVisibility();

        return {
            logicielIds: splitCsv(d.logicielIds),
            materielIds: splitCsv(d.materielIds)
        };
    }

    function resetToEditable() {
        disableAgentSection(false);

        [logicielsCard, materielCard].forEach(function (card) {
            if (!card) {
                return;
            }

            card.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
                checkbox.disabled = false;
            });

            setCardBodyOpacity(card, false);
        });

        updatePhoneTypeVisibility();
    }

    function applyTypeUi(typeValue) {
        const isFermeture = typeValue === 'fermeture';
        const needsParent = isFermeture || typeValue === 'modification';

        if (parentRequestInput) {
            parentRequestInput.required = needsParent;
        }

        if (!needsParent) {
            resetToEditable();
            return;
        }

        if (!getSelectedParentOption()) {
            if (parentModal) {
                parentModal.show();
            }
            return;
        }

        const parentData = prefillFromParent();

        if (isFermeture) {
            disableAgentSection(true);

            if (fieldDepartureDate) {
                fieldDepartureDate.disabled = false;
            }

            if (fieldCommentary) {
                fieldCommentary.disabled = false;
                setCardBodyOpacity(fieldCommentary.closest('.card'), false);
            }

            setResourceCheckboxes(logicielsCard, parentData ? parentData.logicielIds : [], true);
            setResourceCheckboxes(materielCard, parentData ? parentData.materielIds : [], true);
        } else {
            disableAgentSection(false);

            if (parentData) {
                setResourceCheckboxes(logicielsCard, parentData.logicielIds, false);
                setResourceCheckboxes(materielCard, parentData.materielIds, false);
            }
        }
    }

    requestForm.addEventListener('submit', function () {
        requestForm.querySelectorAll('input:disabled, select:disabled, textarea:disabled').forEach(function (el) {
            el.disabled = false;
        });
    });

    typeRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            applyTypeUi(this.value);
        });
    });

    if (parentRequestInput) {
        parentRequestInput.addEventListener('change', function () {
            const checked = requestForm.querySelector(`input[name="${typeFieldName}"]:checked`);
            if (checked) {
                applyTypeUi(checked.value);
            }
        });
    }

    if (materielCard) {
        materielCard.addEventListener('change', function (event) {
            const target = event.target;
            if (target && target.matches && target.matches('input[type="checkbox"]')) {
                updatePhoneTypeVisibility();
            }
        });
    }

    if (confirmParentRequestBtn) {
        confirmParentRequestBtn.addEventListener('click', function () {
            const checked = requestForm.querySelector(`input[name="${typeFieldName}"]:checked`);
            if (checked) {
                applyTypeUi(checked.value);
            }
        });
    }

    if (fileInput && fileNameLabel) {
        fileInput.addEventListener('change', function () {
            fileNameLabel.textContent = fileInput.files[0]
                ? fileInput.files[0].name
                : 'Aucun fichier sélectionné';
        });
    }

    const checkedRadio = requestForm.querySelector(`input[name="${typeFieldName}"]:checked`);
    applyTypeUi(checkedRadio ? checkedRadio.value : 'ouverture');
    updatePhoneTypeVisibility();
});