<?php declare(strict_types=1);

namespace Splac\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1785507500AddPromptCacheUsage extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785507500;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('splac_llm_usage');

        if (!isset($columns['cache_creation_input_tokens'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `splac_llm_usage`
                    ADD COLUMN `cache_creation_input_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0
                        AFTER `input_tokens`;
            SQL);
        }

        if (!isset($columns['cache_read_input_tokens'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `splac_llm_usage`
                    ADD COLUMN `cache_read_input_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0
                        AFTER `cache_creation_input_tokens`;
            SQL);
        }
    }
}
