<?php

use Nicodemuz\DoctrineYamlToAttributes\Runner;

require 'vendor/autoload.php';

$runner = new Runner(
    yamlFilesDir: '/path/to/symfony/config/doctrine',
    doctrineEntityDir: '/path/to/symfony/src/Entity',
);
$runner->run();
