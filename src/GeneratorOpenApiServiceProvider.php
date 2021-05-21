<?php

namespace Genoa;

use Illuminate\Support\ServiceProvider;

class GeneratorOpenApiServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * The commands to be registered.
     *
     * @var array
     */
    protected $commands = [
        'AllMake' => 'command.genoa.make',
        'YmlMake' => 'command.genoa.yml',
    ];

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->registerCommands($this->commands);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        if ($this->app->environment('production')) {
            return array_values($this->commands);
        }

        return array_merge(array_values($this->commands), array_values($this->devCommands));
    }

    /**
     * Register the given commands.
     */
    protected function registerCommands(array $commands)
    {
        foreach (array_keys($commands) as $command) {
            $method = "register{$command}Command";

            call_user_func_array([$this, $method], []);
        }

        $this->commands(array_values($commands));
    }

    protected function registerYmlMakeCommand()
    {
        $this->app->singleton('command.genoa.yml', function ($app) {
            return new Console\YmlCommand($app['files']);
        });
    }

    /**
     * Register the command.
     */
    protected function registerAllMakeCommand()
    {
        $this->app->singleton('command.genoa.make', function ($app) {
            return new Console\CrudMakeCommand($app['files']);
        });
    }

    /**
     * Register the command.
     */
    protected function registerModelMakeCommand()
    {
        $this->app->singleton('command.model.make', function ($app) {
            return new Console\ModelMakeCommand($app['files']);
        });
    }

    /**
     * Register the command.
     */
    protected function registerRouteListCommand()
    {
        $this->app->singleton('command.route.list', function ($app) {
            return new Console\RouteListCommand();
        });
    }

    /**
     * Register the command.
     */
    protected function registerControllerMakeCommand()
    {
        $this->app->singleton('command.controller.make', function ($app) {
            return new Console\ControllerMakeCommand($app['files']);
        });
    }
}
