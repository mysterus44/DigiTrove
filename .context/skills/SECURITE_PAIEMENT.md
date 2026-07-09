# SECURITE_PAIEMENT.md — Transactions & Webhooks
# Règle mère : **on ne livre JAMAIS sur un retour navigateur.** Seule une
# confirmation serveur-à-serveur, revérifiée, déclenche la livraison.

---

## 🧨 LES 4 FRAUDES CLASSIQUES

1. **Retour navigateur falsifié** — le client édite l'URL `?status=success`. Si tu
   livres là-dessus, tu offres ton catalogue.
2. **Webhook forgé** — n'importe qui poste sur ton endpoint. Sans signature, tu gobes.
3. **Montant modifié** — le client paie 500 au lieu de 50 000. Si tu ne revérifies
   pas le montant côté serveur, tu livres.
4. **Webhook rejoué** — le même événement arrive 3 fois. Sans idempotence, tu crées
   3 commandes ou 3 remboursements.

---

## ✅ LE FLUX CORRECT

```
1. Client valide le panier
2. Serveur crée Order (status=pending) + Payment (status=pending, idempotency_key)
3. Serveur appelle l'agrégateur → reçoit une URL de paiement
4. Client paie chez l'agrégateur
5. Agrégateur POST le webhook → notre serveur
   ├─ a) vérifier la SIGNATURE du webhook
   ├─ b) vérifier l'IDEMPOTENCE (déjà traité ? on sort)
   ├─ c) CONTRE-APPELER l'API de l'agrégateur (getStatus) ← ne fais pas confiance au payload
   ├─ d) vérifier que le MONTANT correspond exactement à Order.total_minor
   └─ e) alors seulement : Order → paid, Event OrderPaid → livraison
6. Le retour navigateur ne sert QU'À afficher une page. Il ne livre rien.
```

L'étape (c) est celle que tout le monde saute. C'est celle qui te sauve.

---

## 🔏 VÉRIFICATION DE SIGNATURE

```php
// app/Services/Payment/WebhookVerifier.php
public function verify(Request $request): bool
{
    $signature = $request->header('X-Signature', '');
    $expected  = hash_hmac('sha256', $request->getContent(), config('services.provider.secret'));

    // Comparaison à temps constant : jamais ===, sinon timing attack
    return hash_equals($expected, $signature);
}
```

---

## 🔁 IDEMPOTENCE — la clé unique qui sauve la comptabilité

```php
public function handle(Request $request): Response
{
    abort_unless($this->verifier->verify($request), 401);

    $payload = $request->validated();

    // Contrainte UNIQUE en base sur idempotency_key : la course est impossible
    $payment = Payment::where('idempotency_key', $payload['reference'])->first();
    abort_if($payment === null, 404);

    if ($payment->status !== PaymentStatus::Pending) {
        return response()->json(['message' => 'already processed'], 200);  // 200, pas 4xx
    }

    // 🔴 CONTRE-APPEL : on ne fait pas confiance au contenu du webhook
    $remote = $this->gateway->getStatus($payment->provider_ref);

    if ($remote->status !== 'succeeded') {
        $payment->update(['status' => PaymentStatus::Failed, 'raw_payload' => $payload]);
        return response()->json(['message' => 'ok'], 200);
    }

    // 🔴 MONTANT : comparaison stricte en entiers, jamais de tolérance
    if ($remote->amountMinor !== $payment->amount_minor) {
        Log::critical('FRAUD_ATTEMPT amount mismatch', ['payment_id' => $payment->id]);
        $payment->update(['status' => PaymentStatus::Failed]);
        return response()->json(['message' => 'ok'], 200);
    }

    DB::transaction(function () use ($payment, $payload) {
        $payment->update([
            'status'       => PaymentStatus::Succeeded,
            'processed_at' => now(),
            'raw_payload'  => $payload,
        ]);
        $payment->order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    });

    OrderPaid::dispatch($payment->order);   // → livraison, e-mail, rollups

    return response()->json(['message' => 'ok'], 200);   // réponse rapide
}
```

Détails qui comptent :
- On renvoie **200** même sur un échec métier : sinon l'agrégateur retente en boucle.
- `raw_payload` en `JSONB` : pour l'audit, les litiges, et le debug.
- Le webhook doit répondre **vite** (< 2 s). Tout le lourd part en queue.

---

## 💸 MONTANTS — comparaison en entiers, toujours

```php
// ❌ Catastrophe
if (abs($remote->amount - $order->total) < 0.01) { ... }

// ✅ Entiers, égalité stricte
if ($remote->amountMinor !== $order->total_minor) { throw new AmountMismatch(); }
```

---

## 🏗️ MULTI-AGRÉGATEURS (pattern Gateway)

```php
interface PaymentGateway
{
    public function initiate(Order $order): PaymentIntent;
    public function getStatus(string $providerRef): RemotePaymentStatus;
    public function verifyWebhook(Request $request): bool;
}

// CinetPayGateway · WaveGateway · StripeGateway
// Le reste du code ne dépend QUE de l'interface.
```

Le provider actif se choisit dans `.env` (`PAYMENT_PROVIDER=cinetpay`). Changer
d'agrégateur ne doit jamais toucher `OrderService`.

---

## ⏱️ EXPIRATION & NETTOYAGE

```php
// Job planifié, toutes les 10 minutes
Order::where('status', OrderStatus::Pending)
    ->where('placed_at', '<', now()->subMinutes(30))
    ->update(['status' => OrderStatus::Cancelled]);
```

---

## 💰 REMBOURSEMENTS

Un remboursement ne modifie pas la commande : il crée une ligne `refunds` et
**révoque les download_grants** associés. L'historique reste intact.

---

## ✅ CHECKLIST PAIEMENT

```
[ ] Signature webhook vérifiée (hash_equals, jamais ===)
[ ] Contre-appel getStatus systématique (ne jamais croire le payload)
[ ] Montant comparé en entiers, égalité stricte
[ ] Clé d'idempotence UNIQUE en base
[ ] Webhook répond 200 rapidement ; le lourd part en queue
[ ] Aucune livraison depuis le retour navigateur
[ ] raw_payload archivé (JSONB) pour l'audit
[ ] Tentatives de fraude loggées en niveau critical
[ ] Remboursement → révocation des grants
[ ] Aucune clé API dans le code (.env uniquement)
```
