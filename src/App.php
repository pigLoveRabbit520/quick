<?php
namespace Quick;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Quick\Exception\MethodNotAllowedException;
use Quick\Exception\NotFoundException;
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
        if ($callable instanceof \Closure) {
            $callable = $callable->bindTo($this->container);
        }
        $this->routesMap[] = [$methods, $pattern, $callable];
    }

    protected function handleException(Exception $e, ServerRequestInterface $request, ResponseInterface $response)
    {
        if ($e instanceof MethodNotAllowedException) {
            $handler = 'notAllowedHandler';
            $params = [$e->getRequest(), $e->getResponse(), $e->getAllowedMethods()];
        } elseif ($e instanceof NotFoundException) {
            $handler = 'notFoundHandler';
            $params = [$e->getRequest(), $e->getResponse(), $e];
        } elseif ($e instanceof SlimException) {
            // This is a Stop exception and contains the response
            return $e->getResponse();
        } else {
            // Other exception, use $request and $response params
            $handler = 'errorHandler';
            $params = [$request, $response, $e];
        }

        if ($this->container->has($handler)) {
            $callable = $this->container->get($handler);
            // Call the registered handler
            return call_user_func_array($callable, $params);
        }

        // No handlers found, so just throw the exception
        throw $e;
    }

    /**
     * @param $request
     * @param $response
     * @param $httpMethod
     * @param $uri
     * @return mixed|string
     * @throws MethodNotAllowedException
     * @throws NotFoundException
     * @throws \Interop\Container\Exception\ContainerException
     */
    protected function process($request, $response, $httpMethod, $uri)
    {
        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);
        try {
            switch ($routeInfo[0]) {
                case FastRoute\Dispatcher::NOT_FOUND:
                    if (!$this->container->has('notFoundHandler')) {
                        throw new NotFoundException($request, $response);
                    }
                    /** @var callable $notFoundHandler */
                    $notFoundHandler = $this->container->get('notFoundHandler');
                    return $notFoundHandler($request, $response);
                    break;
                case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                    $allowedMethods = $routeInfo[1];
                    if (!$this->container->has('notAllowedHandler')) {
                        throw new MethodNotAllowedException($request, $response);
                    }
                    /** @var callable $notAllowedHandler */
                    $notAllowedHandler = $this->container->get('notAllowedHandler');
                    return $notAllowedHandler($request, $response, $routeInfo[1]);
                    break;
                case FastRoute\Dispatcher::FOUND:
                    $handler = $routeInfo[1];
                    $vars = $routeInfo[2];
                    if ($handler instanceof \Closure) {
                        $res = $handler($request, $response, $vars);
                    } else {
                        $parts = explode(':', $handler);
                        $controller = new $parts[0]($this->container);
                        $method = $parts[1];
                        $res = call_user_func_array(array($controller, $method), [$request, $response, $vars]);
                    }
                    return $res;
                    break;
            }
        } catch (\Exception $ex) {

        }
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
            $res = $this->process($request, $response, $httpMethod, $uri);
            $response->end($res);
        });

        $this->httpServer->start();
    }
}
