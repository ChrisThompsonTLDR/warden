<?php

namespace Warden\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateWardenSharedKey
{
    /**
     * Handle an incoming request.
     *
     * Validates requests against the WARDEN_SHARED_KEY for basic protection.
     * This is for local development only; use stronger auth in production.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('warden.shared_key');

        // If no key is configured, allow requests (with warning in logs)
        if (empty($configuredKey)) {
            if (app()->environment('production')) {
                abort(403, 'WARDEN_SHARED_KEY must be configured in production');
            }

            // Log warning in non-production
            logger()->warning('Warden: WARDEN_SHARED_KEY not configured. MCP endpoint is unprotected.');

            return $next($request);
        }

        // Check for key in header (preferred)
        $providedKey = $request->header('X-Warden-Key');

        // Fall back to query parameter
        if (empty($providedKey)) {
            $providedKey = $request->query('warden_key');
        }

        // Fall back to Bearer token
        if (empty($providedKey)) {
            $providedKey = $request->bearerToken();
        }

        if (empty($providedKey) || ! hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'error' => 'Invalid or missing Warden shared key',
                'hint' => 'Provide the key via X-Warden-Key header, warden_key query parameter, or Bearer token',
            ], 401);
        }

        return $next($request);
    }
}
