<?php

use App\Models\Tenant;
use App\Services\Auth\CognitoAccessTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('manager routes require cognito authentication', function (): void {
    $this->getJson('/api/manager/tenants')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Missing bearer token.');
});

test('can create a tenant', function (): void {
    $response = $this->postJson('/api/manager/tenants', tenantApiPayload([
        'id' => 'tenant-acme',
        'name' => 'Acme Inc.',
        'ip' => '10.0.0.1',
        'rate_limit' => 25,
    ]), cognitoHeaders());

    $response
        ->assertCreated()
        ->assertJsonPath('id', 'tenant-acme')
        ->assertJsonPath('name', 'Acme Inc.')
        ->assertJsonPath('ip', '10.0.0.1')
        ->assertJsonPath('rate_limit', 25);

    expect(Tenant::query()->find('tenant-acme'))
        ->not
        ->toBeNull();
});

test('uses default rate limit when value is omitted', function (): void {
    $response = $this->postJson('/api/manager/tenants', tenantApiPayload([
        'id' => 'tenant-beta',
        'name' => 'Beta Corp.',
        'ip' => '192.168.0.10',
    ]), cognitoHeaders());

    $response
        ->assertCreated()
        ->assertJsonPath('rate_limit', 0);

    expect(Tenant::query()->find('tenant-beta')?->rate_limit)
        ->toBe(0);
});

test('rejects invalid ip address', function (): void {
    $this->postJson('/api/manager/tenants', tenantApiPayload([
        'id' => 'tenant-ip-invalid',
        'name' => 'Invalid Ip',
        'ip' => '999.999.999.999',
        'rate_limit' => 10,
    ]), cognitoHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ip']);
});

test('rejects negative rate limit', function (): void {
    $this->postJson('/api/manager/tenants', tenantApiPayload([
        'id' => 'tenant-rate-invalid',
        'name' => 'Invalid Rate',
        'ip' => '127.0.0.1',
        'rate_limit' => -1,
    ]), cognitoHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rate_limit']);
});

test('manager routes do not require env and document in api body payload', function (): void {
    $this->postJson('/api/manager/tenants', [
        'id' => 'tenant-without-api-payload',
        'name' => 'Without Payload',
        'ip' => '10.0.0.2',
        'rate_limit' => 12,
    ], cognitoHeaders())
        ->assertCreated()
        ->assertJsonPath('id', 'tenant-without-api-payload');
});

test('manager routes do not validate document format', function (): void {
    $this->postJson('/api/manager/tenants', tenantApiPayload([
        'env' => 'dev',
        'document' => '123.456.789-00',
        'id' => 'tenant-with-invalid-document',
        'name' => 'Tenant With Invalid Document',
        'ip' => '10.0.0.3',
        'rate_limit' => 20,
    ]), cognitoHeaders())
        ->assertCreated()
        ->assertJsonPath('id', 'tenant-with-invalid-document');
});

test('can list tenants', function (): void {
    Tenant::query()->create([
        'id' => 'tenant-list-a',
        'name' => 'List A',
        'ip' => '10.1.0.1',
        'rate_limit' => 30,
    ]);
    Tenant::query()->create([
        'id' => 'tenant-list-b',
        'name' => 'List B',
        'ip' => '10.1.0.2',
        'rate_limit' => 40,
    ]);

    $response = $this->getJson('/api/manager/tenants?per_page=1', cognitoHeaders());

    $response
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'tenant-list-a')
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('last_page', 2);
});

test('can show a tenant', function (): void {
    Tenant::query()->create([
        'id' => 'tenant-show',
        'name' => 'Show Tenant',
        'ip' => '172.16.0.1',
        'rate_limit' => 15,
    ]);

    $this->getJson('/api/manager/tenants/tenant-show', cognitoHeaders())
        ->assertSuccessful()
        ->assertJsonPath('id', 'tenant-show')
        ->assertJsonPath('name', 'Show Tenant');
});

test('can update a tenant', function (): void {
    Tenant::query()->create([
        'id' => 'tenant-update',
        'name' => 'Update Tenant',
        'ip' => '172.16.0.2',
        'rate_limit' => 20,
    ]);

    $this->patchJson('/api/manager/tenants/tenant-update', tenantApiPayload([
        'name' => 'Updated Tenant',
        'ip' => '172.16.0.3',
        'rate_limit' => 35,
    ]), cognitoHeaders())
        ->assertSuccessful()
        ->assertJsonPath('id', 'tenant-update')
        ->assertJsonPath('name', 'Updated Tenant')
        ->assertJsonPath('ip', '172.16.0.3')
        ->assertJsonPath('rate_limit', 35);

    $tenant = Tenant::query()->findOrFail('tenant-update');

    expect($tenant->name)->toBe('Updated Tenant')
        ->and($tenant->ip)->toBe('172.16.0.3')
        ->and($tenant->rate_limit)->toBe(35);
});

test('can delete a tenant', function (): void {
    Tenant::query()->create([
        'id' => 'tenant-delete',
        'name' => 'Delete Tenant',
        'ip' => '172.16.0.4',
        'rate_limit' => 8,
    ]);

    $this->deleteJson('/api/manager/tenants/tenant-delete', tenantApiPayload(), cognitoHeaders())
        ->assertNoContent();

    expect(Tenant::query()->find('tenant-delete'))
        ->toBeNull();
});

test('tenant route denies token without manager tenant scope', function (): void {
    $response = $this->getJson('/api/manager/tenants', cognitoHeaders(['other.scope']));

    $response
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden');
});

test('tenant routes are protected by manager tenant scope middleware', function (): void {
    $route = app('router')->getRoutes()->getByName('tenants.index');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())
        ->toContain('cognito.scope:manager.tenant');
});

test('serializes tenant timestamps using app timezone', function (): void {
    $tenant = Tenant::query()->create([
        'id' => 'tenant-timezone',
        'name' => 'Timezone Tenant',
        'ip' => '10.10.10.10',
        'rate_limit' => 1,
    ]);

    $response = $this->getJson('/api/manager/tenants/tenant-timezone', cognitoHeaders());

    $response->assertSuccessful();

    $expectedCreatedAt = $tenant->created_at
        ->copy()
        ->setTimezone((string) config('app.timezone'))
        ->format(DateTimeInterface::ATOM);
    $expectedUpdatedAt = $tenant->updated_at
        ->copy()
        ->setTimezone((string) config('app.timezone'))
        ->format(DateTimeInterface::ATOM);

    $response
        ->assertJsonPath('created_at', $expectedCreatedAt)
        ->assertJsonPath('updated_at', $expectedUpdatedAt);
});

/**
 * @param  array<int, string>  $scopes
 * @return array<string, string>
 */
function cognitoHeaders(array $scopes = ['manager.tenant']): array
{
    config()->set('services.cognito.resource_server', 'ff360-api');

    $claims = [
        'iss' => 'https://example.test',
        'token_use' => 'access',
        'client_id' => 'ff360-test-client',
        'scope' => implode(' ', $scopes),
        'iat' => now()->subMinute()->timestamp,
        'exp' => now()->addMinutes(10)->timestamp,
    ];

    $verifier = Mockery::mock(CognitoAccessTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->with('test-access-token')
        ->andReturn($claims)
        ->zeroOrMoreTimes();
    $verifier->shouldReceive('extractScopes')
        ->with($claims)
        ->andReturn($scopes)
        ->zeroOrMoreTimes();

    app()->instance(CognitoAccessTokenVerifier::class, $verifier);

    return [
        'Authorization' => 'Bearer test-access-token',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tenantApiPayload(array $overrides = []): array
{
    return $overrides;
}
