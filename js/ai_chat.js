/**
 * Assistant IA - Chat Widget pour Acadenique
 * Fonctionne avec Ollama (local, open-source)
 */
(function () {
    'use strict';

    // Récupérer le token CSRF depuis le meta tag
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const AJAX_URL = 'ajax/chat_ai.php';

    let isOpen = false;
    let isSending = false;

    // =========================================
    // Construire le widget HTML
    // =========================================
    function buildWidget() {
        // Bouton flottant avec logo JadTech
        const fab = document.createElement('button');
        fab.id = 'ai-chat-fab';
        fab.title = 'JadBot — Assistant IA';
        fab.innerHTML = '<img src="img/logo jad-tech.png" alt="Logo JadTech" class="jadbot-fab-logo"><span class="badge-dot"></span>';
        fab.addEventListener('click', toggleChat);

        // Fenêtre de chat
        const win = document.createElement('div');
        win.id = 'ai-chat-window';
        win.innerHTML = `
            <div class="ai-chat-header">
                <div>
                    <div class="ai-chat-title">
                        <img src="img/logo jad-tech.png" alt="Logo JadTech" class="jadbot-avatar-img">
                        JadBot
                    </div>
                    <div class="ai-status">Assistant IA by JadTech</div>
                </div>
                <div class="ai-chat-header-actions">
                    <button id="ai-btn-clear" title="Nouvelle conversation">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button id="ai-btn-close" title="Fermer">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
            <div id="ai-chat-messages"></div>
            <div class="ai-chat-input-area">
                <textarea id="ai-chat-input" rows="1" placeholder="Posez votre question..."></textarea>
                <button id="ai-chat-send" title="Envoyer">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
            <div class="ai-chat-footer">
                Conçu par <strong>Ir Jean Marie IBANGA</strong> &bull; <span>JadTech</span>
            </div>
        `;

        document.body.appendChild(fab);
        document.body.appendChild(win);

        // Événements
        document.getElementById('ai-btn-close').addEventListener('click', toggleChat);
        document.getElementById('ai-btn-clear').addEventListener('click', clearChat);
        document.getElementById('ai-chat-send').addEventListener('click', sendMessage);
        document.getElementById('ai-chat-input').addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize du textarea
        document.getElementById('ai-chat-input').addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 80) + 'px';
        });

        // Afficher le message de bienvenue
        showWelcome();
    }

    // =========================================
    // Ouvrir / Fermer
    // =========================================
    function toggleChat() {
        const win = document.getElementById('ai-chat-window');
        const fab = document.getElementById('ai-chat-fab');
        isOpen = !isOpen;

        if (isOpen) {
            win.classList.add('open');
            fab.style.display = 'none';
            document.getElementById('ai-chat-input').focus();
        } else {
            win.classList.remove('open');
            fab.style.display = 'flex';
        }
    }

    // =========================================
    // Message de bienvenue
    // =========================================
    function showWelcome() {
        const container = document.getElementById('ai-chat-messages');
        container.innerHTML = `
            <div class="ai-welcome">
                <div class="jadbot-welcome-avatar-img"><img src="img/logo jad-tech.png" alt="Logo JadTech" style="width:56px;height:56px;border-radius:50%;box-shadow:0 2px 8px #22242922;"></div>
                <h6>Salut, je suis JadBot !</h6>
                <p>Votre assistant IA propulsé par <strong>JadTech</strong>.<br>Posez-moi vos questions sur Acadenique.</p>
                <div class="ai-suggestions">
                    <button class="ai-suggestion-btn" data-msg="Comment inscrire un nouvel étudiant ?">
                        📝 Comment inscrire un nouvel étudiant ?
                    </button>
                    <button class="ai-suggestion-btn" data-msg="Comment fonctionnent les délibérations LMD ?">
                        🎓 Comment fonctionnent les délibérations LMD ?
                    </button>
                    <button class="ai-suggestion-btn" data-msg="Comment imprimer un relevé de notes ?">
                        🖨️ Comment imprimer un relevé de notes ?
                    </button>
                    <button class="ai-suggestion-btn" data-msg="Expliquer le calcul des crédits et moyennes">
                        📊 Expliquer le calcul des crédits et moyennes
                    </button>
                </div>
            </div>
        `;

        // Attacher les événements aux suggestions
        container.querySelectorAll('.ai-suggestion-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('ai-chat-input').value = this.dataset.msg;
                sendMessage();
            });
        });
    }

    // =========================================
    // Envoyer un message
    // =========================================
    function sendMessage() {
        if (isSending) return;

        var input = document.getElementById('ai-chat-input');
        var msg = input.value.trim();
        if (!msg) return;

        // Nettoyer le message de bienvenue s'il est encore là
        var welcome = document.querySelector('.ai-welcome');
        if (welcome) welcome.remove();

        // Afficher le message utilisateur
        appendMessage(msg, 'user');
        input.value = '';
        input.style.height = 'auto';

        // Afficher l'indicateur de frappe
        showTyping();
        isSending = true;
        updateSendButton();

        // Appel AJAX
        fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: msg,
                csrf_token: CSRF_TOKEN
            })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            hideTyping();
            if (data.error) {
                appendMessage(data.error, 'system');
            } else if (data.response) {
                appendMessage(data.response, 'assistant');
            }
        })
        .catch(function () {
            hideTyping();
            appendMessage('⚠️ JadBot est temporairement indisponible. Vérifiez qu\'Ollama est démarré.', 'system');
        })
        .finally(function () {
            isSending = false;
            updateSendButton();
            input.focus();
        });
    }

    // =========================================
    // Nouvelle conversation
    // =========================================
    function clearChat() {
        fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: 'reset',
                action: 'clear',
                csrf_token: CSRF_TOKEN
            })
        }).catch(function () {});

        showWelcome();
    }

    // =========================================
    // Helpers d'affichage
    // =========================================
    function appendMessage(text, role) {
        var container = document.getElementById('ai-chat-messages');
        var div = document.createElement('div');
        div.className = 'ai-msg ' + role;
        if (role === 'assistant' || role === 'system') {
            div.innerHTML = renderMarkdown(text);
        } else {
            div.textContent = text;
        }
        container.appendChild(div);
        scrollToBottom();
    }

    // Parseur markdown simple pour gras, italique, monospace, listes, liens
    function renderMarkdown(text) {
        if (!text) return '';
        // Sécurité : échapper les balises HTML
        text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        // Gras **texte**
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Italique *texte*
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Monospace `texte`
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Liens [texte](url)
        text = text.replace(/\[([^\]]+)\]\(([^\)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
        // Listes à puces
        text = text.replace(/(^|\n)[\-\*] (.*?)(?=\n|$)/g, '$1<ul><li>$2</li></ul>');
        // Listes numérotées
        text = text.replace(/(^|\n)\d+\. (.*?)(?=\n|$)/g, '$1<ol><li>$2</li></ol>');
        // Sauts de ligne
        text = text.replace(/\n/g, '<br>');
        // Fusionner les <ul> et <ol> consécutifs
        text = text.replace(/(<\/ul>)(<ul>)/g, '');
        text = text.replace(/(<\/ol>)(<ol>)/g, '');
        return text;
    }

    function showTyping() {
        var container = document.getElementById('ai-chat-messages');
        var div = document.createElement('div');
        div.id = 'ai-typing-indicator';
        div.className = 'ai-msg assistant ai-typing';
        div.innerHTML = '<span></span><span></span><span></span>';
        container.appendChild(div);
        scrollToBottom();
    }

    function hideTyping() {
        var el = document.getElementById('ai-typing-indicator');
        if (el) el.remove();
    }

    function scrollToBottom() {
        var container = document.getElementById('ai-chat-messages');
        container.scrollTop = container.scrollHeight;
    }

    function updateSendButton() {
        var btn = document.getElementById('ai-chat-send');
        btn.disabled = isSending;
    }

    // =========================================
    // Initialisation au chargement de la page
    // =========================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildWidget);
    } else {
        buildWidget();
    }
})();
