<?php

namespace Genoa\Console;

use cebe\openapi\SpecObjectInterface;
use Illuminate\Console\Concerns\InteractsWithIO;

/**
 * Trait purpose for generating Routes specific.
 */
trait RouteTrait
{
    use InteractsWithIO;

    public function generateRoutes(SpecObjectInterface $openApi, array $operations, $fileName)
    {
        $routeFile = !empty($fileName) ? $fileName.'.php' : 'custom-route.php';
        $path = base_path().'/routes/'.$routeFile;

        $tplContent = file_get_contents(__DIR__.'/stubs/routes.stub');
        $contents = [];
        foreach ($operations as $operation) {
            $params = [
                'method' => !empty($operation['method']) ? $operation['method'] : 'get',
                'uri' => $operation['path'],
                'action' => $operation['controller'].'Controller@'.$operation['action'],
                'desc' => $operation['description'],
                'id' => $operation['operationId'],
            ];

            $contents[] = view()->make('template::routes', $params)->render();
            // $this->file->append($path, $content);
        }
        $content = str_replace([
            '{{ title }}',
            '{{ description }}',
            '{{ php_version }}',
            '{{ version }}',
            '{{ routes }}',
        ], [
            $openApi->info->title,
            $openApi->info->description,
            PHP_VERSION,
            $openApi->openapi,
            implode("\n", $contents),
        ], $tplContent);

        if (file_exists($path)) {
            $confirmSkip = $this->confirm("File routes/{$routeFile} exist, skip generate?");
            if ($confirmSkip) {
                return;
            }

            $name = $this->ask('Enter new file?');

            return $this->generateRoutes($openApi, $operations, $name);
        }

        try {
            file_put_contents($path, $content);
            $this->appendToBootstrap($routeFile);
            $this->info(count($operations).' routes generated on '.$path);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    protected function appendToBootstrap($routeFile)
    {
        $bootstrapPath = base_path().'/bootstrap/app.php';
        $boostrapChunk = file($bootstrapPath, FILE_IGNORE_NEW_LINES);
        $findExistingRoute = array_filter($boostrapChunk, function ($value, $key) {
            return false !== strpos($value, 'routes/web.php') ? (int) $key : false;
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($findExistingRoute)) {
            $key = key($findExistingRoute);
            array_splice($boostrapChunk, $key + 1, 0, "\trequire __DIR__.'/../routes/{$routeFile}';");
        }

        return file_put_contents($bootstrapPath, join("\n", $boostrapChunk));
    }
}
