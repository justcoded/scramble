<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Support\Type;

class ArrayObjectType extends ArrayType
{
    public function __construct(Type $value)
    {
        parent::__construct($value);
    }

    public function nodes(): array
    {
        return ['value', 'key'];
    }
}
