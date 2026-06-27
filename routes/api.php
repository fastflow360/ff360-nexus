<?php

use App\Http\Controllers\TenantController;
use App\Http\Middleware\AuthenticateCognitoM2M;
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
    ])
    ->group(function (): void {
        Route::get('repository', function (): JsonResponse {
            $documents = [];
            $baseDocument = 32400000000;

            for ($index = 1; $index <= 1000000; $index++) {
                $documents[] = (string) ($baseDocument + $index);
            }

            return response()->json([
                'documents' => $documents,
            ]);
        })->middleware('cognito.scope:kyc.repository');

        Route::get('repository/{document}', function (string $document): JsonResponse {
            $documentMask = sprintf(
                '%s%s%s',
                str_repeat('*', 3),
                substr($document, 3, 6),
                str_repeat('*', 2),
            );

            return response()->json([
                'document' => $document,
                'identity' => [
                    'document' => $document,
                    'document_mask' => $documentMask,
                    'full_name' => 'LANA GERON DOS SANTOS',
                    'birth_date' => '1987-04-03',
                    'gender' => 'F',
                    'mother_name' => 'ROSANE DE JESUS GERON DOS SANTOS',
                ],
                'summary' => [
                    'tax_status' => 'REGULAR',
                    'is_deceased' => false,
                    'pep_matches' => 1,
                    'sanction_matches' => 0,
                    'age' => 40,
                ],
                'metadata' => [
                    'product' => 'kyc-repository',
                    'reviews' => 17,
                    'first_review_at' => '2027-07-01T10:15:15Z',
                    'last_review_at' => '2027-07-01T10:15:15Z',
                ],
                'reviews' => [
                    [
                        'id' => '019f0a57-ae42-704f-8389-df232fb5189a',
                        'order' => 1,
                        'trigger' => 'ONBOARDING',
                        'execution_at' => '2026-06-25T19:38:47Z',
                        'document' => [
                            'status' => 'REGULAR',
                            'is_deceased' => false,
                            'age' => 39,
                        ],
                        'pep' => [
                            'matches' => [],
                        ],
                        'sanctions' => [
                            'matches' => [],
                        ],
                    ],
                ],
                'engine' => null,
            ]);
        })->middleware('cognito.scope:kyc.repository');
    });
