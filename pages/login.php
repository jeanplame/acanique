<!-- Page de connexion -->
<style>
    /* Styles personnalisés pour la page de connexion */
    .login-container-modern {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    
    .login-card {
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(25px);
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 20px;
        box-shadow: 
            0 20px 60px rgba(0, 0, 0, 0.12),
            0 0 0 1px rgba(255, 255, 255, 0.15),
            inset 0 1px 0 rgba(255, 255, 255, 0.7);
        overflow: hidden;
        max-width: 850px;
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        animation: slideInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1), cardPulse 4s ease-in-out infinite;
        position: relative;
    }
    
    @keyframes cardPulse {
        0%, 100% {
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.12),
                0 0 0 1px rgba(255, 255, 255, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.7);
        }
        50% {
            box-shadow: 
                0 25px 70px rgba(102, 126, 234, 0.18),
                0 0 0 2px rgba(102, 126, 234, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }
    }
    
    .login-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #667eea, #764ba2, #667eea);
        background-size: 200% 100%;
        animation: gradientShift 3s ease-in-out infinite;
    }
    
    @keyframes gradientShift {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
    
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .login-form-section {
        padding: 40px 35px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,252,0.8) 100%);
        position: relative;
    }
    
    .login-form-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: 
            radial-gradient(circle at 20% 50%, rgba(102, 126, 234, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 80% 20%, rgba(118, 75, 162, 0.05) 0%, transparent 50%);
        pointer-events: none;
    }
    
    .login-visual-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    
    .login-visual-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('images/bg-01.png') center/cover;
        opacity: 0.3;
    }
    
    .logo-container {
        text-align: center;
        margin-bottom: 25px;
        position: relative;
    }
    
    .logo-wrapper {
        display: inline-block;
        padding: 18px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
        border-radius: 18px;
        box-shadow: 
            0 10px 25px rgba(102, 126, 234, 0.4),
            inset 0 2px 8px rgba(255,255,255,0.2);
        margin-bottom: 15px;
        position: relative;
        overflow: hidden;
        border: 2px solid rgba(255,255,255,0.3);
        backdrop-filter: blur(10px);
    }
    
    .logo-wrapper::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shimmer 2s infinite;
    }
    
    @keyframes shimmer {
        0% { left: -100%; }
        100% { left: 100%; }
    }
    
    .logo-wrapper::after {
        content: '';
        position: absolute;
        top: -2px;
        left: -2px;
        right: -2px;
        bottom: -2px;
        background: conic-gradient(from 0deg, #667eea, #764ba2, #f093fb, #667eea);
        border-radius: 18px;
        z-index: -1;
        animation: rotate 3s linear infinite;
    }
    
    @keyframes rotate {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .logo-container img {
        height: 50px;
        width: auto;
        filter: brightness(0) invert(1) drop-shadow(0 2px 8px rgba(0,0,0,0.3));
        position: relative;
        z-index: 2;
        transition: all 0.3s ease;
    }
    
    .logo-wrapper:hover img {
        transform: scale(1.05);
        filter: brightness(0) invert(1) drop-shadow(0 4px 12px rgba(0,0,0,0.4));
    }
    
    .brand-text {
        font-size: 20px;
        font-weight: 800;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-top: 10px;
        letter-spacing: 2px;
        text-transform: uppercase;
        text-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        animation: glow 2s ease-in-out infinite alternate;
    }
    
    @keyframes glow {
        from {
            filter: drop-shadow(0 0 5px rgba(102, 126, 234, 0.5));
        }
        to {
            filter: drop-shadow(0 0 20px rgba(118, 75, 162, 0.7));
        }
    }
    
    .login-title {
        font-size: 24px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 8px;
        text-align: center;
    }
    
    .login-subtitle {
        color: #7f8c8d;
        text-align: center;
        margin-bottom: 30px;
        font-size: 14px;
    }
    
    .modern-input-group {
        position: relative;
        margin-bottom: 20px;
    }
    
    .modern-input {
        width: 100%;
        padding: 12px 18px 12px 45px;
        border: 2px solid #e1e8ed;
        border-radius: 10px;
        font-size: 15px;
        transition: all 0.3s ease;
        background: #f8f9fa;
    }
    
    .modern-input:focus {
        outline: none;
        border-color: #667eea;
        background: white;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .input-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #7f8c8d;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .modern-input:focus + .input-icon {
        color: #667eea;
    }
    
    .modern-btn {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        border-radius: 10px;
        color: white;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .modern-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
    }
    
    .modern-btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }
    
    .btn-spinner {
        display: none;
        margin-right: 8px;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    .modern-checkbox {
        display: flex;
        align-items: center;
        margin: 18px 0;
    }
    
    .modern-checkbox input {
        margin-right: 10px;
        transform: scale(1.2);
    }
    
    .forgot-password {
        color: #667eea;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }
    
    .forgot-password:hover {
        color: #764ba2;
        text-decoration: underline;
    }
    
    .error-alert {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        color: white;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        border: none;
        display: flex;
        align-items: center;
        animation: shake 0.5s ease-in-out;
    }
    
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    
    .visual-content {
        text-align: center;
        color: white;
        z-index: 2;
        position: relative;
    }
    
    .visual-content h2 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 20px;
        text-shadow: 0 2px 10px rgba(0,0,0,0.3);
    }
    
    .visual-content p {
        font-size: 1.1rem;
        opacity: 0.9;
        line-height: 1.6;
    }
    
    @media (max-width: 768px) {
        .login-card {
            grid-template-columns: 1fr;
            margin: 20px;
        }
        
        .login-visual-section {
            min-height: 200px;
        }
        
        .login-form-section {
            padding: 40px 30px;
        }
    }
</style>

<div class="login-container-modern">
    <div class="login-card">
        <div class="login-form-section">
            <form class="modern-login-form" method="POST" action="?page=login" id="loginForm">
                <!-- Logo et titre -->
                <div class="logo-container">
                    
                    <h1 class="login-title">Connexion ACANIQUE</h1>
                    <p class="login-subtitle">Accédez à votre espace académique</p>
                </div>

                <!-- Token CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                <!-- Messages d'erreur -->
                <?php if (isset($error)): ?>
                    <div class="error-alert" role="alert">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 10px; font-size: 18px;"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Champ nom d'utilisateur -->
                <div class="modern-input-group">
                    <input type="text" name="username" class="modern-input" placeholder="Nom d'utilisateur" 
                           required minlength="3" maxlength="25" autocomplete="username" id="usernameInput">
                    <i class="bi bi-person-fill input-icon"></i>
                </div>

                <!-- Champ mot de passe -->
                <div class="modern-input-group">
                    <input type="password" name="pass" class="modern-input" placeholder="Mot de passe" 
                           required minlength="4" autocomplete="current-password" id="passwordInput">
                    <i class="bi bi-lock-fill input-icon"></i>
                </div>

                <!-- Options et liens -->
                <div class="d-flex justify-content-between align-items-center" style="margin: 25px 0;">
                    <div class="modern-checkbox" style="position: relative;">
                        <input type="checkbox" name="remember-me" id="rememberMe" style="accent-color: #667eea;">
                        <label for="rememberMe" style="color: #7f8c8d; font-size: 14px; cursor: pointer;">
                            Se souvenir de moi
                            <i class="bi bi-info-circle-fill" style="margin-left: 5px; color: #667eea; font-size: 12px;" 
                               title="Vous resterez connecté pendant 30 jours sur cet appareil"
                               data-toggle="tooltip" data-placement="top"></i>
                        </label>
                    </div>

                    <a href="?page=forgot-password" class="forgot-password">
                        Mot de passe oublié ?
                    </a>
                </div>

                <!-- Bouton de connexion -->
                <button type="submit" class="modern-btn" id="loginButton">
                    <i class="bi bi-arrow-clockwise btn-spinner" id="loadingSpinner"></i>
                    <span id="buttonText">Se connecter</span>
                </button>
            </form>
        </div>
        
        <!-- Section visuelle -->
        <div class="login-visual-section">
            <div class="visual-content">
                <div class="logo">
                    <img src="img/logo_acanique.png" alt="ACANIQUE" onerror="this.style.display='none'" width="300">
                </div>
                <br>
                <br>
                <div class="logo-description">
                    <h2>Bienvenue sur ACANIQUE</h2>
                    <p class="logo-subtitle text-white">Votre plateforme de gestion académique moderne et intuitive</p>
                </div>
                <div style="margin-top: 30px;">
                    <div style="display: inline-block; margin: 0 15px;">
                        <i class="bi bi-mortarboard-fill" style="font-size: 2rem; opacity: 0.8;"></i>
                    </div>
                    <div style="display: inline-block; margin: 0 15px;">
                        <i class="bi bi-book-fill" style="font-size: 2rem; opacity: 0.8;"></i>
                    </div>
                    <div style="display: inline-block; margin: 0 15px;">
                        <i class="bi bi-people-fill" style="font-size: 2rem; opacity: 0.8;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('loginForm');
    const loginButton = document.getElementById('loginButton');
    const buttonText = document.getElementById('buttonText');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const usernameInput = document.getElementById('usernameInput');
    const passwordInput = document.getElementById('passwordInput');

    // Auto-focus sur le premier champ
    if (usernameInput) {
        usernameInput.focus();
    }

    // Validation en temps réel
    function validateInput(input, minLength = 1) {
        const isValid = input.value.trim().length >= minLength;
        input.style.borderColor = isValid ? '#28a745' : '#dc3545';
        return isValid;
    }

    usernameInput.addEventListener('blur', function() {
        validateInput(this, 3);
    });

    passwordInput.addEventListener('blur', function() {
        validateInput(this, 4);
    });

    // Animation des icônes lors du focus
    const inputs = document.querySelectorAll('.modern-input');
    inputs.forEach(function(input) {
        input.addEventListener('focus', function() {
            this.parentElement.querySelector('.input-icon').style.transform = 'translateY(-50%) scale(1.1)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.querySelector('.input-icon').style.transform = 'translateY(-50%) scale(1)';
        });
    });

    // Gestion de la soumission du formulaire
    form.addEventListener('submit', function(e) {
        // Validation finale
        const isUsernameValid = validateInput(usernameInput, 3);
        const isPasswordValid = validateInput(passwordInput, 4);

        if (!isUsernameValid || !isPasswordValid) {
            e.preventDefault();
            
            // Animation d'erreur
            if (!isUsernameValid) {
                usernameInput.style.animation = 'shake 0.5s ease-in-out';
                setTimeout(() => usernameInput.style.animation = '', 500);
            }
            if (!isPasswordValid) {
                passwordInput.style.animation = 'shake 0.5s ease-in-out';
                setTimeout(() => passwordInput.style.animation = '', 500);
            }
            
            return false;
        }

        // État de chargement
        loginButton.disabled = true;
        loadingSpinner.style.display = 'inline-block';
        buttonText.textContent = 'Connexion en cours...';
        
        // Timeout de sécurité (si la requête prend trop de temps)
        setTimeout(function() {
            loginButton.disabled = false;
            loadingSpinner.style.display = 'none';
            buttonText.textContent = 'Se connecter';
        }, 10000);
    });

    // Gestion de la touche Entrée
    inputs.forEach(function(input) {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (input === usernameInput) {
                    passwordInput.focus();
                } else if (input === passwordInput) {
                    form.requestSubmit();
                }
            }
        });
    });

    // Gestion du tooltip pour "Se souvenir de moi"
    const rememberCheckbox = document.getElementById('rememberMe');
    const infoIcon = document.querySelector('[data-toggle="tooltip"]');
    
    if (infoIcon) {
        let tooltipTimeout;
        
        infoIcon.addEventListener('mouseenter', function() {
            clearTimeout(tooltipTimeout);
            const tooltip = document.createElement('div');
            tooltip.className = 'remember-tooltip';
            tooltip.innerHTML = 'Vous resterez connecté pendant 30 jours sur cet appareil';
            tooltip.style.cssText = `
                position: absolute;
                bottom: 25px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(102, 126, 234, 0.95);
                color: white;
                padding: 8px 12px;
                border-radius: 6px;
                font-size: 12px;
                white-space: nowrap;
                z-index: 1000;
                opacity: 0;
                transition: opacity 0.3s ease;
                pointer-events: none;
            `;
            
            this.parentElement.appendChild(tooltip);
            setTimeout(() => tooltip.style.opacity = '1', 10);
        });
        
        infoIcon.addEventListener('mouseleave', function() {
            const tooltip = this.parentElement.querySelector('.remember-tooltip');
            if (tooltip) {
                tooltip.style.opacity = '0';
                tooltipTimeout = setTimeout(() => {
                    if (tooltip.parentElement) {
                        tooltip.parentElement.removeChild(tooltip);
                    }
                }, 300);
            }
        });
    }

    // Animation d'apparition progressive
    setTimeout(function() {
        document.querySelector('.login-card').style.opacity = '1';
        document.querySelector('.login-card').style.transform = 'translateY(0)';
    }, 100);
});
</script>

<!-- Fin de la page de connexion -->
