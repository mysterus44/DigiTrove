@php
    $products = [
        [
            'name' => 'Pack Livres',
            'type' => 'Livre',
            'summary' => 'Une bibliothèque prête à exploiter pour apprendre, vendre et lancer plus vite des offres digitales.',
            'price' => '3 500 XOF',
            'compare_at' => '7 700 XOF',
            'sales' => '202 ventes',
            'image' => asset('images/digitrove/products/pack-livres.png'),
            'badge' => 'Ebooks',
        ],
        [
            'name' => 'Pack De Formation Bureautique',
            'type' => 'Formation',
            'summary' => 'Des modules pratiques pour maîtriser les outils de bureau, gagner du temps et professionnaliser son travail.',
            'price' => '9 000 XOF',
            'compare_at' => '21 000 XOF',
            'sales' => '239 ventes',
            'image' => asset('images/digitrove/products/pack-ms-office.png'),
            'badge' => 'Productivite',
        ],
        [
            'name' => 'Pack +200 logiciels Pro + Bonus',
            'type' => 'Logiciel',
            'summary' => 'Une sélection premium de ressources logicielles et bonus pour entrepreneurs, freelances et techniciens.',
            'price' => '4 900 XOF',
            'compare_at' => '16 500 XOF',
            'sales' => '192 ventes',
            'image' => asset('images/digitrove/products/pack-logiciels-premium.png'),
            'badge' => 'Top logiciels',
        ],
        [
            'name' => 'Systeme de Gestion StarCode Pro avec Droits de Revente',
            'type' => 'Ressource',
            'summary' => 'Un generateur de cles et systeme de gestion destine aux vendeurs qui veulent structurer leur offre.',
            'price' => '9 900 XOF',
            'compare_at' => '27 500 XOF',
            'sales' => '308 ventes',
            'image' => asset('images/digitrove/products/starcode-pro.png'),
            'badge' => 'Best-seller',
        ],
        [
            'name' => 'Pack De Formation Developpement Web FullStack',
            'type' => 'Formation',
            'summary' => 'Un parcours de developpement web complet pour passer des bases au lancement de projets concrets.',
            'price' => '9 900 XOF',
            'compare_at' => '49 900 XOF',
            'sales' => '89 ventes',
            'image' => asset('images/digitrove/products/formation-dev-fullstack.png'),
            'badge' => 'Fullstack',
        ],
    ];

    $categories = [
        ['name' => 'Formations', 'count' => '2 packs', 'image' => asset('images/digitrove/categories/formation.png')],
        ['name' => 'Logiciels', 'count' => '200+ outils', 'image' => asset('images/digitrove/categories/logiciel.png')],
        ['name' => 'Ebooks', 'count' => 'Bibliotheque', 'image' => asset('images/digitrove/categories/livres.png')],
        ['name' => 'Ressources', 'count' => 'Revente', 'image' => asset('images/digitrove/categories/resourcebank.png')],
    ];

    $reviews = [
        ['name' => 'Seydou Ouedraaogo', 'role' => 'Vendeur de produits digitaux', 'quote' => "Le pack m'a beaucoup aide. C'est vraiment une banque de produits digitaux complete."],
        ['name' => 'Kadidja Soro', 'role' => 'Etudiante', 'quote' => "La formation developpement web full stack est tres complete pour ce prix."],
        ['name' => 'Marc', 'role' => 'Entrepreneur', 'quote' => 'Bon produit, utile pour mon business au quotidien. Support reactif.'],
    ];

    $articles = [
        ['title' => 'La Revolution No-Code', 'category' => 'Developpement & Tech', 'excerpt' => 'Creer une application ou un site sans coder, avec les bons outils et une vraie logique business.'],
        ['title' => 'Freelance : gagner en devises depuis l Afrique', 'category' => 'Freelance & Teletravail', 'excerpt' => 'Positionnement, plateformes, prospection LinkedIn et moyens de paiement pour vendre plus loin.'],
        ['title' => 'Facebook Ads rentable en 2025', 'category' => 'Marketing Digital', 'excerpt' => 'Arreter de bruler le budget et structurer des campagnes orientees ventes.'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="DigiTrove prepare une boutique autonome pour logiciels, formations, ebooks et ressources digitales.">

    <title>DigiTrove - Vitrine statique</title>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>{!! file_get_contents(resource_path('css/app.css')) !!}</style>
    @endif
</head>
<body>
    <header class="site-header" aria-label="Navigation principale">
        <a class="brand" href="#top" aria-label="DigiTrove accueil">
            <img src="{{ asset('images/digitrove/logos/digitrove-2.0.png') }}" alt="" class="brand-mark">
            <span>DigiTrove</span>
        </a>

        <nav class="nav-links" aria-label="Sections">
            <a href="#catalogue">Catalogue</a>
            <a href="#avis">Avis</a>
            <a href="#blog">Blog</a>
            <a href="#newsletter">Acces</a>
        </nav>
    </header>

    <main id="top">
        <section class="hero-section" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow">SITE-00 - Vitrine statique de previsualisation</p>
                <h1 id="hero-title">DigiTrove</h1>
                <p class="hero-lede">
                    La future boutique autonome pour vendre logiciels, formations, ebooks et ressources digitales sans dependance a une marketplace.
                </p>
                <div class="hero-actions">
                    <a class="button button-primary" href="#catalogue">Explorer le catalogue</a>
                    <button class="button button-muted" type="button" disabled>Checkout bientot disponible</button>
                </div>
                <dl class="hero-metrics" aria-label="Indicateurs catalogue">
                    <div>
                        <dt>5</dt>
                        <dd>offres legacy preparees</dd>
                    </div>
                    <div>
                        <dt>1 030</dt>
                        <dd>ventes historisees</dd>
                    </div>
                    <div>
                        <dt>XOF</dt>
                        <dd>prix affiches en entiers</dd>
                    </div>
                </dl>
            </div>

            <div class="hero-showcase" aria-label="Apercu produits">
                @foreach (array_slice($products, 0, 3) as $product)
                    <article class="showcase-card">
                        <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}">
                        <div>
                            <p>{{ $product['type'] }}</p>
                            <strong>{{ $product['name'] }}</strong>
                            <span>{{ $product['price'] }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="notice-band" aria-label="Etat du checkout">
            <strong>Activation commerciale en attente.</strong>
            <span>Le checkout, les paiements et la livraison securisee seront branches uniquement apres les phases backend validees.</span>
        </section>

        <section class="benefits-section" aria-labelledby="benefits-title">
            <div class="section-heading">
                <p class="eyebrow">Promesse front-office</p>
                <h2 id="benefits-title">Une experience claire pour acheter des produits digitaux premium</h2>
            </div>
            <div class="benefit-grid">
                <article>
                    <span class="icon-pill">01</span>
                    <h3>Catalogue lisible</h3>
                    <p>Chaque offre met en avant le type, le prix, le positionnement et la preuve de traction.</p>
                </article>
                <article>
                    <span class="icon-pill">02</span>
                    <h3>Conversion sobre</h3>
                    <p>Les CTA orientent vers les produits et la liste d'attente sans declencher de paiement.</p>
                </article>
                <article>
                    <span class="icon-pill">03</span>
                    <h3>Base securisee</h3>
                    <p>La livraison finale passera par des liens uniques, expirables et revocables, hors espace public.</p>
                </article>
            </div>
        </section>

        <section id="catalogue" class="catalogue-section" aria-labelledby="catalogue-title">
            <div class="section-heading split-heading">
                <div>
                    <p class="eyebrow">Produits digitaux</p>
                    <h2 id="catalogue-title">Catalogue de previsualisation</h2>
                </div>
                <a class="text-link" href="#newsletter">Recevoir l'ouverture</a>
            </div>

            <div class="product-grid">
                @foreach ($products as $product)
                    <article class="product-card">
                        <div class="product-media">
                            <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}">
                            <span>{{ $product['badge'] }}</span>
                        </div>
                        <div class="product-body">
                            <p class="product-type">{{ $product['type'] }}</p>
                            <h3>{{ $product['name'] }}</h3>
                            <p>{{ $product['summary'] }}</p>
                            <div class="price-row">
                                <strong>{{ $product['price'] }}</strong>
                                <span>{{ $product['compare_at'] }}</span>
                            </div>
                            <div class="card-footer">
                                <small>{{ $product['sales'] }}</small>
                                <button type="button" disabled>Checkout bientot disponible</button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="categories-section" aria-labelledby="categories-title">
            <div class="section-heading">
                <p class="eyebrow">Categories</p>
                <h2 id="categories-title">Parcours d'achat prepares pour P2</h2>
            </div>
            <div class="category-grid">
                @foreach ($categories as $category)
                    <article class="category-tile">
                        <img src="{{ $category['image'] }}" alt="">
                        <div>
                            <h3>{{ $category['name'] }}</h3>
                            <p>{{ $category['count'] }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="avis" class="reviews-section" aria-labelledby="reviews-title">
            <div class="section-heading split-heading">
                <div>
                    <p class="eyebrow">Preuve sociale</p>
                    <h2 id="reviews-title">Avis clients issus du contenu historique</h2>
                </div>
                <p class="rating-summary">Note moyenne affichee : 4.8/5</p>
            </div>
            <div class="review-grid">
                @foreach ($reviews as $review)
                    <article>
                        <p class="stars" aria-label="5 etoiles">★★★★★</p>
                        <blockquote>{{ $review['quote'] }}</blockquote>
                        <div>
                            <strong>{{ $review['name'] }}</strong>
                            <span>{{ $review['role'] }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="faq-section" aria-labelledby="faq-title">
            <div class="section-heading">
                <p class="eyebrow">FAQ</p>
                <h2 id="faq-title">Questions avant ouverture</h2>
            </div>
            <div class="faq-list">
                <details open>
                    <summary>Puis-je acheter maintenant ?</summary>
                    <p>Non. Cette page est une previsualisation SITE-00. Les achats seront actives apres validation du schema et implementation securisee des phases backend.</p>
                </details>
                <details>
                    <summary>Les fichiers digitaux sont-ils accessibles publiquement ?</summary>
                    <p>Non. La vitrine affiche seulement des images marketing. Les livrables resteront sur disque prive lors de la vraie livraison.</p>
                </details>
                <details>
                    <summary>Pourquoi afficher deja les prix ?</summary>
                    <p>Pour tester le positionnement commercial avec les contenus legacy tout en gardant la logique paiement hors scope.</p>
                </details>
            </div>
        </section>

        <section id="blog" class="blog-section" aria-labelledby="blog-title">
            <div class="section-heading split-heading">
                <div>
                    <p class="eyebrow">SEO natif</p>
                    <h2 id="blog-title">Apercu editorial</h2>
                </div>
                <a class="text-link" href="#newsletter">Suivre les publications</a>
            </div>
            <div class="blog-grid">
                @foreach ($articles as $article)
                    <article>
                        <p>{{ $article['category'] }}</p>
                        <h3>{{ $article['title'] }}</h3>
                        <span>{{ $article['excerpt'] }}</span>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="newsletter" class="cta-section" aria-labelledby="cta-title">
            <div>
                <p class="eyebrow">Liste d'attente</p>
                <h2 id="cta-title">Recevoir l'ouverture de la boutique securisee</h2>
                <p>La prochaine etape reste la validation humaine du schema BDD, puis P1 Identite. Cette vitrine ne cree aucune commande.</p>
            </div>
            <form class="waitlist-form" aria-label="Formulaire statique de liste d'attente">
                <input type="email" placeholder="email@example.com" aria-label="Adresse email" disabled>
                <button type="button" disabled>Inscription bientot disponible</button>
            </form>
        </section>
    </main>
</body>
</html>
