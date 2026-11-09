<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        $token = $plain
            ? ApiToken::query()->where('token_hash', ApiToken::hash($plain))->whereNull('revoked_at')->first()
            : null;

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
