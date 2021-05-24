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

class YmlCommand extends Command
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
        parent::__construct();
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
            }
        } catch (\Throwable $th) {
            $this->error($th->getMessage());
        }
    }

    protected function generateRoutes(array $operations)
    {
        // code...
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
                        $actionParams[$m[1]] = $pathItem->parameters[$m[1]];
                    }
                    $actionParams[] = $m[1];
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
                    'controller' => $controller.'Controller',
                    'description' => !empty($operation->description) ? $operation->description : $operation->summary,
                    'operationId' => $operation->operationId,
                    'action' => $actionName,
                    'method' => strtoupper($method),
                    'actionParam' => $actionParams,
                    'query' => $this->getQuery($operation->parameters),
                    'request' => $req,
                ];
                $components[] = $c;
            }
        }

        return $components;
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
