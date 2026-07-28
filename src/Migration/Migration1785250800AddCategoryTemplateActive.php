<?php declare(strict_types=1);

namespace Splac\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1785250800AddCategoryTemplateActive extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785250800;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('splac_category_template');
        if (isset($columns['active'])) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `splac_category_template`
                ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1
                    AFTER `name`;
        SQL);
    }
}
