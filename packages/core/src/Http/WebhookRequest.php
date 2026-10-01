<?php

declare(strict_types=1);

namespace Korbytes\Payments\Http;

use Korbytes\Payments\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Framework-neutral view of an incoming webhook request.
 *
 * Method names mirror the subset of Illuminate\Http\Request that drivers use
 * (all, input, header, getContent) so porting is mechanical.
 */
final class WebhookRequest
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    /**
     * @param  array<string, mixed>  $payload  Decoded body (JSON or form fields)
     * @param  array<string, string|array<int, string>>  $headers
     * @param  array<string, mixed>  $query
     */
    public function __construct(
        private readonly array $payload = [],
        array $headers = [],
        private readonly array $query = [],
        private readonly string $rawBody = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
    }

    /**
     * Normalise whatever the host framework hands over.
     *
     * Accepts a WebhookRequest, a PSR-7 server request, or any request object that
     * exposes the Illuminate/Symfony-style surface (all(), getContent(), headers, query).
     */
    public static function from(object $request): self
    {
        if ($request instanceof self) {
            return $request;
        }

        if ($request instanceof ServerRequestInterface) {
            return self::fromPsr7($request);
        }

        $payload = method_exists($request, 'all')
            ? $request->all()
            : (isset($request->request) && is_object($request->request) && method_exists($request->request, 'all') ? $request->request->all() : []);

        $headers = isset($request->headers) && is_object($request->headers) && method_exists($request->headers, 'all')
            ? $request->headers->all()
            : [];

        $query = isset($request->query) && is_object($request->query) && method_exists($request->query, 'all')
            ? $request->query->all()
            : [];

        $raw = method_exists($request, 'getContent') ? (string) $request->getContent() : '';

        return new self(is_array($payload) ? $payload : [], $headers, $query, $raw);
    }

    /**
     * Build from PHP superglobals, for plain-PHP webhook endpoints.
     *
     * The body is decoded as JSON when possible, otherwise taken from $_POST
     * (ePayco posts form fields), and the query string is merged in like Laravel's all().
     *
     * @param  array<string, mixed>|null  $server  defaults to $_SERVER
     * @param  array<string, mixed>|null  $query  defaults to $_GET
     * @param  array<string, mixed>|null  $post  defaults to $_POST
     * @param  string|null  $rawBody  defaults to php://input
     */
    public static function fromGlobals(?array $server = null, ?array $query = null, ?array $post = null, ?string $rawBody = null): self
    {
        $server ??= $_SERVER;
        $query ??= $_GET;
        $post ??= $_POST;
        $rawBody ??= (string) file_get_contents('php://input');

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr((string) $key, 5))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) && $value !== '') {
                $headers[str_replace('_', '-', (string) $key)] = (string) $value;
            }
        }

        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        $payload = is_array($decoded) ? $decoded : $post;

        return new self($query + $payload, $headers, $query, $rawBody);
    }

    public static function fromPsr7(ServerRequestInterface $request): self
    {
        $raw = (string) $request->getBody();
        $request->getBody()->isSeekable() && $request->getBody()->rewind();

        $parsed = $request->getParsedBody();

        if (! is_array($parsed)) {
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $parsed = is_array($decoded) ? $decoded : [];
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new self($parsed, $headers, $request->getQueryParams(), $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->payload;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        // Dot notation ("data.id") searches the body first, then the query string.
        $value = Arr::get($this->payload, $key);

        return $value ?? Arr::get($this->query, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function getContent(): string
    {
        return $this->rawBody;
    }
}
