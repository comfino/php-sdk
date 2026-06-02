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

use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\SerializerInterface;
use Comfino\Auth\WebhookSignatureVerifier;
use OverflowException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use ReflectionClass;

/**
 * Centralized webhook endpoint manager for secure API webhook handling.
 *
 * This class manages webhook endpoint registration, request routing, and security verification for incoming webhook
 * requests from Comfino API. It handles request authentication via CR-Signature header validation and provides
 * automatic response preparation.
 *
 * Features:
 * - Endpoint registration and routing by name or URI pattern matching.
 * - CR-Signature authentication with SHA3-256 hash verification.
 * - Support for multiple API keys (for different environments).
 * - Automatic HTTP method-based response status codes (200/201/204/400/401/403/404).
 * - PSR-7 compliant request/response handling.
 *
 * Security:
 * - Validates CR-Signature header against calculated hash.
 * - For GET requests: hash(apiKey + validationKey).
 * - For POST/PUT/PATCH requests: hash(apiKey + requestBody).
 * - Uses timing-safe comparison (hash_equals).
 */
final class WebhookManager
{
    private const MAX_BODY_BYTES = 1_048_576; // 1 MB

    /** @var WebhookEndpointInterface[] Registered webhook endpoints */
    private array $registeredEndpoints = [];

    private ?string $crSignature = null; // Received CR-Signature header value
    private ?string $calculatedCrSignature = null; // Calculated CR-Signature hash

    private readonly WebhookSignatureVerifier $signatureVerifier;

    /**
     * @param string $platformName Name of the platform (e.g., PrestaShop, WooCommerce, Magento)
     * @param string $platformVersion Version of the platform (e.g., 1.0.0)
     * @param string $pluginVersion Version of the Comfino plugin (e.g., 1.0.0)
     * @param string[] $apiKeys Array of API keys for different environments
     * @param ServerRequestFactoryInterface $serverRequestFactory PSR-7 server request factory
     * @param StreamFactoryInterface $streamFactory PSR-7 stream factory
     * @param UriFactoryInterface $uriFactory PSR-7 URI factory
     * @param ResponseFactoryInterface $responseFactory PSR-7 response factory
     * @param SerializerInterface $serializer JSON serializer for request/response body handling
     * @param ReplayProtectionInterface|null $replayProtection Optional replay protection implementation
     * @param RateLimiterInterface|null $rateLimiter Optional rate limiter implementation
     * @param IpWhitelistInterface|null $ipWhitelist Optional IP whitelist implementation
     */
    public function __construct(
        private readonly string $platformName,
        private readonly string $platformVersion,
        private readonly string $pluginVersion,
        private readonly array $apiKeys,
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly SerializerInterface $serializer,
        private readonly ?ReplayProtectionInterface $replayProtection = null,
        private readonly ?RateLimiterInterface $rateLimiter = null,
        private readonly ?IpWhitelistInterface $ipWhitelist = null
    ) {
        $this->signatureVerifier = new WebhookSignatureVerifier();
    }

    /**
     * Registers a webhook endpoint and configures its serializer.
     *
     * @param WebhookEndpointInterface $endpoint The endpoint to register
     */
    public function registerEndpoint(WebhookEndpointInterface $endpoint): void
    {
        $this->registeredEndpoints[$endpoint->getName()] = $endpoint;
        $this->registeredEndpoints[$endpoint->getName()]->setSerializer($this->serializer);
    }

    /**
     * Retrieves a registered endpoint by name.
     *
     * @param string $name The endpoint name
     *
     * @return WebhookEndpointInterface|null The endpoint if found, null otherwise
     */
    public function getEndpointByName(string $name): ?WebhookEndpointInterface
    {
        return $this->registeredEndpoints[$name] ?? null;
    }

    /**
     * Returns CR-Signature received in the HTTP request header.
     *
     * @return string|null The CR-Signature header value or null if not present
     */
    public function getReceivedCrSignature(): ?string
    {
        return $this->crSignature;
    }

    /**
     * Returns internally calculated CR-Signature.
     *
     * @return string|null The calculated signature or null if not yet calculated
     */
    public function getCalculatedCrSignature(): ?string
    {
        return $this->calculatedCrSignature;
    }

    /**
     * Returns a validation key for GET requests processed by plugin API endpoints.
     *
     * @return string Randomly generated validation key
     */
    public function getValidationKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Returns calculated CR signature for plugin API endpoints based on request data.
     *
     * @param string $requestData Request data to sign
     *
     * @return string Calculated CR signature
     */
    public function getCrSignature(string $requestData): string
    {
        return hash('sha3-256', $this->apiKeys[0] . $requestData);
    }

    /**
     * Resets the WebhookManager for testing purposes.
     */
    public static function reset(): void
    {
        // No-op for non-singleton; override in subclasses if needed.
    }

    /**
     * Returns all registered endpoints with their metadata.
     *
     * @return array<string, array{url: string, methods: array<string>}> Endpoint metadata
     */
    public function getRegisteredEndpoints(): array
    {
        $endpoints = [];

        foreach ($this->registeredEndpoints as $endpoint) {
            // Get the endpoint class name and its methods.
            $endpoints[(new ReflectionClass($endpoint))->getShortName()] = [
                'url' => $endpoint->getEndpointUrl(),
                'methods' => $endpoint->getMethods(),
            ];
        }

        return $endpoints;
    }

    /**
     * Processes an incoming webhook request with security verification and routing.
     *
     * @param string|null $endpointName Optional specific endpoint name to route to
     * @param ServerRequestInterface|null $serverRequest Optional PSR-7 request (uses globals if null)
     *
     * @return ResponseInterface PSR-7 response with appropriate status and headers
     */
    public function processRequest(
        ?string $endpointName = null,
        ?ServerRequestInterface $serverRequest = null
    ): ResponseInterface {
        if ($serverRequest === null) {
            try {
                // Create a PSR-7 ServerRequest from PHP globals.
                $serverRequest = $this->getServerRequest();
            } catch (OverflowException $e) {
                // Handle the exception if the request body exceeds the maximum allowed size.
                return $this->getPreparedResponse($this->responseFactory->createResponse(413, $e->getMessage()));
            }
        }

        if ($this->ipWhitelist !== null && !$this->ipWhitelist->isAllowed($serverRequest)) {
            // If the IP is not whitelisted, return a 403 Forbidden response.
            return $this->getPreparedResponse($this->responseFactory->createResponse(403, 'Access forbidden.'));
        }

        try {
            // Verify the request against the API keys and calculate the CR-Signature hash.
            $this->verifyRequest($serverRequest);
        } catch (HttpErrorExceptionInterface $e) {
            // If the request is not authorized, return a 401 Unauthorized response.
            return $this->getPreparedResponse(
                $this->responseFactory->createResponse($e->getStatusCode(), $e->getMessage())
            );
        }

        // Get the client IP address.
        $clientIp = $serverRequest->getServerParams()['REMOTE_ADDR'] ?? '';

        if (($endpointName !== null) && ($endpoint = $this->getEndpointByName($endpointName)) !== null) {
            if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($endpoint->getName(), $clientIp)) {
                // If the rate limit is exceeded, return a 429 Too Many Requests response.
                return $this->getPreparedResponse($this->responseFactory->createResponse(429, 'Rate limit exceeded.'));
            }

            try {
                // Process the webhook request using the endpoint.
                $responseBody = $endpoint->processRequest($serverRequest, $endpointName);

                // Mark the request as processed if replay protection is enabled and request was successfully processed.
                $this->replayProtection?->markProcessed($this->crSignature);

                return $this->prepareResponse($serverRequest, $responseBody);
            } catch (HttpErrorExceptionInterface $e) {
                // If there's an error processing the request, return a 400 Bad Request response.
                return $this->getPreparedResponse(
                    $this->responseFactory->createResponse($e->getStatusCode(), $e->getMessage()),
                    ['error' => $e->getMessage()]
                );
            }
        }

        foreach ($this->registeredEndpoints as $endpoint) {
            if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($endpoint->getName(), $clientIp)) {
                // If the rate limit is exceeded, return a 429 Too Many Requests response.
                return $this->getPreparedResponse($this->responseFactory->createResponse(429, 'Rate limit exceeded.'));
            }

            try {
                // Process the webhook request using the endpoint.
                $responseBody = $endpoint->processRequest($serverRequest, $endpointName);

                // Mark the request as processed if replay protection is enabled and request was successfully processed.
                $this->replayProtection?->markProcessed($this->crSignature);

                return $this->prepareResponse($serverRequest, $responseBody);
            } catch (InvalidEndpoint) {
                // If the endpoint is not found, continue to the next one.
                continue;
            } catch (HttpErrorExceptionInterface $e) {
                // If there's an error processing the request, return a 400 Bad Request response.
                return $this->getPreparedResponse(
                    $this->responseFactory->createResponse($e->getStatusCode(), $e->getMessage()),
                    ['error' => $e->getMessage()]
                );
            }
        }

        // If no endpoint is found, return a 404 Not Found response.
        return $this->getPreparedResponse($this->responseFactory->createResponse(404, 'Endpoint not found.'));
    }

    /**
     * Creates a PSR-7 ServerRequest from PHP globals.
     *
     * @return ServerRequestInterface PSR-7 server request populated from globals
     *
     * @throws OverflowException If the request body exceeds the maximum allowed size
     */
    public function getServerRequest(): ServerRequestInterface
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Determine the scheme, host, and target based on the current environment.
        if (array_key_exists('HTTPS', $_SERVER) && 'off' !== $_SERVER['HTTPS']) {
            $scheme = 'https://';
        } else {
            $scheme = 'http://';
        }

        // Determine the host and target based on the current environment.
        if (array_key_exists('HTTP_HOST', $_SERVER)) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (array_key_exists('SERVER_NAME', $_SERVER)) {
            $host = $_SERVER['SERVER_NAME'];

            if (array_key_exists('SERVER_PORT', $_SERVER)) {
                $host .= (':' . $_SERVER['SERVER_PORT']);
            }
        } else {
            $host = 'localhost';
        }

        // Determine the target based on the current environment.
        if (array_key_exists('REQUEST_URI', $_SERVER)) {
            $target = $_SERVER['REQUEST_URI'];
        } elseif (array_key_exists('PHP_SELF', $_SERVER)) {
            $target = $_SERVER['PHP_SELF'];

            if (array_key_exists('QUERY_STRING', $_SERVER)) {
                $target .= ('?' . $_SERVER['QUERY_STRING']);
            }
        } else {
            $target = '/';
        }

        // Creating a new ServerRequest object with the correct method and URI.
        $serverRequest = $this->serverRequestFactory->createServerRequest(
            $requestMethod,
            $this->uriFactory->createUri($scheme . $host . $target),
            $_SERVER
        );

        // PSR-17 createServerRequest() does not auto-populate headers from $_SERVER HTTP_* keys, extract explicitly.
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $serverRequest = $serverRequest->withHeader(str_replace('_', '-', substr($key, 5)), $value);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' || $key === 'CONTENT_MD5') {
                $serverRequest = $serverRequest->withHeader(str_replace('_', '-', $key), $value);
            }
        }

        if ($requestMethod === 'POST' || $requestMethod === 'PUT' || $requestMethod === 'PATCH') {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

            if ($contentLength > self::MAX_BODY_BYTES) {
                // Throw an exception if the request body exceeds the maximum allowed size.
                throw new OverflowException('Request body exceeds maximum allowed size.');
            }

            // Reading the request body into a stream.
            $input = fopen('php://input', 'rb');
            $resource = fopen('php://temp', 'r+b');

            stream_copy_to_stream($input, $resource, self::MAX_BODY_BYTES);
            rewind($resource);

            $bodyStream = $this->streamFactory->createStreamFromResource($resource);

            if (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
                // If the request content type is JSON, parse the body into an array.
                rewind($input);

                if (!empty($body = stream_get_contents($input))) {
                    // Parse the JSON body into an array.
                    $parsedBody = $this->serializer->unserialize($body);
                }
            } else {
                // If the request content type is not JSON, parse the body into an array.
                $parsedBody = $_POST;
            }

            fclose($input);
        }

        if (isset($bodyStream)) {
            // Return the ServerRequest object with the parsed body and query parameters.
            return isset($parsedBody)
                ? $serverRequest->withQueryParams($_GET)->withBody($bodyStream)->withParsedBody($parsedBody)
                : $serverRequest->withQueryParams($_GET)->withBody($bodyStream);
        }

        // Return the ServerRequest object with the query parameters.
        return $serverRequest->withQueryParams($_GET);
    }

    /**
     * Verifies the CR-Signature header against the API keys and calculates the hash.
     *
     * @throws AuthorizationError If the CR-Signature is missing or invalid
     * @throws AccessDenied If the request is not authorized for the API key
     */
    protected function verifyRequest(ServerRequestInterface $request): void
    {
        // Check if the CR-Signature header is present and valid.
        $this->crSignature = $request->hasHeader('CR-Signature') ? $request->getHeader('CR-Signature')[0] : null;

        if (empty($this->crSignature) && $request->hasHeader('X-CR-Signature')) {
            // Check if the X-CR-Signature header is present and valid.
            $this->crSignature = $request->getHeader('X-CR-Signature')[0] ?? null;
        }

        if (empty($this->crSignature)) {
            // If the CR-Signature header is missing, throw an exception.
            throw new AuthorizationError('Unauthorized request.');
        }

        $requestAuthorized = false;
        $requestMethod = strtoupper($request->getMethod());

        if ($requestMethod === 'GET') {
            // For GET requests, hash(apiKey + validationKey).
            if (!isset($request->getQueryParams()['vkey'])) {
                throw new AuthorizationError('Unauthorized request.');
            }

            $validationKey = $request->getQueryParams()['vkey'];

            foreach ($this->apiKeys as $apiKey) {
                // Calculate the hash for the current API key and validation key.
                $this->calculatedCrSignature = hash('sha3-256', $apiKey . $validationKey);

                if ($this->signatureVerifier->verify($this->crSignature, $apiKey, $validationKey)) {
                    // If the CR-Signature is valid, mark the request as authorized.
                    $requestAuthorized = true;

                    break;
                }
            }
        } else {
            // For POST/PUT/PATCH requests, hash(apiKey + requestBody).
            $requestBody = $request->getBody()->getContents();

            $request->getBody()->rewind();

            foreach ($this->apiKeys as $apiKey) {
                // Calculate the hash for the current API key and request body.
                $this->calculatedCrSignature = hash('sha3-256', $apiKey . $requestBody);

                if ($this->signatureVerifier->verify($this->crSignature, $apiKey, $requestBody)) {
                    // If the CR-Signature is valid, mark the request as authorized.
                    $requestAuthorized = true;

                    break;
                }
            }
        }

        if (!$requestAuthorized) {
            // If the CR-Signature is not valid, throw an exception.
            throw new AccessDenied('Access not allowed. Failed comparison of CR-Signature and shop hash.');
        }

        if ($this->replayProtection?->isDuplicate($this->crSignature)) {
            // If the request is a duplicate, throw an exception.
            throw new AccessDenied('Duplicate webhook request.');
        }
    }

    /**
     * Prepares a PSR-7 response based on the request method and response body.
     *
     * @param ServerRequestInterface $serverRequest The PSR-7 compatible incoming request
     * @param array<string, mixed>|null $responseBody The response body to include in the response
     *
     * @return ResponseInterface The prepared response compatible with PSR-7
     */
    protected function prepareResponse(ServerRequestInterface $serverRequest, ?array $responseBody): ResponseInterface
    {
        return match (strtoupper($serverRequest->getMethod())) {
            'GET' => $this->getPreparedResponse(
                $this->responseFactory->createResponse(200, 'OK'),
                $responseBody
            ),
            'POST' => $this->getPreparedResponse(
                $this->responseFactory->createResponse(201, 'Created'),
                $responseBody
            ),
            'PUT', 'PATCH', 'DELETE' => empty($responseBody)
                ? $this->getPreparedResponse($this->responseFactory->createResponse(204, 'No content'))
                : $this->getPreparedResponse($this->responseFactory->createResponse(200, 'OK'), $responseBody),
            default => $this->getPreparedResponse($this->responseFactory->createResponse(404, 'Endpoint not found.'))
        };
    }

    /**
     * Prepares a PSR-7 response with the given response and optional data.
     *
     * @param ResponseInterface $response The PSR-7 compatible response to prepare
     * @param array<string, mixed>|null $responseData The data to include in the response body
     *
     * @return ResponseInterface The prepared response compatible with PSR-7
     */
    protected function getPreparedResponse(ResponseInterface $response, ?array $responseData = null): ResponseInterface
    {
        $pluginHeader = "$this->platformName $this->platformVersion, Comfino $this->pluginVersion";

        if ($responseData !== null) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Comfino-Plugin', $pluginHeader)
                ->withBody($this->streamFactory->createStream($this->serializer->serialize($responseData)));
        }

        return $response->withHeader('Comfino-Plugin', $pluginHeader);
    }
}
