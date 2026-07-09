<?php
require_once __DIR__ . '/functions.php';

$page_title = isset($page_title) ? $page_title . ' - ' . SITE_NAME : SITE_NAME;
$page_desc = isset($page_desc) ? $page_desc : "Découvrez DigiTrove, votre plateforme de ressources numériques.";
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
?>
<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <meta name="description" content="<?php echo e($page_desc); ?>">

    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-5254433934223993"
    crossorigin="anonymous"></script>
    <link rel="icon" type="image/svg+xml" href="<?php echo asset('images/logos/logo.svg'); ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css" />
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        'brand-blue': '#0000FF', 'brand-orange': '#F76507',
                        'light-bg': '#FFFFFF', 'light-card': '#F3F4F6', 'light-text': '#111827', 'light-text-secondary': '#6B7280',
                        'dark-bg': '#111827', 'dark-card': '#1F2937', 'dark-text': '#F9FAFB', 'dark-text-secondary': '#9CA3AF',
                    },
                    animation: { 'gradient-x': 'gradient-x 10s ease infinite' },
                    keyframes: { 'gradient-x': { '0%, 100%': { 'background-position': 'left center' }, '50%': { 'background-position': 'right center' } } }
                }
            }
        }
    </script>
    
    <style>
        html { scroll-behavior: smooth; }
        body { @apply bg-light-bg text-light-text dark:bg-dark-bg dark:text-dark-text transition-colors duration-300; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #4b5563; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* --- ANIMATIONS EXACTES DE INDEX.PHP --- */
        
        /* 1. Theme Icon (Soleil/Lune) */
        .theme-icon { transition: transform .5s ease-in-out; }
        #theme-toggle:hover { color: #0000FF; }
        .theme-icon .sun, .theme-icon .moon { transform-origin: center center; transition: transform .5s ease-in-out, opacity .5s ease-in-out; }
        .theme-icon .sun-ray { transform-origin: center center; transition: transform .5s ease-in-out; }
        /* Light Mode */
        .theme-icon .moon { transform: scale(1.75); opacity: 0; }
        /* Dark Mode */
        .dark .theme-icon { transform: rotate(-90deg); }
        .dark .theme-icon .sun { transform: scale(0.5); opacity: 0; }
        .dark .theme-icon .moon { transform: scale(1); opacity: 1; }

        /* 2. Hamburger Menu */
        .hamburger-icon { width: 24px; height: 24px; cursor: pointer; }
        .hamburger-icon .line { fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; }
        .hamburger-icon .line-top { transform-origin: 12px 6px; }
        .hamburger-icon .line-bottom { transform-origin: 12px 18px; }
        #menu-burger.is-active .line-top { transform: rotate(45deg); }
        #menu-burger.is-active .line-middle { opacity: 0; transform: scale(0); }
        #menu-burger.is-active .line-bottom { transform: rotate(-45deg); }
        #menu-burger:hover { color: #F76507; }

        /* 3. Scroll Button */
        .scroll-button { position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 50; width: 40px; height: 40px; border-radius: 50%; background-color: #F76507; color: white; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); border: none; cursor: pointer; opacity: 0; transform: scale(0.8); visibility: hidden; transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s; }
        .scroll-button.is-visible { opacity: 1; transform: scale(1); visibility: visible; }
        .scroll-button:hover { transform: scale(1.1); }
        .scroll-progress { position: absolute; top: 0; left: 0; transform: rotate(-90deg); }
        .progress-ring-bg { fill: transparent; stroke: rgba(255, 255, 255, 0.3); stroke-width: 4; }
        .progress-ring-fg { fill: transparent; stroke: white; stroke-width: 4; stroke-linecap: round; transition: stroke-dashoffset 0.2s linear; }
        .scroll-arrow { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .scroll-button.is-past-half .scroll-arrow { transform: translate(-50%, -50%) rotate(180deg); }

        /* 4. Autres */
        .review-star { width: 20px; height: 20px; color: #E5E7EB; transition: all 0.3s ease; }
        .dark .review-star { color: #4B5563; }
        .review-star.filled { color: #FBBF24; transform: scale(0); animation: star-pop 0.5s cubic-bezier(0.77, 0, 0.175, 1) forwards; }
        @keyframes star-pop { 0% { transform: scale(0); } 80% { transform: scale(1.2); } 100% { transform: scale(1); } }
        
        .animated-gradient-bg { background: linear-gradient(-45deg, #0000FF, #F76507, #0000FF, #F76507); background-size: 400% 400%; animation: animate-gradient 5s ease infinite; }
        @keyframes animate-gradient { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
    </style>
</head>
<body class="flex flex-col min-h-screen">
