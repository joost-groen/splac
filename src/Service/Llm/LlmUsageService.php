<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LlmUsageService
{
    private const CONFIG_PREFIX = 'Splac.config.';

    private const TOKENS_PER_RATE_UNIT = 1000000;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function record(
        ?string $processId,
        string $provider,
        string $model,
        string $operation,
        LlmResponse $response,
    ): float {
        $inputRate = $this->positiveConfigFloat('inputTokenCost');
        $outputRate = $this->positiveConfigFloat('outputTokenCost');
        $ocrPageRate = $this->positiveConfigFloat('ocrPageCost');
        $currency = $this->currency();

        $cost = ($response->inputTokens * $inputRate / self::TOKENS_PER_RATE_UNIT)
            + ($response->outputTokens * $outputRate / self::TOKENS_PER_RATE_UNIT)
            + ($response->ocrPages * $ocrPageRate);
        $cost = round($cost, 8);

        $processBytes = $processId !== null && Uuid::isValid($processId)
            ? Uuid::fromHexToBytes($processId)
            : null;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->connection->transactional(function (Connection $connection) use (
            $processBytes,
            $provider,
            $model,
            $operation,
            $response,
            $inputRate,
            $outputRate,
            $ocrPageRate,
            $cost,
            $currency,
            $now,
        ): void {
            $connection->insert('splac_llm_usage', [
                'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                'process_id' => $processBytes,
                'provider' => mb_substr($provider, 0, 64),
                'model' => mb_substr($model, 0, 255),
                'operation' => mb_substr($operation, 0, 64),
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'ocr_pages' => $response->ocrPages,
                'input_token_rate' => $inputRate,
                'output_token_rate' => $outputRate,
                'ocr_page_rate' => $ocrPageRate,
                'cost' => $cost,
                'currency' => $currency,
                'created_at' => $now->format('Y-m-d H:i:s.v'),
            ], [
                'id' => ParameterType::BINARY,
                'process_id' => ParameterType::BINARY,
            ]);

            if ($processBytes !== null) {
                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE `splac_process`
                        SET
                            `llm_cost` = CASE
                                WHEN `llm_cost_currency` = :currency THEN `llm_cost` + :cost
                                ELSE :cost
                            END,
                            `llm_cost_currency` = :currency
                        WHERE `id` = :processId
                    SQL,
                    ['cost' => $cost, 'currency' => $currency, 'processId' => $processBytes],
                    ['processId' => ParameterType::BINARY]
                );
            }
        });

        return $cost;
    }

    /**
     * @return array{
     *     currency: string,
     *     last24Hours: float,
     *     last30Days: float,
     *     allTime: float,
     *     averageCostPerCompleted: float,
     *     totalCompleted: int,
     *     startedLast30Days: int,
     *     activeProcesses: int,
     *     successRate: float
     * }
     */
    public function statistics(): array
    {
        $currency = $this->currency();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $last24Hours = $now->sub(new \DateInterval('PT24H'));
        $last30Days = $now->sub(new \DateInterval('P30D'));

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(CASE WHEN `created_at` >= :last24Hours THEN `cost` ELSE 0 END), 0) AS `last_24_hours`,
                    COALESCE(SUM(CASE WHEN `created_at` >= :last30Days THEN `cost` ELSE 0 END), 0) AS `last_30_days`,
                    COALESCE(SUM(`cost`), 0) AS `all_time`
                FROM `splac_llm_usage`
                WHERE `currency` = :currency
            SQL,
            [
                'last24Hours' => $last24Hours->format('Y-m-d H:i:s.v'),
                'last30Days' => $last30Days->format('Y-m-d H:i:s.v'),
                'currency' => $currency,
            ]
        );

        $processRow = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    SUM(CASE WHEN `status` = 'done' THEN 1 ELSE 0 END) AS `total_completed`,
                    SUM(CASE WHEN `created_at` >= :last30Days THEN 1 ELSE 0 END) AS `started_last_30_days`,
                    SUM(CASE WHEN `status` IN ('extracting', 'generating', 'creating') THEN 1 ELSE 0 END) AS `active_processes`,
                    SUM(CASE WHEN `status` = 'failed' THEN 1 ELSE 0 END) AS `total_failed`,
                    AVG(
                        CASE
                            WHEN `status` = 'done' AND `llm_cost_currency` = :currency THEN `llm_cost`
                            ELSE NULL
                        END
                    ) AS `average_cost_per_completed`
                FROM `splac_process`
            SQL,
            [
                'last30Days' => $last30Days->format('Y-m-d H:i:s.v'),
                'currency' => $currency,
            ]
        );

        $totalCompleted = (int) ($processRow['total_completed'] ?? 0);
        $totalFailed = (int) ($processRow['total_failed'] ?? 0);
        $concludedProcesses = $totalCompleted + $totalFailed;
        $successRate = $concludedProcesses > 0
            ? ($totalCompleted / $concludedProcesses) * 100
            : 0.0;

        return [
            'currency' => $currency,
            'last24Hours' => round((float) ($row['last_24_hours'] ?? 0), 8),
            'last30Days' => round((float) ($row['last_30_days'] ?? 0), 8),
            'allTime' => round((float) ($row['all_time'] ?? 0), 8),
            'averageCostPerCompleted' => round((float) ($processRow['average_cost_per_completed'] ?? 0), 8),
            'totalCompleted' => $totalCompleted,
            'startedLast30Days' => (int) ($processRow['started_last_30_days'] ?? 0),
            'activeProcesses' => (int) ($processRow['active_processes'] ?? 0),
            'successRate' => round($successRate, 1),
        ];
    }

    private function positiveConfigFloat(string $key): float
    {
        return max(0.0, (float) ($this->systemConfig->get(self::CONFIG_PREFIX . $key) ?? 0));
    }

    private function currency(): string
    {
        $currency = strtoupper(trim((string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'costCurrency') ?? 'EUR')));

        return \in_array($currency, ['EUR', 'USD', 'GBP'], true) ? $currency : 'EUR';
    }
}
