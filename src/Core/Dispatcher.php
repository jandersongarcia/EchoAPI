<?php

namespace Core;

use Symfony\Component\HttpFoundation\Response;
use Monolog\Logger;
use Medoo\Medoo;
use Core\MiddlewareLoader;

class Dispatcher
{
    private $router;
    private $db;
    private $logger;
    private $middlewareLoader;

    public function __construct($router, Medoo $db, Logger $logger, MiddlewareLoader $middlewareLoader)
    {
        $this->router = $router;
        $this->db = $db;
        $this->logger = $logger;
        $this->middlewareLoader = $middlewareLoader;
    }

    public function run()
    {
        try {
            $match = $this->router->match();

            if ($match) {
                $target = $match['target'];

                if (is_callable($target)) {
                    // Route via Closure
                    $response = call_user_func_array($target, $match['params']);
                } elseif (is_string($target)) {
                    // Route via Controller@method
                    [$controllerClass, $method] = explode('@', $target);

                    // Executa middlewares
                    foreach ($this->middlewareLoader->load() as $middleware) {
                        $middleware->handle($_REQUEST);
                    }

                    $controller = new $controllerClass($this->db, $this->logger);
                    $response = call_user_func_array([$controller, $method], $match['params']);
                } else {
                    throw new \Exception("Tipo de rota inválido.");
                }

                if ($response instanceof Response) {
                    $response->send();
                } else {
                    echo $response;
                }
            } else {
                $this->logger->warning('Route not found', [
                    'request_uri' => $_SERVER['REQUEST_URI'],
                    'method' => $_SERVER['REQUEST_METHOD']
                ]);

                http_response_code(404);
                echo '404 - Page not found';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected execution error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(500);
            echo 'Internal server error';
        }
    }
}
