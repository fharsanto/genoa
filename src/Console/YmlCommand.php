<?php

namespace Genoa\Console;

use cebe\openapi\Reader;
use cebe\openapi\spec\MediaType;
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
            $this->generateUrls();
        } catch (\Throwable $th) {
            $this->error($th->getMessage());
        }
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
    protected function generateUrls()
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
                if (in_array($a, ['store', 'update', 'destroy'])
                    && null !== $operation->requestBody) {
                    $req = $this->generateRequestBody($operation->requestBody);
                }
                $c = [
                    'path' => $path,
                    'controller' => $controller.'Controller',
                    'action' => !empty($action) ? $action : $a,
                    'method' => strtoupper($method),
                    'actionParam' => $actionParams,
                    'query' => $this->generateQuery($operation->parameters),
                    'request' => $req,
                ];
                $components[] = $c;
            }
        }
        // var_dump($components);
    }

    protected function generateRequestBody(RequestBody $requestBody)
    {
        if (null === $requestBody) {
            return;
        }

        if ($requestBody instanceof Reference) {
            $requestBody = $requestBody->resolve();
        }

        foreach ($requestBody->content as $ct => $content) {
            // var_dump($content);

            // exit;
            list($modelClass) = $this->getModelClass($content);
            // var_dump($content->schema->type, $content->schema->description);
            if (null !== $modelClass) {
                return $modelClass;
            }
        }
    }

    protected function getModelClass(MediaType $content)
    {
        // @var $referencedSchema Schema
        if ($content->schema instanceof Reference) {
            $referencedSchema = $content->schema->resolve();
            // Model data is directly returned
            if (null === $referencedSchema->type || 'object' === $referencedSchema->type) {
                $ref = $content->schema->getJsonReference()->getJsonPointer()->getPointer();
                if (0 === strpos($ref, '/components/schemas/')) {
                    return [substr($ref, 20), '', ''];
                }
            }
            // an array of Model data is directly returned
            if ('array' === $referencedSchema->type && $referencedSchema->items instanceof Reference) {
                $ref = $referencedSchema->items->getJsonReference()->getJsonPointer()->getPointer();
                if (0 === strpos($ref, '/components/schemas/')) {
                    return [substr($ref, 20), '', ''];
                }
            }
        } else {
            $referencedSchema = $content->schema;
        }

        if (null === $referencedSchema) {
            return [null, null, null];
        }
        if (null === $referencedSchema->type || 'object' === $referencedSchema->type) {
            foreach ($referencedSchema->properties as $propertyName => $property) {
                if ($property instanceof Reference) {
                    $referencedModelSchema = $property->resolve();
                    if (null === $referencedModelSchema->type || 'object' === $referencedModelSchema->type) {
                        // Model data is wrapped
                        $ref = $property->getJsonReference()->getJsonPointer()->getPointer();
                        if (0 === strpos($ref, '/components/schemas/')) {
                            return [substr($ref, 20), $propertyName, null];
                        }
                    } elseif ('array' === $referencedModelSchema->type && $referencedModelSchema->items instanceof Reference) {
                        // an array of Model data is wrapped
                        $ref = $referencedModelSchema->items->getJsonReference()->getJsonPointer()->getPointer();
                        if (0 === strpos($ref, '/components/schemas/')) {
                            return [substr($ref, 20), null, $propertyName];
                        }
                    }
                } elseif ('array' === $property->type && $property->items instanceof Reference) {
                    // an array of Model data is wrapped
                    $ref = $property->items->getJsonReference()->getJsonPointer()->getPointer();
                    if (0 === strpos($ref, '/components/schemas/')) {
                        return [substr($ref, 20), null, $propertyName];
                    }
                }
            }
        }
        if ('array' === $referencedSchema->type && $referencedSchema->items instanceof Reference) {
            $ref = $referencedSchema->items->getJsonReference()->getJsonPointer()->getPointer();
            if (0 === strpos($ref, '/components/schemas/')) {
                return [substr($ref, 20), '', ''];
            }
        }

        return [null, null, null];
    }

    /**
     * Get Query from Parameters.
     *
     * @param array \cebe\openapi\spec\Parameters
     * @param \cebe\openapi\spec\Parameter $parameters
     */
    protected function generateQuery($parameters)
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
