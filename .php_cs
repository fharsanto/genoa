<?php
return PhpCsFixer\Config::create()
->setRules([
    '@PhpCsFixer' => true,
    'indentation_type' => true,
    'array_indentation' => true,
    'array_syntax' => array('syntax' => 'short'),    
])
//->setIndent("\t")
->setLineEnding("\n")
;