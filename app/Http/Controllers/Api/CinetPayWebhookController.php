<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\CinetPayWebhookRequest;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use App\Services\Payments\PaymentConfirmationService;
use App\Services\Payments\WebhookOutcome;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * CinetPay webhook ingress (P3-D4, D-034).
 *
 * Deliberately thin: HTTP → envelope → service → generic response. It holds no
 * financial logic and never reveals whether an order or a payment exists. The
 * provider authenticates via HMAC inside the service, not via a user session.
 */
final class CinetPayWebhookController extends Controller
{
    /** Liveness ping only: 200, no write, no provider call, no config detail. */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function handle(CinetPayWebhookRequest $request, PaymentConfirmationService $service): JsonResponse
    {
        // Provider webhooks are form-encoded; a JSON body is refused outright.
        if ($request->isJson()) {
            return response()->json(['status' => 'invalid'], 422);
        }

        $params = [];
        foreach ($request->post() as $key => $value) {
            if (is_scalar($value)) {
                $params[(string) $key] = (string) $value;
            }
        }

        $envelope = new ProviderWebhookEnvelope(
            headers: ['x-token' => (string) $request->header('x-token', '')],
            params: $params,
        );

        try {
            $outcome = $service->handleCinetPayWebhook($envelope);
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
            Reason::ProviderUnavailable,
            Reason::ProviderProtocolFailure,
            Reason::ProviderConfigurationFailure => [503, 'unavailable'],
            default => [500, 'error'],
        };

        return response()->json(['status' => $body], $status);
    }
}
