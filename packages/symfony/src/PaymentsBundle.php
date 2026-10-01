<?php

declare(strict_types=1);

namespace Korbytes\Payments\Symfony;

use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\Symfony\Command\ProcessSubscriptionsCommand;
use Korbytes\Payments\Symfony\Command\SchemaCommand;
use Korbytes\Payments\Symfony\Controller\WebhookController;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony bundle for korozcolt/payments-core.
 *
 *   # config/packages/payments.yaml
 *   payments:
 *       default: wompi
 *       dsn: '%env(PAYMENTS_DATABASE_DSN)%'          # or pdo: 'my.pdo.service'
 *       drivers:
 *           wompi: { public_key: '%env(WOMPI_PUBLIC_KEY)%', ... }
 *
 *   # config/routes/payments.yaml
 *   payments:
 *       resource: '@PaymentsBundle/config/routes.php'
 */
final class PaymentsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'payments';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default')->defaultValue('wompi')->end()
                ->arrayNode('enabled')
                    ->info('Enabled drivers. Empty enables all of them.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['wompi', 'mercadopago', 'epayco'])
                ->end()
                ->arrayNode('urls')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('return')->defaultNull()->end()
                        ->scalarNode('webhook')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('drivers')
                    ->info('Provider credentials, keyed by driver (wompi, mercadopago, epayco).')
                    ->useAttributeAsKey('name')
                    ->variablePrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('payouts')
                    ->info('Payout credentials, keyed by driver. Separate from "drivers".')
                    ->useAttributeAsKey('name')
                    ->variablePrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('subscriptions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('scheduled_providers')
                            ->info('Providers charged by payments:process-subscriptions. Only those without a billing engine (Wompi).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['wompi'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->scalarNode('statement_descriptor')->defaultNull()->end()
                ->scalarNode('pdo')->defaultNull()->info('Service id of an existing \PDO connection.')->end()
                ->scalarNode('dsn')->defaultNull()->info('PDO DSN, used when "pdo" is not set.')->end()
                ->scalarNode('username')->defaultNull()->end()
                ->scalarNode('password')->defaultNull()->end()
                ->scalarNode('http_client')->defaultNull()->info('Service id of a PSR-18 client that also implements the PSR-17 request/stream factories. Defaults to Symfony HttpClient (30s timeout).')->end()
            ->end();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // --- PDO -------------------------------------------------------------------
        if ($config['pdo'] !== null) {
            $builder->setAlias('payments.pdo', $config['pdo']);
        } else {
            if ($config['dsn'] === null) {
                throw new \LogicException('Configure either payments.pdo (a \PDO service id) or payments.dsn.');
            }

            $builder->setDefinition('payments.pdo', (new Definition(\PDO::class, [
                $config['dsn'],
                $config['username'],
                $config['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            ]))->setLazy(true));
        }

        // --- HTTP (PSR-18 + PSR-17) -----------------------------------------------
        if ($config['http_client'] !== null) {
            $builder->setAlias('payments.http_client', $config['http_client']);
        } else {
            $builder->setDefinition('payments.symfony_http_client', (new Definition(\Symfony\Contracts\HttpClient\HttpClientInterface::class))
                ->setFactory([HttpClient::class, 'create'])
                ->setArguments([['timeout' => 30]]));
            $builder->setDefinition('payments.http_client', (new Definition(Psr18Client::class))
                ->setArguments([new Reference('payments.symfony_http_client')]));
        }

        // --- Core -------------------------------------------------------------------
        $coreConfig = [
            'default' => $config['default'],
            'enabled' => $config['enabled'],
            'urls' => $config['urls'],
            'drivers' => $this->withoutEmpty($config['drivers']),
            'payouts' => $this->withoutEmpty($config['payouts']),
            'subscriptions' => $config['subscriptions'],
            'logging' => $config['logging'],
            'statement_descriptor' => $config['statement_descriptor'],
        ];

        $builder->setDefinition('payments', (new Definition(Standalone::class))
            ->setFactory([Standalone::class, 'pdo'])
            ->setArguments([
                '$config' => $coreConfig,
                '$pdo' => new Reference('payments.pdo'),
                '$http' => new Reference('payments.http_client'),
                '$factory' => new Reference('payments.http_client'),
                '$logger' => new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
                '$events' => new Reference('event_dispatcher'),
            ])
            ->setPublic(true));
        $builder->setAlias(Standalone::class, 'payments')->setPublic(true);

        $builder->setDefinition(WebhookController::class, (new Definition(WebhookController::class))
            ->setArguments([new Reference('payments')])
            ->addTag('controller.service_arguments')
            ->setPublic(true));

        $builder->setDefinition(ProcessSubscriptionsCommand::class, (new Definition(ProcessSubscriptionsCommand::class))
            ->setArguments([new Reference('payments')])
            ->addTag('console.command'));

        $builder->setDefinition(SchemaCommand::class, (new Definition(SchemaCommand::class))
            ->setArguments([new Reference('payments.pdo')])
            ->addTag('console.command'));
    }

    /**
     * Drivers whose credentials are all empty count as not configured.
     *
     * @param  array<string, array<string, mixed>>  $drivers
     * @return array<string, array<string, mixed>>
     */
    private function withoutEmpty(array $drivers): array
    {
        foreach ($drivers as $name => $settings) {
            $credentials = array_filter($settings, fn ($value, $key) => $key !== 'sandbox' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);

            if ($credentials === []) {
                $drivers[$name] = [];
            }
        }

        return $drivers;
    }
}
