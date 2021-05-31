<?php

namespace Genoa\Console;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;
use cebe\openapi\SpecObjectInterface;

/**
 * Extract Paths from Open API yml.
 */
trait OperationTrait
{
    /**
     * Generate All endpoint API to array.
     *
     * @return array
     */
    protected function getOperations(SpecObjectInterface $oa)
    {
        if (empty($oa->paths)) {
            throw new \Exception('Path is empty');
        }

        $components = [];

        // Loop througt endpoint API
        foreach ($oa->paths as $path => $pathItem) {
            if ('/' !== $path[0]) {
                throw new \Exception('Path must begin with /');
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
                throw new \Exception('No Operation detected for '.$part);
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

    /**
     * Get Model Name from ResponseBody.
     *
     * @param mixed $responses
     */
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

    /**
     * Get RequestBody as validation Request.
     */
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

            if ((empty($schema->type) || 'object' === $schema->type)
                && empty($schema->properties)
                && !isset($schema->allOf)) {
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

            if (!empty($schema->allOf)) {
                foreach ($schema->allOf as $sch) {
                    if ($sch instanceof Reference) {
                        $sch = $property->resolve();
                    }
                    foreach ($sch->properties as $key => $prop) {
                        $attributes = $attributes + $this->getValidationRules($prop, $key, isset($prop->required[$key]));
                    }
                }
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

    /**
     * Get Validation from object when there's an $ref.
     */
    protected function getValidationRules(Schema $schema, string $name, bool $required)
    {
        $attributes = [];

        if ('object' == $schema->type && !empty($schema->properties)) {
            foreach ($schema->properties as $propName => $property) {
                $attributes[$name.'.'.$propName] = $this->getValidationRule($property, isset($property->required[$propName]));
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
