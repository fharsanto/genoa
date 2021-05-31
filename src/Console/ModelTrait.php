<?php

namespace Genoa\Console;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use cebe\openapi\SpecObjectInterface;
use Illuminate\Console\Concerns\InteractsWithIO;

/**
 * Generate app/Models from Open API components/schema.
 */
trait ModelTrait
{
    use InteractsWithIO;

    protected function generateModels(SpecObjectInterface $oa)
    {
        $tplContent = file_get_contents(__DIR__.'/stubs/model.stub');
        $models = [];
        $path = app()->basePath('app').'/Models/';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $num = 0;
        foreach ($oa->components->schemas as $schemaName => $schema) {
            if ($schema instanceof Reference) {
                $schema = $schema->resolve();
            }
            $relations = [];

            if ((empty($schema->type) || 'object' === $schema->type)
                && empty($schema->properties)
                && !isset($schema->allOf)) {
                continue;
            }
            if (!empty($schema->type) && 'object' !== $schema->type) {
                continue;
            }

            $relations = $this->getRelations($schema);

            if (!empty($schema->allOf) && count($schema->allOf) > 1) {
                $endAllOf = $schema->allOf[count($schema->allOf) - 1];
                $relations = $relations + $this->getRelations($endAllOf);
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
            if (file_put_contents($pathModel, $content)) {
                $this->line("<info>Model {$schemaName} created</info>");
                ++$num;
            }
        }
        $this->info("{$num} models generated on {$path}");

        return $models;
    }

    protected function getRelations(Schema $schema)
    {
        $relations = [];
        $schemaIsObject = 'object' === $schema->type;
        foreach ($schema->properties as $name => $property) {
            if ('object' === $property->type || $schemaIsObject) {
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

        return $relations;
    }
}
