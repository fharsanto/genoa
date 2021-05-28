<?php

namespace Genoa\Console;

use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Generate Migrations from Models.
 */
trait MigrationTrait
{
    use InteractsWithIO;
    /**
     * @var Filesystem
     */
    protected $files;

    protected function generateMigrations(array $models, Filesystem $files)
    {
        $this->files = $files;
        if (empty($models)) {
            $this->line('<info>Models empty, nothing to migrate</info>');

            return;
        }

        $tplContent = file_get_contents(__DIR__.'/stubs/migration.stub');
        $path = base_path().'/database/migrations/';

        $num = 0;
        foreach ($models as $model) {
            $this->ensureMigrationDoesntAlreadyExist($model, $path);
            $modelName = Str::studly($model);
            $content = str_replace([
                '{{ class }}',
                '{{ table }}',
                '{{ index }}',
                '{{ indexKey }}',
            ], [
                $modelName,
                Str::snake(Str::pluralStudly($model)),
                'unique',
                'id',
            ], $tplContent);
            $file = date('Y_m_d_His').'_'.Str::lower($modelName).'.php';
            if (file_put_contents($path.$file, $content)) {
                ++$num;
                $this->line("<info>Created Migration:</info> {$file}");
            }
        }
        $this->line("{$num} migrations generated");
    }

    protected function ensureMigrationDoesntAlreadyExist($name, $migrationPath = null)
    {
        if (!empty($migrationPath)) {
            $migrationFiles = $this->files->glob($migrationPath.'/*.php');

            foreach ($migrationFiles as $migrationFile) {
                $this->files->requireOnce($migrationFile);
            }
        }

        if (class_exists($className = Str::studly($name))) {
            throw new \InvalidArgumentException("A {$className} class already exists.");
        }
    }
}
