<?php

use App\Services\Reports\ReportRegistry;

test('has returns true for registered report codes', function (): void {
    $registry = new ReportRegistry;

    expect($registry->has('cpf-pep'))->toBeTrue()
        ->and($registry->has('cpf-basic'))->toBeTrue();
});

test('data source order matches configured value for registered report codes', function (): void {
    $registry = new ReportRegistry;
    $expectedOrder = ['datalake', 'bigdatacorp'];

    expect($registry->dataSourceOrder('cpf-pep'))->toBe($expectedOrder)
        ->and($registry->dataSourceOrder('cpf-basic'))->toBe($expectedOrder);
});

test('has returns false for unknown report code', function (): void {
    $registry = new ReportRegistry;

    expect($registry->has('unknown-report'))->toBeFalse();
});

test('data source order returns empty array for unknown report code', function (): void {
    $registry = new ReportRegistry;

    expect($registry->dataSourceOrder('unknown-report'))->toBe([]);
});

test('data source order preserves source priority order', function (): void {
    $registry = new ReportRegistry;
    $dataSourceOrder = $registry->dataSourceOrder('cpf-pep');

    expect($dataSourceOrder[0])->toBe('datalake')
        ->and($dataSourceOrder[1])->toBe('bigdatacorp');
});
