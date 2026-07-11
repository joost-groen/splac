<?php declare(strict_types=1);

namespace Splac\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1752148800CreateSplacTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752148800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `splac_template` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `description_templates` JSON NULL,
                `config` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `json.splac_template.description_templates` CHECK (JSON_VALID(`description_templates`)),
                CONSTRAINT `json.splac_template.config` CHECK (JSON_VALID(`config`))
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `splac_category_template` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `parent_category_id` BINARY(16) NULL,
                `parent_category_version_id` BINARY(16) NULL,
                `config` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `json.splac_category_template.config` CHECK (JSON_VALID(`config`))
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `splac_process` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `status` VARCHAR(64) NOT NULL,
                `current_step` VARCHAR(64) NULL,
                `template_id` BINARY(16) NOT NULL,
                `category_template_id` BINARY(16) NULL,
                `product_id` BINARY(16) NULL,
                `product_version_id` BINARY(16) NULL,
                `input` JSON NULL,
                `output` JSON NULL,
                `error_message` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.splac_process.status` (`status`),
                KEY `fk.splac_process.template_id` (`template_id`),
                CONSTRAINT `fk.splac_process.template_id` FOREIGN KEY (`template_id`)
                    REFERENCES `splac_template` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `fk.splac_process.category_template_id` FOREIGN KEY (`category_template_id`)
                    REFERENCES `splac_category_template` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `json.splac_process.input` CHECK (JSON_VALID(`input`)),
                CONSTRAINT `json.splac_process.output` CHECK (JSON_VALID(`output`))
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `splac_process_source` (
                `id` BINARY(16) NOT NULL,
                `process_id` BINARY(16) NOT NULL,
                `media_id` BINARY(16) NULL,
                `filename` VARCHAR(500) NOT NULL,
                `status` VARCHAR(64) NOT NULL,
                `extracted_text` LONGTEXT NULL,
                `error_message` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `fk.splac_process_source.process_id` (`process_id`),
                CONSTRAINT `fk.splac_process_source.process_id` FOREIGN KEY (`process_id`)
                    REFERENCES `splac_process` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);
    }
}
