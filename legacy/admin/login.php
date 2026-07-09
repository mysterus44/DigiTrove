<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Génération du token de sécurité CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Si déjà connecté, redirection intelligente
if (is_logged_in()) {
    if (is_admin()) {
        redirect('dashboard.php');
    } else {
        redirect('../page/boutique.php');
    }
}

$page_title = "Connexion Sécurisée - " . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .perspective { perspective: 1000px; }
        .flip-card-inner {
            position: relative;
            width: 100%;
            height: 100%;
            text-align: center;
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }
        .flip-card-inner.flipped { transform: rotateY(180deg); }
        .flip-front, .flip-back {
            position: absolute;
            width: 100%;
            height: 100%;
            -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
            top: 0; left: 0;
        }
        .flip-back { transform: rotateY(180deg); }
    </style>
</head>
<body class="min-h-screen bg-gray-900 flex items-center justify-center px-4 sm:px-6 lg:px-8" 
      style="background-image: radial-gradient(at 47% 33%, hsl(220, 20%, 20%) 0px, transparent 50%), radial-gradient(at 82% 65%, hsl(22, 100%, 50%, 0.2) 0px, transparent 50%); background-size: cover;">

    <div class="w-full max-w-md perspective h-[600px]">
        <div class="flip-card-inner bg-white dark:bg-gray-800 rounded-2xl shadow-2xl relative" id="card-inner">
            
            <div class="flip-front p-8 flex flex-col justify-center h-full bg-white rounded-2xl border border-gray-200 shadow-xl">
                <div class="mb-8 text-center">
                    <img src="../images/logos/digitrove-2.0-bgR.svg" alt="Logo" class="h-12 mx-auto mb-4">
                    <h2 class="text-2xl font-bold text-gray-900">Espace Membre</h2>
                    <p class="text-sm text-gray-500 mt-1">Connectez-vous pour accéder à vos trésors.</p>
                </div>

                <div id="login-alert" class="hidden bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 text-sm text-left" role="alert"></div>

                <form id="login-form" class="space-y-5 text-left">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input name="email" type="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
                        <input name="password" type="password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none transition">
                    </div>

                    <button type="submit" class="w-full py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                        Se connecter
                    </button>
                </form>

                <div class="mt-6 text-center border-t pt-4">
                    <p class="text-sm text-gray-600">Nouveau chez DigiTrove ? 
                        <button type="button" onclick="toggleFlip()" class="font-bold text-orange-600 hover:text-orange-700 ml-1 focus:outline-none">Créer un compte</button>
                    </p>
                </div>
            </div>

            <div class="flip-back p-8 flex flex-col justify-center h-full bg-white rounded-2xl border border-gray-200 shadow-xl">
                <div class="mb-6 text-center">
                    <h2 class="text-2xl font-bold text-gray-900">Rejoignez-nous</h2>
                    <p class="text-sm text-gray-500 mt-1">Créez votre compte gratuitement</p>
                </div>

                <div id="signup-alert" class="hidden bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 text-sm text-left"></div>

                <form id="signup-form" class="space-y-4 text-left">
                    <input type="hidden" name="action" value="signup">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1 uppercase">Prénom</label>
                            <input name="prenom" type="text" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1 uppercase">Nom</label>
                            <input name="nom" type="text" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 outline-none text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1 uppercase">Email</label>
                        <input name="email" type="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 outline-none text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1 uppercase">Mot de passe</label>
                        <input name="password" type="password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 outline-none text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1 uppercase">Confirmation</label>
                        <input name="confirm_password" type="password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 outline-none text-sm">
                    </div>

                    <button type="submit" class="w-full py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition-colors">
                        S'inscrire
                    </button>
                </form>

                <div class="mt-4 text-center border-t pt-4">
                    <p class="text-sm text-gray-600">Déjà membre ? 
                        <button type="button" onclick="toggleFlip()" class="font-bold text-blue-600 hover:text-blue-700 ml-1 focus:outline-none">Connexion</button>
                    </p>
                </div>
            </div>

        </div>
    </div>

    <script>
        function toggleFlip() {
            document.getElementById('card-inner').classList.toggle('flipped');
            document.getElementById('login-alert').classList.add('hidden');
            document.getElementById('signup-alert').classList.add('hidden');
        }

        async function handleAuth(formId, alertId) {
            const form = document.getElementById(formId);
            const alertBox = document.getElementById(alertId);
            const btn = form.querySelector('button[type="submit"]');
            const originalBtnText = btn.innerText;

            // Désactiver le bouton et mettre un loader
            btn.disabled = true;
            btn.innerText = "Chargement...";
            
            const formData = new FormData(form);

            try {
                const response = await fetch('auth_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                // Gestion des erreurs serveur (non-JSON)
                if (!response.ok) throw new Error("Erreur serveur (" + response.status + ")");

                const data = await response.json();

                alertBox.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'border-red-500', 'bg-green-50', 'text-green-700', 'border-green-500');
                
                if (data.success) {
                    if (formId === 'login-form') {
                        window.location.href = data.redirect;
                    } else {
                        // Inscription réussie
                        alertBox.classList.add('bg-green-50', 'text-green-700', 'border-green-500');
                        alertBox.textContent = data.message;
                        form.reset();
                        setTimeout(() => { toggleFlip(); }, 2000);
                    }
                } else {
                    alertBox.classList.add('bg-red-50', 'text-red-700', 'border-red-500');
                    alertBox.textContent = data.message;
                }
            } catch (error) {
                alertBox.classList.remove('hidden');
                alertBox.classList.add('bg-red-50', 'text-red-700', 'border-red-500');
                alertBox.textContent = "Une erreur technique est survenue.";
                console.error(error);
            } finally {
                // Réactiver le bouton
                btn.disabled = false;
                btn.innerText = originalBtnText;
            }
        }

        document.getElementById('login-form').addEventListener('submit', (e) => {
            e.preventDefault();
            handleAuth('login-form', 'login-alert');
        });

        document.getElementById('signup-form').addEventListener('submit', (e) => {
            e.preventDefault();
            handleAuth('signup-form', 'signup-alert');
        });
    </script>
</body>
</html>