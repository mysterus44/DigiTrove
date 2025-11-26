<?php
// admin/dashboard.php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// 1. Sécurité : Vérifier si Admin
if (!is_admin()) {
    redirect('../admin/login.php');
}

$admin_name = $_SESSION['user_nom'] ?? 'Administrateur';
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - DigiTrove Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* Scrollbar personnalisée */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #1f2937; }
        ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        /* État chargement */
        .loading-spinner {
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-left-color: #F76507;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 50px auto;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 font-sans antialiased overflow-hidden">

    <div class="flex h-screen">
        <aside id="sidebar" class="bg-gray-800 w-64 flex-shrink-0 border-r border-gray-700 flex flex-col transition-all duration-300 absolute md:relative z-20 h-full transform -translate-x-full md:translate-x-0">
            <div class="h-16 flex items-center justify-center border-b border-gray-700">
                <h1 class="text-2xl font-bold text-white tracking-wider">
                    Digi<span class="text-orange-500">Trove</span>
                </h1>
            </div>

            <nav class="flex-1 overflow-y-auto py-4">
                <ul class="space-y-2 px-3">
                    <li>
                        <a href="#overview" class="nav-item flex items-center p-3 rounded-lg hover:bg-gray-700 group transition-colors text-orange-500 bg-gray-700/50" data-page="overview">
                            <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3"></i>
                            <span class="font-medium">Vue d'ensemble</span>
                        </a>
                    </li>
                    <li>
                        <a href="#products" class="nav-item flex items-center p-3 rounded-lg hover:bg-gray-700 group transition-colors text-gray-300" data-page="products">
                            <i data-lucide="package" class="w-5 h-5 mr-3 group-hover:text-orange-500 transition-colors"></i>
                            <span class="font-medium">Produits</span>
                        </a>
                    </li>
                    <li>
                        <a href="#reviews" class="nav-item flex items-center p-3 rounded-lg hover:bg-gray-700 group transition-colors text-gray-300" data-page="reviews">
                            <i data-lucide="message-square" class="w-5 h-5 mr-3 group-hover:text-orange-500 transition-colors"></i>
                            <span class="font-medium">Avis Clients</span>
                        </a>
                    </li>
                    <li>
                        <a href="#blog" class="nav-item flex items-center p-3 rounded-lg hover:bg-gray-700 group transition-colors text-gray-300" data-page="blog">
                            <i data-lucide="newspaper" class="w-5 h-5 mr-3 group-hover:text-orange-500 transition-colors"></i>
                            <span class="font-medium">Blog</span>
                        </a>
                    </li>
                    <li>
                        <a href="#users" class="nav-item flex items-center p-3 rounded-lg hover:bg-gray-700 group transition-colors text-gray-300" data-page="users">
                            <i data-lucide="users" class="w-5 h-5 mr-3 group-hover:text-orange-500 transition-colors"></i>
                            <span class="font-medium">Utilisateurs</span>
                        </a>
                    </li>
                    <li>
                        <a href="../page/index.php" target="_blank" class="flex items-center p-3 rounded-lg hover:bg-gray-700 group transition-colors text-gray-400 mt-8 border border-gray-700 hover:border-gray-600">
                            <i data-lucide="external-link" class="w-5 h-5 mr-3"></i>
                            <span class="font-medium">Voir le site</span>
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="p-4 border-t border-gray-700">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-orange-500 flex items-center justify-center text-white font-bold text-lg">
                        <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-white"><?php echo e($admin_name); ?></p>
                        <p class="text-xs text-gray-400">Administrateur</p>
                    </div>
                </div>
                <a href="logout.php" class="block w-full py-2 px-4 bg-red-600/20 hover:bg-red-600/40 text-red-400 text-center rounded-lg transition-colors text-sm font-medium">
                    Se déconnecter
                </a>
            </div>
        </aside>

        <div class="flex-1 flex flex-col h-screen overflow-hidden relative">
            <header class="bg-gray-800 border-b border-gray-700 h-16 flex items-center justify-between px-4 md:hidden z-10">
                <button id="menu-toggle" class="text-gray-300 hover:text-white">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
                <span class="font-bold text-lg">Dashboard</span>
                <div class="w-6"></div> </header>

            <main id="content-area" class="flex-1 overflow-y-auto p-4 md:p-8 bg-gray-900 relative">
                <div class="loading-spinner"></div>
            </main>
        </div>
        
        <div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-10 hidden md:hidden backdrop-blur-sm"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            
            const contentArea = document.getElementById('content-area');
            const navItems = document.querySelectorAll('.nav-item');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const menuToggle = document.getElementById('menu-toggle');

            // Fonction de chargement de page
            async function loadPage(page) {
                // Afficher spinner
                contentArea.innerHTML = '<div class="loading-spinner"></div>';
                
                // Fermer sidebar sur mobile
                if (window.innerWidth < 768) closeSidebar();

                try {
                    const response = await fetch(`dashboard_data.php?page=${page}`);
                    if (!response.ok) throw new Error('Erreur réseau');
                    const data = await response.json();
                    
                    if (data.error) {
                        contentArea.innerHTML = `<div class="text-red-500 p-4">Erreur: ${data.error}</div>`;
                    } else {
                        contentArea.innerHTML = data.html;
                        // Ré-initialiser les icônes Lucide pour le nouveau contenu
                        lucide.createIcons();
                        // Exécuter les scripts spécifiques (Graphiques, etc.)
                        if (data.script) {
                            const scriptEl = document.createElement('script');
                            scriptEl.textContent = data.script;
                            document.body.appendChild(scriptEl);
                        }
                    }
                } catch (error) {
                    contentArea.innerHTML = `<div class="text-red-500 p-4">Impossible de charger les données.<br>${error.message}</div>`;
                }
            }

            // Gestion Navigation
            navItems.forEach(item => {
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    // Active state
                    navItems.forEach(nav => {
                        nav.classList.remove('text-orange-500', 'bg-gray-700/50');
                        nav.classList.add('text-gray-300');
                    });
                    item.classList.remove('text-gray-300');
                    item.classList.add('text-orange-500', 'bg-gray-700/50');
                    
                    const page = item.getAttribute('data-page');
                    loadPage(page);
                });
            });

            // Mobile Menu Logic
            function openSidebar() {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }
            function closeSidebar() {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            menuToggle.addEventListener('click', openSidebar);
            overlay.addEventListener('click', closeSidebar);

            // Chargement initial
            loadPage('overview');
        });
    </script>
</body>
</html>