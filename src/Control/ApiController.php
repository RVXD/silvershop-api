<?php

declare(strict_types=1);

namespace SilverShop\Api\Control;

use JsonException;
use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\TokenAuthenticator;
use SilverShop\Api\Resource\ResourceRegistry;
use SilverShop\Api\Resource\ResourceResult;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;
use SimpleXMLElement;
use Throwable;

/**
 * The headless REST API entry point. Mounted at `api/v1` (taking over core's {@see \SilverShop\Control\WebServiceController}
 * route), it is a backwards-compatible superset: the same `products`/`cart` contracts, CSRF behaviour and JSON/XML
 * content negotiation, plus categories, variations, orders, customer self-service, token auth, pagination and webhooks.
 *
 * Dispatch is data-driven: the URL after the base path is split into `resource/segments…`, the resource is resolved
 * from the config-ordered {@link ResourceRegistry}, and the resulting {@link ResourceResult} is rendered in the
 * negotiated format. Resources signal errors by throwing {@link ApiException}.
 */
class ApiController extends Controller
{
    /**
     * The URL prefix this controller is mounted at (must match the route + {@see \SilverShop\Api\Auth\BearerTokenMiddleware}).
     */
    private static string $base_path = 'api/v1';

    /**
     * Whether the live catalog can be read without a token. When false, catalog reads require a token with the
     * `read` scope.
     */
    private static bool $public_read = true;

    private static int $default_page_size = 30;

    private static int $max_page_size = 100;

    /**
     * Minimal, OFF-by-default CORS support for browser-based headless clients.
     *
     * @var array<string, mixed>
     */
    private static array $cors = [
        'enabled' => false,
        'allow_origin' => '*',
        'allow_methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        'allow_headers' => 'Authorization, Content-Type, X-Requested-With',
        'max_age' => 600,
    ];

    public function handleRequest(HTTPRequest $request): HTTPResponse
    {
        $this->setRequest($request);
        $this->pushCurrent();

        try {
            $rest = $this->pathAfterBase($request);
            $format = $this->negotiateFormat($request, $rest);

            $response = $this->handleCors($request, $format);
            if ($response instanceof HTTPResponse) {
                return $response;
            }

            $context = $this->resolveContext($request);

            $segments = $rest === '' ? [] : array_map('rawurldecode', explode('/', $rest));

            if ($segments === []) {
                return $this->renderResult($this->indexResult(), $format);
            }

            $resourceName = array_shift($segments);
            $resource = ResourceRegistry::singleton()->get($resourceName);
            if ($resource === null) {
                throw ApiException::notFound(sprintf('Unknown resource "%s"', $resourceName));
            }

            $result = $resource->handle($request, $context, $segments);

            return $this->renderResult($result, $format);
        } catch (ApiException $e) {
            return $this->errorResponse($e->getStatusCode(), $e->getMessage(), $format ?? 'json');
        } catch (Throwable $e) {
            $message = Director::isDev()
                ? sprintf('%s: %s', get_class($e), $e->getMessage())
                : 'Internal server error';
            return $this->errorResponse(500, $message, $format ?? 'json');
        } finally {
            $this->popCurrent();
        }
    }

    /**
     * Resolve the request's auth context: the one the middleware published, else a fresh resolution (so the
     * controller is self-contained for direct/unit use). Logs the token member in for this request if needed.
     */
    private function resolveContext(HTTPRequest $request): ApiContext
    {
        $context = ApiContext::current();
        if ($context === null) {
            $context = Injector::inst()->create(TokenAuthenticator::class)->authenticate($request);
            if ($context->isAuthenticated() && $context->getMember() !== null && !Security::getCurrentUser()) {
                Security::setCurrentUser($context->getMember());
            }
        }

        return $context;
    }

    private function pathAfterBase(HTTPRequest $request): string
    {
        $base = trim((string) $this->config()->get('base_path'), '/');
        $url = trim((string) $request->getURL(), '/');

        if ($url === $base) {
            return '';
        }
        if (str_starts_with($url, $base . '/')) {
            return substr($url, strlen($base) + 1);
        }

        return $url;
    }

    /**
     * Determine the response format and strip any `.json`/`.xml` suffix from the path.
     */
    private function negotiateFormat(HTTPRequest $request, string &$rest): string
    {
        $suffix = null;
        if (preg_match('/\.(json|xml)$/i', $rest, $matches) === 1) {
            $suffix = strtolower($matches[1]);
            $rest = (string) preg_replace('/\.(json|xml)$/i', '', $rest);
        }

        $query = strtolower((string) $request->getVar('format'));
        if (in_array($query, ['json', 'xml'], true)) {
            return $query;
        }

        $extension = strtolower((string) $request->getExtension());
        if (in_array($extension, ['json', 'xml'], true)) {
            return $extension;
        }

        if ($suffix !== null) {
            return $suffix;
        }

        if (str_contains(strtolower((string) $request->getHeader('Accept')), 'xml')) {
            return 'xml';
        }

        return 'json';
    }

    /**
     * A small discovery document at the API root.
     */
    private function indexResult(): ResourceResult
    {
        return ResourceResult::ok([
            'name' => 'silvershop/api',
            'version' => 'v1',
            'resources' => ResourceRegistry::singleton()->names(),
        ], [], 'api');
    }

    private function handleCors(HTTPRequest $request, string $format): ?HTTPResponse
    {
        $cors = (array) $this->config()->get('cors');
        if (empty($cors['enabled'])) {
            return null;
        }

        if (strtoupper($request->httpMethod()) === 'OPTIONS') {
            $response = HTTPResponse::create('', 204);
            $this->applyCorsHeaders($response, $cors);
            return $response;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function applyCorsHeaders(HTTPResponse $response, array $cors): void
    {
        $response->addHeader('Access-Control-Allow-Origin', (string) ($cors['allow_origin'] ?? '*'));
        $response->addHeader('Access-Control-Allow-Methods', (string) ($cors['allow_methods'] ?? 'GET, POST, OPTIONS'));
        $response->addHeader('Access-Control-Allow-Headers', (string) ($cors['allow_headers'] ?? 'Authorization, Content-Type'));
        $response->addHeader('Access-Control-Max-Age', (string) ($cors['max_age'] ?? 600));
    }

    private function renderResult(ResourceResult $result, string $format): HTTPResponse
    {
        $response = $this->formatResponse(
            $format,
            $result->getXmlRoot(),
            $result->getData(),
            $result->getStatus(),
            $result->getXmlItemNode()
        );

        foreach ($result->getHeaders() as $name => $value) {
            $response->addHeader($name, $value);
        }

        $cors = (array) $this->config()->get('cors');
        if (!empty($cors['enabled'])) {
            $this->applyCorsHeaders($response, $cors);
        }

        return $response;
    }

    /**
     * @param array<mixed> $payload
     */
    private function formatResponse(string $format, string $rootNode, array $payload, int $statusCode, string $itemNode = 'item'): HTTPResponse
    {
        $rootNode = preg_replace('/[^A-Za-z0-9_-]/', '', $rootNode) ?: 'response';

        if ($format === 'xml') {
            $xml = new SimpleXMLElement(sprintf('<%s/>', $rootNode));
            $this->appendXml($xml, $payload, $itemNode);
            $body = $xml->asXML();

            if ($body === false) {
                return $this->jsonResponse(['message' => 'Failed to encode response data as XML'], 500);
            }

            $response = HTTPResponse::create($body, $statusCode);
            $response->addHeader('Content-Type', 'application/xml; charset=utf-8');
            return $response;
        }

        return $this->jsonResponse($payload, $statusCode);
    }

    /**
     * @param array<mixed> $payload
     */
    private function jsonResponse(array $payload, int $statusCode): HTTPResponse
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = '{"message":"Failed to encode response data as JSON"}';
            $statusCode = 500;
        }

        $response = HTTPResponse::create($body, $statusCode);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    private function errorResponse(int $statusCode, string $message, string $format): HTTPResponse
    {
        return $this->formatResponse($format, 'error', ['message' => $message], $statusCode);
    }

    private function appendXml(SimpleXMLElement $xml, mixed $payload, string $numericNodeName = 'item'): void
    {
        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $nodeName = is_int($key) ? $numericNodeName : (string) $key;
                $nodeName = preg_replace('/[^A-Za-z0-9_-]/', '', $nodeName) ?: $numericNodeName;
                if (is_array($value)) {
                    $child = $xml->addChild($nodeName);
                    $this->appendXml($child, $value, $numericNodeName);
                } else {
                    $xml->addChild($nodeName, $this->xmlScalar($value));
                }
            }

            return;
        }

        $xml[0] = $this->xmlScalar($payload);
    }

    private function xmlScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_XML1);
    }
}
