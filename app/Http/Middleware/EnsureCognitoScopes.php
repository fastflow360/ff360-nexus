<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCognitoScopes
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        if ($requiredScopes === []) {
            return $next($request);
        }

        $grantedScopes = $request->attributes->get('cognito_scopes');

        if (! is_array($grantedScopes)) {
            return $this->forbiddenResponse();
        }

        $scopeLookup = [];

        foreach ($grantedScopes as $grantedScope) {
            if (! is_string($grantedScope) || $grantedScope === '') {
                continue;
            }

            foreach ($this->scopeVariants($grantedScope) as $scopeVariant) {
                $scopeLookup[$scopeVariant] = true;
            }
        }

        $missingScopes = [];

        foreach ($requiredScopes as $requiredScope) {
            if ($requiredScope === '') {
                continue;
            }

            $matchesRequiredScope = false;

            foreach ($this->scopeVariants($requiredScope) as $scopeVariant) {
                if (isset($scopeLookup[$scopeVariant])) {
                    $matchesRequiredScope = true;
                    break;
                }
            }

            if (! $matchesRequiredScope) {
                $missingScopes[] = $requiredScope;
            }
        }

        if ($missingScopes !== []) {
            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function scopeVariants(string $scope): array
    {
        $normalizedScope = trim($scope);

        if ($normalizedScope === '') {
            return [];
        }

        $resourceServer = $this->resourceServer();
        $variants = [$normalizedScope];

        if ($resourceServer === '') {
            return $variants;
        }

        if (str_starts_with($normalizedScope, $resourceServer.'/')) {
            $variants[] = substr($normalizedScope, strlen($resourceServer) + 1);

            return array_values(array_unique(array_filter($variants)));
        }

        $variants[] = $resourceServer.'/'.$normalizedScope;

        return array_values(array_unique(array_filter($variants)));
    }

    private function resourceServer(): string
    {
        return trim((string) config('services.cognito.resource_server', ''));
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Forbidden',
        ], 403);
    }
}
