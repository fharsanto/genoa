<?php

namespace Genoa\Console;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class YmlCommand extends GeneratorCommand
{
    use RouteTrait;
    use OperationTrait;
    use RequestTrait;
    use ModelTrait;
    use ControllerTrait;
    use MigrationTrait;
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

    /**
     * @var string[]
     */
    protected $pathInfo;

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
            $ops = $this->getOperations($this->getOpenApi());
            if (!empty($ops)) {
                $this->generateRoutes($this->openApi, $ops, $this->pathInfo['filename']);
                $this->generateRequests($ops);
                $models = $this->generateModels($this->openApi);
                $this->generateControllers($ops);
                // $this->generateMigrations($models, $this->file);

                if ($this->confirm('Do you wish to Fix using auto fixer (php-cs-fixer) ?')) {
                    $this->phpFixer();
                }
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
        $config = __DIR__.'/../../.php_cs';
        foreach ($dirs as $dir) {
            $out = shell_exec('vendor/bin/php-cs-fixer fix '.$dir.' --config '.$config);
            echo $out."\r\n";
        }
    }

    protected function getStub()
    {
        return '';
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
        $this->pathInfo = $fileInfo;

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
}
