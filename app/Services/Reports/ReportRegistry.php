<?php

namespace App\Services\Reports;

final class ReportRegistry
{
    /**
     * @var array<string, array{data_source_order: array<int, string>}>
     */
    private const REPORTS = [
        'cpf-pep' => [
            'data_source_order' => ['datalake', 'bigdatacorp'],
        ],
        'cpf-basic' => [
            'data_source_order' => ['datalake', 'bigdatacorp'],
        ],
    ];

    public function has(string $reportCode): bool
    {
        return array_key_exists($this->normalizeReportCode($reportCode), self::REPORTS);
    }

    /**
     * @return array<int, string>
     */
    public function dataSourceOrder(string $reportCode): array
    {
        $normalizedReportCode = $this->normalizeReportCode($reportCode);

        if (! array_key_exists($normalizedReportCode, self::REPORTS)) {
            return [];
        }

        return self::REPORTS[$normalizedReportCode]['data_source_order'];
    }

    private function normalizeReportCode(string $reportCode): string
    {
        return strtolower(trim($reportCode));
    }
}
