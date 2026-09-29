<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Http;

use JsonException as PhpJsonException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Buffered response returned by SwooleHttpClient.
 */
final class SwooleResponse implements ResponseInterface
{
    private bool $canceled = false;

    /**
     * @param array<string, array<int, string>> $headers
     * @param array<string, mixed> $info
     */
    public function __construct(
        private int $statusCode,
        private array $headers,
        private string $body,
        private array $info,
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->body;
    }

    public function toArray(bool $throw = true): array
    {
        $content = $this->getContent($throw);

        try {
            $decoded = json_decode(
                $content,
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (PhpJsonException $exception) {
            throw new JsonException(
                $exception->getMessage(),
                $exception->getCode(),
                $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new JsonException(
                'The response content must decode to an array.',
            );
        }

        return $decoded;
    }

    public function cancel(): void
    {
        $this->canceled = true;
        $this->info['canceled'] = true;
    }

    public function getInfo(string $type = null): mixed
    {
        $this->info['canceled'] = $this->canceled;

        if ($type !== null) {
            return $this->info[$type] ?? null;
        }

        return $this->info;
    }

    private function checkStatusCode(): void
    {
        if ($this->statusCode >= 500) {
            throw new ServerException($this);
        }

        if ($this->statusCode >= 400) {
            throw new ClientException($this);
        }

        if ($this->statusCode >= 300) {
            throw new RedirectionException($this);
        }
    }
}
