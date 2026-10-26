<?php

// Note: no declare(strict_types=1) on purpose. URL parameters are always strings, and without
// strict types PHP converts them for handlers that ask for e.g. an int: function (int $id) { ... }

/**
 * @author      Bram(us) Van Damme <bramus@bram.us>
 * @author      Joris Maervoet
 * @copyright   Copyright (c), 2013 Bram(us) Van Damme
 * @license     MIT public license
 */

namespace Mjoris\Router;

/**
 * A lightweight and simple object oriented PHP Router.
 *
 * Usage in a nutshell:
 *   1. create a Router
 *   2. register routes: a request method + a URL pattern + a handler (a function or 'Controller@method')
 *   3. call run(): the router looks at the current URL and calls the handler of the first matching route
 */
class Router
{
    /**
     * All request methods the router supports, | delimited.
     */
    private const ALL_METHODS = 'GET|POST|PUT|DELETE|OPTIONS|PATCH|HEAD';

    /**
     * The routes, grouped per request method: ['GET' => [['pattern' => '/about', 'fn' => ...], ...], ...].
     *
     * @var array<string, list<array{pattern: string, fn: callable|string}>>
     */
    private array $afterRoutes = [];

    /**
     * The before middlewares, grouped per request method (same structure as $afterRoutes).
     *
     * @var array<string, list<array{pattern: string, fn: callable|string}>>
     */
    private array $beforeRoutes = [];

    /**
     * The 404 handlers, keyed by the route pattern they apply to.
     *
     * @var array<string, callable|string>
     */
    protected array $notFoundCallback = [];

    /**
     * Current base route, used for (sub)route mounting.
     */
    private string $baseRoute = '';

    /**
     * The request method that needs to be handled.
     */
    private string $requestedMethod = '';

    /**
     * The server base path for router execution (null = not determined yet).
     */
    private ?string $serverBasePath = null;

    /**
     * Default controllers namespace.
     */
    private string $namespace = '';

    /**
     * Has run() been called? Used to warn when it was forgotten.
     */
    private bool $hasRun = false;

    /**
     * Store a before middleware route and a handling function to be executed when accessed using one of the specified methods.
     *
     * @param string          $methods Allowed methods, | delimited (or '*' for all methods)
     * @param string          $pattern A route pattern such as /about/system
     * @param callable|string $fn      The handling function, or a 'Controller@method' string
     */
    public function before(string $methods, string $pattern, callable|string $fn): void
    {
        $pattern = $this->baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->baseRoute ? rtrim($pattern, '/') : $pattern;

        if ($methods === '*') {
            $methods = self::ALL_METHODS;
        }

        foreach (explode('|', $methods) as $method) {
            $this->beforeRoutes[$method][] = [
                'pattern' => $pattern,
                'fn' => $fn,
            ];
        }
    }

    /**
     * Store a route and a handling function to be executed when accessed using one of the specified methods.
     *
     * @param string          $methods Allowed methods, | delimited
     * @param string          $pattern A route pattern such as /about/system
     * @param callable|string $fn      The handling function, or a 'Controller@method' string
     */
    public function match(string $methods, string $pattern, callable|string $fn): void
    {
        $pattern = $this->baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->baseRoute ? rtrim($pattern, '/') : $pattern;

        foreach (explode('|', $methods) as $method) {
            $this->afterRoutes[$method][] = [
                'pattern' => $pattern,
                'fn' => $fn,
            ];
        }
    }

    /**
     * Shorthand for a route accessed using any method.
     */
    public function all(string $pattern, callable|string $fn): void
    {
        $this->match(self::ALL_METHODS, $pattern, $fn);
    }

    /**
     * Shorthand for a route accessed using GET.
     */
    public function get(string $pattern, callable|string $fn): void
    {
        $this->match('GET', $pattern, $fn);
    }

    /**
     * Shorthand for a route accessed using POST.
     */
    public function post(string $pattern, callable|string $fn): void
    {
        $this->match('POST', $pattern, $fn);
    }

    /**
     * Shorthand for a route accessed using PATCH.
     */
    public function patch(string $pattern, callable|string $fn): void
    {
        $this->match('PATCH', $pattern, $fn);
    }

    /**
     * Shorthand for a route accessed using DELETE.
     */
    public function delete(string $pattern, callable|string $fn): void
    {
        $this->match('DELETE', $pattern, $fn);
    }

    /**
     * Shorthand for a route accessed using PUT.
     */
    public function put(string $pattern, callable|string $fn): void
    {
        $this->match('PUT', $pattern, $fn);
    }

    /**
     * Shorthand for a route accessed using OPTIONS.
     */
    public function options(string $pattern, callable|string $fn): void
    {
        $this->match('OPTIONS', $pattern, $fn);
    }

    /**
     * Mounts a collection of callbacks onto a base route.
     *
     * @param string   $baseRoute The route sub pattern to mount the callbacks on
     * @param callable $fn        The callback that registers the routes
     */
    public function mount(string $baseRoute, callable $fn): void
    {
        // Track current base route
        $curBaseRoute = $this->baseRoute;

        // Build new base route string
        $this->baseRoute .= $baseRoute;

        // Call the callable, which registers the routes
        $fn();

        // Restore original base route
        $this->baseRoute = $curBaseRoute;
    }

    /**
     * Get all request headers.
     *
     * @return array<string, string> The request headers
     */
    public function getRequestHeaders(): array
    {
        // If getallheaders() is available (e.g. Apache), use that
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        // Otherwise manually extract them from $_SERVER: 'HTTP_X_FOO_BAR' becomes 'X-Foo-Bar'
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $name = substr($name, 5);
            } elseif ($name !== 'CONTENT_TYPE' && $name !== 'CONTENT_LENGTH') {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
            $headers[str_replace('Http', 'HTTP', $name)] = $value;
        }

        return $headers;
    }

    /**
     * Get the request method used, taking overrides into account.
     *
     * @return string The request method to handle
     */
    public function getRequestMethod(): string
    {
        // Take the method as found in $_SERVER
        $method = $_SERVER['REQUEST_METHOD'];

        // If it's a HEAD request override it to being GET and prevent any output, as per HTTP Specification
        // @url http://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.4
        if ($method === 'HEAD') {
            ob_start();
            $method = 'GET';
        }

        // If it's a POST request, check for a method override header
        elseif ($method === 'POST') {
            $headers = $this->getRequestHeaders();
            $override = $headers['X-HTTP-Method-Override'] ?? null;
            if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
                $method = $override;
            }
        }

        return $method;
    }

    /**
     * Set a default lookup namespace for 'Controller@method' handlers.
     */
    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Get the default lookup namespace for 'Controller@method' handlers.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Execute the router: loop all defined before middlewares and routes, and execute the handling function if a match was found.
     *
     * @param callable|null $callback Function to be executed after a matching route was handled (= after router middleware)
     *
     * @return bool True if a route was handled, false otherwise
     */
    public function run(?callable $callback = null): bool
    {
        $this->hasRun = true;

        // Define which method we need to handle
        $this->requestedMethod = $this->getRequestMethod();

        // Handle all before middlewares
        if (isset($this->beforeRoutes[$this->requestedMethod])) {
            $this->handle($this->beforeRoutes[$this->requestedMethod]);
        }

        // Handle all routes
        $numHandled = 0;
        if (isset($this->afterRoutes[$this->requestedMethod])) {
            $numHandled = $this->handle($this->afterRoutes[$this->requestedMethod], true);
        }

        // If no route was handled, trigger the 404.
        // If a route was handled, perform the finish callback (if any)
        if ($numHandled === 0) {
            $this->trigger404();
        } elseif ($callback !== null) {
            $callback();
        }

        // If it originally was a HEAD request, clean up after ourselves by emptying the output buffer
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            ob_end_clean();
        }

        return $numHandled !== 0;
    }

    /**
     * Set the 404 handling function.
     *
     * Usage: set404($fn) for a general 404 handler, or set404('/api(/.*)?', $fn) for a 404 handler
     * that only applies to URLs matching the given pattern.
     *
     * @param callable|string      $matchFn The handling function, or the route pattern when $fn is given
     * @param callable|string|null $fn      The handling function
     */
    public function set404(callable|string $matchFn, callable|string|null $fn = null): void
    {
        if ($fn === null) {
            $this->notFoundCallback['/'] = $matchFn;
        } elseif (is_string($matchFn)) {
            $this->notFoundCallback[$matchFn] = $fn;
        } else {
            throw new \TypeError('set404(): when two arguments are given, the first one must be a route pattern');
        }
    }

    /**
     * Triggers the 404 response: executes the matching 404 handler(s), or sends a plain 404 header if there are none.
     */
    public function trigger404(): void
    {
        $numHandled = 0;

        // Execute every 404 handler whose pattern matches the current URL
        foreach ($this->notFoundCallback as $pattern => $fn) {
            if ($this->patternMatches((string) $pattern, $this->getCurrentUri(), $matches)) {
                $this->invoke($fn);
                ++$numHandled;
            }
        }

        // None matched: use the general 404 handler, or fall back to a plain 404 header
        if ($numHandled === 0 && isset($this->notFoundCallback['/'])) {
            $this->invoke($this->notFoundCallback['/']);
        } elseif ($numHandled === 0 && !headers_sent()) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        }
    }

    /**
     * Checks if a route pattern matches the given URI.
     * Curly braces placeholders like {id} are first replaced by a regex group (like Laravel).
     *
     * @param string       $pattern The route pattern
     * @param string       $uri     The URI to match against
     * @param mixed[]|null $matches Will contain the regex matches (with their offsets)
     *
     * @param-out mixed[] $matches
     */
    private function patternMatches(string $pattern, string $uri, ?array &$matches): bool
    {
        // Replace all curly braces matches {} into word patterns (like Laravel)
        $pattern = preg_replace('/\/{(.*?)}/', '/(.*?)', $pattern);

        // we may have a match!
        return (bool) preg_match_all('#^' . $pattern . '$#', $uri, $matches, PREG_OFFSET_CAPTURE);
    }

    /**
     * Extract the URL parameters (and only the parameters) out of the regex matches.
     *
     * @param mixed[] $matches The regex matches, as found by patternMatches()
     *
     * @return list<string|null> The parameters to pass to the handling function
     */
    private function extractParams(array $matches): array
    {
        // Rework matches to only contain the matches, not the orig string
        $matches = array_slice($matches, 1);

        return array_map(function ($match, $index) use ($matches) {
            // We have a following parameter: take the substring from the current param position until the next one's position (thank you PREG_OFFSET_CAPTURE)
            // This makes nested optional subpatterns such as /blog(/\d+(/\d+)?)? work
            if (isset($matches[$index + 1][0]) && is_array($matches[$index + 1][0])) {
                if ($matches[$index + 1][0][1] > -1) {
                    return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
                }
            }

            // We have no following parameters: return the whole lot
            return isset($match[0][0]) && $match[0][1] != -1 ? trim($match[0][0], '/') : null;
        }, $matches, array_keys($matches));
    }

    /**
     * Handle a set of routes: if a match is found, execute the relating handling function.
     *
     * @param list<array{pattern: string, fn: callable|string}> $routes Collection of route patterns and their handling functions
     * @param bool $quitAfterRun Does the handle function need to quit after one route was matched?
     *
     * @return int The number of routes handled
     */
    private function handle(array $routes, bool $quitAfterRun = false): int
    {
        // Counter to keep track of the number of routes we've handled
        $numHandled = 0;

        // The current page URL
        $uri = $this->getCurrentUri();

        // Loop all routes
        foreach ($routes as $route) {
            // is there a valid match?
            if ($this->patternMatches($route['pattern'], $uri, $matches)) {
                // Call the handling function with the URL parameters
                $this->invoke($route['fn'], $this->extractParams($matches));

                ++$numHandled;

                // If we need to quit, then quit
                if ($quitAfterRun) {
                    break;
                }
            }
        }

        // Return the number of routes handled
        return $numHandled;
    }

    /**
     * Call a handling function: either a callable, or a 'Controller@method' string.
     *
     * @param callable|string   $fn     The handling function
     * @param list<string|null> $params The parameters to pass to it
     *
     * @throws \Error When the handler is a 'Controller@method' string that can't be called
     */
    private function invoke(callable|string $fn, array $params = []): void
    {
        // A closure, a function name, [$object, 'method'], ...: just call it
        if (is_callable($fn)) {
            $fn(...$params);

            return;
        }

        // Otherwise it must be a 'Controller@method' string
        if (!str_contains($fn, '@')) {
            throw new \Error("Route handler '$fn' is not callable and is not of the form 'Controller@method'");
        }
        [$controller, $method] = explode('@', $fn, 2);

        // Adjust controller class if namespace has been set
        if ($this->getNamespace() !== '') {
            $controller = $this->getNamespace() . '\\' . $controller;
        }

        // Make sure the controller and its method exist, and that the method can be called
        if (!class_exists($controller)) {
            throw new \Error("Controller class '$controller' not found");
        }
        if (!method_exists($controller, $method)) {
            throw new \Error("Method '$method' not found in controller class '$controller'");
        }
        $reflectedMethod = new \ReflectionMethod($controller, $method);
        if (!$reflectedMethod->isPublic() || $reflectedMethod->isAbstract()) {
            throw new \Error("Method '$controller::$method' must be public and not abstract");
        }

        // A static method is called on the class, a non-static method on a new instance of the class
        if ($reflectedMethod->isStatic()) {
            $controller::$method(...$params);
        } else {
            (new $controller())->$method(...$params);
        }
    }

    /**
     * Define the current relative URI.
     */
    public function getCurrentUri(): string
    {
        // Get the current Request URI and remove rewrite base path from it (= allows one to run the router in a sub folder)
        $uri = substr(rawurldecode($_SERVER['REQUEST_URI']), strlen($this->getBasePath()));

        // Don't take query params into account on the URL
        $uri = explode('?', $uri)[0];

        // Remove trailing slash + enforce a slash at the start
        return '/' . trim($uri, '/');
    }

    /**
     * Return server base path, and define it if it isn't defined.
     */
    public function getBasePath(): string
    {
        // Check if server base path is defined, if not define it.
        if ($this->serverBasePath === null) {
            $this->serverBasePath = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME']), 0, -1)) . '/';
        }

        return $this->serverBasePath;
    }

    /**
     * Explicitly sets the server base path. To be used when your entry script path differs from your entry URLs.
     * @see https://github.com/bramus/router/issues/82#issuecomment-466956078
     */
    public function setBasePath(string $serverBasePath): void
    {
        $this->serverBasePath = $serverBasePath;
    }

    /**
     * Called by PHP when the router is destroyed, at the latest when the script ends.
     * Warns when routes were defined but run() was never called: without run() nothing happens
     * and you would just get an empty page.
     */
    public function __destruct()
    {
        if (!$this->hasRun && $this->afterRoutes !== []) {
            trigger_error('Routes were defined, but the router was never started: did you forget to call $router->run()?', E_USER_WARNING);
        }
    }
}
