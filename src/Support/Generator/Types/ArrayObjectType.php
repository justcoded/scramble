<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Support\Generator\Types;

class ArrayObjectType extends ArrayType
{
    public function toArray(): array
    {
        $result = new ObjectType();

        if (! $this->items || (! is_array($this->items) && $this->items->getAttribute('missing'))) {
            return [
                'items' => $result,
            ];
        }

        $items = is_array($this->items)
            ? array_map(static fn($item) => $item->toArray(), $this->items)
            : $this->items->toArray();


        foreach ($items as $item) {
            $result->addProperty($item->key, $item->value);
        }

        return [
            'items' => $result,
        ];
    }
}
