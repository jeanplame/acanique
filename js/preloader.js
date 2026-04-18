/**
 * ACANIQUE – Preloader Engine
 * Gère l'écran de chargement post-login avec progression réelle.
 */
(function () {
    'use strict';

    var overlay  = document.getElementById('preloader-overlay');
    if (!overlay) return;

    var bar      = overlay.querySelector('.preloader-progress-bar');
    var status   = overlay.querySelector('.preloader-status');
    var pctEl    = overlay.querySelector('.preloader-percent');
    var welcome  = overlay.querySelector('.preloader-welcome');

    var progress = 0;
    var tasks    = [
        { label: 'Initialisation de la session…',            weight: 10 },
        { label: 'Chargement des paramètres système…',       weight: 15 },
        { label: 'Récupération de l\'année académique…',     weight: 15 },
        { label: 'Chargement des domaines et filières…',     weight: 20 },
        { label: 'Préparation du tableau de bord…',          weight: 15 },
        { label: 'Chargement des ressources graphiques…',    weight: 15 },
        { label: 'Finalisation…',                            weight: 10 }
    ];

    var totalWeight = tasks.reduce(function (s, t) { return s + t.weight; }, 0);
    var taskIndex   = 0;

    function setProgress(pct) {
        pct = Math.min(100, Math.max(0, pct));
        progress = pct;
        bar.style.width = pct + '%';
        pctEl.textContent = Math.round(pct) + '%';
    }

    function setStatus(text) {
        status.classList.add('changing');
        setTimeout(function () {
            status.textContent = text;
            status.classList.remove('changing');
        }, 200);
    }

    function runNextTask() {
        if (taskIndex >= tasks.length) {
            finish();
            return;
        }

        var task = tasks[taskIndex];
        setStatus(task.label);

        // Simuler la progression granulaire pour ce task
        var target = progress + (task.weight / totalWeight) * 100;
        var steps  = 5 + Math.floor(Math.random() * 4);
        var step   = 0;
        var delay  = (200 + Math.random() * 300) / steps;

        function tick() {
            step++;
            var ratio = step / steps;
            // ease-out
            var eased = 1 - Math.pow(1 - ratio, 2);
            setProgress(progress + (target - progress) * eased);

            if (step < steps) {
                setTimeout(tick, delay + Math.random() * 60);
            } else {
                setProgress(target);
                taskIndex++;
                setTimeout(runNextTask, 100 + Math.random() * 150);
            }
        }

        setTimeout(tick, delay);
    }

    function finish() {
        setProgress(100);
        setStatus('Prêt !');

        if (welcome) {
            setTimeout(function () {
                welcome.classList.add('show');
            }, 300);
        }

        // Attendre un instant pour montrer 100%, puis disparaître
        setTimeout(function () {
            overlay.classList.add('fade-out');
            // Retirer du DOM après la transition
            setTimeout(function () {
                overlay.remove();
                // Stocker dans sessionStorage pour ne pas rejouer
                try { sessionStorage.setItem('acanique_loaded', '1'); } catch (e) {}
            }, 700);
        }, 900);
    }

    // Vérifier si on revient d'un refresh normal (pas un login frais)
    try {
        if (sessionStorage.getItem('acanique_loaded') === '1' && !overlay.dataset.force) {
            overlay.remove();
            return;
        }
    } catch (e) {}

    // Attendre que les ressources critiques commencent à charger
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(runNextTask, 200);
        });
    } else {
        setTimeout(runNextTask, 200);
    }
})();
