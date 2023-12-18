<?php

namespace Nicodemuz\DoctrineYamlToAttributes;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class Runner
{
    public function __construct(
        private readonly string $yamlFilesDir,
        private readonly string $doctrineEntityDir,
    ) {
    }

    public function run(): void
    {
        $finder = new Finder();
        $finder
            ->in($this->yamlFilesDir)
            ->files()
            ->name('*.orm.yml')
        ;

        foreach ($finder as $file) {
            $this->handleFile($file);
        }
    }

    private function handleFile(SplFileInfo $file): void
    {
        $absoluteFilePath = $file->getRealPath();

        $yaml = Yaml::parseFile($absoluteFilePath);

        $fileNamespace = array_keys($yaml)[0];
        $parts = explode('\\', $fileNamespace);
        $fileClass = end($parts);

        $yamlEntity = $yaml[$fileNamespace];

        $filename = $this->doctrineEntityDir . '/' . $fileClass . '.php';

        if (file_exists($filename)) {
            $code = file_get_contents($filename);
            $phpFile = PhpFile::fromCode($code);

            $class = current($phpFile->getClasses());

            if ($class instanceof ClassType) {

                foreach ($phpFile->getNamespaces() as $namespace) {
                    $namespace->addUse('Doctrine\ORM\Mapping', 'ORM');
                    $namespace->addUse('Doctrine\DBAL\Types\Types');
                }

                $this->handleEntityAttribute($yamlEntity, $phpFile, $class);
                if ($yamlEntity['type'] === 'entity') {
                    unset($yamlEntity['type']);
                }
                unset($yamlEntity['repositoryClass']);
                $this->handleTableAttribute($yamlEntity, $class);
                unset($yamlEntity['table']);

                if (array_key_exists('id', $yamlEntity)) {
                    foreach ($yamlEntity['id'] as $key => $field) {
                        $this->handleProperty($phpFile, $class, $key, $field);
                    }
                    unset($yamlEntity['id']);
                }

                if (array_key_exists('fields', $yamlEntity)) {
                    foreach ($yamlEntity['fields'] as $key => $field) {
                        $this->handleProperty($phpFile, $class, $key, $field);
                    }
                    unset($yamlEntity['fields']);
                }

                if (sizeof($yamlEntity) > 0) {
                    dump('Unsupported property key: ', $yamlEntity); die;
                }

                $printer = new PsrPrinter();
                $printer->wrapLength = 9999;
                $newCode = $printer->printFile($phpFile);

                file_put_contents($filename, $newCode);
            }
        }
    }

    public function handleTableAttribute(array $yamlEntity, ClassType $class): void
    {
        if (array_key_exists('table', $yamlEntity)) {
            $tableAttributes = [
                'name' => $yamlEntity['table']
            ];

            $class->addAttribute('Doctrine\ORM\Mapping\Table', $tableAttributes);
        }
    }

    public function handleEntityAttribute(array $yamlEntity, PhpFile $phpFile, ClassType $class): void
    {
        $entityAttributes = [];

        if (array_key_exists('repositoryClass', $yamlEntity)) {
            foreach ($phpFile->getNamespaces() as $namespace) {
                $namespace->addUse($yamlEntity['repositoryClass']);
            }
            $repositoryClass = str_replace('App\\Repository\\', '', $yamlEntity['repositoryClass']);
            $entityAttributes['repositoryClass'] = new Literal($repositoryClass . '::class');
        }

        $class->addAttribute('Doctrine\ORM\Mapping\Entity', $entityAttributes);
    }

    public function handleProperty(PhpFile $phpFile, ClassType $class, int|string $key, array $field): void
    {
        if ($class->hasProperty($key)) {
            $property = $class->getProperty($key);
            if (isset($field['id']) && $field['id'] === true) {
                $property->addAttribute('Doctrine\ORM\Mapping\Id');
                unset($field['id']);
            }
            if (isset($field['generator']['strategy']) && $field['generator']['strategy'] === 'AUTO') {
                $property->addAttribute('Doctrine\ORM\Mapping\GeneratedValue');
                unset($field['generator']);
            }
            if (isset($field['generator']['strategy']) && $field['generator']['strategy'] === 'CUSTOM') {
                $attributes = ['strategy' => 'CUSTOM'];
                $property->addAttribute('Doctrine\ORM\Mapping\GeneratedValue', $attributes);
                unset($field['generator']);
            }
            if (isset($field['customIdGenerator']['class'])) {
                foreach ($phpFile->getNamespaces() as $namespace) {
                    $namespace->addUse($field['customIdGenerator']['class']);
                }
                $classParts = explode('\\', $field['customIdGenerator']['class']);
                $attributes = ['class' => new Literal(end($classParts) . '::class')];

                $property->addAttribute('Doctrine\ORM\Mapping\CustomIdGenerator', $attributes);
                unset($field['customIdGenerator']);
            }

            $columnAttributes = [];

            if (isset($field['type'])) {
                $type = $this->convertType($field['type']);
                if ($type) {
                    $columnAttributes['type'] = new Literal('Types::' . $this->convertType($field['type']));
                }
                unset($field['type']);
            }

            if (isset($field['length'])) {
                $columnAttributes['length'] = $field['length'];
                unset($field['length']);
            }

            if (isset($field['scale'])) {
                $columnAttributes['scale'] = $field['scale'];
                unset($field['scale']);
            }

            if (isset($field['unique'])) {
                $columnAttributes['unique'] = $field['unique'];
                unset($field['unique']);
            }

            if (isset($field['nullable'])) {
                $columnAttributes['nullable'] = $field['nullable'];
                unset($field['nullable']);
            }

            if (isset($field['options'])) {
                $columnAttributes['options'] = $field['options'];
                unset($field['options']);
            }

            if (sizeof($field) > 0) {
                dump('Unsupported property key: ', $field); die;
            }

            $property->addAttribute('Doctrine\ORM\Mapping\Column', $columnAttributes);
        }
    }

    private function convertType(string $type): ?string
    {
        return match ($type) {
            'ascii_string' => 'ASCII_STRING',
            'bigint' => 'BIGINT',
            'binary' => 'BINARY',
            'blob' => 'BLOB',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE_MUTABLE',
            'date_immutable' => 'DATE_IMMUTABLE',
            'dateinterval' => 'DATEINTERVAL',
            'datetime' => 'DATETIME_MUTABLE',
            'datetime_immutable' => 'DATETIME_IMMUTABLE',
            'datetimetz' => 'DATETIMETZ_MUTABLE',
            'datetimetz_immutable' => 'DATETIMETZ_IMMUTABLE',
            'decimal' => 'DECIMAL',
            'float' => 'FLOAT',
            'guid' => 'GUID',
            'integer' => 'INTEGER',
            'json' => 'JSON',
            default => null,
        };
    }
}