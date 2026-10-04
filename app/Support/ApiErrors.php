<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Turns unexpected failures on JSON/API requests into short, localized messages the user can
 * act on — never a raw SQL error, class name or stack trace. Each response carries a reference
 * code that also appears in the log, so a support ticket can be matched to the real error.
 */
final class ApiErrors
{
    /** Framework default messages that are replaced by a localized one (custom abort() texts are kept). */
    private const DEFAULT_MESSAGES = ['', 'Not Found', 'Forbidden', 'This action is unauthorized.', 'Too Many Attempts.',
        'Method Not Allowed', 'Server Error', 'Unauthenticated.', 'Service Unavailable', 'Page Expired', 'CSRF token mismatch.'];

    public static function reference(): string
    {
        $id = (string) (Context::get('request_id') ?? '');
        return strtoupper(substr($id !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $id) : Str::random(8), 0, 8));
    }

    /** Database errors caused by the submitted data (missing required value, bad reference, duplicate…) → 422. */
    public static function query(QueryException $e): JsonResponse
    {
        $ref = self::reference();
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $column = preg_match('/column "([a-z0-9_]+)"/i', $e->getMessage(), $m) ? $m[1] : null;
        $label = $column ? self::attribute($column) : null;

        [$status, $key] = match (true) {
            $state === '23502' => [422, $label ? 'errors.required_field' : 'errors.invalid_data'],   // not null
            $state === '23503' => [422, 'errors.missing_reference'],                                 // foreign key
            $state === '23505' => [422, 'errors.duplicate'],                                         // unique
            str_starts_with($state, '22') => [422, 'errors.invalid_data'],                          // bad value / too long
            default => [500, 'errors.server'],
        };
        Log::log($status >= 500 ? 'error' : 'warning', 'API database error', ['ref' => $ref, 'sqlstate' => $state, 'column' => $column, 'error' => $e->getMessage()]);

        $body = ['message' => __($key, ['field' => (string) $label, 'ref' => $ref]), 'reference' => $ref];
        if ($status === 422 && $column) {
            $body['errors'] = [$column => [$body['message']]];
        }
        return response()->json($body, $status);
    }

    /** Anything else: keep HTTP errors (with a localized default text), hide internals of real crashes. */
    public static function other(\Throwable $e): ?JsonResponse
    {
        if ($e instanceof \Illuminate\Validation\ValidationException || $e instanceof \Illuminate\Auth\AuthenticationException) {
            return null; // already precise and localized
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();
            if (!in_array($message, self::DEFAULT_MESSAGES, true) && !str_starts_with($message, 'No query results') && !str_starts_with($message, 'The route')) {
                return null; // a deliberate, already user-facing message (e.g. abort(422, __('…')))
            }
            $key = match ($status) {
                401 => 'errors.unauthenticated', 403 => 'errors.forbidden', 404 => 'errors.not_found', 405 => 'errors.not_found',
                419 => 'errors.expired', 429 => 'errors.too_many', 503 => 'errors.unavailable', default => $status >= 500 ? 'errors.server' : 'errors.invalid_data',
            };
            $ref = self::reference();
            return response()->json(['message' => __($key, ['ref' => $ref]), 'reference' => $ref], $status, $e->getHeaders());
        }
        if (config('app.debug')) {
            return null; // developers keep the full exception page/JSON locally
        }
        $ref = self::reference();
        Log::error('API request failed', ['ref' => $ref, 'exception' => $e::class, 'error' => $e->getMessage()]);
        return response()->json(['message' => __('errors.server', ['ref' => $ref]), 'reference' => $ref], 500);
    }

    private static function attribute(string $column): string
    {
        foreach (['validation.attributes.'.$column, 'planner.fields.'.$column] as $key) {
            $t = __($key);
            if (is_string($t) && $t !== $key) return $t;
        }
        return str_replace('_', ' ', $column);
    }
}
