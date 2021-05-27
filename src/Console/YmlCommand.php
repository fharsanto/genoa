<?php

namespace Genoa\Console;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class YmlCommand extends GeneratorCommand
{
    /**
     * Filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $file;

    /**
     * @var string
     */
    protected $name = 'genoa:yml';

    /**
     * @var string
     */
    protected $signature = 'genoa:yml {path}';

    /**
     * Command description.
     *
     * @var string
     */
    protected $description = 'Read yml file as an object';

    /**
     * @var bool
     */
    protected $ignoreSpecError = false;

    /**
     * @var \cebe\openapi\SpecObjectInterface
     */
    protected $openApi;

    /**
     * @var string
     */
    protected $path;

    public function __construct(Filesystem $file)
    {
        parent::__construct($file);
        $this->file = $file;
    }

    public function handle()
    {
        $path = $this->argument('path');

        try {
            $this->initializeOpenApi($path);
            $ops = $this->getOperations();
            if (!empty($ops)) {
                $this->generateRoutes($ops);
                $this->generateRequests($ops);
                $this->generateModels();
                $this->generateControllers($ops);
                $this->phpFixer();
            }
            // var_dump($ops);
        } catch (\Throwable $th) {
            $this->error($th->getMessage());
        }
    }

    protected function phpFixer()
    {
        $app = app()->basePath('app');
        $dirs = [
            $app.'/Http/Requests/',
            $app.'/Models/',
            $app.'/Http/Controllers/',
        ];
        foreach ($dirs as $dir) {
            $out = shell_exec('php-cs-fixer fix '.$dir);
            echo $out."\r\n";
        }
    }

    protected function getStub()
    {
        return '';
    }

    protected function generateRoutes(array $operations)
    {
        $path = base_path().'/routes/web.php';

        foreach ($operations as $operation) {
            $params = [
                'method' => !empty($operation['method']) ? $operation['method'] : 'get',
                'uri' => $operation['path'],
                'action' => $operation['controller'].'Controller@'.$operation['action'],
                'desc' => $operation['description'],
                'id' => $operation['operationId'],
            ];

            $content = view()->make('template::routes', $params)->render();
            $this->file->append($path, $content);
        }
    }

    protected function generateRequests(array $operations)
    {
        $tplContent = $this->file->get(__DIR__.'/stubs/request.stub');
        $path = app()->basePath('app').'/Http/Requests/';
        $this->makeDirectory($path);
        foreach ($operations as $operation) {
            if (empty($operation['request'])) {
                continue;
            }
            $req = $operation['request'];
            $content = str_replace([
                'DummyRequest',
                '[];',
            ], [$req['name'].'Request', var_export($req['attributes'], true).';'], $tplContent);
            // $content = view()->make('template::request', $operation['request'])->render();
            $this->file->put($path.$req['name'].'Request.php', $content);
        }
    }

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
        $tplContent = $this->file->get(__DIR__.'/stubs/controller.stub');
        $c = [];
        foreach ($operations as $operation) {
            $params = [];
            if (!empty($operation['request'])) {
                $params[] = '\\App\\Http\\Requests\\'.$operation['request']['name'].'Request $request';
            }

            if (!empty($operation['actionParam'])) {
                $params[] = $operation['actionParam'][0];
            }

            if (empty($operation['model'])) {
                $operation['model'] = $this->guestModelName($operations, $operation['controller']);
            }
            $operation['methodParam'] = implode(', ', $params);
            $c[$operation['controller']][] = $operation;
        }
        $path = app()->basePath('app').'/Http/Controllers/';
        $this->makeDirectory($path);

        foreach ($c as $controllerName => $controller) {
            $cName = $controllerName.'Controller';
            $params = [
                'name' => $cName,
                'attributes' => $controller,
            ];
            $contentMethods = view()->make('template::controller-method', $params)->render();
            $content = str_replace([
                'DummyController',
                '// methods',
            ], [
                $cName,
                $contentMethods,
            ], $tplContent);
            $cPath = $path.$cName.'.php';
            // echo $content."\r\n";
            $this->file->put($cPath, $content);
        }
    }

    protected function generateModels()
    {
        $tplContent = $this->file->get(__DIR__.'/stubs/model.stub');
        $oa = $this->getOpenApi();
        $models = [];
        $path = app()->basePath('app').'/Models/';
        $this->makeDirectory($path);
        foreach ($oa->components->schemas as $schemaName => $schema) {
            if ($schema instanceof Reference) {
                $schema = $schema->resolve();
            }
            $relations = [];

            if ((empty($schema->type) || 'object' === $schema->type) && empty($schema->properties)) {
                continue;
            }
            if (!empty($schema->type) && 'object' !== $schema->type) {
                continue;
            }

            foreach ($schema->properties as $name => $property) {
                if ('object' === $property->type) {
                    $ref = $property->getDocumentPosition();
                    if (0 === strpos($ref, '/components/schemas/')) {
                        $relations[$name] = [
                            'class' => substr($ref, 20),
                            'type' => 'hasOne',
                        ];
                    }
                }

                if ('array' === $property->type && !empty($property->items)) {
                    $ref = $property->items->getDocumentPosition();
                    if (isset($property->items->type) && 'string' === $property->items->type) {
                        continue;
                    }
                    if (0 === strpos($ref, '/components/schemas/')) {
                        $relations[$name] = [
                            'class' => substr($ref, 20),
                            'type' => 'hasMany',
                        ];
                    }
                }
            }
            $pathModel = $path.$schemaName.'.php';
            $rContent = view()->make('template::model-relations', [
                'name' => $schemaName,
                'relations' => $relations,
            ])->render();
            $content = str_replace([
                'DummyModel',
                '// relations',
            ], [$schemaName, $rContent], $tplContent);
            $models[] = $schemaName;
            // echo $content."\r\n\r\n";
            $this->file->put($pathModel, $content);
        }

        return $models;
    }

    /**
     * Checking Open API path validity.
     * Initiliaze Open API instance.
     *
     * @param string $path
     *
     * @throws Exception if file is invalid
     */
    protected function initializeOpenApi($path)
    {
        $rPath = realpath($path);
        if (!$rPath) {
            throw new Exception('Path file is invalid');
        }

        $fileInfo = pathinfo($rPath);
        if (empty($fileInfo['extension'])) {
            throw new Exception('File Extension problem');
        }

        if (!in_array($fileInfo['extension'], ['json', 'yaml'])) {
            throw new Exception('Allowed File Extension: .json or .yaml');
        }

        $this->path = $rPath;

        if ('json' === $fileInfo['extension']) {
            $this->openApi = Reader::readFromJsonFile($rPath, OpenApi::class, true);
        } elseif ('yaml' === $fileInfo['extension']) {
            $this->openApi = Reader::readFromYamlFile($rPath, OpenApi::class, true);
        }
    }

    /**
     * @return OpenApi
     */
    protected function getOpenApi()
    {
        if (null === $this->openApi) {
            $this->initializeOpenApi($this->path);
        }

        return $this->openApi;
    }

    /**
     * Generate All endpoint API to array.
     *
     * @return array
     */
    protected function getOperations()
    {
        $oa = $this->getOpenApi();
        if (empty($oa->paths)) {
            throw new Exception('Path is empty');
        }

        $components = [];

        // Loop througt endpoint API
        foreach ($oa->paths as $path => $pathItem) {
            if ('/' !== $path[0]) {
                throw new Exception('Path must begin with /');
            }
            if (null === $pathItem) {
                continue;
            }
            if ($pathItem instanceof Reference) {
                $pathItem = $pathItem->resolve();
            }

            $parts = explode('/', trim($path, '/'));
            $controller = [];
            $action = [];
            $actionParams = [];
            $params = false;

            foreach ($parts as $p => $part) {
                if (preg_match('/\{(.*)\}/', $part, $m)) {
                    $params = true;
                    if (isset($pathItem->parameters[$m[1]])) {
                        $actionParams[$m[1]] = '$'.$pathItem->parameters[$m[1]];
                    }
                    $actionParams[] = '$'.$m[1];
                } elseif ($params) {
                    $action[] = $part;
                } else {
                    $controller[] = ucfirst($part);
                }
            }

            $controller = implode('', $controller);
            $action = empty($action) ? '' : implode('-', $action);

            // Skip whole process when there's no http operation detected
            if (empty($pathItem->getOperations())) {
                throw new Exception('No Operation detected for '.$part);
            }

            $listMethod = [
                'get' => empty($actionParams) ? 'index' : 'show',
                'post' => 'store',
                'put' => 'update',
                'patch' => 'update',
                'delete' => 'destroy',
            ];
            foreach ($pathItem->getOperations() as $method => $operation) {
                $a = (isset($listMethod[$method])
                        ? $listMethod[$method] : 'http-'.$method);
                $req = null;
                $actionName = !empty($action) ? $action : $a;
                if (in_array($a, ['store', 'update', 'destroy'])
                    && null !== $operation->requestBody) {
                    $req = $this->getRequestBody($operation->requestBody, $controller, $actionName);
                }
                $c = [
                    'path' => $path,
                    'controller' => $controller,
                    'description' => !empty($operation->description) ? $operation->description : $operation->summary,
                    'operationId' => $operation->operationId,
                    'action' => $actionName,
                    'method' => $method,
                    'actionParam' => $actionParams,
                    'query' => $this->getQuery($operation->parameters),
                    'request' => $req,
                    'model' => $this->getModel($operation->responses),
                ];
                $components[] = $c;
            }
        }

        return $components;
    }

    protected function getModel($responses)
    {
        foreach ($responses as $code => $successResponse) {
            if (((string) $code)[0] !== '2') {
                continue;
            }
            if ($successResponse instanceof Reference) {
                $successResponse = $successResponse->resolve();
            }
            foreach ($successResponse->content as $contentType => $content) {
                $schema = $content->schema;
                if ($content->schema instanceof Reference) {
                    $schema = $content->resolve();
                }

                if ((empty($schema->type) || 'object' === $schema->type) && empty($schema->properties)) {
                    continue;
                }

                $attributes = [];
                $ref = $schema->getDocumentPosition();
                if (0 === strpos($ref, '/components/schemas/')) {
                    return substr($ref, 20);
                }
            }
        }
    }

    protected function getRequestBody(RequestBody $requestBody, string $controller, string $actionName)
    {
        if (null === $requestBody) {
            return;
        }

        if ($requestBody instanceof Reference) {
            $requestBody = $requestBody->resolve();
        }

        foreach ($requestBody->content as $ct => $content) {
            $schema = $content->schema;
            if ($content->schema instanceof Reference) {
                $schema = $content->resolve();
            }

            if ((empty($schema->type) || 'object' === $schema->type) && empty($schema->properties)) {
                continue;
            }

            $attributes = [];
            $ref = $schema->getDocumentPosition();
            $name = 0 === strpos($ref, '/components/schemas/')
                    ? substr($ref, 20) : $controller.$actionName;

            if ('array' === $schema->type && !empty($schema->items)) {
                $ref = $schema->items->getDocumentPosition();
                $name = 0 === strpos($ref, '/components/schemas/')
                    ? substr($ref, 20) : $controller.$actionName;

                $attributes = $attributes + $this->getValidationRules($schema->items, $name, isset($schema->items->required[$name]));
            }

            foreach ($schema->properties as $propertyName => $property) {
                if ($property instanceof Reference) {
                    $property = $property->resolve();
                }
                $attributes = $attributes + $this->getValidationRules($property, $propertyName, isset($property->required[$propertyName]));
            }

            return [
                'name' => $name,
                'attributes' => $attributes,
            ];
        }
    }

    /**
     * Get Query from Parameters.
     *
     * @param array \cebe\openapi\spec\Parameters
     * @param \cebe\openapi\spec\Parameter $parameters
     */
    protected function getQuery($parameters)
    {
        if (empty($parameters)) {
            return [];
        }

        $query = [];
        foreach ($parameters as $p => $parameter) {
            // if ('query' === $parameter->in) {
            $query[$parameter->name] = $this->getValidationRule($parameter->schema, $parameter->required);
            // }
        }

        return $query;
    }

    protected function getValidationRules(Schema $schema, string $name, bool $required)
    {
        $attributes = [];

        if ('object' == $schema->type && !empty($schema->properties)) {
            foreach ($schema->properties as $propName => $property) {
                $attributes[$name.'.*.'.$propName] = $this->getValidationRule($property, isset($property->required[$propName]));
            }
        } else {
            $attributes[$name] = $this->getValidationRule($schema, isset($schema->required[$name]));
        }

        return $attributes;
    }

    /**
     * Read a nested or single Schema.
     */
    protected function getValidationRule(Schema $schema, bool $required)
    {
        $additional = [];

        if ($required) {
            $additional[] = 'required';
        } else {
            $additional[] = 'nullable';
        }

        if (!empty($schema->minimum)) {
            $additional[] = 'min:'.$schema->minimum;
        }

        if (!empty($schema->maximum)) {
            $additional[] = 'max:'.$schema->maximum;
        }

        if (!empty($schema->maxLength)) {
            $additional[] = 'size:'.$schema->maxLength;
        }

        if (!empty($schema->pattern)) {
            $additional[] = 'regex:/'.$schema->pattern.'/';
        }

        if ('string' == $schema->type) {
            if (!empty($schema->format)) {
                $additional[] = $schema->format;
            }
        }
        $additional[] = $schema->type;

        return implode('|', $additional);
    }
}
