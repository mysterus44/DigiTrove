<?php
$current_page = basename($_SERVER['PHP_SELF']);

// Fonction helper améliorée pour les classes de menu
function nav_class($page_name, $current_page) {
    $base = "nav-link relative py-2 transition-colors duration-300 ";
    // Si c'est la page active, on ajoute la classe 'active' et la couleur orange
    if ($current_page === $page_name) {
        return $base . "active text-brand-orange font-bold";
    }
    // Sinon, couleur standard avec hover
    return $base . "text-light-text-secondary dark:text-dark-text-secondary hover:text-brand-orange dark:hover:text-brand-orange";
}
function nav_class2($page_name, $current_page) {

    $base = "transition-colors duration-200 ";

    if ($current_page === $page_name) {

        return $base . "text-brand-orange font-bold";

    }

    return $base . "text-light-text-secondary dark:text-dark-text-secondary hover:text-brand-orange dark:hover:text-brand-orange";

}
?>
<style>
    /* Barre de soulignement animée */
    .nav-link::after {
        content: '';
        position: absolute;
        width: 0;
        height: 3px; /* Épaisseur de la barre */
        bottom: 0;
        left: 0;
        background-color: #F76507; /* Orange Brand */
        transition: width 0.3s ease-in-out;
        border-radius: 2px;
    }

    /* Animation au survol */
    .nav-link:hover::after {
        width: 100%;
    }

    /* Barre fixe pour le lien actif */
    .nav-link.active::after {
        width: 100%;
    }
</style>

<header class="sticky top-0 z-50 bg-light-bg/80 dark:bg-dark-bg/80 backdrop-blur-sm border-b border-light-card dark:border-dark-card">
    <nav class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="relative flex items-center justify-between h-20">
            
            <div class="hidden md:flex items-center space-x-8">
                <a href="<?php echo url('page/index.php'); ?>" class="<?php echo nav_class('index.php', $current_page); ?>">Accueil</a>
                <a href="<?php echo url('page/boutique.php'); ?>" class="<?php echo nav_class('boutique.php', $current_page); ?>">Boutique</a>
                <a href="<?php echo url('page/blog.php'); ?>" class="<?php echo nav_class('blog.php', $current_page); ?>">Blog</a>
                <a href="<?php echo url('page/a-propos.php'); ?>" class="<?php echo nav_class('a-propos.php', $current_page); ?>">À Propos</a>
            </div>

            <div class="md:hidden">
                <button id="menu-burger" class="p-2 rounded-md text-light-text-secondary dark:text-dark-text-secondary hover:bg-light-card dark:hover:bg-dark-card focus:outline-none">
                    <svg class="hamburger-icon" viewBox="0 0 24 24">
                        <path class="line line-top" d="M3,6 H21"/>
                        <path class="line line-middle" d="M3,12 H21"/>
                        <path class="line line-bottom" d="M3,18 H21"/>
                    </svg>
                </button>
            </div>

            <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 transform hover:scale-105 transition-transform duration-300">
                <a href="<?php echo url('index.php'); ?>" class="flex items-center space-x-2">
                    <img src="<?php echo asset('images/logos/lg.svg'); ?>" alt="DigiTrove" class="h-14 w-auto" onerror="this.src='https://placehold.co/120x60?text=DigiTrove'">
                </a>
            </div>

            <div class="flex items-center space-x-2 sm:space-x-4">
                <div class="relative" id="user-menu-container">
                    <button id="user-menu-button" class="group p-2 rounded-md text-light-text-secondary dark:text-dark-text-secondary hover:bg-light-card dark:hover:bg-dark-card transition-colors focus:outline-none">
                        <svg class="user-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle class="user-icon-head" cx="12" cy="8" r="4" />
                            <path class="user-icon-body" d="M18,20 C18,17 15.31,15 12,15 C8.69,15 6,17 6,20" />
                        </svg>
                    </button>
                    <div id="user-dropdown-menu" class="user-dropdown absolute right-0 mt-2 w-48 bg-light-bg dark:bg-dark-card rounded-xl shadow-lg ring-1 ring-black ring-opacity-5 py-2 z-50 hidden">
                        <?php if(is_logged_in()): ?>
                            <a href="<?php echo url('admin/logout.php'); ?>" class="block px-4 py-2 text-brand-orange hover:bg-light-card dark:hover:bg-gray-700">Déconnexion</a>
                        <?php else: ?>
                            <a href="<?php echo url('admin/login.php'); ?>" class="block px-4 py-2 text-brand-orange hover:bg-light-card dark:hover:bg-gray-700">Se connecter</a>
                            <a href="<?php echo url('admin/login.php'); ?>" class="block px-4 py-2 text-brand-orange hover:bg-light-card dark:hover:bg-gray-700">S'inscrire</a>
                        <?php endif; ?>
                    </div>
                </div>

                <button id="theme-toggle" class="relative p-2 rounded-md text-light-text-secondary dark:text-dark-text-secondary hover:bg-light-card dark:hover:text-orange transition-colors">
                    <svg class="theme-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <g class="sun">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line class="sun-ray" x1="12" y1="1" x2="12" y2="3"></line>
                            <line class="sun-ray" x1="12" y1="21" x2="12" y2="23"></line>
                            <line class="sun-ray" x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                            <line class="sun-ray" x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                            <line class="sun-ray" x1="1" y1="12" x2="3" y2="12"></line>
                            <line class="sun-ray" x1="21" y1="12" x2="23" y2="12"></line>
                            <line class="sun-ray" x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                            <line class="sun-ray" x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                        </g>
                        <g class="moon">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </g>
                    </svg>
                </button>
            </div>
        </div>
    </nav>

    <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-light-bg dark:bg-dark-bg border-b border-light-card dark:border-dark-card p-4">
        <div class="flex flex-col space-y-4">
                <a href="<?php echo url('page/index.php'); ?>" class="<?php echo nav_class2('index.php', $current_page); ?>">Accueil</a>
                <a href="<?php echo url('page/boutique.php'); ?>" class="<?php echo nav_class2('boutique.php', $current_page); ?>">Boutique</a>
                <a href="<?php echo url('page/blog.php'); ?>" class="<?php echo nav_class2('blog.php', $current_page); ?>">Blog</a>
                <a href="<?php echo url('page/a-propos.php'); ?>" class="<?php echo nav_class2('a-propos.php', $current_page); ?>">À Propos</a>
        
    </div>
</header>