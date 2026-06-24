<?php

namespace App\Http\Middleware;

use App\Services\Auth\CognitoAccessTokenVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCognitoM2M
{
    public function __construct(
        private readonly CognitoAccessTokenVerifier $tokenVerifier,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken) || $bearerToken === '') {
            return $this->unauthorizedResponse('Missing bearer token.');
        }

        try {
            $claims = $this->tokenVerifier->verify($bearerToken);
        } catch (RuntimeException) {
            return $this->unauthorizedResponse('Invalid access token.');
        }

        $request->attributes->set('cognito_claims', $claims);
        $request->attributes->set('cognito_scopes', $this->tokenVerifier->extractScopes($claims));

        return $next($request);
    }

    private function unauthorizedResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 401);
    }
}
