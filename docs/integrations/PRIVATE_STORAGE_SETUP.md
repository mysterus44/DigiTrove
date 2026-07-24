# Private Storage Setup

Ce guide configure le stockage derrière D-036. Il ne rend aucun fournisseur
actif et ne remplace pas sa documentation officielle.

## Règles communes

* Le disque doit être déclaré dans `DELIVERY_PRIVATE_DISKS`.
* Sa visibilité ne doit jamais être `public` et `serve` ne doit jamais être
  activé.
* Les secrets restent dans `.env`, jamais dans Git.
* `product_files.storage_path` reste relatif, normalisé et non exposé au client.
* Aucun bucket public, URL permanente, `Storage::url()` ou `temporaryUrl()`.
* Vérifier l'existence et la taille exacte du fichier avant toute remise.

Configuration applicative par défaut :

```dotenv
FILESYSTEM_DISK=private
DELIVERY_PRIVATE_DISKS=private
DELIVERY_FILE_DRIVER=stream
DELIVERY_STREAM_CHUNK_BYTES=1048576
DELIVERY_ACCELERATION_DRIVER=none
DELIVERY_X_ACCEL_PREFIX=
DELIVERY_X_ACCEL_MIN_BYTES=0
```

## Disque local privé

Le disque `private` de `config/filesystems.php` pointe vers
`storage/app/private`. Les fichiers n'y sont pas servis par le web server.

```php
'private' => [
    'driver' => 'local',
    'root' => storage_path('app/private'),
    'visibility' => 'private',
    'serve' => false,
    'throw' => true,
],
```

Conserver les droits système minimaux : lecture pour le processus PHP, aucune
publication directe par Nginx/Apache.

## X-Accel-Redirect optionnel

X-Accel est réservé au stockage local privé. Exemple à adapter et auditer :

```nginx
# Exemple uniquement. Le préfixe est internal et ne doit jamais être public.
location /_digitrove_private/ {
    internal;
    alias /srv/digitrove/storage/app/private/;
}
```

```dotenv
DELIVERY_FILE_DRIVER=x_accel
DELIVERY_ACCELERATION_DRIVER=x_accel
DELIVERY_X_ACCEL_PREFIX=/_digitrove_private
DELIVERY_X_ACCEL_MIN_BYTES=104857600
```

Le préfixe ne doit pas contenir `..`. DigiTrove construit l'URI interne depuis
le `storage_path` validé ; il n'accepte aucune URL fournie par l'utilisateur.
Une configuration incomplète échoue fermée.

## Stockage S3-compatible

Utiliser Laravel Filesystem uniquement si le driver requis est déjà installé et
audité. Le mode actuel passe par `readStream()` ; il ne génère aucune URL.

```dotenv
# Exemple de noms seulement, sans valeur ni endpoint inventé.
FILESYSTEM_DISK=s3-private
DELIVERY_PRIVATE_DISKS=s3-private
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Le disque doit conserver `visibility=private` et `throw=true`. Tester en sandbox :

* lecture privée et absence d'accès anonyme ;
* comportement de `readStream()` ;
* taille et checksum ;
* chiffrement au repos et en transit ;
* permissions minimales du compte technique ;
* rotation et révocation des clés.

Le streaming Range applicatif peut devoir lire et écarter les octets précédents
si le flux distant n'est pas seekable. Pour de gros objets, ne pas activer en
production sans audit de performance et stratégie fournisseur officielle. Aucun
endpoint de téléchargement spécifique n'est ajouté dans ce gate.

## Fournisseurs à auditer séparément

Pour chacun des fournisseurs suivants :

```text
TODO(PROVIDER-OFFICIAL-DOC):
- vérifier l'endpoint officiel ;
- vérifier la région ;
- vérifier le style path/virtual-host ;
- vérifier le support Range/readStream ;
- vérifier le chiffrement ;
- placer les secrets dans .env ;
- interdire le bucket public ;
- tester en sandbox ;
- ne jamais committer les secrets.
```

| Fournisseur | État |
|---|---|
| AWS S3 | Non configuré, documentation officielle à vérifier |
| Cloudflare R2 | Non configuré, documentation officielle à vérifier |
| DigitalOcean Spaces | Non configuré, documentation officielle à vérifier |
| Wasabi | Non configuré, documentation officielle à vérifier |
| Backblaze B2 | Non configuré, documentation officielle à vérifier |

Ne jamais copier un endpoint depuis un exemple tiers sans vérification
officielle. Aucun de ces fournisseurs n'est actif automatiquement.

## Validation opérationnelle

Avant d'activer `DELIVERY_PIPELINE_ENABLED=true` :

1. vérifier le disque privé et ses ACL ;
2. déposer un fichier de test non sensible ;
3. confirmer `exists`, `size` et `readStream` ;
4. tester GET, HEAD et les trois formes de Range ;
5. confirmer que les réponses ne contiennent ni disque ni chemin interne ;
6. tester l'échec stockage et l'absence de secret dans les logs ;
7. tester rate limiting, réconciliation et purge en environnement de staging ;
8. conserver le pipeline désactivé si une seule preuve échoue.
