<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter;

use CodeIgniter\HTTP\IncomingRequest;
use Korbytes\Payments\Http\WebhookRequest;

/**
 * Converts a CodeIgniter request into the core's framework-neutral WebhookRequest.
 */
final class RequestMapper
{
    public static function fromIncomingRequest(IncomingRequest $request): WebhookRequest
    {
        $headers = [];
        foreach ($request->headers() as $name => $header) {
            $headers[(string) $name] = is_array($header)
                ? implode(', ', array_map(fn ($h) => $h->getValueLine(), $header))
                : $header->getValueLine();
        }

        $raw = (string) $request->getBody();

        $payload = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        // Form-encoded providers (ePayco) arrive as POST fields.
        $payload ??= (array) $request->getPost();

        $query = (array) $request->getGet();

        return new WebhookRequest($query + $payload, $headers, $query, $raw);
    }
}
