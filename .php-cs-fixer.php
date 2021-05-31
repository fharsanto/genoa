<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('tests/Fixtures')
    ->in(__DIR__)
;

$config = new PhpCsFixer\Config();
$config->setRules([
    '@PhpCsFixer' => true,
    'indentation_type' => true,
    'array_indentation' => true,
    'array_syntax' => ['syntax' => 'short'],
])
//->setIndent("\t")
    ->setLineEnding("\n")
    ->setFinder($finder)
;
