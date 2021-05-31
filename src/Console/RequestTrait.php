<?php

namespace Genoa\Console;

use Illuminate\Console\Concerns\InteractsWithIO;

/**
 * Generate app/Http/Request from Open API.
 */
trait RequestTrait
{
    use InteractsWithIO;

    /**
     * Generate Request from array $operations => request, attributes.
     */
    protected function generateRequests(array $operations)
    {
        $tplContent = file_get_contents(__DIR__.'/stubs/request.stub');
        $path = app()->basePath('app').'/Http/Requests/';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $num = 0;
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
            if (file_put_contents($path.$req['name'].'Request.php', $content)) {
                $this->line("<info>Request {$req['name']} created</info>");
                ++$num;
            }
            // $this->file->put($path.$req['name'].'Request.php', $content);
        }

        $this->info("{$num} Request generated on {$path}");
    }
}
