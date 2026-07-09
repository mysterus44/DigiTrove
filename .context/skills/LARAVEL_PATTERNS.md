# LARAVEL_PATTERNS.md — Conventions & Architecture Laravel
# Niveau : God-tier | Laravel 13 · PHP 8.3+ · orienté objet

---

## 🏛️ LES COUCHES (dans l'ordre du flux)

```
Route → Middleware → Form Request (validation) → Policy (autorisation)
      → Controller (fin) → Service (logique métier) → Model/Repository
      → Event → Listener/Job (effets de bord)
```

**Règle** : un contrôleur ne doit **jamais** dépasser ~15 lignes par méthode.
S'il contient de la logique métier, elle appartient à un Service.

---

## 🎮 CONTRÔLEUR FIN

```php
// ❌ MAUVAIS — logique métier dans le contrôleur
public function store(Request $request) {
    $validated = $request->validate([...]);
    $order = Order::create([...]);
    foreach ($request->items as $item) { /* 40 lignes */ }
    Mail::to(...)->send(...);
    return redirect()->route('orders.show', $order);
}

// ✅ BON
public function store(StoreOrderRequest $request, OrderService $orders): RedirectResponse
{
    $order = $orders->createFromCart(
        cart: $request->user()->activeCart(),
        email: $request->validated('email'),
    );

    return redirect()->route('orders.show', $order);
}
```

---

## 🧠 SERVICE (la logique métier vit ici)

```php
// app/Services/OrderService.php
final class OrderService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    public function createFromCart(Cart $cart, string $email): Order
    {
        return DB::transaction(function () use ($cart, $email) {
            $order = Order::create([
                'order_number'   => $this->generateOrderNumber(),
                'user_id'        => $cart->user_id,
                'visitor_id'     => $cart->visitor_id,
                'email'          => $email,
                'status'         => OrderStatus::Pending,
                'subtotal_minor' => $cart->subtotalMinor(),
                'total_minor'    => $cart->totalMinor(),
                'currency'       => 'XOF',
            ]);

            foreach ($cart->items as $item) {
                // 🔴 SNAPSHOT : on fige nom et prix. Jamais de JOIN plus tard.
                $order->items()->create([
                    'product_id'            => $item->product_id,
                    'product_name_snapshot' => $item->product->name,
                    'product_type_snapshot' => $item->product->type,
                    'unit_price_minor'      => $item->product->price_minor,
                    'quantity'              => $item->quantity,
                    'line_total_minor'      => $item->product->price_minor * $item->quantity,
                ]);
            }

            $cart->update(['status' => CartStatus::Converted]);

            return $order;
        });
    }
}
```

Points clés :
- `final` par défaut. `readonly` sur les dépendances injectées.
- `DB::transaction()` dès qu'il y a plusieurs écritures liées.
- Types de retour explicites partout. Pas de `mixed` paresseux.

---

## ✅ FORM REQUEST (validation) + POLICY (autorisation)

```php
// app/Http/Requests/StoreProductRequest.php
final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);   // → Policy
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => ['required', 'alpha_dash', 'unique:products,slug'],
            'type'        => ['required', Rule::enum(ProductType::class)],
            'price_minor' => ['required', 'integer', 'min:0'],   // 💰 entier, jamais float
        ];
    }
}

// app/Policies/ProductPolicy.php
final class ProductPolicy
{
    public function create(User $user): bool
    {
        return $user->role->isStaff();
    }
}
```

❌ Jamais `if ($user->role === 'admin')` dans un contrôleur. Toujours une Policy.

---

## 🔢 ENUMS PHP 8.1+ (au lieu de chaînes magiques)

```php
enum OrderStatus: string
{
    case Pending           = 'pending';
    case Paid              = 'paid';
    case Failed            = 'failed';
    case Refunded          = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Cancelled         = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Refunded, self::Cancelled], true);
    }
}

// Modèle
protected function casts(): array
{
    return ['status' => OrderStatus::class];
}
```

---

## 💰 L'ARGENT — value object, jamais un float

```php
// app/Support/Money.php
final readonly class Money
{
    public function __construct(
        public int $minor,          // XOF : 1 minor = 1 FCFA
        public string $currency = 'XOF',
    ) {}

    public function add(Money $o): self  { return new self($this->minor + $o->minor, $this->currency); }
    public function times(int $q): self  { return new self($this->minor * $q, $this->currency); }
    public function format(): string     { return number_format($this->minor, 0, ',', ' ') . ' FCFA'; }
}
```

En base : `BIGINT`. En PHP : `int` ou `Money`. **Jamais `float`.** Un `0.1 + 0.2`
qui ne fait pas `0.3` sur une facture, c'est un procès.

---

## 📡 EVENTS & JOBS (les effets de bord)

Un paiement confirmé déclenche : livraison, e-mail, rollups CRM. Ne mets pas tout ça
dans le service de paiement.

```php
// Après confirmation SERVEUR du paiement
OrderPaid::dispatch($order);

// Listeners (en queue)
class IssueDownloadGrants implements ShouldQueue { /* crée les grants */ }
class SendPurchaseEmail    implements ShouldQueue { /* envoie les liens */ }
class UpdateCustomerRollups implements ShouldQueue { /* orders_count, LTV */ }
```

Chaque listener est **idempotent** : rejoué deux fois, il ne double rien.

---

## 🗄️ PERFORMANCE — les 3 fautes classiques

```php
// ❌ N+1 : une requête par produit
foreach (Order::all() as $order) { echo $order->items->count(); }

// ✅ Eager loading + compteur
Order::withCount('items')->paginate(25);

// ❌ Charger 100 000 lignes en mémoire
$all = Event::all();

// ✅ Curseur / chunk
Event::where('occurred_at', '>=', $since)->chunkById(1000, fn ($rows) => ...);

// ❌ Recalculer le CA à chaque affichage du dashboard
Order::where('status','paid')->sum('total_minor');

// ✅ Lire le rollup
DailySalesStat::whereBetween('day', [$from, $to])->sum('revenue_minor');
```

---

## 🧪 TESTS (Pest) — pas de feature sans test

```php
it('fige le prix au moment de la commande', function () {
    $product = Product::factory()->create(['price_minor' => 5000]);
    $order   = app(OrderService::class)->createFromCart(cartWith($product), 'a@b.c');

    $product->update(['price_minor' => 9999]);          // le prix change après coup

    expect($order->items->first()->unit_price_minor)->toBe(5000);  // l'histoire ne bouge pas
});
```

Tests attendus par couche : Service (unitaire) · Endpoint (feature) · Policy
(autorisation) · Migration (schéma) · Sécurité (accès refusé).

---

## 📁 ARBORESCENCE CIBLE

```
app/
├── Enums/                 OrderStatus, ProductType, LifecycleStage…
├── Models/                Eloquent, fins, relations + casts uniquement
├── Services/              OrderService, DownloadService, AnalyticsService…
├── Http/
│   ├── Controllers/       fins
│   ├── Requests/          validation
│   └── Middleware/
├── Policies/              autorisation
├── Events/ · Listeners/ · Jobs/
├── Support/               Money, helpers purs
└── Filament/              Resources, Widgets (back-office)
database/
├── migrations/            une migration = un changement
├── factories/ · seeders/  (seeder admin : lit .env, JAMAIS de mot de passe en dur)
tests/
├── Unit/ · Feature/
```
