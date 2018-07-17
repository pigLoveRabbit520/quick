<?php
namespace Quick;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use swoole_http_server;
use FastRoute;

class App
{
    /**
     * Current version
     *
     * @var string
     */
    const VERSION = '1.0.0';

    /**
     * Container
     *
     * @var ContainerInterface
     */
    private $container;
    private $dispatcher;
    private $httpServer;

    private $routesMap;


    public function __construct($container = [])
    {
        if (is_array($container)) {
            $container = new Container($container);
        }
        if (!$container instanceof ContainerInterface) {
            throw new InvalidArgumentException('Expected a ContainerInterface');
        }
        $this->container = $container;
        $this->routesMap = [];
    }

    /**
     * Enable access to the DI container by consumers of $app
     *
     * @return ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Enable access to the DI container by consumers of $app
     *
     * @return swoole_http_server
     */
    public function getHttpServer()
    {
        return $this->httpServer;
    }


    /********************************************************************************
     * Router proxy methods
     *******************************************************************************/

    /**
     * Add GET route
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function get($pattern, $callable)
    {
        return $this->map(['GET'], $pattern, $callable);
    }

    /**
     * Add POST route
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function post($pattern, $callable)
    {
        return $this->map(['POST'], $pattern, $callable);
    }

    /**
     * Add PUT route
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function put($pattern, $callable)
    {
        return $this->map(['PUT'], $pattern, $callable);
    }

    /**
     * Add PATCH route
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function patch($pattern, $callable)
    {
        return $this->map(['PATCH'], $pattern, $callable);
    }

    /**
     * Add DELETE route
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function delete($pattern, $callable)
    {
        return $this->map(['DELETE'], $pattern, $callable);
    }

    /**
     * Add OPTIONS route
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function options($pattern, $callable)
    {
        return $this->map(['OPTIONS'], $pattern, $callable);
    }

    /**
     * Add route for any HTTP method
     *
     * @param  string $pattern  The route URI pattern
     * @param  callable|string  $callable The route callback routine
     *
     * @return \Slim\Interfaces\RouteInterface
     */
    public function any($pattern, $callable)
    {
        return $this->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $pattern, $callable);
    }

    /**
     * Add route with multiple methods
     *
     * @param  string[] $methods  Numeric array of HTTP method names
     * @param  string   $pattern  The route URI pattern
     * @param  callable|string    $callable The route callback routine
     *
     * @return RouteInterface
     */
    public function map(array $methods, $pattern, $callable)
    {
        $this->routesMap[] = [$methods, $pattern, $callable];
    }


    public function start()
    {
        $this->httpServer = new swoole_http_server($this->container->settings['host'], $this->container->settings['port']);
        $this->dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
            foreach ($this->routesMap as $item) {
                $r->addRoute($item[0], $item[1], $item[2]);
            }
        });

        $this->httpServer->on("start", function ($server) {
            echo sprintf("Swoole http server is started at http://%s:%u\n", $this->container->settings['host'], $this->container->settings['port']);
        });

        $this->httpServer->on("request", function ($request, $response) {
            $response->header("X-Powered-By", "salamander/quick");

            // Fetch method and URI from somewhere
            $httpMethod = $request->server['request_method'];
            $uri = $request->server['request_uri'];

            // Strip query string (?foo=bar) and decode URI
            if (false !== $pos = strpos($uri, '?')) {
                $uri = substr($uri, 0, $pos);
            }
            $uri = rawurldecode($uri);
            $res = '';

            $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);
            switch ($routeInfo[0]) {
                case FastRoute\Dispatcher::NOT_FOUND:
                    // ... 404 Not Found
                    break;
                case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                    $allowedMethods = $routeInfo[1];
                    // ... 405 Method Not Allowed
                    break;
                case FastRoute\Dispatcher::FOUND:
                    $handler = $routeInfo[1];
                    $vars = $routeInfo[2];
                    $parts = explode(':', $handler);
                    $controller = new $parts[0];
                    $method = $parts[1];
                    $res = call_user_func_array(array($controller, $method), [$vars]);
                    break;
            }
            $response->end($res);
        });

        $this->httpServer->start();
    }
}
