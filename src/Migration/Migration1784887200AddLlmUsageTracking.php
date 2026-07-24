<?php declare(strict_types=1);

namespace Splac\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1784887200AddLlmUsageTracking extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784887200;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('splac_process');
        if (!isset($columns['llm_cost'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `splac_process`
                    ADD COLUMN `llm_cost` DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000
                        AFTER `error_message`;
            SQL);
        }

        if (!isset($columns['llm_cost_currency'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `splac_process`
                    ADD COLUMN `llm_cost_currency` VARCHAR(3) NOT NULL DEFAULT 'EUR'
                        AFTER `llm_cost`;
            SQL);
        }

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `splac_llm_usage` (
                `id` BINARY(16) NOT NULL,
                `process_id` BINARY(16) NULL,
                `provider` VARCHAR(64) NOT NULL,
                `model` VARCHAR(255) NOT NULL,
                `operation` VARCHAR(64) NOT NULL,
                `input_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `output_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `ocr_pages` INT UNSIGNED NOT NULL DEFAULT 0,
                `input_token_rate` DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000,
                `output_token_rate` DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000,
                `ocr_page_rate` DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000,
                `cost` DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000,
                `currency` VARCHAR(3) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.splac_llm_usage.currency_created_at` (`currency`, `created_at`),
                KEY `fk.splac_llm_usage.process_id` (`process_id`),
                CONSTRAINT `fk.splac_llm_usage.process_id` FOREIGN KEY (`process_id`)
                    REFERENCES `splac_process` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);
    }
}
