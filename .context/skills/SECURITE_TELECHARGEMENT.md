# SECURITE_TELECHARGEMENT.md — Coeur de livraison DigiTrove

Contrat actif : D-029.4 à D-029.6, D-035 et D-036. Ce document décrit le code
réel P4-C0 à P4-C6. Il ne doit jamais servir à contourner G1-G6.

---

## Invariants absolus

1. Les fichiers digitaux restent sur un disque privé. Rien dans `public/`.
2. Aucun token brut, chemin privé, secret HMAC ou IP brute en base ou dans les
   logs.
3. Le grant secret est un CSPRNG de 32 octets. Seul son SHA-256 est persisté.
4. Le secret de tentative est un autre CSPRNG. Seul son SHA-256 est persisté.
5. Aucun secret en query string, URL de redirection, HTML serveur,
   localStorage, sessionStorage, console ou exception.
6. G5 est l'unique chemin qui couple `download_logs.started` et
   `downloads_count + 1`.
7. Un Range ou retry réutilise la même tentative. Il ne consomme jamais une
   nouvelle unité.
8. HEAD ne crée pas de log, ne consomme pas et ne change aucun statut.
9. Une interruption ne rend jamais le quota. La réconciliation clôt l'audit.
10. Seuls les logs terminaux après rétention sont supprimables par G6.
11. Aucun I/O filesystem ou objet, aucune ouverture de stream et aucun callback
    HTTP de streaming ne s'exécute sous transaction PostgreSQL.
12. `DELIVERY_PIPELINE_ENABLED=false` coupe aussi les tentatives déjà émises.
13. Tout paramètre transportant un secret brut est marqué `SensitiveParameter`.

---

## Parcours du secret

```text
SecureDeliveryJob
  -> grant token brut en mémoire du worker
  -> e-mail : /downloads/{publicId}#token=<grant-token>
  -> navigateur lit location.hash
  -> history.replaceState() retire immédiatement le fragment
  -> POST /api/downloads/{publicId}/authorize
       Authorization: Bearer <grant-token>
  -> transaction Order FOR UPDATE -> Grant FOR UPDATE
  -> G5 : log started + compteur +1
  -> secret de tentative distinct
  -> cookie dl_attempt HttpOnly, SameSite=Strict, Secure hors local/testing
  -> GET|HEAD /downloads/{publicId}/file
```

La page d'échange :

* ne consulte pas la BDD ;
* est identique pour un public ID connu ou inconnu ;
* ne charge aucun script, style, analytics ou ressource externe ;
* applique une CSP stricte ;
* ne conserve rien dans un stockage navigateur ;
* n'envoie le grant token qu'au POST dans le header Bearer.

Le cookie contient le **secret de tentative**, jamais le grant token. Son chemin
est limité à `/downloads/{publicId}/file` et sa durée est bornée.

---

## Autorisation non énumérable

`DownloadAuthorizationService` refuse une transaction ambiante, valide la forme
du public ID et du token, calcule le digest en mémoire et effectue le préflight
du ProductFile/disque privé **hors transaction**. Il verrouille ensuite dans une
transaction courte, sans aucun I/O stockage, selon l'ordre global :

```text
Order -> DownloadGrant
```

Sous verrou, il revalide :

* grant non révoqué et non expiré ;
* quota disponible ;
* Order `paid` ou `partially_refunded` ;
* ProductFile toujours présent et actif selon les métadonnées revalidées ;
* absence d'une tentative consommante encore réutilisable.

Inconnu, faux, expiré, révoqué, épuisé, non livrable et fichier indisponible
produisent la même réponse publique. Les raisons internes restent des codes
fermés et sanitizés.

Une autorisation réussie produit exactement :

```text
1 download_logs.started
1 downloads_count + 1
1 secret de tentative brut en mémoire/cookie
1 SHA-256 de tentative en base
```

Le dernier quota peut aussi produire un log direct `denied` non consommant pour
la seconde requête concurrente. Il ne produit jamais une seconde consommation.

---

## Livraison HTTP

`DownloadFileService` résout le digest du cookie puis verrouille :

```text
Order -> DownloadGrant -> DownloadLog
```

La préparation refuse toute transaction ambiante. Elle suit trois phases :

1. transaction DB courte : revalidation de la tentative, du grant, de l'Order
   et du ProductFile, puis snapshot scalaire immuable ;
2. hors transaction : disque/chemin privé, existence, taille, Range,
   `readStream()` ou X-Accel ;
3. transaction DB courte : nouvelle revalidation et finalisation du log.

Le contrôleur reste mince. `PrivateFileLocator` refuse chaque entrée filesystem
si `DB::transactionLevel() !== 0`.

### GET et Range

Un seul Range est accepté :

```text
bytes=start-end
bytes=start-
bytes=-suffix
```

Sont refusés en `416` : multi-range, autre unité, nombres signés, syntaxe
ambiguë, overflow et plage hors fichier.

Headers minimaux :

```text
Accept-Ranges: bytes
Content-Type
Content-Length
Content-Disposition
ETag
Content-Range (206/416)
Cache-Control: private, no-store
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
```

Le nom de fichier est nettoyé, sans CRLF. Aucun `storage_path`, nom de disque ou
chemin absolu n'est exposé.

### HEAD

HEAD effectue les validations mais n'ouvre pas le flux et ne modifie pas le
`DownloadLog`. Il retourne les mêmes métadonnées sans corps.

### Streaming

Mode par défaut : `readStream()` sur disque privé, chunks bornés, positionnement
Range par seek ou discard borné, fermeture du flux dans `finally`. Jamais de
lecture entière, `Storage::url()`, `temporaryUrl()` ou URL permanente.

X-Accel est opt-in. Il exige :

* un disque local privé ;
* un préfixe interne explicitement configuré ;
* un chemin relatif normalisé sans traversée ;
* une taille minimale valide.

Configuration incomplète ou non locale : refus fail-closed. DigiTrove ne
configure jamais Nginx automatiquement.

`completed` signifie que le fichier a été remis au mécanisme de livraison, pas
que le navigateur a reçu chaque octet. Une panne avant ouverture devient
`denied/storage_failure` dans une transaction de finalisation séparée, sans
restitution de quota. Un stream ouvert est fermé si la revalidation finale
refuse la livraison. Une panne après engagement relève du modèle at-least-once
et de la réconciliation.

---

## Rate limiting et pseudonymisation

Deux limiters nommés existent :

```text
download-authorize
download-file
```

La clé combine :

* HMAC-SHA-256 de l'IP avec secret externe et version ;
* SHA-256 du public ID du grant.

Jamais le token. En absence de clé HMAC valide, le fallback est volontairement
plus restrictif, jamais plus permissif.

`download_logs.ip_hash` stocke seulement le pseudonyme HMAC versionné. Le
`user_agent` est borné. Les commandes et métriques n'affichent ni hash, ni IP,
ni e-mail, ni token.

---

## Opérations

### Réconciliation

Un `started` plus ancien que le seuil devient une seule fois :

```text
denied + delivery_interrupted + terminal_at
```

La transaction reprend l'ordre Order -> Grant -> Log. Le quota n'est jamais
décrémenté, aucune nouvelle tentative n'est créée et le rejeu est idempotent.

### Détection d'abus

Signal :

```text
COUNT(DISTINCT ip_hash) > seuil
pour un grant et une fenêtre bornée
```

Le résultat est agrégé. Il n'entraîne aucune révocation automatique.

### Révocation support

Seule la raison `manual_security_reissue` est admise. Order puis Grant sont
verrouillés. `revoked_at` et la raison sont set-once ; le même rejeu est
idempotent, une autre raison ne réécrit rien.

### Purge

Seuls les logs `completed|denied` avec `retention_until <= now()` sont candidats.
G6 reste l'autorité. Aucun grant, Order, OrderItem ou ProductFile n'est supprimé.
Un `started` n'est jamais purgé.

Commandes :

```text
downloads:reconcile
downloads:detect-abuse --dry-run
downloads:purge --dry-run
downloads:metrics
downloads:revoke <id> --dry-run
```

Le scheduler utilise `withoutOverlapping`. `onOneServer` n'est ajouté qu'avec un
cache distribué supportant les verrous atomiques.

---

## Concurrence et incidents

Les preuves C1-C6 utilisent des processus Laravel et connexions
`digitrove_runtime` indépendants :

* double autorisation : une consommation maximum ;
* révocation contre autorisation : sérialisation Order/Grant ;
* deux Range : une ligne, une unité ;
* dernière unité : jamais de dépassement ;
* purge contre lecture : aucun log actif supprimé ;
* double réconciliation : une transition.

En incident :

1. ne jamais réactiver un grant ;
2. ne jamais rendre le quota ;
3. révoquer explicitement avec une raison allowlistée ;
4. réémettre par le service d'émission historique ;
5. conserver les logs jusqu'à leur rétention ;
6. rechercher uniquement par IDs internes et métriques agrégées ;
7. ne jamais coller un token, digest, IP ou chemin privé dans un ticket/log.

---

## Checklist

```text
[ ] DELIVERY_PIPELINE_ENABLED reste false avant validation opérationnelle
[ ] kill switch vérifié sur autorisation, GET, HEAD, Range et appels directs
[ ] aucun fichier digital sous public/
[ ] aucun secret en query string ou logs
[ ] paramètres de secrets bruts marqués SensitiveParameter
[ ] grant token dans fragment puis Bearer POST seulement
[ ] attempt token dans cookie HttpOnly/Strict seulement
[ ] G5 et G6 présents et ACL runtime intactes
[ ] disque privé allowlisté ; aucune URL objet publique
[ ] transactionLevel = 0 pour exists/size/readStream/X-Accel/callback stream
[ ] HEAD inerte ; Range unique ; retries sans quota
[ ] rate limits et TTL/batches dans leurs bornes
[ ] réconciliation, purge et métriques sans PII
[ ] X-Accel activé seulement après revue Nginx
[ ] tests C1-C6 PostgreSQL verts
```
