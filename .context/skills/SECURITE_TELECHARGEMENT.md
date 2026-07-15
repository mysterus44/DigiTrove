# SECURITE_TELECHARGEMENT.md — 🔴 LE CŒUR DE DIGITROVE
# Livraison automatisée et sécurisée des produits digitaux après paiement.
# Une faille ici = ton catalogue entier circule gratuitement. Tolérance zéro.

---

## 🧨 LES 5 FAÇONS DE TOUT PERDRE

1. Mettre les fichiers dans `public/` → Google les indexe. C'est arrivé à des milliers de boutiques.
2. Servir une URL devinable (`/download/produit-42.zip`) → énumération triviale.
3. Stocker le token en clair en base → une fuite SQL = tout le catalogue.
4. Lien sans expiration ni quota → il finit sur un forum de partage.
5. Livrer avant confirmation **serveur** du paiement → Wi-Fi… pardon, produits gratuits.

---

## ✅ L'ARCHITECTURE CORRECTE

```
Paiement confirmé (côté SERVEUR)
   └─> Event OrderPaid
        └─> Listener IssueDownloadGrants
             ├─ pour chaque order_item × product_file :
             │    token clair  = random_bytes(32)        ← envoyé UNE fois au client
             │    token_hash   = hash('sha256', token)   ← seul stocké en base
             │    expires_at   = now + DOWNLOAD_LINK_TTL_HOURS
             │    max_downloads = DOWNLOAD_MAX_PER_GRANT
             └─ e-mail avec les liens contenant le token CLAIR
```

Le token clair n'existe que dans l'e-mail du client. La base ne contient que son
empreinte. **Exactement comme un mot de passe.**

---

## 🔐 LE CONTRÔLEUR DE TÉLÉCHARGEMENT

```php
// routes/web.php
Route::get('/download/{token}', DownloadController::class)
    ->middleware('throttle:10,1')          // 10 tentatives / minute / IP
    ->name('download');

final class DownloadController
{
    public function __invoke(string $token, DownloadService $service): StreamedResponse
    {
        $grant = $service->resolveValidGrant($token);   // lève 404 si invalide

        return $service->stream($grant, request());
    }
}
```

```php
// app/Services/DownloadService.php
final class DownloadService
{
    public function resolveValidGrant(string $token): DownloadGrant
    {
        $hash = hash('sha256', $token);

        $grant = DownloadGrant::where('token_hash', $hash)->first();

        // Réponse identique dans tous les cas d'échec : pas de fuite d'information
        abort_if($grant === null,                 404);
        abort_if($grant->revoked_at !== null,     404);
        abort_if($grant->expires_at->isPast(),    404);
        abort_if($grant->downloads_count >= $grant->max_downloads, 404);

        return $grant;
    }

    public function stream(DownloadGrant $grant, Request $request): StreamedResponse
    {
        $file = $grant->productFile;

        // 🔴 Le disque est PRIVÉ. Aucune URL publique n'existe pour ce fichier.
        abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 404);

        // Incrément atomique : deux requêtes simultanées ne peuvent pas dépasser le quota
        $updated = DownloadGrant::where('id', $grant->id)
            ->where('downloads_count', '<', $grant->max_downloads)
            ->increment('downloads_count');

        abort_if($updated === 0, 404);   // quota atteint entre-temps

        DownloadLog::create([
            'grant_id'   => $grant->id,
            'ip_hash'    => hash_hmac('sha256', $request->ip(), config('app.key')),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'status'     => 'started',
        ]);

        return Storage::disk($file->storage_disk)->download(
            $file->storage_path,
            $file->original_name,          // le vrai nom, jamais le chemin interne
        );
    }
}
```

---

## 📦 GROS FICHIERS — ne fais pas passer 4 Go par PHP

Trois stratégies, par ordre de préférence :

| Volume | Stratégie |
|--------|-----------|
| < 100 Mo | `Storage::download()` (streamé par Laravel) |
| 100 Mo – 2 Go | **X-Sendfile / X-Accel-Redirect** : PHP vérifie le grant, Nginx sert le fichier |
| > 2 Go, ou S3 | **URL pré-signée S3 courte** (5 min), générée après validation du grant |

```php
// Nginx X-Accel-Redirect : PHP autorise, Nginx livre. Zéro mémoire PHP.
return response('', 200, [
    'X-Accel-Redirect'    => '/protected/' . $file->storage_path,
    'Content-Disposition' => 'attachment; filename="' . $file->original_name . '"',
]);
```

⚠️ Même avec S3 : **l'URL pré-signée est générée après vérification du grant**, elle
est courte (5 min), et elle n'est jamais stockée.

---

## 🕵️ DÉTECTION D'ABUS (partage de lien)

`download_logs` existe pour ça. Alerte quand :

```sql
-- Un même grant téléchargé depuis > 3 IP distinctes en 24h = lien partagé
SELECT grant_id, COUNT(DISTINCT ip_hash) AS ips
FROM download_logs
WHERE created_at > now() - interval '24 hours'
GROUP BY grant_id
HAVING COUNT(DISTINCT ip_hash) > 3;
```

Réaction : révoquer le grant (`revoked_at = now()`), notifier l'admin, éventuellement
réémettre un grant frais pour le client légitime.

---

## 🔄 RÉVOCATION

Un grant se révoque, jamais ne se supprime (on garde la trace) :
- Remboursement → révoquer tous les grants de la commande
- Fraude détectée → révoquer + bloquer le compte
- Fichier remplacé (nouvelle version) → révoquer les anciens, réémettre

```php
$order->items->each(fn ($item) => $item->downloadGrants()
    ->whereNull('revoked_at')
    ->update(['revoked_at' => now()]));
```

---

## 🔑 LICENCES LOGICIELLES (si applicable)

Même principe que les tokens : on stocke `license_key_hash`, pas la clé.
La clé est affichée une fois au client. Vérification par comparaison de hash.
`activation_limit` + `activations_count` limitent la réutilisation.

---

## ✅ CHECKLIST — à repasser à chaque déploiement

```
[ ] Aucun fichier digital dans public/ ni accessible par URL directe
[ ] storage_path n'est jamais exposé au client (ni en HTML, ni en JSON, ni en erreur)
[ ] token_hash en base ; le token clair n'existe que dans l'e-mail
[ ] expires_at, max_downloads, revoked_at vérifiés à CHAQUE requête
[ ] Incrément du compteur atomique (pas de race condition)
[ ] Rate limiting sur la route de téléchargement
[ ] IP hachée dans les logs (jamais l'IP brute) — RGPD
[ ] Échec = 404 générique (jamais « lien expiré » vs « lien inexistant »)
[ ] checksum_sha256 vérifié à l'upload (intégrité du fichier)
[ ] Grants révoqués automatiquement en cas de remboursement
```
