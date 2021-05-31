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
        // 'AllMake' => 'command.genoa.make',
        'Yml' => 'command.genoa.yml',
        // 'RouteMake' => 'command.genoa.route',
    ];

    public function __call($name, $arguments)
    {
        $className = '\\Genoa\\Console\\'.$name;

        $this->app->singleton($arguments[0], function ($app) use ($className) {
            return new $className($app['files']);
        });
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->registerCommands($this->commands);
        view()->addNamespace('template', __DIR__.'/Console/stubs');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        if ($this->app->environment('production')) {
            return [];
        }

        return array_values($this->commands);
    }

    public function boot()
    {
        $this->app->resolving(FormRequest::class, function ($form, $app) {
            $form = FormRequest::createFrom($app['request'], $form);
            $form->setContainer($app);
        });

        $this->app->afterResolving(FormRequest::class, function (FormRequest $form) {
            $form->validate();
        });
    }

    /**
     * Register the given commands.
     */
    protected function registerCommands(array $commands)
    {
        foreach ($commands as $kc => $command) {
            $method = "{$kc}Command";
            call_user_func_array([$this, $method], [$command]);
        }

        $this->commands(array_values($commands));
    }
}
