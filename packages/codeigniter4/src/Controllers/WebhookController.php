<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Korbytes\Payments\CodeIgniter\RequestMapper;

/**
 * Receives provider webhooks: POST {payments.webhookPrefix}/{provider}.
 *
 * Status codes and bodies come from the core's WebhookHandler, so they are
 * identical to the Laravel, Slim and Symfony adapters.
 *
 * Remember to exclude the webhook URI from the `csrf` filter in app/Config/Filters.php.
 */
class WebhookController extends ResourceController
{
    protected $format = 'json';

    public function handle(string $provider): ResponseInterface
    {
        $result = service('payments')->webhooks()->handle(
            $provider,
            RequestMapper::fromIncomingRequest($this->request),
            ['ip' => $this->request->getIPAddress()],
        );

        return $this->response
            ->setStatusCode($result->status)
            ->setJSON($result->body);
    }
}
