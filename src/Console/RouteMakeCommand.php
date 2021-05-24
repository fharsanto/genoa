<?php

namespace Genoa\Console;

use Dingo\Api\Routing\Router;
use Illuminate\Console\Command;

class RouteMakeCommand extends Command
{
    protected $signature = 'genoa:route {uri : Endpoint of API/Route}
        {--method= : Http Methods (GET/POST/PUT/PATCH/DELETE)}
        {--action= : Controller@methods}
        {--desc= : Description of route}';
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'genoa:route';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new route.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $router = $this->getRouter();
        $routeCollection = $router->getRoutes();
        $rows = [];

        foreach ($routeCollection as $route) {
            $rows[] = [
                'verb' => $route['method'],
                'path' => $route['uri'],
                'namedRoute' => $this->getNamedRoute($route['action']),
                'controller' => $this->getController($route['action']),
                'action' => $this->getAction($route['action']),
                'middleware' => $this->getMiddleware($route['action']),
            ];
        }

        if ($this->laravel->bound(Router::class)) {
            $routes = $this->laravel->make(Router::class)->getRoutes();

            foreach ($routes as $route) {
                foreach ($route->getRoutes() as $innerRoute) {
                    $rows[] = [
                        'verb' => implode('|', $innerRoute->getMethods()),
                        'path' => $innerRoute->getPath(),
                        'namedRoute' => $innerRoute->getName(),
                        'controller' => get_class($innerRoute->getControllerInstance()),
                        'action' => $this->getAction($innerRoute->getAction()),
                        'middleware' => implode('|', $innerRoute->getMiddleware()),
                    ];
                }
            }
        }

        $headers = ['Verb', 'Path', 'NamedRoute', 'Controller', 'Action', 'Middleware'];
        $this->table($headers, $rows);
    }

    /**
     * Get the router.
     *
     * @return \Laravel\Lumen\Routing\Router
     */
    protected function getRouter()
    {
        return isset($this->laravel->router) ? $this->laravel->router : $this->laravel;
    }

    /**
     * @return string
     */
    protected function getNamedRoute(array $action)
    {
        return (!isset($action['as'])) ? '' : $action['as'];
    }

    /**
     * @return mixed|string
     */
    protected function getController(array $action)
    {
        if (empty($action['uses'])) {
            return 'None';
        }

        return current(explode('@', $action['uses']));
    }

    /**
     * @return string
     */
    protected function getAction(array $action)
    {
        if (!empty($action['uses']) && is_string($action['uses'])) {
            $data = $action['uses'];
            if (($pos = strpos($data, '@')) !== false) {
                return substr($data, $pos + 1);
            }

            return 'METHOD NOT FOUND';
        }

        return 'Closure';
    }

    /**
     * @return string
     */
    protected function getMiddleware(array $action)
    {
        return (isset($action['middleware'])) ? (is_array($action['middleware'])) ? join(', ', $action['middleware']) : $action['middleware'] : '';
    }
}
