<?php

declare(strict_types=1);

return [
    'products' => [
        [
            'name' => 'Pack Livres',
            'type' => 'Livre',
            'summary' => 'Une bibliothèque prête à exploiter pour apprendre, vendre et lancer plus vite des offres digitales.',
            'price' => '3 500 XOF',
            'compare_at' => '7 700 XOF',
            'sales' => '202 ventes',
            'image' => 'images/digitrove/products/pack-livres.png',
            'badge' => 'Ebooks',
        ],
        [
            'name' => 'Pack De Formation Bureautique',
            'type' => 'Formation',
            'summary' => 'Des modules pratiques pour maîtriser les outils de bureau, gagner du temps et professionnaliser son travail.',
            'price' => '9 000 XOF',
            'compare_at' => '21 000 XOF',
            'sales' => '239 ventes',
            'image' => 'images/digitrove/products/pack-ms-office.png',
            'badge' => 'Productivite',
        ],
        [
            'name' => 'Pack +200 logiciels Pro + Bonus',
            'type' => 'Logiciel',
            'summary' => 'Une sélection premium de ressources logicielles et bonus pour entrepreneurs, freelances et techniciens.',
            'price' => '4 900 XOF',
            'compare_at' => '16 500 XOF',
            'sales' => '192 ventes',
            'image' => 'images/digitrove/products/pack-logiciels-premium.png',
            'badge' => 'Top logiciels',
        ],
        [
            'name' => 'Systeme de Gestion StarCode Pro avec Droits de Revente',
            'type' => 'Ressource',
            'summary' => 'Un generateur de cles et systeme de gestion destine aux vendeurs qui veulent structurer leur offre.',
            'price' => '9 900 XOF',
            'compare_at' => '27 500 XOF',
            'sales' => '308 ventes',
            'image' => 'images/digitrove/products/starcode-pro.png',
            'badge' => 'Best-seller',
        ],
        [
            'name' => 'Pack De Formation Developpement Web FullStack',
            'type' => 'Formation',
            'summary' => 'Un parcours de developpement web complet pour passer des bases au lancement de projets concrets.',
            'price' => '9 900 XOF',
            'compare_at' => '49 900 XOF',
            'sales' => '89 ventes',
            'image' => 'images/digitrove/products/formation-dev-fullstack.png',
            'badge' => 'Fullstack',
        ],
    ],
    'categories' => [
        ['name' => 'Formations', 'count' => '2 packs', 'image' => 'images/digitrove/categories/formation.png'],
        ['name' => 'Logiciels', 'count' => '200+ outils', 'image' => 'images/digitrove/categories/logiciel.png'],
        ['name' => 'Ebooks', 'count' => 'Bibliotheque', 'image' => 'images/digitrove/categories/livres.png'],
        ['name' => 'Ressources', 'count' => 'Revente', 'image' => 'images/digitrove/categories/resourcebank.png'],
    ],
    'reviews' => [
        ['name' => 'Seydou Ouedraaogo', 'role' => 'Vendeur de produits digitaux', 'quote' => "Le pack m'a beaucoup aide. C'est vraiment une banque de produits digitaux complete."],
        ['name' => 'Kadidja Soro', 'role' => 'Etudiante', 'quote' => 'La formation developpement web full stack est tres complete pour ce prix.'],
        ['name' => 'Marc', 'role' => 'Entrepreneur', 'quote' => 'Bon produit, utile pour mon business au quotidien. Support reactif.'],
    ],
];
