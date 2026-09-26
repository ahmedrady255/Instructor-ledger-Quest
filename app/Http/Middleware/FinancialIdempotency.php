<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FinancialIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return response()->json([
                'message' => 'The idempotency key field is required.',
                'errors' => ['idempotency_key' => ['The idempotency key field is required.']],
            ], 422);
        }

        $scope = $request->route()?->getName() ?? $request->path();
        $hash = hash('sha256', json_encode($this->canonicalize($request->all()), JSON_THROW_ON_ERROR));
        $cacheKey = 'financial-idempotency:'.hash('sha256', $scope."\0".$key);

        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $cached['hash'] === $hash
                ? response()->json($cached['body'], $cached['code'])
                : response()->json(['message' => 'Idempotency key was already used for a different request.'], 409);
        }

        [$code, $body] = DB::transaction(function () use ($scope, $key, $hash, $request, $next) {
            DB::table('idempotency_records')->insertOrIgnore([
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = IdempotencyRecord::query()
                ->where(['scope' => $scope, 'key' => $key])
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->request_hash !== $hash) {
                return [409, ['message' => 'Idempotency key was already used for a different request.']];
            }

            if ($record->response_code !== null) {
                return [$record->response_code, $record->response_body];
            }

            $response = $next($request);
            $body = $response instanceof JsonResponse
                ? $response->getData(true)
                : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            $record->update(['response_code' => $response->getStatusCode(), 'response_body' => $body]);

            return [$response->getStatusCode(), $body];
        });

        try {
            Cache::put($cacheKey, ['hash' => $hash, 'code' => $code, 'body' => $body], now()->addDay());
        } catch (Throwable) {
            // MySQL is authoritative; a cache outage must not fail a committed request.
        }

        return response()->json($body, $code);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }
}
