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

                $this->addUseStatement($phpFile, 'Doctrine\ORM\Mapping', 'ORM');

                $this->handleEntityAttribute($yamlEntity, $phpFile, $class);
                if ($yamlEntity['type'] === 'entity') {
                    unset($yamlEntity['type']);
                }
                unset($yamlEntity['repositoryClass']);
                $this->handleTableAttribute($yamlEntity, $class);
                unset($yamlEntity['table']);

                if (array_key_exists('uniqueConstraints', $yamlEntity)) {
                    foreach ($yamlEntity['uniqueConstraints'] as $key => $field) {
                        $this->handleUniqueConstraint($class, $key, $field);
                    }
                    unset($yamlEntity['uniqueConstraints']);
                }

                if (array_key_exists('indexes', $yamlEntity)) {
                    foreach ($yamlEntity['indexes'] as $key => $field) {
                        $this->handleIndex($class, $key, $field);
                    }
                    unset($yamlEntity['indexes']);
                }

                if (array_key_exists('entityListeners', $yamlEntity)) {
                    $attributes = [];
                    $attributes[] = array_keys($yamlEntity['entityListeners']);

                    $class->addAttribute('Doctrine\ORM\Mapping\EntityListeners', $attributes);

                    unset($yamlEntity['entityListeners']);
                }

                if (array_key_exists('inheritanceType', $yamlEntity)) {
                    $attributes = [];
                    $attributes[] = $yamlEntity['inheritanceType'];

                    $class->addAttribute('Doctrine\ORM\Mapping\InheritanceType', $attributes);

                    unset($yamlEntity['inheritanceType']);
                }

                if (array_key_exists('discriminatorColumn', $yamlEntity)) {
                    $attributes = $yamlEntity['discriminatorColumn'];

                    $class->addAttribute('Doctrine\ORM\Mapping\DiscriminatorColumn', $attributes);

                    unset($yamlEntity['discriminatorColumn']);
                }

                if (array_key_exists('discriminatorMap', $yamlEntity)) {
                    $attributes = [];
                    $attributes[] = $yamlEntity['discriminatorMap'];

                    $class->addAttribute('Doctrine\ORM\Mapping\DiscriminatorMap', $attributes);

                    unset($yamlEntity['discriminatorMap']);
                }

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

                if (array_key_exists('oneToOne', $yamlEntity)) {
                    foreach ($yamlEntity['oneToOne'] as $key => $field) {
                        $this->handleOneToOne($phpFile, $class, $key, $field);
                    }
                    unset($yamlEntity['oneToOne']);
                }

                if (array_key_exists('manyToOne', $yamlEntity)) {
                    foreach ($yamlEntity['manyToOne'] as $key => $field) {
                        $this->handleManyToOne($phpFile, $class, $key, $field);
                    }
                    unset($yamlEntity['manyToOne']);
                }

                if (array_key_exists('oneToMany', $yamlEntity)) {
                    foreach ($yamlEntity['oneToMany'] as $key => $field) {
                        $this->handleOneToMany($phpFile, $class, $key, $field);
                    }
                    unset($yamlEntity['oneToMany']);
                }

                if (array_key_exists('gedmo', $yamlEntity)) {
                    if (array_key_exists('soft_deleteable', $yamlEntity['gedmo'])) {
                        $attributes = [
                            'fieldName' => $yamlEntity['gedmo']['soft_deleteable']['field_name'],
                            'timeAware' => $yamlEntity['gedmo']['soft_deleteable']['time_aware'],
                        ];
                        $class->addAttribute('Gedmo\Mapping\Annotation\SoftDeleteable', $attributes);
                        unset($yamlEntity['gedmo']['soft_deleteable']);
                    }
                    if (sizeof($yamlEntity['gedmo']) === 0) {
                        unset($yamlEntity['gedmo']);
                    }
                }

                if (sizeof($yamlEntity) > 0) {
                    dump('Unsupported class key: ', $yamlEntity);
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
                    $this->addUseStatement($phpFile, 'Doctrine\DBAL\Types\Types');
                    $columnAttributes['type'] = new Literal('Types::' . $this->convertType($field['type']));
                } else {
                    $columnAttributes['type'] = $field['type'];
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

            if (isset($field['precision'])) {
                $columnAttributes['precision'] = $field['precision'];
                unset($field['precision']);
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

            if (isset($field['gedmo']['timestampable'])) {
                $timestampableAttributes = $field['gedmo']['timestampable'];
                $property->addAttribute('Gedmo\Mapping\Annotation\Timestampable', $timestampableAttributes);
                unset($field['gedmo']);
            }

            if (sizeof($field) > 0) {
                dump('Unsupported column key: ', $field); die;
            }

            $property->addAttribute('Doctrine\ORM\Mapping\Column', $columnAttributes);
        }
    }

    public function handleOneToOne(PhpFile $phpFile, ClassType $class, int|string $key, array $field): void
    {
        if ($class->hasProperty($key)) {
            $property = $class->getProperty($key);

            $attributes = [];

            if (isset($field['targetEntity'])) {
                $attributes['targetEntity'] = new Literal($field['targetEntity'] . '::class');
                unset($field['targetEntity']);
            }

            if (isset($field['mappedBy'])) {
                $attributes['mappedBy'] = $field['mappedBy'];
                unset($field['mappedBy']);
            }

            if (isset($field['cascade'])) {
                $attributes['cascade'] = $field['cascade'];
                unset($field['cascade']);
            }

            if (isset($field['fetch'])) {
                $attributes['fetch'] = $field['fetch'];
                unset($field['fetch']);
            }

            if (isset($field['inversedBy'])) {
                $attributes['inversedBy'] = $field['inversedBy'];
                unset($field['inversedBy']);
            }

            if (isset($field['joinColumn'])) {
                $joinColumnAttributes = $field['joinColumn'];
                unset($field['joinColumn']);
            }

            if (sizeof($field) > 0) {
                dump('Unsupported OneToOne key: ', $field); die;
            }

            $property->addAttribute('Doctrine\ORM\Mapping\OneToOne', $attributes);

            if (isset($joinColumnAttributes)) {
                $property->addAttribute('Doctrine\ORM\Mapping\JoinColumn', $joinColumnAttributes);
            }
        }
    }

    public function handleManyToOne(PhpFile $phpFile, ClassType $class, int|string $key, array $field): void
    {
        if ($class->hasProperty($key)) {
            $property = $class->getProperty($key);

            $attributes = [];

            if (isset($field['targetEntity'])) {
                $attributes['targetEntity'] = new Literal($field['targetEntity'] . '::class');
                unset($field['targetEntity']);
            }

            if (isset($field['cascade'])) {
                $attributes['cascade'] = $field['cascade'];
                unset($field['cascade']);
            }

            if (isset($field['fetch'])) {
                $attributes['fetch'] = $field['fetch'];
                unset($field['fetch']);
            }

            if (isset($field['inversedBy'])) {
                $attributes['inversedBy'] = $field['inversedBy'];
                unset($field['inversedBy']);
            }

            if (isset($field['joinColumn'])) {
                $joinColumnAttributes = $field['joinColumn'];
                unset($field['joinColumn']);
            }

            if (sizeof($field) > 0) {
                dump('Unsupported ManyToOne key: ', $field); die;
            }

            $property->addAttribute('Doctrine\ORM\Mapping\ManyToOne', $attributes);

            if (isset($joinColumnAttributes)) {
                $property->addAttribute('Doctrine\ORM\Mapping\JoinColumn', $joinColumnAttributes);
            }
        }
    }

    public function handleOneToMany(PhpFile $phpFile, ClassType $class, int|string $key, array $field): void
    {
        if ($class->hasProperty($key)) {
            $property = $class->getProperty($key);

            $attributes = [];

            if (isset($field['mappedBy'])) {
                $attributes['mappedBy'] = $field['mappedBy'];
                unset($field['mappedBy']);
            }

            if (isset($field['targetEntity'])) {
                $attributes['targetEntity'] = new Literal($field['targetEntity'] . '::class');
                unset($field['targetEntity']);
            }

            if (isset($field['cascade'])) {
                $attributes['cascade'] = $field['cascade'];
                unset($field['cascade']);
            }

            if (isset($field['fetch'])) {
                $attributes['fetch'] = $field['fetch'];
                unset($field['fetch']);
            }

            if (sizeof($field) > 0) {
                dump('Unsupported OneToMany key: ', $field); die;
            }

            $property->addAttribute('Doctrine\ORM\Mapping\OneToMany', $attributes);
        }
    }

    private function convertType(string $type): ?string
    {
        switch ($type) {
            case 'array':
                return 'ARRAY';
            case 'ascii_string':
                return 'ASCII_STRING';
            case 'bigint':
                return 'BIGINT';
            case 'binary':
                return 'BINARY';
            case 'blob':
                return 'BLOB';
            case 'boolean':
                return 'BOOLEAN';
            case 'date':
                return 'DATE_MUTABLE';
            case 'date_immutable':
                return 'DATE_IMMUTABLE';
            case 'dateinterval':
                return 'DATEINTERVAL';
            case 'datetime':
                return 'DATETIME_MUTABLE';
            case 'datetime_immutable':
                return 'DATETIME_IMMUTABLE';
            case 'datetimetz':
                return 'DATETIMETZ_MUTABLE';
            case 'datetimetz_immutable':
                return 'DATETIMETZ_IMMUTABLE';
            case 'decimal':
                return 'DECIMAL';
            case 'float':
                return 'FLOAT';
            case 'guid':
                return 'GUID';
            case 'integer':
                return 'INTEGER';
            case 'json':
                return 'JSON';
            case 'object':
                return 'OBJECT';
            case 'simple_array':
                return 'SIMPLE_ARRAY';
            case 'smallint':
                return 'SMALLINT';
            case 'string':
                return 'STRING';
            case 'text':
                return 'TEXT';
            case 'time':
                return 'TIME_MUTABLE';
            case 'time_immutable':
                return 'TIME_IMMUTABLE';
            case 'uuid':
                return null;
        }

        dump('Unrecognized property type', $type); die;
    }

    private function handleUniqueConstraint(ClassType $class, int|string $key, array $field): void
    {
        $attributes = [
            'name' => $key,
            'columns' => $field['columns']
        ];

        unset($field['columns']);

        if (sizeof($field) > 0) {
            dump('Unsupported UniqueConstraint key: ', $field); die;
        }

        $class->addAttribute('Doctrine\ORM\Mapping\UniqueConstraint', $attributes);
    }

    private function handleIndex(ClassType $class, int|string $key, array $field): void
    {
        $attributes = [
            'columns' => $field['columns'],
            'name' => $key,
        ];

        if (array_key_exists('flags', $field)) {
            $flags = $field['flags'];

            if (is_string($flags)) {
                $flags = [$flags];
            }

            $attributes['flags'] = $flags;
            unset($field['flags']);
        }

        unset($field['columns']);

        if (sizeof($field) > 0) {
            dump('Unsupported Index key: ', $field); die;
        }

        $class->addAttribute('Doctrine\ORM\Mapping\Index', $attributes);
    }

    private function addUseStatement(PhpFile $phpFile, string $name, ?string $alias = null)
    {
        foreach ($phpFile->getNamespaces() as $namespace) {
            $namespace->addUse($name, $alias);
        }
    }
}