document.addEventListener('DOMContentLoaded', function() {
    // Gestion de la sélection de l'année académique
    const selectYear = document.getElementById('select-year');
    if (selectYear) {
        selectYear.addEventListener('change', function() {
            const yearId = this.value;
            if (yearId) {
                window.location.href = `index.php?page=configuration_academique&year=${yearId}`;
            } else {
                window.location.href = 'index.php?page=configuration_academique';
            }
        });
    }

    // Gestion de la sélection du domaine
    const selectDomain = document.getElementById('select-domain');
    if (selectDomain) {
        selectDomain.addEventListener('change', function() {
            const domainId = this.value;
            const yearId = selectYear.value;
            if (domainId) {
                window.location.href = `index.php?page=configuration_academique&year=${yearId}&domain=${domainId}`;
            } else {
                window.location.href = `index.php?page=configuration_academique&year=${yearId}`;
            }
        });
    }

    // Animation des cartes
    document.querySelectorAll('.card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.transition = 'transform 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Gestion des modals
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('show.bs.modal', function() {
            const form = this.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    });

    // Gestion de la navigation progressive
    const updateNavigationState = () => {
        const steps = document.querySelectorAll('.config-step');
        let activeFound = false;

        steps.forEach(step => {
            const select = step.querySelector('select');
            if (select) {
                if (!activeFound && !select.value) {
                    activeFound = true;
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            }
        });
    };

    // Appel initial
    updateNavigationState();
});
