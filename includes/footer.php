<footer class="bg-light-card dark:bg-dark-card border-t border-gray-200 dark:border-gray-800">
        <div class="container mx-auto px-6 py-12">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center pb-8 border-b border-gray-300 dark:border-gray-700">
                <div class="text-center md:text-left">
                    <a href="<?php echo url('page/index.php'); ?>" class="inline-block">
                        <img src="<?php echo asset('images/logos/digitrove-2.0-bgR.svg'); ?>" alt="DigiTrove" class="h-12 mx-auto md:mx-0 mb-4 grayscale hover:grayscale-0 transition duration-500">
                    </a>
                    <p class="text-sm text-gray-500">"Votre Trésor Numérique."</p>
                </div>
                <div class="text-center">
                    <h4 class="font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wider text-sm">Informations</h4>
                    <ul class="space-y-2">
                        <li><a href="<?php echo url('page/politique-confidentialite.php'); ?>" class="text-gray-500 hover:text-brand-orange transition-colors">Politique DeConfidentialité</a></li>
                        <li><a href="<?php echo url('page/return-refund.php'); ?>" class="text-gray-500 hover:text-brand-orange transition-colors">Retours et remboursement</a></li>
                        <li><a href="<?php echo url('page/politique-livraison.php'); ?>" class="text-gray-500 hover:text-brand-orange transition-colors">Politique De Livraison</a></li>
                        <li><a href="<?php echo url('page/a-propos.php'); ?>" class="text-gray-500 hover:text-brand-orange transition-colors">À propos</a></li>
                        <li><a href="<?php echo url('page/faqs.php'); ?>" class="text-gray-500 hover:text-brand-orange transition-colors">FAQs</a></li>
                        <li><a href="<?php echo url('page/cgv.php'); ?>" class="text-gray-500  hover:text-brand-orange transition-colors">CGV</a></li>
                    </ul>
                </div>
                <div class="flex justify-center md:justify-end space-x-5">
                        <a href="https://wa.me/<?php echo str_replace(' ', '', SITE_PHONE); ?>" target="_blank" class="w-10 h-10 rounded-full bg-green-500/10 text-green-600 flex items-center justify-center hover:bg-green-500 hover:text-white transition-all duration-300">
                            <i class="fab fa-whatsapp fa-lg"></i>
                        </a>
                        <a href="https://www.facebook.com/digitrovepage" target="_blank" class="w-10 h-10 rounded-full bg-blue-600/10 text-blue-600 flex items-center justify-center hover:bg-blue-600 hover:text-white transition-all duration-300">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://www.tiktok.com/@digitrovepage?_r=1&_t=ZM-91ipPyObwRk" target="_blank" class="w-10 h-10 rounded-full bg-black/10 dark:bg-white/10 text-black dark:text-white flex items-center justify-center hover:bg-black dark:hover:bg-white hover:text-white dark:hover:text-black transition-all duration-300">
                            <i class="fab fa-tiktok"></i>
                        </a>
                    </div>
            </div>

            <div class="mt-8 pt-8 text-center text-xs text-gray-400 dark:text-gray-500">
                &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. Tous droits réservés. <br>
                <span class="opacity-50">Fait avec ❤️ pour l'Afrique.</span>
            </div>
    </footer>

    <button id="dynamic-scroll-btn" class="scroll-button">
        <svg class="scroll-progress" width="40" height="40" viewBox="0 0 60 60">
            <circle class="progress-ring-bg" cx="30" cy="30" r="28" fill="transparent" stroke="rgba(255,255,255,0.3)" stroke-width="4" />
            <circle class="progress-ring-fg" cx="30" cy="30" r="28" fill="transparent" stroke="white" stroke-width="4" />
        </svg>
        <div class="scroll-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="7 13 12 18 17 13"></polyline>
                <polyline points="7 6 12 11 17 6"></polyline>
            </svg>
        </div>
    </button>

    <script src="https://unpkg.com/swiper/swiper-bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') { lucide.createIcons(); }

            // 1. MOBILE MENU (Logique index.php : toggle class 'hidden' et 'is-active')
            function setupMobileMenu() {
                const burgerBtn = document.getElementById('menu-burger');
                const mobileMenu = document.getElementById('mobile-menu');
                if (!burgerBtn || !mobileMenu) return;
                burgerBtn.addEventListener('click', () => {
                    mobileMenu.classList.toggle('hidden'); // Affiche/Cache
                    burgerBtn.classList.toggle('is-active'); // Anime l'icône
                });
            }

            // 2. THEME TOGGLE (Logique index.php)
            function setupThemeToggle() {
                const themeToggle = document.getElementById('theme-toggle');
                const html = document.documentElement;
                if (!themeToggle) return;
                
                if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    html.classList.add('dark');
                } else {
                    html.classList.remove('dark');
                }
                themeToggle.addEventListener('click', () => {
                    html.classList.toggle('dark');
                    localStorage.theme = html.classList.contains('dark') ? 'dark' : 'light';
                });
            }

            // 3. USER MENU
            function setupUserMenu() {
                const userMenuButton = document.getElementById('user-menu-button');
                const userDropdownMenu = document.getElementById('user-dropdown-menu');
                const userMenuContainer = document.getElementById('user-menu-container');

                if (userMenuButton && userDropdownMenu) {
                    userMenuButton.addEventListener('click', (event) => {
                        event.stopPropagation();
                        userDropdownMenu.classList.toggle('hidden');
                        userDropdownMenu.classList.toggle('block'); // Ajout pour forcer l'affichage si display:none par defaut
                        // Note: dans navbar.php j'ai mis 'hidden' class, donc on toggle 'hidden'.
                    });
                    document.addEventListener('click', (event) => {
                        if (!userMenuContainer.contains(event.target)) {
                            userDropdownMenu.classList.add('hidden');
                            userDropdownMenu.classList.remove('block');
                        }
                    });
                }
            }

            // 4. SCROLL BUTTON (Logique index.php)
            function setupDynamicScrollButton() {
                const scrollBtn = document.getElementById('dynamic-scroll-btn');
                if (!scrollBtn) return;

                const progressRing = scrollBtn.querySelector('.progress-ring-fg');
                const radius = progressRing.r.baseVal.value;
                const circumference = 2 * Math.PI * radius;

                progressRing.style.strokeDasharray = `${circumference} ${circumference}`;
                progressRing.style.strokeDashoffset = circumference;

                window.addEventListener('scroll', () => {
                    const scrollPosition = window.scrollY;
                    const pageHeight = document.documentElement.scrollHeight - window.innerHeight;
                    
                    // Visibilité
                    if (scrollPosition > 100) {
                        scrollBtn.classList.add('is-visible');
                    } else {
                        scrollBtn.classList.remove('is-visible');
                    }

                    // Progression
                    const scrollPercentage = scrollPosition / pageHeight;
                    const offset = circumference - scrollPercentage * circumference;
                    progressRing.style.strokeDashoffset = Math.max(0, Math.min(offset, circumference));

                    // Rotation flèche
                    if (scrollPosition > pageHeight / 2) {
                        scrollBtn.classList.add('is-past-half');
                    } else {
                        scrollBtn.classList.remove('is-past-half');
                    }
                });

                scrollBtn.addEventListener('click', () => {
                    if (scrollBtn.classList.contains('is-past-half')) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    } else {
                        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                    }
                });
            }

            // INIT
            setupMobileMenu();
            setupThemeToggle();
            setupUserMenu();
            setupDynamicScrollButton();
        });
    </script>
</body>
</html>