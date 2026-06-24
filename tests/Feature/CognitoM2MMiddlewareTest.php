<?php

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.cognito.user_pool_id', 'sa-east-1_ZomcFef3Z');
    config()->set('services.cognito.region', 'sa-east-1');
    config()->set('services.cognito.resource_server', 'ff360-api');
    config()->set('services.cognito.token_leeway', 0);
    config()->set('services.cognito.jwks_cache_ttl', 300);

    cache()->flush();

    Http::preventStrayRequests();

    Http::fake([
        cognitoJwksUrl() => Http::response([
            'keys' => [
                cognitoKeyPair()['jwk'],
            ],
        ]),
    ]);
});

test('health endpoint does not require cognito authentication', function (): void {
    $this->getJson('/api/health')
        ->assertOk();
});

test('kyc pep route requires bearer token', function (): void {
    $this->postJson('/api/kyc/cpf-pep', cognitoApiPayload())
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Missing bearer token.',
        ]);
});

test('kyc pep route denies token without required scope', function (): void {
    $token = cognitoAccessToken(scope: 'ff360-api/kyc.read');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/kyc/cpf-pep', cognitoApiPayload())
        ->assertForbidden()
        ->assertJson([
            'message' => 'Forbidden',
        ]);
});

test('kyc pep route allows token with required scope', function (): void {
    $token = cognitoAccessToken(scope: 'ff360-api/kyc.pep');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/kyc/cpf-pep', cognitoApiPayload())
        ->assertSuccessful()
        ->assertJsonPath('status', 'success');
});

test('scope middleware accepts unprefixed scope format', function (): void {
    $token = cognitoAccessToken(scope: 'kyc.pep');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/kyc/cpf-pep', cognitoApiPayload())
        ->assertSuccessful();
});

test('kyc pep route requires env and document fields', function (): void {
    $token = cognitoAccessToken(scope: 'ff360-api/kyc.pep');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/kyc/cpf-pep', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['env', 'document']);
});

test('kyc pep route validates document format', function (): void {
    $token = cognitoAccessToken(scope: 'ff360-api/kyc.pep');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/kyc/cpf-pep', [
            'env' => 'stage',
            'document' => '123.456.789-00',
        ])
        ->assertBadRequest()
        ->assertJsonPath('message', 'document invalid');
});

test('kyc cpf route rejects cnpj document type', function (): void {
    $token = cognitoAccessToken(scope: 'ff360-api/kyc.pep');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/kyc/cpf-pep', [
            'env' => 'stage',
            'document' => '11.444.777/0001-61',
        ])
        ->assertBadRequest()
        ->assertJsonPath('message', 'document invalid');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cognitoApiPayload(array $overrides = []): array
{
    return array_merge([
        'env' => 'stage',
        'document' => '11144477735',
    ], $overrides);
}

function cognitoIssuer(): string
{
    return 'https://cognito-idp.sa-east-1.amazonaws.com/sa-east-1_ZomcFef3Z';
}

function cognitoJwksUrl(): string
{
    return cognitoIssuer().'/.well-known/jwks.json';
}

/**
 * @return array{private_key: string, jwk: array<string, string>}
 */
function cognitoKeyPair(): array
{
    static $pair = null;

    if (is_array($pair)) {
        return $pair;
    }

    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if ($resource === false) {
        throw new RuntimeException('Unable to generate RSA keypair for tests.');
    }

    $privateKey = '';

    if (! openssl_pkey_export($resource, $privateKey) || $privateKey === '') {
        throw new RuntimeException('Unable to export RSA private key for tests.');
    }

    $details = openssl_pkey_get_details($resource);

    if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
        throw new RuntimeException('Unable to extract RSA public key details for tests.');
    }

    $pair = [
        'private_key' => $privateKey,
        'jwk' => [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'test-kid',
            'n' => base64UrlEncode($details['rsa']['n']),
            'e' => base64UrlEncode($details['rsa']['e']),
        ],
    ];

    return $pair;
}

function cognitoAccessToken(string $scope): string
{
    $keyPair = cognitoKeyPair();
    $now = now()->timestamp;

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => 'test-kid',
    ];

    $claims = [
        'iss' => cognitoIssuer(),
        'token_use' => 'access',
        'client_id' => 'ff360-test-client',
        'scope' => $scope,
        'iat' => $now - 60,
        'exp' => $now + 600,
    ];

    $encodedHeader = base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
    $encodedClaims = base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
    $signedPayload = $encodedHeader.'.'.$encodedClaims;

    $signature = '';

    if (! openssl_sign($signedPayload, $signature, $keyPair['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign JWT for tests.');
    }

    return $signedPayload.'.'.base64UrlEncode($signature);
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
