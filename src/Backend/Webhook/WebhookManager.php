<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
 * requests from ComfinoPay API. It handles request authentication via CR-Signature header validation and provides
 * automatic response preparation.
 *
 * Features:
 * - Endpoint registration and routing by name or URI pattern matching.
 * - CR-Signature authentication with SHA3-256 hash verification.
 * - Per-tenant key resolution, so one manager can serve many merchants safely.
 * - The verified tenant handed to endpoints that ask for it, so one manager can be a shared service.
 * - Automatic HTTP method-based response status codes (200/201/204/400/401/403/404/413/429).
 * - PSR-7 compliant request/response handling.
 *
 * Security:
 * - Resolves the tenant *before* verifying, then verifies against that tenant's key only.
 * - Validates CR-Signature header against calculated hash.
 * - For GET requests: hash(apiKey + validationKey).
 * - For POST/PUT/PATCH requests: hash(apiKey + requestBody).
 * - Uses timing-safe comparison (hash_equals).
 *
 * **On tenant resolution.** This class used to take a flat `string[] $apiKeys` and try each key until one matched.
 * In a host serving many merchants the natural wiring is to pass every merchant's key — and then any valid
 * ComfinoPay signature authorizes a request for every merchant: the request really is from ComfinoPay, so it
 * verifies, and it is then handled as whichever tenant the URL named. The library's default integration was the
 * insecure one, and only hosts that noticed on their own escaped it. A {@see WebhookTenantResolverInterface} makes
 * the safe shape the default: resolve the tenant from the request, then verify against that tenant's key alone.
 * Single-shop plugins pass {@see StaticApiKeyResolver}, or keep passing an array — the constructor wraps it in
 * one — and see no change.
 */
final class WebhookManager
{
    /** Default cap on an inbound body, in bytes. Override per instance through the constructor. */
    public const DEFAULT_MAX_BODY_BYTES = 1_048_576; // 1 MB

    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_PAYLOAD_TOO_LARGE = 413;

    /** @var WebhookEndpointInterface[] Registered webhook endpoints */
    private array $registeredEndpoints = [];

    private ?string $crSignature = null; // Received CR-Signature header value (last request; diagnostics only)
    private ?string $calculatedCrSignature = null; // Calculated CR-Signature hash (last request; diagnostics only)

    private readonly WebhookSignatureVerifier $signatureVerifier;
    private readonly WebhookTenantResolverInterface $tenantResolver;
    private readonly ?TenantAwareReplayProtectionInterface $replay;
    private readonly ?TenantAwareRateLimiterInterface $limiter;
    private readonly ClientIpResolver $ipResolver;
    private readonly ReplayKeyExtractorInterface $replayKeyExtractor;

    /**
     * @param string $platformName Name of the platform (e.g., PrestaShop, WooCommerce, Magento)
     * @param string $platformVersion Version of the platform (e.g., 1.0.0)
     * @param string $pluginVersion Version of the ComfinoPay plugin (e.g., 1.0.0)
     * @param string[]|WebhookTenantResolverInterface $apiKeys Either a resolver that maps a request to exactly one
     *                                                         merchant's keys, or — for a single-shop plugin — that
     *                                                         shop's API keys, which are wrapped in a
     *                                                         {@see StaticApiKeyResolver}
     * @param ServerRequestFactoryInterface $serverRequestFactory PSR-7 server request factory
     * @param StreamFactoryInterface $streamFactory PSR-7 stream factory
     * @param UriFactoryInterface $uriFactory PSR-7 URI factory
     * @param ResponseFactoryInterface $responseFactory PSR-7 response factory
     * @param SerializerInterface $serializer JSON serializer for request/response body handling
     * @param ReplayProtectionInterface|TenantAwareReplayProtectionInterface|null $replayProtection Optional replay
     *                                                         protection; a legacy implementation is adapted, losing
     *                                                         only the tenant partition and the TTL it cannot carry
     * @param RateLimiterInterface|TenantAwareRateLimiterInterface|null $rateLimiter Optional rate limiter; a legacy
     *                                                         implementation is adapted, and its rejections then carry
     *                                                         no `Retry-After` because it has none to report
     * @param IpWhitelistInterface|null $ipWhitelist Optional IP whitelist implementation
     * @param int|null $replayTtlSeconds Retention for processed signatures; null selects the interface default
     * @param int $rateLimitTokens Token cost charged per request against the limiter
     * @param ClientIpResolver|null $ipResolver Resolves the caller's address behind reverse proxies, for the
     *                                          rate-limit key and for {@see VerifiedWebhookRequest::$clientIp}; pass
     *                                          the *same instance* given to {@see IpWhitelist} so both guards agree on
     *                                          who called, or `new ClientIpResolver([])` when the host has already
     *                                          resolved it into `REMOTE_ADDR`
     * @param ReplayKeyExtractorInterface|null $replayKeyExtractor What counts as "the same delivery" for replay
     *                                                             protection; defaults to
     *                                                             {@see SignatureReplayKeyExtractor}, which is the
     *                                                             3.0 behavior — read its docblock before enabling
     *                                                             replay protection, because the signature identifies
     *                                                             a payload rather than a delivery
     * @param int|null $maxBodyBytes Cap on an inbound body; null selects {@see DEFAULT_MAX_BODY_BYTES}
     */
    public function __construct(
        private readonly string $platformName,
        private readonly string $platformVersion,
        private readonly string $pluginVersion,
        array|WebhookTenantResolverInterface $apiKeys,
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly SerializerInterface $serializer,
        ReplayProtectionInterface|TenantAwareReplayProtectionInterface|null $replayProtection = null,
        RateLimiterInterface|TenantAwareRateLimiterInterface|null $rateLimiter = null,
        private readonly ?IpWhitelistInterface $ipWhitelist = null,
        private readonly ?int $replayTtlSeconds = null,
        private readonly int $rateLimitTokens = 1,
        ?ClientIpResolver $ipResolver = null,
        ?ReplayKeyExtractorInterface $replayKeyExtractor = null,
        private readonly ?int $maxBodyBytes = null
    ) {
        $this->signatureVerifier = new WebhookSignatureVerifier();

        $this->tenantResolver = is_array($apiKeys) ? new StaticApiKeyResolver($apiKeys) : $apiKeys;

        $this->ipResolver = $ipResolver ?? ClientIpResolver::default();
        $this->replayKeyExtractor = $replayKeyExtractor ?? new SignatureReplayKeyExtractor();

        $this->replay = $replayProtection instanceof ReplayProtectionInterface
            ? new LegacyReplayProtectionAdapter($replayProtection)
            : $replayProtection;

        $this->limiter = $rateLimiter instanceof RateLimiterInterface
            ? new LegacyRateLimiterAdapter($rateLimiter)
            : $rateLimiter;
    }

    /**
     * Returns the resolver deciding which merchant an inbound request belongs to.
     */
    public function getTenantResolver(): WebhookTenantResolverInterface
    {
        return $this->tenantResolver;
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
     *
     * @deprecated Reads the *last verified request's* value from instance state, which in a manager shared across
     *             tenants is whichever request most recently passed through it - possibly another merchant's. Read
     *             {@see VerifiedWebhookRequest::$receivedSignature} from {@see verifyRequest()} instead. Kept for
     *             plugin diagnostics screens that display it after a single call.
     */
    public function getReceivedCrSignature(): ?string
    {
        return $this->crSignature;
    }

    /**
     * Returns internally calculated CR-Signature.
     *
     * @return string|null The calculated signature or null if not yet calculated
     *
     * @deprecated Reads the *last verified request's* value from instance state; see
     *             {@see getReceivedCrSignature()}. Read {@see VerifiedWebhookRequest::$calculatedSignature} instead.
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
     * Signs with the resolved tenant's most current key. In a multi-tenant host the tenant must be named: there is
     * no inbound request to read it from here, and signing with an arbitrary merchant's key would produce a value
     * ComfinoPay attributes to the wrong shop.
     *
     * @param string $requestData Request data to sign
     * @param string|null $tenantKey Merchant to sign for; null asks the resolver for the integration's single tenant,
     *                               which only a single-shop integration can answer
     *
     * @return string Calculated CR signature
     *
     * @throws AuthorizationError If the tenant cannot be resolved or has no usable API key
     */
    public function getCrSignature(string $requestData, ?string $tenantKey = null): string
    {
        $tenant = $this->tenantResolver->resolveByTenantKey($tenantKey);

        if ($tenant === null) {
            throw new AuthorizationError(sprintf('Unable to calculate CR-Signature: no API key configured for tenant "%s".', $tenantKey ?? '(default)'));
        }

        return hash('sha3-256', $tenant->primaryApiKey() . $requestData);
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
    public function processRequest(?string $endpointName = null, ?ServerRequestInterface $serverRequest = null): ResponseInterface
    {
        if ($serverRequest === null) {
            try {
                // Create a PSR-7 ServerRequest from PHP globals.
                $serverRequest = $this->getServerRequest();
            } catch (OverflowException $e) {
                // Handle the exception if the request body exceeds the maximum allowed size.
                return $this->getPreparedResponse($this->responseFactory->createResponse(self::HTTP_PAYLOAD_TOO_LARGE, $e->getMessage()));
            }
        } elseif (($bodySize = $serverRequest->getBody()->getSize()) !== null && $bodySize > $this->maxBodyBytes()) {
            /* The same cap on the path a framework integration actually takes. Enforcing it only for the globals path
               made it a documented guarantee that most integrations never got - and the ones that pass their own
               request are exactly the ones a bound is easy to forget in. */
            return $this->getPreparedResponse(
                $this->responseFactory->createResponse(self::HTTP_PAYLOAD_TOO_LARGE, 'Request body exceeds maximum allowed size.')
            );
        }

        if ($this->ipWhitelist !== null && !$this->ipWhitelist->isAllowed($serverRequest)) {
            // If the IP is not whitelisted, return a 403 Forbidden response.
            return $this->getPreparedResponse($this->responseFactory->createResponse(403, 'Access forbidden.'));
        }

        /* Resolved once, through the same resolver, the allow-list should be given. Reading REMOTE_ADDR raw here while
           the allow-list resolved forwarding headers meant the two guards disagreed about who called: behind a load
           balancer the allow-list saw the caller and the limiter saw the proxy, so every request in the world shared
           one bucket. */
        $clientIp = $this->ipResolver->resolveFromRequest($serverRequest);

        try {
            /* Resolve which merchant this request belongs to, then verify the CR-Signature against that merchant's key
               only - never against a list spanning tenants. */
            $verified = $this->verifyRequest($serverRequest, $clientIp);
        } catch (HttpErrorExceptionInterface $e) {
            // If the request is not authorized, return a 401 Unauthorized response.
            return $this->getPreparedResponse($this->responseFactory->createResponse($e->getStatusCode(), $e->getMessage()));
        }

        /* One request, one token. The limit used to be consumed inside the endpoint loop, so a manager with three
           registered endpoints charged up to three tokens for one request - against the names of endpoints that did not
           handle it, which made the counters both wrong and misattributed, and made the effective quota depend on
           registration order. Routing first costs one pass over the endpoint list and nothing else. */
        $routed = $this->routeRequest($serverRequest, $endpointName);

        if (($rejection = $this->enforceRateLimit($routed?->getName() ?? (string) $endpointName, $verified)) !== null) {
            return $rejection;
        }

        if (($endpointName !== null) && ($endpoint = $this->getEndpointByName($endpointName)) !== null) {
            try {
                // Process the webhook request using the endpoint.
                $responseBody = $this->dispatch($endpoint, $serverRequest, $endpointName, $verified);

                // Mark the request as processed if replay protection is enabled and request was successfully processed.
                $this->markProcessed($verified);

                return $this->prepareResponse($serverRequest, $responseBody);
            } catch (HttpErrorExceptionInterface $e) {
                // If there's an error processing the request, return a 400 Bad Request response.
                return $this->getPreparedResponse($this->responseFactory->createResponse($e->getStatusCode(), $e->getMessage()), ['error' => $e->getMessage()]);
            }
        }

        foreach ($this->registeredEndpoints as $endpoint) {
            try {
                // Process the webhook request using the endpoint.
                $responseBody = $this->dispatch($endpoint, $serverRequest, $endpointName, $verified);

                // Mark the request as processed if replay protection is enabled and request was successfully processed.
                $this->markProcessed($verified);

                return $this->prepareResponse($serverRequest, $responseBody);
            } catch (InvalidEndpoint) {
                // If the endpoint is not found, continue to the next one.
                continue;
            } catch (HttpErrorExceptionInterface $e) {
                // If there's an error processing the request, return a 400 Bad Request response.
                return $this->getPreparedResponse($this->responseFactory->createResponse($e->getStatusCode(), $e->getMessage()), ['error' => $e->getMessage()]);
            }
        }

        // If no endpoint is found, return a 404 Not Found response.
        return $this->getPreparedResponse($this->responseFactory->createResponse(404, 'Endpoint not found.'));
    }

    /**
     * Hands the request to an endpoint, telling it which merchant the request was verified for when it can use that.
     *
     * The distinction is what lets an endpoint be tenant-agnostic and a manager be a container service; see
     * {@see TenantAwareWebhookEndpointInterface} for why it is a second interface rather than a third parameter on the
     * first one.
     *
     * @param WebhookEndpointInterface $endpoint The endpoint to invoke
     * @param ServerRequestInterface $serverRequest The verified inbound request
     * @param string|null $endpointName Explicit endpoint name, when the caller named one
     * @param VerifiedWebhookRequest $verified The verification outcome
     *
     * @return array<string, mixed>|null Response body the endpoint produced
     */
    private function dispatch(
        WebhookEndpointInterface $endpoint,
        ServerRequestInterface $serverRequest,
        ?string $endpointName,
        VerifiedWebhookRequest $verified
    ): ?array {
        if ($endpoint instanceof TenantAwareWebhookEndpointInterface) {
            return $endpoint->processTenantRequest($serverRequest, $endpointName, $verified);
        }

        return $endpoint->processRequest($serverRequest, $endpointName);
    }

    /**
     * Returns the endpoint this request will be handled by, without invoking or charging anything.
     *
     * Deliberately duplicates {@see WebhookEndpoint::endpointPathMatch()}'s rule (a name match, or an exact URL match
     * with an allowed method) using only interface methods, because the real match is protected and adding a
     * `matches()` to {@see WebhookEndpointInterface} would break every endpoint written so far. An endpoint that
     * matches by some other rule resolves to null here and is still reached by the fallback loop - it just has its
     * token charged under the requested name rather than its own.
     *
     * @param ServerRequestInterface $serverRequest The inbound request
     * @param string|null $endpointName Explicit endpoint name, when the caller named one
     *
     * @return WebhookEndpointInterface|null The endpoint that will handle the request, or null when none matches
     */
    private function routeRequest(ServerRequestInterface $serverRequest, ?string $endpointName): ?WebhookEndpointInterface
    {
        $requestMethod = strtoupper($serverRequest->getMethod());

        if ($endpointName !== null && ($named = $this->getEndpointByName($endpointName)) !== null) {
            return in_array($requestMethod, $named->getMethods(), true) ? $named : null;
        }

        foreach ($this->registeredEndpoints as $endpoint) {
            if ((string) $serverRequest->getUri() === $endpoint->getEndpointUrl() && in_array($requestMethod, $endpoint->getMethods(), true)) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * The body cap in effect for this manager.
     */
    private function maxBodyBytes(): int
    {
        return $this->maxBodyBytes ?? self::DEFAULT_MAX_BODY_BYTES;
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
        $serverRequest = $this->serverRequestFactory->createServerRequest($requestMethod, $this->uriFactory->createUri($scheme . $host . $target), $_SERVER);

        // PSR-17 createServerRequest() does not autopopulate headers from $_SERVER HTTP_* keys, extract explicitly.
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $serverRequest = $serverRequest->withHeader(str_replace('_', '-', substr($key, 5)), $value);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' || $key === 'CONTENT_MD5') {
                $serverRequest = $serverRequest->withHeader(str_replace('_', '-', $key), $value);
            }
        }

        if ($requestMethod === 'POST' || $requestMethod === 'PUT' || $requestMethod === 'PATCH') {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

            if ($contentLength > $this->maxBodyBytes()) {
                // Throw an exception if the request body exceeds the maximum allowed size.
                throw new OverflowException('Request body exceeds maximum allowed size.');
            }

            // Reading the request body into a stream.
            $input = fopen('php://input', 'rb');
            $resource = fopen('php://temp', 'r+b');

            stream_copy_to_stream($input, $resource, $this->maxBodyBytes());
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
     * Resolves the request's tenant and verifies the CR-Signature against that tenant's key(s).
     *
     * The order is the security property. Resolution happens first and narrows verification to one merchant's secrets;
     * only then is the signature checked. Verifying against a list spanning merchants would accept any authentic
     * ComfinoPay signature for any merchant, since the signature proves who *sent* the request, not whose shop it is
     * about. A tenant that cannot be resolved is rejected outright — there is deliberately no fallback to trying other
     * merchants' keys.
     *
     * More than one key is tried only when the resolved tenant itself has more than one, which happens during a key
     * rotation, and both keys belong to the same merchant.
     *
     * @param ServerRequestInterface $request The untrusted inbound request
     * @param string|null $clientIp Caller address as resolved by {@see ClientIpResolver}, carried on the result so the
     *                              guards, the endpoints and the logs all name the same caller
     *
     * @return VerifiedWebhookRequest The tenant this request belongs to, plus the signatures, for the caller to thread
     *                                through rate limiting and replay marking
     *
     * @throws AuthorizationError If the CR-Signature is missing or the tenant cannot be resolved
     * @throws AccessDenied If the signature does not match the tenant's key, or the request is a replay
     */
    protected function verifyRequest(ServerRequestInterface $request, ?string $clientIp = null): VerifiedWebhookRequest
    {
        // Check if the CR-Signature header is present and valid.
        $crSignature = $request->hasHeader('CR-Signature') ? $request->getHeader('CR-Signature')[0] : null;

        if (empty($crSignature) && $request->hasHeader('X-CR-Signature')) {
            // Check if the X-CR-Signature header is present and valid.
            $crSignature = $request->getHeader('X-CR-Signature')[0] ?? null;
        }

        // Kept for the diagnostic accessors; the authoritative per-request values travel in the returned object.
        $this->crSignature = $crSignature;
        $this->calculatedCrSignature = null;

        if (empty($crSignature)) {
            // If the CR-Signature header is missing, throw an exception.
            throw new AuthorizationError('Unauthorized request.');
        }

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            /* Either the request names no tenant, or it names one this integration does not serve, or that tenant has
               no usable API key. All three are unauthorized: without a secret to compare against there is no
               verification to perform, and comparing against an empty key would authorize anyone. */
            throw new AuthorizationError('Unauthorized request: unknown tenant or no API key configured.');
        }

        $requestMethod = strtoupper($request->getMethod());

        if ($requestMethod === 'GET') {
            // For GET requests, hash(apiKey + validationKey).
            if (!isset($request->getQueryParams()['vkey'])) {
                throw new AuthorizationError('Unauthorized request.');
            }

            $signedValue = (string) $request->getQueryParams()['vkey'];
        } else {
            // For POST/PUT/PATCH requests, hash(apiKey + requestBody).
            $signedValue = $request->getBody()->getContents();

            $request->getBody()->rewind();
        }

        $requestAuthorized = false;

        foreach ($tenant->apiKeys as $apiKey) {
            // Calculate the hash for the current API key and the signed value.
            $this->calculatedCrSignature = hash('sha3-256', $apiKey . $signedValue);

            if ($this->signatureVerifier->verify($crSignature, $apiKey, $signedValue)) {
                // If the CR-Signature is valid, mark the request as authorized.
                $requestAuthorized = true;

                break;
            }
        }

        if (!$requestAuthorized) {
            // If the CR-Signature is not valid, throw an exception.
            throw new AccessDenied('Access not allowed. Failed comparison of CR-Signature and shop hash.');
        }

        /* What counts as "the same delivery" is the extractor's decision, not the signature's. A null key means this
           delivery cannot be identified, and then it is processed rather than dropped - see ReplayKeyExtractorInterface
           on why failing open is the right direction. */
        $replayKey = $this->replayKeyExtractor->extract($request, $crSignature);

        if ($replayKey !== null && $this->replay?->isDuplicate($replayKey, $tenant->tenantKey)) {
            // If the request is a duplicate, throw an exception.
            throw new AccessDenied('Duplicate webhook request.');
        }

        return new VerifiedWebhookRequest(
            $tenant,
            $crSignature,
            (string) $this->calculatedCrSignature,
            $clientIp,
            $replayKey
        );
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
            'GET' => $this->getPreparedResponse($this->responseFactory->createResponse(200, 'OK'), $responseBody),
            'POST' => $this->getPreparedResponse($this->responseFactory->createResponse(201, 'Created'), $responseBody),
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
     * @param array<string, string> $extraHeaders Additional headers, e.g. the rate-limit headers on a 429
     *
     * @return ResponseInterface The prepared response compatible with PSR-7
     */
    protected function getPreparedResponse(ResponseInterface $response, ?array $responseData = null, array $extraHeaders = []): ResponseInterface
    {
        $pluginHeader = "$this->platformName $this->platformVersion, Comfino $this->pluginVersion";

        $response = $response->withHeader('Comfino-Plugin', $pluginHeader);

        foreach ($extraHeaders as $headerName => $headerValue) {
            $response = $response->withHeader($headerName, $headerValue);
        }

        if ($responseData !== null) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->serializer->serialize($responseData)));
        }

        return $response;
    }

    /**
     * Records a successfully processed request in the replay store, under its tenant's partition.
     *
     * @param VerifiedWebhookRequest $verified The verification outcome, carrying the tenant and the signature
     */
    private function markProcessed(VerifiedWebhookRequest $verified): void
    {
        if ($verified->replayKey === null) {
            // Nothing identified this delivery, so there is nothing to recognize it by later either.
            return;
        }

        $this->replay?->markProcessed($verified->replayKey, $verified->tenantKey(), $this->replayTtlSeconds);
    }

    /**
     * Applies the rate limiter for the resolved tenant, returning a prepared 429 when the request must be rejected.
     *
     * The limit is counted against (endpoint, caller IP, tenant). The tenant is what makes the partition correct: all
     * ComfinoPay webhooks arrive from ComfinoPay's own infrastructure, so the caller IP identifies the sender rather
     * than the merchant, and without the tenant one merchant's burst would throttle every other merchant's
     * notifications. The caller IP is the *resolved* one — see {@see ClientIpResolver} and the note in
     * {@see processRequest()}.
     *
     * Called exactly once per request, before the endpoint runs. It used to be called per endpoint tried.
     *
     * The rejection carries whatever the limiter could report — `Retry-After`, `X-RateLimit-Limit`,
     * `X-RateLimit-Remaining` — so a throttled sender knows when to come back instead of guessing.
     *
     * @param string $endpointName Name of the endpoint the request routes to, or the requested name when nothing routed
     * @param VerifiedWebhookRequest $verified The verification outcome, carrying the tenant and the caller address
     *
     * @return ResponseInterface|null A prepared 429 response, or null when the request may proceed
     */
    private function enforceRateLimit(string $endpointName, VerifiedWebhookRequest $verified): ?ResponseInterface
    {
        if ($this->limiter === null) {
            return null;
        }

        $verdict = $this->limiter->consume(new RateLimitKey($endpointName, $verified->clientIp ?? '', $verified->tenantKey()), $this->rateLimitTokens);

        if ($verdict->accepted) {
            return null;
        }

        return $this->getPreparedResponse(
            $this->responseFactory->createResponse(self::HTTP_TOO_MANY_REQUESTS, 'Rate limit exceeded.'),
            null,
            $verdict->toHeaders()
        );
    }
}
