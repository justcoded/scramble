<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Support\Generator\Types;

class ArrayObjectType extends ArrayType
{
    public function toArray(): array
    {
        if (! $this->items || (! is_array($this->items) && $this->items->getAttribute('missing'))) {
            return array_filter([
                'type' => $this->type,
                'title' => $this->title,
                'items' => [
                    'type' => 'object',
                ],
            ]);
        }

        $items = is_array($this->items)
            ? array_map(static fn($item) => $item->toArray(), $this->items)
            : $this->items->toArray();

        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'items' => array_filter($items),
        ]);
    }
}
