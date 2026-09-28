<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Billing\Gateways\StripeGateway;
use App\Services\Billing\StripeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/billing/webhook/stripe
 *
 * 400 for a missing/invalid signature (nothing is processed), 200 for processed, ignored or
 * duplicate events, 500 when processing failed so Stripe retries later.
 */
final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $gateway, StripeWebhookHandler $handler): JsonResponse
    {
        if ($gateway->webhookSecret() === '') {
            return response()->json(['error' => 'not_configured'], 503);
        }
        $event = $gateway->parseWebhook((string) $request->getContent(), (string) $request->header('Stripe-Signature', ''));
        if ($event === null) {
            Log::warning('Stripe webhook rejected: invalid signature', ['request_id' => $request->attributes->get('request_id')]);
            return response()->json(['error' => 'invalid_signature'], 400);
        }
        $result = $handler->handle($event);
        return response()->json(['status' => $result], $result === 'failed' ? 500 : 200);
    }
}
