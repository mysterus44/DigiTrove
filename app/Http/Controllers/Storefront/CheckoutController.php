<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Models\Order;
use App\Services\Cart\GuestCartService;
use App\Services\Checkout\CheckoutException;
use App\Services\Checkout\GuestCheckoutOrchestrator;
use App\Services\Checkout\GuestCheckoutSession;
use App\Services\Payments\PaymentInitiationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Guest checkout: an email, an order, a redirect to the provider, and a status page.
 *
 * THE RETURN ROUTE CONFIRMS NOTHING. CinetPay sends the buyer back to a URL under our
 * control, and every parameter on it is attacker-supplied — a forged `status=ACCEPTED`
 * costs nothing to write. The page therefore reads the order state ALREADY in the
 * database and calls no confirmation logic whatsoever. Only the signed webhook plus the
 * provider counter-call can move an order to paid (D-034).
 */
final class CheckoutController
{
    public function __construct(
        private readonly GuestCartService $cart,
        private readonly GuestCheckoutOrchestrator $checkout,
        private readonly GuestCheckoutSession $session,
    ) {}

    /** The summary and the email form. Read only. */
    public function show(): Response
    {
        $view = $this->cart->view();

        if ($view['lines'] === []) {
            return response()->view('storefront.checkout.empty', [], 200);
        }

        return response()->view('storefront.checkout.show', $view);
    }

    public function store(Request $request): RedirectResponse
    {
        // The ONLY thing the client supplies. No price, no currency, no total, no
        // discount — all of that is the authorities' to decide.
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);

        $cart = $this->cart->current();

        if ($cart === null) {
            return redirect()->route('cart.show');
        }

        try {
            $order = $this->checkout->placeOrder((string) $cart->public_id, $validated['email']);
        } catch (CheckoutException) {
            // Deliberately generic. `PricingService` refuses the WHOLE quote when a line
            // stops being sellable, and saying which line — or why — would report the
            // catalogue state of a product an administrator has just withdrawn.
            throw ValidationException::withMessages([
                'email' => 'Votre panier a changé et la commande ne peut pas être créée. Vérifiez votre panier.',
            ])->redirectTo(route('cart.show'));
        }

        try {
            $paymentUrl = $this->checkout->startPayment($order);
        } catch (PaymentInitiationException|RuntimeException) {
            // The order exists and stays `pending`; only the provider hand-off failed.
            // The buyer lands on the status page rather than on a broken redirect.
            return redirect()->route('checkout.status', ['order' => $order->public_id]);
        }

        return redirect()->away($paymentUrl);
    }

    /**
     * The provider return entry point.
     *
     * `CINETPAY_RETURN_URL` is a STATIC configuration value, so it cannot carry a
     * per-order id. This route therefore takes no parameter: it reads the order this
     * session recorded at checkout and forwards to the canonical status URL. With no
     * session it goes to the cart rather than guessing — and, like everything else on
     * this surface, it confirms nothing.
     */
    public function providerReturn(): RedirectResponse
    {
        $publicId = $this->session->currentPublicId();

        if ($publicId === null) {
            return redirect()->route('cart.show');
        }

        return redirect()->route('checkout.status', ['order' => $publicId]);
    }

    /**
     * The status page.
     *
     * `public_id` in the path is an identifier, never an authorisation: it is matched
     * against the one this session recorded at checkout. Any mismatch — someone else's
     * real order, or an id that never existed — gets the same flat 404.
     */
    public function status(string $order): Response
    {
        if (! $this->session->owns($order)) {
            throw new NotFoundHttpException;
        }

        $model = Order::query()->where('public_id', $order)->first();

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        return response()
            ->view('storefront.checkout.status', [
                'order' => $model,
                // Read from the database, written only by the webhook path.
                'status' => $this->statusLabel($model),
            ])
            // An order status is never cacheable: a shared cache could serve one buyer's
            // state to another, and a stale "pending" after payment would be worse.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    /** Plain language, no provider payload, no internal code. */
    private function statusLabel(Order $order): string
    {
        $status = $order->status instanceof \BackedEnum ? (string) $order->status->value : (string) $order->status;

        return match ($status) {
            'paid' => 'Paiement confirmé. Vos liens de téléchargement vous ont été envoyés par e-mail.',
            'partially_refunded', 'refunded' => 'Cette commande a fait l\'objet d\'un remboursement.',
            'cancelled', 'expired' => 'Cette commande n\'est plus valide.',
            default => 'Paiement en cours de vérification. Cette page se mettra à jour une fois la confirmation reçue.',
        };
    }
}
