<?php

declare(strict_types=1);

namespace Korbytes\Payments\Symfony;

use Korbytes\Payments\Http\WebhookRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Converts a Symfony request into the core's framework-neutral WebhookRequest.
 */
final class RequestMapper
{
    public static function fromSymfony(Request $request): WebhookRequest
    {
        $raw = $request->getContent();

        $payload = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        // Form-encoded providers (ePayco) arrive as request fields.
        $payload ??= $request->request->all();

        $query = $request->query->all();

        return new WebhookRequest($query + $payload, $request->headers->all(), $query, $raw);
    }
}
