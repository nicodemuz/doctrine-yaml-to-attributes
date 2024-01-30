# Doctrine YAML converter

Doctrine's YAML mapping driver is deprecated and will be removed in Doctrine ORM 3.0. This script converts your Doctrine YAML configuration files to compatible configurations using PHP attributes.

## Installation

You can simply clone this repository and run the script. This script does not have to be part of your project files.

```bash
git clone https://github.com/nicodemuz/doctrine-yaml-to-attributes.git;
cd doctrine-yaml-to-attributes;
composer install;
```

## Sample usage

```php
<?php

use Nicodemuz\DoctrineYamlToAttributes\Runner;

require 'vendor/autoload.php';

$runner = new Runner(
    yamlFilesDir: '/path/to/symfony/config/doctrine',
    doctrineEntityDir: '/path/to/symfony/src/Entity',
);
$runner->run();
```

## Notes

This script was hacked together in a few hours. Use at own risk. Commit your work before executing.

The script does not support all Doctrine V3 configuration parameters. If a configuration parameter is unsupported, no changes will be executed. Please modify `Runner.php` file accordingly. Pull requests will be gladly accepted.

The script uses Nette PHP Code Generator to rewrite your PHP files. It will likely introduce some unwanted changes. You may wish to modify the printer code in `Runner.php` to match your coding standard.

## Authors

* [Nico Hiort af Ornäs](https://github.com/nicodemuz)