class CreditCalculator {
    constructor(baseHeuresParCredit = 15) {
        this.baseHeuresParCredit = baseHeuresParCredit;
        this.multiplicateurs = {
            'th': 1,
            'td': 1.5,
            'tp': 2
        };
    }

    calculerHeuresTotal(inputs) {
        let totalHeures = 0;
        inputs.forEach(input => {
            const heures = parseFloat(input.value) || 0;
            const type = input.getAttribute('data-type');
            totalHeures += heures * this.multiplicateurs[type];
        });
        return totalHeures;
    }

    calculerCredits(heuresTotal) {
        return Math.ceil(heuresTotal / this.baseHeuresParCredit);
    }
}

class ProgrammeForm {
    constructor(formId, options = {}) {
        this.form = document.getElementById(formId);
        this.calculator = new CreditCalculator(options.baseHeuresParCredit);
        this.initializeForm();
    }

    initializeForm() {
        this.totalHeuresInput = this.form.querySelector('#total_heures');
        this.creditsInput = this.form.querySelector('#credits');
        this.heuresInputs = this.form.querySelectorAll('.heures-input');
        
        this.heuresInputs.forEach(input => {
            input.addEventListener('input', () => this.updateCalculations());
        });

        const recalculButton = this.form.querySelector('#recalculer_credits');
        if (recalculButton) {
            recalculButton.addEventListener('click', () => this.updateCalculations());
        }
    }

    updateCalculations() {
        const totalHeures = this.calculator.calculerHeuresTotal(this.heuresInputs);
        const credits = this.calculator.calculerCredits(totalHeures);

        if (this.totalHeuresInput) {
            this.totalHeuresInput.value = totalHeures.toFixed(1) + ' heures';
        }
        if (this.creditsInput) {
            this.creditsInput.value = credits;
        }
    }
}

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les formulaires
    const ueForm = new ProgrammeForm('addUEForm');
    const ecForm = new ProgrammeForm('addECForm');

    // Gestion de l'ID de l'UE pour le modal EC
    const addECModal = document.getElementById('addECModal');
    if (addECModal) {
        addECModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const ueId = button.getAttribute('data-ue-id');
            const modalIdUeInput = document.getElementById('modal_id_ue');
            if (modalIdUeInput) {
                modalIdUeInput.value = ueId;
            }
        });
    }
});
