<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Support\Generator\Types;

class ArrayObjectType extends ObjectType
{
    public function __construct()
    {
        parent::__construct('array');
    }
}
