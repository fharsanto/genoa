# Genoa (Generator from Open API)
The package provides a simple way to create Open API service (REST API). 

This package uses [cebe/php-openapi](https://github.com/cebe/php-openapi) for reading from Open API specification.

## Features
- Auto generate routes, Http/Request, Http/Controller, models
- Auto generate common HTTP responses
- Add support allOf extends Open API

## Getting started
### Installation via composer
First of all, create lumen project
```sh
$ composer create-project --prefer-dist laravel/lumen my-project
```

in your project directory run:
```sh
$ composer require tukangketik/genoa
```

add the service provider in `bootstrap/app.php`
```php
$app->register(Genoa\GeneratorOpenApiServiceProvider::class);
```
The service provider will register to artisan command.

### Running generator
```sh
$ php artisan genoa:yml pathOfYmlFile.yml
```

Add 
<!-- Rest API Generator for Lumen -->