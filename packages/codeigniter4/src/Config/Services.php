<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter\Config;

use CodeIgniter\Config\BaseService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Config\Database as DatabaseConfig;
use Korbytes\Payments\CodeIgniter\Events\EventBridge;
use Korbytes\Payments\CodeIgniter\PdoFactory;
use Korbytes\Payments\Core\Standalone;

/**
 * Registers service('payments'), discovered automatically from this Composer package.
 *
 *   $charge = service('payments')->driver('wompi')->charge($paymentData);
 */
class Services extends BaseService
{
    public static function payments(bool $getShared = true): Standalone
    {
        if ($getShared) {
            return static::getSharedInstance('payments');
        }

        /** @var Payments $config */
        $config = config('Payments');

        $databases = new DatabaseConfig;
        $group = $databases->{$config->dbGroup} ?? throw new \InvalidArgumentException("Database group [{$config->dbGroup}] is not defined in app/Config/Database.php.");

        return Standalone::pdo(
            config: $config->toCoreConfig(),
            pdo: PdoFactory::fromDatabaseGroup($group),
            http: new Client(['timeout' => 30]),
            factory: new HttpFactory,
            logger: service('logger'),
            events: new EventBridge,
        );
    }
}
