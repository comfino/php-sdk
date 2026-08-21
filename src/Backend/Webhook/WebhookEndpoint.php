<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

use Comfino\Api\SerializerInterface;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\Exception\InvalidRequest;
use Comfino\Api\Exception\ResponseValidationError;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Abstract base class for webhook endpoint implementations.
 *
 * This class provides common functionality for webhook endpoint handlers that process incoming webhook requests from
 * Comfino API. It handles request matching, HTTP method validation, and request body parsing with support for JSON and
 * form-encoded data.
 *
 * Child classes must:
 * - Define the $methods property with allowed HTTP methods.
 * - Implement processRequest() to handle business logic.
 * - Call parent::__construct() with endpoint name and URL.
 *
 * @see WebhookEndpointInterface For interface contract.
 * @see WebhookManager For endpoint registration and routing.
 */
abstract class WebhookEndpoint implements WebhookEndpointInterface
{
    /**
     * List of allowed HTTP methods for this endpoint (uppercase).
     *
     * Must be defined by child classes. Valid values: 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'.
     *
     * @var string[]
     */
    protected array $methods;

    /**
     * JSON serializer for request/response body handling.
     *
     * @var SerializerInterface
     */
    protected SerializerInterface $serializer;

    /**
     * Creates a new webhook endpoint with a unique name and URL pattern.
     *
     * @param string $name Unique endpoint identifier (snake_case recommended)
     * @param string $endpointUrl URL pattern for request matching (absolute path)
     */
    public function __construct(protected readonly string $name, protected readonly string $endpointUrl)
    {
        $this->serializer = new JsonSerializer();
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return $this->name;
    }

    /** @inheritDoc */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /** @inheritDoc */
    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    /** @inheritDoc */
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    /** @return array<string, mixed>|null */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        if (!$this->endpointPathMatch($serverRequest, $endpointName)) {
            throw new InvalidEndpoint('Endpoint path does not match request path.');
        }

        try {
            if (!is_array($requestPayload = $this->getParsedRequestBody($serverRequest))) {
                throw new InvalidRequest(
                    (string) $serverRequest->getUri(),
                    $serverRequest->getBody()->getContents(),
                    'Invalid request payload.'
                );
            }
        } catch (ResponseValidationError $e) {
            throw new InvalidRequest(
                (string) $serverRequest->getUri(),
                $serverRequest->getBody()->getContents(),
                sprintf('Invalid request payload: %s', $e->getPrevious()->getMessage()),
                $e->getPrevious()->getCode(),
                $e->getPrevious()
            );
        }

        return $requestPayload;
    }

    /**
     * Checks if the incoming request matches this endpoint.
     *
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     * @param string|null $endpointName Optional explicit endpoint name for direct routing
     *
     * @return bool True if request matches this endpoint, false otherwise.
     */
    protected function endpointPathMatch(ServerRequestInterface $serverRequest, ?string $endpointName = null): bool
    {
        $requestMethod = strtoupper($serverRequest->getMethod());

        if ($endpointName !== null && $endpointName === $this->name && in_array($requestMethod, $this->methods, true)) {
            return true;
        }

        return (string) $serverRequest->getUri() === $this->endpointUrl &&
            in_array($requestMethod, $this->methods, true);
    }

    /**
     * Parses and returns the request body based on Content-Type.
     *
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     *
     * @return array<string, mixed>|string|null Parsed request body (array for JSON/form, string for raw, null if empty)
     *
     * @throws ResponseValidationError If JSON parsing fails.
     */
    protected function getParsedRequestBody(ServerRequestInterface $serverRequest): array|string|null
    {
        $contentType = $serverRequest->hasHeader('Content-Type') ? $serverRequest->getHeader('Content-Type')[0] : '';
        $requestPayload = $serverRequest->getBody()->getContents();

        $serverRequest->getBody()->rewind();

        if ($contentType === 'application/json') {
            return $this->serializer->unserialize($requestPayload);
        }

        if (strtoupper($serverRequest->getMethod()) === 'POST') {
            return $serverRequest->getParsedBody();
        }

        return $requestPayload;
    }
}
