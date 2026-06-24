<?php

use App\Http\Controllers\TenantController;
use App\Http\Middleware\AuthenticateCognitoM2M;
use App\Http\Middleware\ValidateApiRequestBody;
use App\Http\Middleware\ValidateDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (): Response {
    return response()->noContent(200);
})->withoutMiddleware([
    AuthenticateCognitoM2M::class,
]);

// Routes for manager nexus core
Route::prefix('manager')->group(function (): void {
    Route::apiResource('tenants', TenantController::class)
        ->middleware('cognito.scope:manager.tenant');
});

// Produtcs verticals

Route::prefix('kyc')
    ->middleware([
        ValidateApiRequestBody::class,
        ValidateDocument::class,
    ])
    ->group(function (): void {
        Route::post('cpf-pep', function (): JsonResponse {
            return response()->json([
                'status' => 'success',
                'message' => 'Mock PEP check response',
                'data' => [
                    'is_pep' => false,
                    'risk_level' => 'low',
                ],
            ]);
        })->middleware('cognito.scope:kyc.pep');
    });
