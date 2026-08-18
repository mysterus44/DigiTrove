<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use Tests\Concerns\InteractsWithCrmDatabase;

/**
 * ⚠️ CE FICHIER APPARTIENT AU HARNAIS NON TRANSACTIONNEL. NE PAS LE RAMENER SOUS
 * `RefreshesDatabaseAsMigrator` — le test passerait de vert a rouge sans qu'aucun code
 * produit n'ait change.
 *
 * POURQUOI. `OrderService` termine chaque checkout par `SET CONSTRAINTS ALL IMMEDIATE`
 * (ligne 136), pour transformer une violation differee en rollback propre plutot qu'en
 * echec au COMMIT. C'est une bonne pratique. Mais la portee de `SET CONSTRAINTS` est LA
 * TRANSACTION, pas le savepoint : un `RELEASE SAVEPOINT` ne la reinitialise pas.
 *
 * Sous `RefreshDatabase`, tout le test vit dans UNE transaction externe et
 * `DB::transaction()` n'ouvre qu'un savepoint. Le reglage pose par le premier checkout
 * survit donc au second, ou `orders_validate_items_consistency_trigger` — normalement
 * `DEFERRABLE INITIALLY DEFERRED` — se declenche des l'INSERT de `orders`, avant que les
 * `order_items` existent. Resultat : « orders must contain at least one order_item »,
 * sanitise en `integrity_failure`. Un faux defaut, entierement produit par le harnais.
 *
 * En production chaque checkout est sa propre transaction, le reglage ne fuit jamais.
 *
 * CE QUE CE TEST GARDE. La preuve empirique la plus forte dont nous disposions qu'un
 * SECOND acheteur invite distinct peut reellement commander. C'est la fondation de
 * l'attribution P6-D2 : sans elle, un affilie pourrait etre crédité pour une vente qui
 * n'a jamais pu aboutir.
 */
uses(InteractsWithCrmDatabase::class);

it('lets a second distinct guest complete a checkout in production-like conditions', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 15_000,
        // Epingle : la factory tire ce champ au hasard et le CHECK exige qu'il depasse le prix.
        'compare_at_price_minor' => null,
        'is_active' => true,
    ]);

    test()->post(route('cart.items.store', $product->slug));
    test()->post(route('checkout.store'), ['email' => 'premier@example.test']);

    // Un autre navigateur : session vidée, donc nouvelle identite visiteur.
    test()->flushSession();

    test()->post(route('cart.items.store', $product->slug));
    test()->post(route('checkout.store'), ['email' => 'second@example.test']);

    $orders = Order::query()->orderBy('id')->get();

    expect($orders)->toHaveCount(2)
        // Deux identites anonymes distinctes : si elles se confondaient, une attribution
        // pourrait crediter un affilie pour une vente faite par quelqu'un qui n'a jamais
        // vu son lien.
        ->and($orders[0]->visitor_id)->not->toBeNull()
        ->and($orders[1]->visitor_id)->not->toBeNull()
        ->and($orders[0]->visitor_id)->not->toBe($orders[1]->visitor_id)
        // Toujours des invites : aucun compte n'est cree en chemin.
        ->and($orders[0]->user_id)->toBeNull()
        ->and($orders[1]->user_id)->toBeNull();
});
