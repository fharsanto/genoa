<?php

namespace Genoa\Console;

use Illuminate\Console\Concerns\InteractsWithIO;

/**
 * Generate app/Http/Controllers from Open API.
 */
trait ControllerTrait
{
    use InteractsWithIO;

    protected function guestModelName(array $operations, $controllerName)
    {
        foreach ($operations as $operation) {
            if ($operation['controller'] === $controllerName && !empty($operation['model'])) {
                return $operation['model'];
            }
        }

        return '';
    }

    protected function generateControllers(array $operations)
    {
        $tplContent = file_get_contents(__DIR__.'/stubs/controller.stub');
        $c = [];
        $models = [];
        foreach ($operations as $operation) {
            $params = [];
            if (!empty($operation['request'])) {
                $params[] = '\\App\\Http\\Requests\\'.$operation['request']['name'].'Request $request';
            }

            if (!empty($operation['actionParam'])) {
                $params[] = $operation['actionParam'][0];
            }

            if (empty($params) && !empty($operation['query']) && 'index' === $operation['action']) {
                $params[] = '\\Illuminate\\Http\\Request $request';
            }

            if (empty($operation['model'])) {
                $operation['model'] = $this->guestModelName($operations, $operation['controller']);
            }
            $operation['methodParam'] = implode(', ', $params);
            $c[$operation['controller']][] = $operation;
            if (!empty($operation['model'])) {
                $models[$operation['controller']][] = 'use App\\Models\\'.$operation['model'].';';
            }
        }

        $path = app()->basePath('app').'/Http/Controllers/';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $num = 0;
        foreach ($c as $controllerName => $controller) {
            $listModels = [];
            if (!empty($models[$controllerName])) {
                $listModels = array_unique($models[$controllerName]);
            }
            $cName = $controllerName.'Controller';
            $params = [
                'name' => $cName,
                'attributes' => $controller,
            ];
            $contentMethods = view()->make('template::controller-method', $params)->render();
            $content = str_replace([
                'DummyController',
                '// methods',
                '// imports',
            ], [
                $cName,
                $contentMethods,
                join("\n", $listModels),
            ], $tplContent);
            $cPath = $path.$cName.'.php';
            // echo $content."\r\n";
            if (file_put_contents($cPath, $content)) {
                $this->line("<info>Controller {$cName} created</info>");
                ++$num;
            }
        }

        $this->info("{$num} Controller generated on {$path}");
    }
}
