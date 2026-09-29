<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Http;

use JsonException;
use LogicException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Throwable;
use Traversable;

/**
 * Symfony HttpClient adapter backed by Swoole's coroutine HTTP client.
 *
 * The adapter is synchronous from the caller's point of view, while the
 * underlying socket operations yield inside a Swoole coroutine.
 */
final class SwooleHttpClient implements HttpClientInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $defaultOptions;

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function __construct(array $defaultOptions = [])
    {
        if (! class_exists(Client::class)) {
            throw new LogicException(
                'The Swoole extension is required to use SwooleHttpClient.',
            );
        }

        $defaultOptions = $this->sanitizeOptions($defaultOptions);
        $this->assertSupportedOptions($defaultOptions);
        $this->defaultOptions = array_replace(
            self::OPTIONS_DEFAULTS,
            $defaultOptions,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(
        string $method,
        string $url,
        array $options = [],
    ): ResponseInterface {
        if (Coroutine::getCid() < 0) {
            throw new TransportException(
                'SwooleHttpClient must be used inside a Swoole coroutine.',
            );
        }

        $options = $this->sanitizeOptions($options);
        $this->assertSupportedOptions($options);

        $requestHeaders = $options['headers'] ?? [];
        $options = array_replace($this->defaultOptions, $options);
        $options['headers'] = $this->mergeHeaders(
            $this->defaultOptions['headers'] ?? [],
            $requestHeaders,
        );

        if ($options['proxy'] !== null) {
            throw new TransportException(
                'The proxy option is not supported by SwooleHttpClient.',
            );
        }

        if (! in_array($options['bindto'], [null, '', '0', '0.0.0.0:0'], true)) {
            throw new TransportException(
                'The bindto option is not supported by SwooleHttpClient.',
            );
        }

        if ($options['peer_fingerprint'] !== null) {
            throw new TransportException(
                'The peer_fingerprint option is not supported by SwooleHttpClient.',
            );
        }

        if (
            $options['http_version'] !== null
            && (string) $options['http_version'] !== '1.1'
        ) {
            throw new TransportException(
                'SwooleHttpClient only supports HTTP/1.1.',
            );
        }

        $url = $this->resolveUrl($url, $options['base_uri']);
        $url = $this->appendQuery($url, $options['query']);

        $urlParts = parse_url($url);

        if (
            ! is_array($urlParts)
            || ! in_array($urlParts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($urlParts['host'] ?? null)
            || $urlParts['host'] === ''
        ) {
            throw new TransportException("Invalid HTTP URL: {$url}");
        }

        $method = strtoupper($method);
        $host = $urlParts['host'];
        $ssl = $urlParts['scheme'] === 'https';
        $port = (int) ($urlParts['port'] ?? ($ssl ? 443 : 80));
        $path = $urlParts['path'] ?? '/';

        if (isset($urlParts['query']) && $urlParts['query'] !== '') {
            $path .= '?' . $urlParts['query'];
        }

        $headers = $this->normalizeHeaders($options['headers']);

        if (! isset($headers['host'])) {
            $headers['host'] = $host
                . (($ssl && $port === 443) || (! $ssl && $port === 80)
                    ? ''
                    : ':' . $port);
        }

        if ($options['auth_bearer'] !== null) {
            $headers['authorization'] = 'Bearer ' . $options['auth_bearer'];
        } elseif ($options['auth_basic'] !== null) {
            $credentials = is_array($options['auth_basic'])
                ? implode(':', $options['auth_basic'])
                : (string) $options['auth_basic'];
            $headers['authorization'] = 'Basic ' . base64_encode($credentials);
        }

        $body = $this->prepareBody($options, $headers);
        $connectHost = $options['resolve'][$host] ?? $host;

        if (is_array($connectHost)) {
            $connectHost = reset($connectHost);
        }

        if (! is_string($connectHost) || $connectHost === '') {
            throw new TransportException(
                "Invalid resolved address for host {$host}.",
            );
        }

        $timeout = $options['timeout'] === null
            ? (float) ini_get('default_socket_timeout')
            : (float) $options['timeout'];

        if ((float) $options['max_duration'] > 0) {
            $timeout = min($timeout, (float) $options['max_duration']);
        }

        $settings = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'read_timeout' => $timeout,
            'ssl_verify_peer' => (bool) $options['verify_peer'],
            'ssl_allow_self_signed' => ! (bool) $options['verify_peer'],
        ];

        if ($options['ciphers'] !== null && $options['ciphers'] !== '') {
            $settings['ssl_ciphers'] = $options['ciphers'];
        }

        if ($ssl && (bool) $options['verify_host']) {
            $settings['ssl_host_name'] = $host;
        }

        foreach ([
            'cafile' => 'ssl_cafile',
            'capath' => 'ssl_capath',
            'local_cert' => 'ssl_cert_file',
            'local_pk' => 'ssl_key_file',
            'passphrase' => 'ssl_passphrase',
        ] as $option => $setting) {
            if ($options[$option] !== null && $options[$option] !== '') {
                $settings[$setting] = $options[$option];
            }
        }

        $startTime = microtime(true);
        $client = null;

        try {
            $client = new Client($connectHost, $port, $ssl);

            if (
                ! $client->set($settings)
                || ! $client->setMethod($method)
                || ! $client->setHeaders($headers)
            ) {
                throw new TransportException(
                    'Unable to configure the Swoole HTTP client.',
                );
            }

            if ($body !== '' && ! $client->setData($body)) {
                throw new TransportException(
                    'Unable to set the Swoole HTTP request body.',
                );
            }

            if (is_callable($options['on_progress'])) {
                ($options['on_progress'])(0, 0, [
                    'url' => $url,
                    'http_method' => $method,
                ]);
            }

            if (! $client->execute($path) || $client->statusCode <= 0) {
                $message = $client->errMsg !== ''
                    ? $client->errMsg
                    : "Swoole HTTP request failed with error code {$client->errCode}.";

                throw new TransportException($message, $client->errCode);
            }

            $responseHeaders = $this->normalizeResponseHeaders(
                is_array($client->headers) ? $client->headers : [],
            );
            $responseBody = (string) $client->body;
            $peer = $client->getpeername();

            $info = [
                'canceled' => false,
                'error' => null,
                'http_code' => $client->statusCode,
                'http_method' => $method,
                'redirect_count' => 0,
                'redirect_url' => null,
                'response_headers' => $this->headerLines(
                    $client->statusCode,
                    $responseHeaders,
                ),
                'start_time' => $startTime,
                'total_time' => microtime(true) - $startTime,
                'url' => $url,
                'user_data' => $options['user_data'],
                'primary_ip' => is_array($peer) ? ($peer['host'] ?? null) : null,
                'primary_port' => is_array($peer) ? ($peer['port'] ?? null) : null,
            ];

            if (
                $ssl
                && (bool) $options['capture_peer_cert_chain']
                && false !== ($peerCertificate = $client->getPeerCert())
            ) {
                $info['peer_certificate_chain'] = [$peerCertificate];
            }

            if (is_callable($options['on_progress'])) {
                ($options['on_progress'])(
                    strlen($responseBody),
                    strlen($responseBody),
                    $info,
                );
            }

            return new SwooleResponse(
                statusCode: $client->statusCode,
                headers: $responseHeaders,
                body: $responseBody,
                info: $info,
            );
        } catch (TransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TransportException(
                $exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        } finally {
            if ($client instanceof Client) {
                $client->close();
            }
        }
    }

    public function stream(
        ResponseInterface|iterable $responses,
        float $timeout = null,
    ): ResponseStreamInterface {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $generator = (function () use ($responses) {
            foreach ($responses as $response) {
                if (! $response instanceof ResponseInterface) {
                    throw new TransportException(
                        'SwooleHttpClient::stream() expects ResponseInterface instances.',
                    );
                }

                yield $response => new FirstChunk();

                $content = $response->getContent(false);

                if ($content !== '') {
                    yield $response => new DataChunk(0, $content);
                }

                yield $response => new LastChunk(strlen($content));
            }
        })();

        return new ResponseStream($generator);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $options = $this->sanitizeOptions($options);
        $this->assertSupportedOptions($options);

        $clone = clone $this;
        $clone->defaultOptions = array_replace(
            $this->defaultOptions,
            $options,
        );

        if (array_key_exists('headers', $options)) {
            $clone->defaultOptions['headers'] = $this->mergeHeaders(
                $this->defaultOptions['headers'] ?? [],
                $options['headers'],
            );
        }

        return $clone;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertSupportedOptions(array $options): void
    {
        $unsupported = array_diff_key($options, self::OPTIONS_DEFAULTS);

        if ($unsupported !== []) {
            throw new TransportException(sprintf(
                'Unsupported Swoole HTTP option(s): %s.',
                implode(', ', array_keys($unsupported)),
            ));
        }
    }

    /**
     * EasyWeChat uses Symfony's internal normalized_headers cache when it
     * prepares default options. The public transport only needs headers.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sanitizeOptions(array $options): array
    {
        unset($options['normalized_headers']);

        return $options;
    }

    private function resolveUrl(string $url, mixed $baseUri): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (! is_string($baseUri) || $baseUri === '') {
            throw new TransportException(
                "Relative URL requires a base_uri: {$url}",
            );
        }

        return rtrim($baseUri, '/') . '/' . ltrim($url, '/');
    }

    private function appendQuery(string $url, mixed $query): string
    {
        if (! is_array($query) || $query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }

    /**
     * @param mixed $defaultHeaders
     * @param mixed $requestHeaders
     * @return array<string, string>
     */
    private function mergeHeaders(
        mixed $defaultHeaders,
        mixed $requestHeaders,
    ): array {
        return array_replace(
            $this->normalizeHeaders($defaultHeaders),
            $this->normalizeHeaders($requestHeaders),
        );
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (! is_iterable($headers)) {
            throw new TransportException('HTTP headers must be iterable.');
        }

        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                if (! is_string($value) || ! str_contains($value, ':')) {
                    throw new TransportException("Invalid HTTP header: {$value}");
                }

                [$name, $value] = explode(':', $value, 2);
            }

            $name = strtolower(trim((string) $name));
            $values = is_array($value) ? $value : [$value];
            $normalized[$name] = implode(', ', array_map(
                static fn ($item): string => trim((string) $item),
                $values,
            ));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $headers
     */
    private function prepareBody(array $options, array &$headers): string
    {
        if ($options['json'] !== null) {
            try {
                $body = json_encode(
                    $options['json'],
                    JSON_THROW_ON_ERROR
                    | JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_AMP
                    | JSON_HEX_QUOT
                    | JSON_PRESERVE_ZERO_FRACTION,
                );
            } catch (JsonException $exception) {
                throw new TransportException(
                    'Unable to encode the JSON request body: ' . $exception->getMessage(),
                    0,
                    $exception,
                );
            }

            $headers['content-type'] ??= 'application/json';

            return $body;
        }

        $body = $options['body'];

        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        if (is_array($body)) {
            $headers['content-type'] ??= 'application/x-www-form-urlencoded';

            return http_build_query($body);
        }

        if ($body instanceof Traversable) {
            $content = '';

            foreach ($body as $chunk) {
                $content .= (string) $chunk;
            }

            return $content;
        }

        if (is_callable($body)) {
            $content = '';

            while ('' !== ($chunk = $body(16372))) {
                if (! is_string($chunk)) {
                    throw new TransportException(
                        'The HTTP body callback must return a string.',
                    );
                }

                $content .= $chunk;
            }

            return $content;
        }

        if (! is_string($body)) {
            throw new TransportException('The HTTP request body must be stringable.');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, array<int, string>>
     */
    private function normalizeResponseHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = array_values(array_map(
                static fn ($item): string => (string) $item,
                (array) $value,
            ));
        }

        return $normalized;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<int, string>
     */
    private function headerLines(int $statusCode, array $headers): array
    {
        $lines = ["HTTP/1.1 {$statusCode}"];

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }
}
