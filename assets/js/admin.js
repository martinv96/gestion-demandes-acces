document.addEventListener('DOMContentLoaded', function () {
    
    // 1. GESTION DES ONGLETS (SUBTITLES)
    const adminTabs = document.getElementById('adminTabs');
    const adminSubtitle = document.getElementById('adminSubtitle');

    const subtitles = {
        '#tab-users': 'Gestion des comptes',
        '#tab-services': 'Gestion des services',
        '#tab-logiciels': 'Gestion des logiciels',
        '#tab-audits': 'Audit des connexions'
    };

    if (adminTabs && adminSubtitle) {
        adminTabs.addEventListener('shown.bs.tab', function (event) {
            const target = event.target.getAttribute('data-bs-target');
            if (subtitles[target]) {
                adminSubtitle.textContent = subtitles[target];
            }
        });
    }

    // 2. MODAL DE CONFIRMATION DE SUPPRESSION
    const confirmModalElement = document.getElementById('deleteConfirmModal');
    const confirmModalTitle = document.getElementById('deleteConfirmModalLabel');
    const confirmModalMessage = document.getElementById('deleteConfirmModalMessage');
    const confirmSubmitButton = document.getElementById('deleteConfirmSubmitBtn');
    const deleteTriggers = document.querySelectorAll('.js-delete-trigger');

    // On remplace le 'return' par une condition globale
    if (confirmModalElement && confirmSubmitButton && deleteTriggers.length > 0) {
        
        const confirmModal = new bootstrap.Modal(confirmModalElement);
        let formToSubmit = null;

        deleteTriggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const formId = trigger.dataset.formId;
                formToSubmit = formId ? document.getElementById(formId) : null;

                if (!formToSubmit) return; // Ici le return est valide car on est dans une fonction de callback !

                const title = trigger.dataset.deleteTitle || 'Confirmer la suppression';
                const message = trigger.dataset.deleteMessage || 'Cette action est irréversible.';
                const confirmLabel = trigger.dataset.deleteConfirmLabel || 'Supprimer';
                const btnClass = trigger.dataset.deleteBtnClass || 'btn btn-danger';

                confirmModalTitle.textContent = title;
                confirmModalMessage.innerHTML = message;
                confirmSubmitButton.textContent = confirmLabel;
                confirmSubmitButton.className = btnClass;

                confirmModal.show();
            });
        });

        confirmSubmitButton.addEventListener('click', function () {
            if (formToSubmit) {
                formToSubmit.submit();
            }
        });
    }

    // 3. OUVERTURE DES AUTRES MODALS GENERIQUES
    const modalTriggers = document.querySelectorAll('.js-open-modal');
    modalTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const modalId = trigger.dataset.openModalId;
            if (!modalId) return;

            const modalElement = document.getElementById(modalId);
            if (!modalElement) return;

            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        });
    });
});