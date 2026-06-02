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
use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\Exception\InvalidRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Webhook endpoint interface for Comfino API webhook handlers.
 *
 * This interface defines the contract for webhook endpoint implementations that handle
 * incoming webhook requests from Comfino API. Each endpoint is identified by a unique
 * name, supports specific HTTP methods, and is mapped to a URL pattern.
 *
 * Implementations should:
 * - Define allowed HTTP methods (GET, POST, PUT, PATCH, DELETE).
 * - Match incoming requests by endpoint name or URI pattern.
 * - Parse and validate request data.
 * - Return structured response data as an associative array or null.
 * - Throw InvalidEndpoint when the request doesn't match this endpoint.
 * - Throw InvalidRequest when request data is malformed or invalid.
 *
 * @see WebhookEndpoint For base implementation with common utilities.
 * @see WebhookManager For endpoint registration and routing.
 */
interface WebhookEndpointInterface
{
    /**
     * Returns the unique identifier for this endpoint.
     *
     * @return string Unique endpoint identifier (snake_case recommended)
     */
    public function getName(): string;

    /**
     * Returns the list of allowed HTTP methods for this endpoint.
     *
     * @return string[] Array of HTTP method names (e.g., ['GET', 'POST'])
     */
    public function getMethods(): array;

    /**
     * Returns the URL pattern for this endpoint.
     *
     * @return string Endpoint URL pattern (absolute path)
     */
    public function getEndpointUrl(): string;

    /**
     * Sets the JSON serializer for request/response body handling.
     *
     * @param SerializerInterface $serializer JSON serializer instance
     */
    public function setSerializer(SerializerInterface $serializer): void;

    /**
     * Processes an incoming webhook request and returns response data.
     *
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     * @param string|null $endpointName Optional explicit endpoint name for direct routing
     *
     * @return array<string, mixed>|null Response data as associative array or null
     *
     * @throws InvalidEndpoint When request doesn't match this endpoint
     * @throws InvalidRequest When request data is malformed or fails validation
     */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array;
}
