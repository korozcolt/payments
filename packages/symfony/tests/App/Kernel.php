<?php

declare(strict_types=1);

namespace Korbytes\Payments\Symfony\Tests\App;

use Korbytes\Payments\Symfony\PaymentsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $dbFile, private readonly array $payments = [])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new PaymentsBundle;
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/payments-symfony-tests/'.md5($this->dbFile).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/payments-symfony-tests/'.md5($this->dbFile).'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->extension('payments', $this->payments + [
            'dsn' => 'sqlite:'.$this->dbFile,
            'drivers' => ['wompi' => [
                'sandbox' => true,
                'public_key' => 'pub',
                'private_key' => 'prv',
                'integrity_secret' => 'i',
                'events_secret' => 'test_events_xxx',
            ]],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../../config/routes.php');
    }
}
