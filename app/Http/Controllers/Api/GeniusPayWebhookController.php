<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\GeniusPayWebhookRequest;
use App\Payments\GeniusPay\GeniusPayWebhook;
use App\Services\Payments\GeniusPayRefundIntakeService;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use App\Services\Payments\PaymentConfirmationService;
use App\Services\Payments\WebhookOutcome;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * GeniusPay webhook ingress (Genius Pay gate).
 *
 * The twin of {@see CinetPayWebhookController}: HTTP → envelope → service → generic
 * response, no financial logic, and it never reveals whether an order or a payment exists.
 * The provider authenticates by HMAC inside the service, not by a user session.
 *
 * Two things differ, both forced by the provider:
 *
 *  1. GeniusPay posts JSON, where CinetPay posts form fields — so this controller REQUIRES
 *     JSON where the CinetPay one refuses it.
 *  2. The signature covers the RAW BODY, so the exact bytes travel in the envelope
 *     alongside the flattened view. `$request->getContent()` is read once, before anything
 *     re-encodes anything.
 */
final class GeniusPayWebhookController extends Controller
{
    /** Liveness ping only: 200, no write, no provider call, no config detail. */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function handle(
        GeniusPayWebhookRequest $request,
        PaymentConfirmationService $service,
        GeniusPayRefundIntakeService $refunds,
    ): JsonResponse {
        // GeniusPay signs a JSON document; a form-encoded body cannot be the one it signed.
        if (! $request->isJson()) {
            return response()->json(['status' => 'invalid'], 422);
        }

        $rawBody = $request->getContent();

        // The size gate runs BEFORE the HMAC: verifying an unbounded body is a cheap way to
        // burn CPU without ever holding the secret.
        if ($rawBody === '' || strlen($rawBody) > GeniusPayWebhookRequest::MAX_BODY_BYTES) {
            return response()->json(['status' => 'invalid'], 422);
        }

        $decoded = $request->json()->all();
        if (! is_array($decoded)) {
            return response()->json(['status' => 'invalid'], 422);
        }

        $envelope = new ProviderWebhookEnvelope(
            headers: [
                'x-webhook-signature' => (string) $request->header('x-webhook-signature', ''),
                'x-webhook-timestamp' => (string) $request->header('x-webhook-timestamp', ''),
            ],
            params: GeniusPayWebhook::flatten($decoded),
            // The bytes that actually arrived — never a re-encoding of `$decoded`.
            rawBody: $rawBody,
        );

        try {
            $outcome = $service->handleGeniusPayWebhook($envelope, $refunds);
        } catch (PaymentConfirmationException $exception) {
            return $this->refuse($exception->reason);
        } catch (Throwable) {
            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(
            ['status' => $outcome === WebhookOutcome::SignatureRejected ? 'rejected' : 'ok'],
            $outcome->httpStatus(),
        );
    }

    private function refuse(Reason $reason): JsonResponse
    {
        [$status, $body] = match ($reason) {
            Reason::WebhookInvalid => [422, 'invalid'],
            // A signed webhook naming a payment we cannot find yet. 503 invites a retry
            // rather than closing the event: see the unresolved branch of
            // PaymentConfirmationService::confirm(). The response body is the same generic
            // 'unavailable' as a provider outage, so it reveals nothing about local state.
            Reason::PaymentUnavailable,
            Reason::ProviderUnavailable,
            Reason::ProviderProtocolFailure,
            Reason::ProviderConfigurationFailure => [503, 'unavailable'],
            default => [500, 'error'],
        };

        return response()->json(['status' => $body], $status);
    }
}
