<?php

declare(strict_types=1);

use Korbytes\Payments\Symfony\Controller\WebhookController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import it from config/routes/payments.yaml:
 *
 *   payments:
 *       resource: '@PaymentsBundle/config/routes.php'
 *       prefix: /payments/webhooks      # optional, defaults to the path below
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('payments_webhook', '/payments/webhooks/{provider}')
        ->controller(WebhookController::class)
        ->methods(['POST']);
};
