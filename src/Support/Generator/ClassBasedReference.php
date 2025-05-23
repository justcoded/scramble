<?php

namespace Dedoc\Scramble\Support\Generator;

use Dedoc\Scramble\Attributes\SchemaName;
use ReflectionClass;

class ClassBasedReference
{
    public static function create(string $referenceType, string $className, Components $components)
    {
        return app(Reference::class, [
            'referenceType' => $referenceType,
            'fullName' => $className,
            'components' => $components,
            'shortName' => static::getClassBasedName($className)
        ]);
    }

    public static function createInput(string $referenceType, string $className, Components $components)
    {
        return app(Reference::class, [
            'referenceType' => $referenceType,
            'fullName' => $className,
            'components' => $components,
            'shortName' => static::getClassBasedName($className, input: true)
        ]);
    }

    private static function getClassBasedName(string $className, bool $input = false): ?string
    {
        $reflectionClass = new ReflectionClass($className);

        $schemaNameAttribute = ($reflectionClass->getAttributes(SchemaName::class)[0] ?? null)?->newInstance();

        return $schemaNameAttribute
            ? ($input && $schemaNameAttribute->input ? $schemaNameAttribute->input : $schemaNameAttribute->name)
            : null;
    }
}
