<?php
declare(strict_types=1);

/**
 * File: app/Bootstrap/App.php
 *
 * Purpose:
 *   Core bootstrap primitives for the Gorgan Horse Federation Panel.
 *   Contains the tiny DI container, the request/response objects, the
 *   router, and the JSON envelope builder. All classes share the namespace
 *   App\Bootstrap and live in this single file per the file-merging policy
 *   (Principle P23, Technical §4.3).
 *
 * Conventions:
 *   - PHP 8.1+ with strict types.
 *   - All timestamps are UTC ISO-8601.
 *   - No framework; no Composer.
 *
 * @package App\Bootstrap
 */

namespace App\Bootstrap;

use App\Exceptions\NotFoundException;
use App\Exceptions\ServerErrorException;

/**
 * Class: Container
 *
 * Purpose:
 *   A tiny PSR-11-like service container. Bindings are string keys mapped to
 *   factory closures or resolved singletons. There is no auto-wiring: every
 *   dependency is registered explicitly in App::boot().
 *
 * Side effects: none.
 */
final class Container
{
    /** @var array<string, callable> Factory bindings. */
    private array $bindings = [];

    /** @var array<string, mixed> Resolved singleton instances. */
    private array $instances = [];

    /**
     * Register a factory binding.
     *
     * @param string   $key     Service key (e.g. "db").
     * @param callable $factory Receives the container, returns the service.
     * @return void
     */
    public function bind(string $key, callable $factory): void
    {
        $this->bindings[$key] = $factory;
        unset($this->instances[$key]);
    }

    /**
     * Register a singleton binding (memoised after first resolution).
     *
     * Alias of bind() retained for readability in Bootstrap.php; both resolve
     * the factory once and cache the resulting instance.
     *
     * @param string   $key     Service key (e.g. "db").
     * @param callable $factory Receives the container, returns the service.
     * @return void
     */
    public function singleton(string $key, callable $factory): void
    {
        $this->bind($key, $factory);
    }

    /**
     * Register a singleton instance directly.
     *
     * @param string $key   Service key.
     * @param mixed  $value The instance.
     * @return void
     */
    public function instance(string $key, mixed $value): void
    {
        $this->instances[$key] = $value;
    }

    /**
     * Determine whether a key is bound or resolved.
     *
     * @param string $key Service key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->instances[$key]) || isset($this->bindings[$key]);
    }

    /**
     * Resolve a service by key. Singletons are memoised after first resolution.
     *
     * @param string $key Service key.
     * @return mixed The resolved service.
     * @throws ServerErrorException If the key is not bound.
     */
    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->instances)) {
            return $this->instances[$key];
        }
        if (!isset($this->bindings[$key])) {
            throw new ServerErrorException('SERVICE_UNAVAILABLE', 'Container binding missing: ' . $key);
        }
        $instance = ($this->bindings[$key])($this);
        $this->instances[$key] = $instance;
        return $instance;
    }
}

/**
 * Class: Request
 *
 * Purpose:
 *   Immutable-ish representation of an incoming HTTP request. Provides typed
 *   access to query, body, JSON, files, headers, cookies, and router attributes.
 *
 * @package App\Bootstrap
 */
final class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $post;
    private array $files;
    private array $headers;
    private array $cookies;
    private array $server;
    /** @var array<string, mixed> Router-assigned parameters. */
    private array $attributes = [];
    /** @var array<string, mixed>|null Decoded JSON body (lazy). */
    private ?array $json = null;

    /**
     * Build a request from PHP superglobals.
     *
     * @return self
     */
    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        return new self($method, $path, $_GET, $_POST, $_FILES, $headers, $_COOKIE, $_SERVER);
    }

    /**
     * @param string $method  HTTP method.
     * @param string $path    URL path.
     * @param array  $query   Query parameters.
     * @param array  $post    Form body.
     * @param array  $files   Uploaded files.
     * @param array  $headers Request headers.
     * @param array  $cookies Cookies.
     * @param array  $server  Server variables.
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $files = [],
        array $headers = [],
        array $cookies = [],
        array $server = []
    ) {
        $this->method = strtoupper($method);
        $this->path = '/' . trim($path, '/');
        if ($this->path === '/') {
            $this->path = '/';
        }
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->cookies = $cookies;
        $this->server = $server;
    }

    /** @return string HTTP method. */
    public function method(): string { return $this->method; }

    /** @return string URL path. */
    public function path(): string { return $this->path; }

    /**
     * @param string $method Method to compare.
     * @return bool
     */
    public function isMethod(string $method): bool { return $this->method === strtoupper($method); }

    /**
     * Read a query parameter.
     *
     * @param string|null $key     Parameter name, or null for all.
     * @param mixed       $default Default value.
     * @return mixed
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) { return $this->query; }
        return $this->query[$key] ?? $default;
    }

    /**
     * Read a form body parameter.
     *
     * @param string|null $key     Parameter name, or null for all.
     * @param mixed       $default Default value.
     * @return mixed
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) { return $this->post; }
        return $this->post[$key] ?? $default;
    }

    /**
     * Decode the JSON request body (lazy, once).
     *
     * @return array
     */
    public function json(): array
    {
        if ($this->json !== null) { return $this->json; }
        $raw = $this->rawBody();
        if ($raw === '') { return $this->json = []; }
        $decoded = json_decode($raw, true);
        return $this->json = is_array($decoded) ? $decoded : [];
    }

    /** @return string Raw request body. */
    public function rawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Read an input value from JSON body, form body, or query, in that order.
     *
     * @param string $key     Field name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) { return $json[$key]; }
        if (array_key_exists($key, $this->post)) { return $this->post[$key]; }
        return $this->query[$key] ?? $default;
    }

    /**
     * Read a file upload entry.
     *
     * @param string|null $key File field name, or null for all.
     * @return mixed
     */
    public function files(?string $key = null): mixed
    {
        if ($key === null) { return $this->files; }
        return $this->files[$key] ?? null;
    }

    /**
     * Read a request header (case-insensitive).
     *
     * @param string $name    Header name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Read a cookie.
     *
     * @param string $name    Cookie name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * Set a router-assigned attribute.
     *
     * @param string $key   Attribute name.
     * @param mixed  $value Value.
     * @return void
     */
    public function setAttr(string $key, mixed $value): void { $this->attributes[$key] = $value; }

    /**
     * Read a router-assigned attribute.
     *
     * @param string $key     Attribute name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array All router attributes. */
    public function attributes(): array { return $this->attributes; }

    /** @return string Client IP address. */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** @return string User agent string. */
    public function userAgent(): string
    {
        return (string) ($this->header('user-agent') ?? '');
    }

    /** @return string SHA-256 hash of the user agent. */
    public function uaHash(): string
    {
        return hash('sha256', $this->userAgent());
    }

    /** @return bool Whether the request expects JSON. */
    public function isJson(): bool
    {
        $accept = (string) ($this->header('accept') ?? '');
        $ctype = (string) ($this->header('content-type') ?? '');
        return str_contains($accept, 'application/json') || str_contains($ctype, 'application/json');
    }

    /** @return string Request scheme (http/https). */
    public function scheme(): string
    {
        $https = $this->server['HTTPS'] ?? '';
        return (!empty($https) && $https !== 'off') ? 'https' : 'http';
    }

    /** @return string Request host. */
    public function host(): string
    {
        return (string) ($this->header('host') ?? 'localhost');
    }

    /**
     * Build the absolute base URL of the application.
     *
     * @return string e.g. "https://panel.example.com"
     */
    public function baseUrl(): string
    {
        return $this->scheme() . '://' . $this->host();
    }
}

/**
 * Class: Response
 *
 * Purpose:
 *   HTTP response value object. Supports HTML, JSON, redirects, no-content,
 *   file downloads, and custom headers. Emissions buffer output and then send.
 *
 * @package App\Bootstrap
 */
final class Response
{
    private int $status;
    private string $body;
    /** @var array<string,string> */
    private array $headers = [];
    private ?string $downloadPath = null;
    private ?string $downloadName = null;
    private bool $sent = false;

    /**
     * @param string $body   Response body.
     * @param int    $status HTTP status code.
     * @param array  $headers Response headers.
     */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /**
     * Build an HTML response.
     *
     * @param string $body   HTML body.
     * @param int    $status HTTP status.
     * @return self
     */
    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Build a JSON response.
     *
     * @param mixed $payload JSON-serialisable payload.
     * @param int   $status  HTTP status.
     * @return self
     */
    public static function json(mixed $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /**
     * Build a redirect response.
     *
     * @param string $url    Target URL.
     * @param int    $status HTTP status (302 by default).
     * @return self
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * Build an empty 204 response.
     *
     * @return self
     */
    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * Build a file download response.
     *
     * @param string $path Absolute file path.
     * @param string $name Download filename.
     * @return self
     */
    public static function download(string $path, string $name): self
    {
        $r = new self('', 200, ['Content-Type' => 'application/octet-stream']);
        $r->downloadPath = $path;
        $r->downloadName = $name;
        return $r;
    }

    /**
     * Add or replace a header.
     *
     * @param string $name  Header name.
     * @param string $value Header value.
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Attach an HttpOnly/SameSite cookie to this response.
     *
     * @param string      $name     Cookie name.
     * @param string      $value    Cookie value.
     * @param int         $expires  Expiry timestamp.
     * @param string      $path     Cookie path.
     * @param bool        $httpOnly HttpOnly flag.
     * @param string|null $sameSite SameSite attribute.
     * @return self
     */
    public function withCookie(string $name, string $value, int $expires, string $path = '/', bool $httpOnly = true, ?string $sameSite = 'Lax'): self
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => $path,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite ?? 'Lax',
        ]);
        return $this;
    }

    /** @return int HTTP status code. */
    public function status(): int { return $this->status; }

    /** @return string Response body. */
    public function body(): string { return $this->body; }

    /** @return array<string,string> Headers. */
    public function headers(): array { return $this->headers; }

    /**
     * Emit the response to the client (headers + body). Idempotent.
     *
     * @return void
     */
    public function send(): void
    {
        if ($this->sent) { return; }
        $this->sent = true;

        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->downloadPath !== null) {
            if (is_file($this->downloadPath)) {
                header('Content-Length: ' . (string) filesize($this->downloadPath));
                readfile($this->downloadPath);
            }
            return;
        }
        echo $this->body;
    }
}

/**
 * Class: Route
 *
 * Purpose:
 *   Value object describing a single route: method, path pattern, controller
 *   callable, and per-route middleware list.
 *
 * @package App\Bootstrap
 */
final class Route
{
    /**
     * @param string   $method     HTTP method.
     * @param string   $path       Path pattern (may contain {params}).
     * @param callable $handler    Controller callable.
     * @param string[] $middleware Middleware keys.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly array $middleware = []
    ) {
    }

    /**
     * Test a path against this route's pattern, extracting parameters.
     *
     * @param string $path Request path.
     * @return array<string,string>|null Parameters, or null when no match.
     */
    public function match(string $path): ?array
    {
        if (!str_contains($this->path, '{')) {
            return $this->path === $path ? [] : null;
        }
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $this->path);
        $pattern = '#^' . $pattern . '$#';
        if (preg_match($pattern, $path, $m) !== 1) {
            return null;
        }
        $params = [];
        foreach ($m as $k => $v) {
            if (!is_int($k)) { $params[$k] = $v; }
        }
        return $params;
    }
}

/**
 * Class: Router
 *
 * Purpose:
 *   Matches method + path to a Route. Parameters become request attributes.
 *   No regex paths beyond {param}. On no match the caller raises a 404.
 *
 * @package App\Bootstrap
 */
final class Router
{
    /** @var Route[] */
    private array $routes;

    /**
     * @param array<int,array{0:string,1:string,2:callable,3?:string[]}> $definitions Raw route definitions.
     */
    public function __construct(array $definitions)
    {
        $this->routes = [];
        foreach ($definitions as $def) {
            $this->routes[] = new Route($def[0], $def[1], $def[2], $def[3] ?? []);
        }
    }

    /**
     * Find the first route matching method + path.
     *
     * @param string $method HTTP method.
     * @param string $path   Request path.
     * @return array{route:Route, params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        foreach ($this->routes as $route) {
            if ($route->method !== $method) {
                continue;
            }
            $params = $route->match($path);
            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }
        return null;
    }

    /**
     * Determine whether a path is claimed by any route (any method).
     *
     * @param string $path Request path.
     * @return bool
     */
    public function hasPath(string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($route->match($path) !== null) { return true; }
        }
        return false;
    }

    /** @return Route[] All registered routes. */
    public function routes(): array { return $this->routes; }
}

/**
 * Class: Envelope
 *
 * Purpose:
 *   Builds the canonical JSON envelope described in Blueprint §9. Every JSON
 *   response flows through here so the shape is consistent: ok, data, meta,
 *   errors, flash, csrf.
 *
 * @package App\Bootstrap
 */
final class Envelope
{
    /**
     * Build a success payload.
     *
     * @param mixed $data Response data.
     * @param array $meta Meta block (merged over the defaults supplied by caller).
     * @return array
     */
    public static function ok(mixed $data, array $meta = []): array
    {
        return array_merge([
            'ok' => true,
            'data' => $data,
            'errors' => null,
            'flash' => ['success' => $meta['flash_success'] ?? null, 'error' => $meta['flash_error'] ?? null],
            'csrf' => $meta['csrf'] ?? null,
        ], self::cleanMeta($meta));
    }

    /**
     * Build an error payload.
     *
     * @param array<int,array{code:string,field?:string|null,message:string}> $errors Error list.
     * @param array $meta Meta block.
     * @return array
     */
    public static function error(array $errors, array $meta = []): array
    {
        return array_merge([
            'ok' => false,
            'data' => null,
            'errors' => $errors,
            'flash' => ['success' => $meta['flash_success'] ?? null, 'error' => $meta['flash_error'] ?? null],
            'csrf' => $meta['csrf'] ?? null,
        ], self::cleanMeta($meta));
    }

    /**
     * Normalise the meta block, dropping internal transient keys.
     *
     * @param array $meta Raw meta.
     * @return array
     */
    private static function cleanMeta(array $meta): array
    {
        unset($meta['flash_success'], $meta['flash_error'], $meta['csrf']);
        return ['meta' => $meta];
    }
}