<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDocument
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $document = $this->normalizeDocument((string) $request->input('document', ''));
        $expectedDocumentType = $this->resolveDocumentTypeFromRequestPath($request);

        if (! $this->isValidDocument($document, $expectedDocumentType)) {
            return $this->invalidDocumentResponse();
        }

        $request->merge([
            'document' => $document,
        ]);

        return $next($request);
    }

    private function normalizeDocument(string $document): string
    {
        $normalizedDocument = preg_replace('/[.\/\-\s]/', '', trim($document));

        return is_string($normalizedDocument) ? strtoupper($normalizedDocument) : '';
    }

    private function resolveDocumentTypeFromRequestPath(Request $request): ?string
    {
        $pathSegments = explode('/', trim($request->path(), '/'));
        $reportCode = strtolower(trim((string) end($pathSegments)));

        if ($reportCode === '') {
            return null;
        }

        $reportCodeParts = explode('-', $reportCode);
        $documentType = trim($reportCodeParts[0] ?? '');

        return $documentType !== '' ? $documentType : null;
    }

    private function isValidDocument(string $document, ?string $documentType): bool
    {
        if ($document === '' || $documentType === null) {
            return false;
        }

        $validators = $this->documentValidators();
        $validator = $validators[$documentType] ?? null;

        if ($validator === null) {
            return false;
        }

        return $validator($document);
    }

    /**
     * @return array<string, Closure(string): bool>
     */
    private function documentValidators(): array
    {
        return [
            'cpf' => fn (string $document): bool => $this->isValidCpf($document),
            'cnpj' => fn (string $document): bool => $this->isValidCnpj($document),
        ];
    }

    private function isValidCpf(string $document): bool
    {
        if (preg_match('/^\d{11}$/', $document) !== 1) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $document) === 1) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $document[$index] * (($position + 1) - $index);
            }

            $verificationDigit = ((10 * $sum) % 11) % 10;

            if ((int) $document[$position] !== $verificationDigit) {
                return false;
            }
        }

        return true;
    }

    private function isValidCnpj(string $document): bool
    {
        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $document) !== 1) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $document) === 1) {
            return false;
        }

        $firstVerificationWeights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $secondVerificationWeights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        if ((int) $document[12] !== $this->calculateCnpjVerificationDigit($document, $firstVerificationWeights)) {
            return false;
        }

        return (int) $document[13] === $this->calculateCnpjVerificationDigit($document, $secondVerificationWeights);
    }

    /**
     * @param  array<int, int>  $weights
     */
    private function calculateCnpjVerificationDigit(string $document, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $characterValue = $this->cnpjCharacterValue($document[$index] ?? '');

            if ($characterValue < 0) {
                return -1;
            }

            $sum += $characterValue * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }

    private function cnpjCharacterValue(string $character): int
    {
        if ($character === '') {
            return -1;
        }

        if (ctype_digit($character)) {
            return (int) $character;
        }

        if ($character >= 'A' && $character <= 'Z') {
            return ord($character) - 48;
        }

        return -1;
    }

    private function invalidDocumentResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'document invalid',
        ], 400);
    }
}
