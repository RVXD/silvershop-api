<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

/**
 * The outcome of handling a resource request: the payload to serialise, the HTTP status, any extra response headers
 * (e.g. pagination) and the XML envelope hints used only when the client negotiates XML. The JSON representation is
 * simply the payload, so list bodies stay bare arrays (backwards-compatible with core's `api/v1/products`).
 */
class ResourceResult
{
    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public function __construct(
        private array $data,
        private int $status = 200,
        private array $headers = [],
        private string $xmlRoot = 'response',
        private string $xmlItemNode = 'item'
    ) {
    }

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function ok(array $data, array $headers = [], string $xmlRoot = 'response', string $xmlItemNode = 'item'): self
    {
        return new self($data, 200, $headers, $xmlRoot, $xmlItemNode);
    }

    /**
     * @param array<mixed> $data
     */
    public static function created(array $data, string $xmlRoot = 'response'): self
    {
        return new self($data, 201, [], $xmlRoot);
    }

    /**
     * @return array<mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getXmlRoot(): string
    {
        return $this->xmlRoot;
    }

    public function getXmlItemNode(): string
    {
        return $this->xmlItemNode;
    }
}
