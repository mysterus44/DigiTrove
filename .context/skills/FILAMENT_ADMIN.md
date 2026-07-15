# FILAMENT_ADMIN.md — Back-office & ERP
# Filament 5 · gratuit, moderne, suffisant. (Nova est payant et n'apporte rien ici.)

---

## 🧭 CE QUE LE BACK-OFFICE DOIT COUVRIR

| Panneau | Contenu |
|---------|---------|
| Catalogue | Produits, fichiers, catégories, bundles, avis (modération) |
| Commerce | Commandes, paiements, remboursements, coupons |
| CRM | Clients, profils, segments, paniers abandonnés |
| Marketing | Campagnes, attribution, ROAS |
| Analytique | Widgets : CA, tunnel de conversion, top produits |
| Système | Utilisateurs staff, journal d'audit, grants de téléchargement |

---

## 📦 RESSOURCE TYPE (Produit)

```php
final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationGroup = 'Catalogue';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),

            TextInput::make('slug')->required()->unique(ignoreRecord: true),

            Select::make('type')->options(ProductType::class)->required(),

            // 💰 L'admin saisit des FCFA ; on stocke des entiers (unités mineures)
            TextInput::make('price_minor')
                ->label('Prix (FCFA)')
                ->numeric()->integer()->minValue(0)->required()
                ->helperText('Entier. Aucun centime en XOF.'),

            // 🔐 Les fichiers vont sur le disque PRIVÉ, jamais public
            Repeater::make('files')->relationship()->schema([
                FileUpload::make('storage_path')
                    ->disk('private')            // ⚠️ jamais 'public'
                    ->directory('products')
                    ->preserveFilenames(false)   // nom aléatoire sur disque
                    ->maxSize(2 * 1024 * 1024),  // 2 Go
                TextInput::make('original_name')->required(),
                TextInput::make('version')->default('1.0'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('price_minor')
                    ->label('Prix')
                    ->formatStateUsing(fn (int $s) => number_format($s, 0, ',', ' ') . ' FCFA'),
                TextColumn::make('sales_count')->label('Ventes')->sortable(),
                BadgeColumn::make('status'),
            ])
            ->filters([SelectFilter::make('type')->options(ProductType::class)])
            ->defaultSort('created_at', 'desc');
    }
}
```

---

## 📊 WIDGETS — ils lisent les ROLLUPS, jamais `events`

```php
final class SalesOverview extends BaseWidget
{
    protected function getStats(): array
    {
        // ✅ Une lecture sur la table d'agrégats. Pas de SUM sur des millions de lignes.
        $today = DailySalesStat::find(today());

        return [
            Stat::make('CA du jour', money($today?->revenue_minor ?? 0))
                ->chart($this->last7DaysRevenue()),
            Stat::make('Commandes', $today?->orders_count ?? 0),
            Stat::make('Panier moyen', money($today?->avg_order_minor ?? 0)),
        ];
    }
}
```

Widget « tunnel de conversion » → lit `daily_funnel_stats`.
Widget « top produits » → lit `daily_product_stats`.

---

## 🔐 SÉCURITÉ DU BACK-OFFICE

```php
// Accès au panneau : Policy, jamais un if sur le rôle
public function canAccessPanel(Panel $panel): bool
{
    return $this->role->isStaff() && $this->status === UserStatus::Active;
}
```

Règles :
- **Policies** sur chaque Resource (`viewAny`, `create`, `update`, `delete`).
- **2FA obligatoire** pour les comptes `admin`.
- **Journal d'audit** : qui a modifié un prix, révoqué un grant, remboursé.
  (paquet type `spatie/laravel-activitylog`, ou table `audit_logs` maison)
- **Jamais** de suppression physique d'une commande ou d'un paiement. Soft delete
  ou statut. La comptabilité ne s'efface pas.
- Les `download_grants` sont **consultables et révocables** depuis l'admin, mais le
  token clair n'est jamais affiché (il n'existe pas en base).

---

## 🧩 ACTIONS MÉTIER (le « ERP » dans Filament)

```php
Action::make('rembourser')
    ->requiresConfirmation()
    ->form([TextInput::make('reason')->required()])
    ->action(function (Order $record, array $data, RefundService $refunds) {
        $refunds->refund($record, $data['reason']);   // → révoque aussi les grants
    })
    ->visible(fn (Order $r) => $r->status === OrderStatus::Paid);
```

Autres actions utiles : réémettre les liens de téléchargement · révoquer un grant ·
relancer un panier abandonné · exporter un segment en CSV.

---

## ⚠️ PIÈGES FILAMENT

- `FileUpload` sur le disque `public` par défaut → **toujours** forcer `->disk('private')`.
- Widgets qui agrègent en direct → lenteur. Toujours passer par les rollups.
- Oublier les Policies → un compte `staff` peut tout supprimer.
- `Repeater` sur des relations lourdes → N+1. Charger avec `with()`.
