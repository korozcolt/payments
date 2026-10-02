<?php

declare(strict_types=1);

namespace Korbytes\Payments\Slim;

use Korbytes\Payments\Core\Standalone;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Registers the payments webhook route on a Slim application.
 *
 *   $payments = Standalone::pdo(config: [...], pdo: $pdo, http: $client, factory: $psr17);
 *
 *   Payments::registerRoutes($app, $payments);   // POST /payments/webhooks/{provider}
 */
final class Payments
{
    /**
     * @param  App<\Psr\Container\ContainerInterface|null>|RouteCollectorProxy<\Psr\Container\ContainerInterface|null>  $app
     */
    public static function registerRoutes(App|RouteCollectorProxy $app, Standalone $payments, string $prefix = '/payments/webhooks'): void
    {
        $handler = new WebhookRequestHandler($payments->webhooks(), $app->getResponseFactory());

        $app->post(rtrim($prefix, '/').'/{provider}', fn ($request, $response, array $args) => $handler->handle(
            $request->withAttribute('provider', $args['provider']),
        ))->setName('payments.webhooks.handle');
    }
}
