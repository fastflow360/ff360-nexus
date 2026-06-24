<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use RuntimeException;

class CognitoAccessTokenVerifier
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        [$header, $claims, $signature, $signedPayload] = $this->decodeToken($token);

        $this->assertSupportedHeader($header);
        $this->assertClaims($claims);
        $this->assertSignature($header, $signedPayload, $signature);

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<int, string>
     */
    public function extractScopes(array $claims): array
    {
        $scopeClaim = $claims['scope'] ?? null;

        if (! is_string($scopeClaim) || trim($scopeClaim) === '') {
            return [];
        }

        $rawScopes = preg_split('/\s+/', trim($scopeClaim));

        if ($rawScopes === false) {
            return [];
        }

        $scopes = [];

        foreach ($rawScopes as $scope) {
            if (! is_string($scope) || $scope === '') {
                continue;
            }

            $scopes[$scope] = $scope;
        }

        return array_values($scopes);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decodeToken(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Token format is invalid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        if ($encodedHeader === '' || $encodedPayload === '' || $encodedSignature === '') {
            throw new RuntimeException('Token format is invalid.');
        }

        $header = $this->decodeJwtSegmentToArray($encodedHeader, 'header');
        $claims = $this->decodeJwtSegmentToArray($encodedPayload, 'payload');
        $signature = $this->base64UrlDecode($encodedSignature);

        return [$header, $claims, $signature, $encodedHeader.'.'.$encodedPayload];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function assertSupportedHeader(array $header): void
    {
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('Token algorithm is not supported.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaims(array $claims): void
    {
        if (($claims['iss'] ?? null) !== $this->issuer()) {
            throw new RuntimeException('Token issuer is invalid.');
        }

        if (($claims['token_use'] ?? null) !== 'access') {
            throw new RuntimeException('Token must be an access token.');
        }

        if (! isset($claims['client_id']) || ! is_string($claims['client_id']) || $claims['client_id'] === '') {
            throw new RuntimeException('Token client_id is missing.');
        }

        $expiration = $claims['exp'] ?? null;

        if (! is_numeric($expiration)) {
            throw new RuntimeException('Token expiration claim is invalid.');
        }

        $now = now()->timestamp;
        $leeway = $this->tokenLeeway();

        if ((int) $expiration < ($now - $leeway)) {
            throw new RuntimeException('Token has expired.');
        }

        $issuedAt = $claims['iat'] ?? null;

        if ($issuedAt !== null && is_numeric($issuedAt) && (int) $issuedAt > ($now + $leeway)) {
            throw new RuntimeException('Token iat claim is invalid.');
        }

        $notBefore = $claims['nbf'] ?? null;

        if ($notBefore !== null && is_numeric($notBefore) && (int) $notBefore > ($now + $leeway)) {
            throw new RuntimeException('Token nbf claim is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function assertSignature(array $header, string $signedPayload, string $signature): void
    {
        $keyId = $header['kid'] ?? null;

        if (! is_string($keyId) || $keyId === '') {
            throw new RuntimeException('Token key id is missing.');
        }

        $jwk = $this->findSigningKey($keyId);
        $publicKey = $this->buildPublicKeyFromJwk($jwk);
        $verificationResult = openssl_verify($signedPayload, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verificationResult !== 1) {
            throw new RuntimeException('Token signature is invalid.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findSigningKey(string $keyId): array
    {
        foreach ($this->signingKeys() as $key) {
            if (($key['kid'] ?? null) === $keyId) {
                return $key;
            }
        }

        throw new RuntimeException('Token signing key was not found.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function signingKeys(): array
    {
        $expiresAt = now()->addSeconds($this->jwksCacheTtl());

        return $this->cache->remember($this->jwksCacheKey(), $expiresAt, function (): array {
            $response = $this->http
                ->connectTimeout(3)
                ->timeout(5)
                ->retry([100, 200, 500], throw: false)
                ->acceptJson()
                ->get($this->jwksUrl());

            if (! $response->successful()) {
                throw new RuntimeException('Unable to load Cognito signing keys.');
            }

            $keys = $response->json('keys');

            if (! is_array($keys)) {
                throw new RuntimeException('Cognito signing keys payload is invalid.');
            }

            $normalizedKeys = [];

            foreach ($keys as $key) {
                if (is_array($key)) {
                    $normalizedKeys[] = $key;
                }
            }

            if ($normalizedKeys === []) {
                throw new RuntimeException('Cognito signing keys payload is empty.');
            }

            return $normalizedKeys;
        });
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function buildPublicKeyFromJwk(array $jwk): string
    {
        $modulus = $jwk['n'] ?? null;
        $exponent = $jwk['e'] ?? null;

        if (! is_string($modulus) || $modulus === '' || ! is_string($exponent) || $exponent === '') {
            throw new RuntimeException('Signing key is invalid.');
        }

        $encodedRsaPublicKey = $this->asn1EncodeSequence(
            $this->asn1EncodeInteger($this->base64UrlDecode($modulus)),
            $this->asn1EncodeInteger($this->base64UrlDecode($exponent)),
        );

        $encodedAlgorithmIdentifier = $this->asn1EncodeSequence(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01",
            "\x05\x00",
        );

        $encodedSubjectPublicKeyInfo = $this->asn1EncodeSequence(
            $encodedAlgorithmIdentifier,
            "\x03".$this->asn1EncodeLength(strlen($encodedRsaPublicKey) + 1)."\x00".$encodedRsaPublicKey,
        );

        return sprintf(
            "-----BEGIN PUBLIC KEY-----\n%s-----END PUBLIC KEY-----",
            chunk_split(base64_encode($encodedSubjectPublicKeyInfo), 64, "\n"),
        );
    }

    private function asn1EncodeSequence(string ...$encodedValues): string
    {
        $payload = implode('', $encodedValues);

        return "\x30".$this->asn1EncodeLength(strlen($payload)).$payload;
    }

    private function asn1EncodeInteger(string $integerBytes): string
    {
        if ($integerBytes === '') {
            $integerBytes = "\x00";
        }

        if ((ord($integerBytes[0]) & 0x80) !== 0) {
            $integerBytes = "\x00".$integerBytes;
        }

        return "\x02".$this->asn1EncodeLength(strlen($integerBytes)).$integerBytes;
    }

    private function asn1EncodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $binaryLength = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($binaryLength)).$binaryLength;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtSegmentToArray(string $segment, string $segmentName): array
    {
        $decodedSegment = $this->base64UrlDecode($segment);

        try {
            $parsedSegment = json_decode($decodedSegment, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(sprintf('Token %s segment is not valid JSON.', $segmentName));
        }

        if (! is_array($parsedSegment)) {
            throw new RuntimeException(sprintf('Token %s segment is invalid.', $segmentName));
        }

        return $parsedSegment;
    }

    private function base64UrlDecode(string $value): string
    {
        $normalizedValue = strtr($value, '-_', '+/');
        $paddingLength = strlen($normalizedValue) % 4;

        if ($paddingLength > 0) {
            $normalizedValue .= str_repeat('=', 4 - $paddingLength);
        }

        $decodedValue = base64_decode($normalizedValue, true);

        if ($decodedValue === false) {
            throw new RuntimeException('Token contains an invalid base64url segment.');
        }

        return $decodedValue;
    }

    private function jwksUrl(): string
    {
        return $this->issuer().'/.well-known/jwks.json';
    }

    private function jwksCacheKey(): string
    {
        return sprintf('cognito.jwks.%s', sha1($this->jwksUrl()));
    }

    private function issuer(): string
    {
        $configuredIssuer = trim((string) config('services.cognito.issuer', ''));

        if ($configuredIssuer !== '') {
            return rtrim($configuredIssuer, '/');
        }

        return sprintf(
            'https://cognito-idp.%s.amazonaws.com/%s',
            $this->region(),
            $this->userPoolId(),
        );
    }

    private function region(): string
    {
        $configuredRegion = trim((string) config('services.cognito.region', ''));

        if ($configuredRegion !== '') {
            return $configuredRegion;
        }

        $userPoolIdSegments = explode('_', $this->userPoolId(), 2);

        if (($userPoolIdSegments[0] ?? '') === '') {
            throw new RuntimeException('Cognito region could not be inferred.');
        }

        return $userPoolIdSegments[0];
    }

    private function userPoolId(): string
    {
        $userPoolId = trim((string) config('services.cognito.user_pool_id', ''));

        if ($userPoolId === '') {
            throw new RuntimeException('Cognito user pool id is not configured.');
        }

        return $userPoolId;
    }

    private function tokenLeeway(): int
    {
        return max((int) config('services.cognito.token_leeway', 60), 0);
    }

    private function jwksCacheTtl(): int
    {
        return max((int) config('services.cognito.jwks_cache_ttl', 3600), 60);
    }
}
