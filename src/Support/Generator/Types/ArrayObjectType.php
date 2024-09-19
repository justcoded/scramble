<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Support\Generator\Types;

class ArrayObjectType extends ArrayType
{
    public function toArray(): array
    {
        if (! $this->items || (! is_array($this->items) && $this->items->getAttribute('missing'))) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                ],
            ];
        }

        $items = is_array($this->items)
            ? array_map(static fn($item) => $item->toArray(), $this->items)
            : $this->items->toArray();

        $props = $required = [];
        foreach ($items as $item) {
            $props = [
                ...$props,
                ...$item['properties'],
            ];

            $required = [
                ...$required,
                ...array_keys($item['properties']),
            ];
        }

        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => $props,
                'required' => $required,
            ],
        ];
    }
}
