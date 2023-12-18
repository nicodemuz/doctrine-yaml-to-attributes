<?php

use Nicodemuz\DoctrineYamlToAttributes\Runner;

require 'vendor/autoload.php';

$runner = new Runner(
    yamlFilesDir: '/home/nico/Projects/lms-platform/symfony/config/doctrine',
    doctrineEntityDir: '/home/nico/Projects/lms-platform/symfony/src/Entity',
);
$runner->run();
