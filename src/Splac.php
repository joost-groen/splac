<?php declare(strict_types=1);

namespace Splac;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class Splac extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `splac_process_source`');
        $connection->executeStatement('DROP TABLE IF EXISTS `splac_process`');
        $connection->executeStatement('DROP TABLE IF EXISTS `splac_category_template`');
        $connection->executeStatement('DROP TABLE IF EXISTS `splac_template`');
    }
}
