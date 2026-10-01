<?php

declare(strict_types=1);

/** @var \CodeIgniter\Router\RouteCollection $routes */

use Korbytes\Payments\CodeIgniter\Config\Payments;

/** @var Payments $payments */
$payments = config('Payments');

if ($payments->registerRoutes) {
    $routes->post(
        trim($payments->webhookPrefix, '/').'/(:segment)',
        '\Korbytes\Payments\CodeIgniter\Controllers\WebhookController::handle/$1',
    );
}
