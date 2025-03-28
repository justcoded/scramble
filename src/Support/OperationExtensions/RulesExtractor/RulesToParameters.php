<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class RulesToParameters
{
    private array $rules;

    /** @var array<string, PhpDocNode> */
    private array $nodeDocs;

    private TypeTransformer $openApiTransformer;

    public function __construct(array $rules, array $validationNodesResults, TypeTransformer $openApiTransformer)
    {
        $this->rules = $rules;
        $this->openApiTransformer = $openApiTransformer;
        $this->nodeDocs = $this->extractNodeDocs($validationNodesResults);
    }

    public function handle(): array
    {
        return collect($this->rules)
            ->pipe($this->handleConfirmed(...))
            ->map(fn ($rules, $name) => (new RulesToParameter($name, $rules, $this->nodeDocs[$name] ?? null, $this->openApiTransformer))->generate())
            ->filter()
            ->pipe($this->handleNested(...))
            ->values()
            ->all();
    }

    private function handleNested(Collection $parameters)
    {
        [$nested, $parameters] = $parameters
            ->sortBy(fn ($_, $key) => count(explode('.', $key)))
            ->partition(fn ($_, $key) => Str::contains($key, '.'));

        $nestedParentsKeys = $nested->keys()->map(fn ($key) => explode('.', $key)[0]);

        [$nestedParents, $parameters] = $parameters->partition(fn ($_, $key) => $nestedParentsKeys->contains($key));

        /** @var Collection $nested */
        $nested = $nested->merge($nestedParents);

        $nested = $nested
            ->groupBy(fn ($_, $key) => explode('.', $key)[0])
            ->map(function (Collection $params, $groupName) {
                $params = $params->keyBy('name');

                $baseParam = $params->get(
                    $groupName,
                    Parameter::make($groupName, $params->first()->in)
                        ->setSchema(Schema::fromType(
                            $params->keys()->contains(fn ($k) => Str::contains($k, "$groupName.*"))
                                ? new ArrayType
                                : new ObjectType
                        ))
                );

                $params->offsetUnset($groupName);

                foreach ($params as $param) {
                    $this->setDeepType(
                        $baseParam->schema->type,
                        $param->name,
                        $this->extractTypeFromParameter($param),
                    );
                }

                return $baseParam;
            });

        return $parameters
            ->merge($nested);
    }

    private function handleConfirmed(Collection $rules)
    {
        $confirmedParamNameRules = $rules
            ->map(fn ($rules, $name) => [$name, Arr::wrap(is_string($rules) ? explode('|', $rules) : $rules)])
            ->filter(fn ($nameRules) => in_array('confirmed', $nameRules[1]));

        if (! $confirmedParamNameRules) {
            return $rules;
        }

        foreach ($confirmedParamNameRules as $confirmedParamNameRule) {
            $rules->offsetSet(
                "$confirmedParamNameRule[0]_confirmation",
                array_filter($confirmedParamNameRule[1], fn ($rule) => $rule !== 'confirmed'),
            );
        }

        return $rules;
    }

    private function setDeepType(Type &$base, string $key, Type $typeToSet)
    {
        $containingType = $this->getOrCreateDeepTypeContainer(
            $base,
            collect(explode('.', $key))->splice(1)->values()->all(),
        );

        if (! $containingType) {
            return;
        }

        $isSettingArrayItems = ($settingKey = collect(explode('.', $key))->last()) === '*';

        if ($containingType === $base && $base instanceof UnknownType) {
            $containingType = ($isSettingArrayItems ? new ArrayType : new ObjectType)
                ->addProperties($base);

            $base = $containingType;
        }

        if (! ($containingType instanceof ArrayType || $containingType instanceof ObjectType)) {
            return;
        }

        if ($isSettingArrayItems && $containingType instanceof ArrayType) {
            $containingType->items = $typeToSet;

            return;
        }

        if (! $isSettingArrayItems && $containingType instanceof ObjectType) {
            $containingType
                ->addProperty($settingKey, $typeToSet)
                ->addRequired($typeToSet->getAttribute('required') ? [$settingKey] : []);
        }
    }

    private function getOrCreateDeepTypeContainer(Type &$base, array $path)
    {
        $key = $path[0];

        if (count($path) === 1) {
            if ($key !== '*' && $base instanceof ArrayType) {
                $base = new ObjectType;
            }

            return $base;
        }

        if ($key === '*') {
            if (! $base instanceof ArrayType) {
                $base = new ArrayType;
            }

            $next = $path[1];
            if ($next === '*') {
                if (! $base->items instanceof ArrayType) {
                    $base->items = new ArrayType;
                }
            } else {
                if (! $base->items instanceof ObjectType) {
                    $base->items = new ObjectType;
                }
            }

            return $this->getOrCreateDeepTypeContainer(
                $base->items,
                collect($path)->splice(1)->values()->all(),
            );
        } else {
            if (! $base instanceof ObjectType) {
                $base = new ObjectType;
            }

            $next = $path[1];

            if (! $base->hasProperty($key)) {
                $base = $base->addProperty(
                    $key,
                    $next === '*' ? new ArrayType : new ObjectType,
                );
            }
            if (($existingType = $base->getProperty($key)) instanceof UnknownType) {
                $base = $base->addProperty(
                    $key,
                    ($next === '*' ? new ArrayType : new ObjectType)->addProperties($existingType),
                );
            }

            if ($next === '*' && ! $existingType instanceof ArrayType) {
                $base->addProperty($key, (new ArrayType)->addProperties($existingType));
            }
            if ($next !== '*' && $existingType instanceof ArrayType) {
                $base->addProperty($key, (new ObjectType)->addProperties($existingType));
            }

            return $this->getOrCreateDeepTypeContainer(
                $base->properties[$key],
                collect($path)->splice(1)->values()->all(),
            );
        }
    }

    private function extractNodeDocs($validationNodesResults)
    {
        return collect($validationNodesResults)
            ->mapWithKeys(fn (Node\Expr\ArrayItem $item) => [
                $item->key->value => $item->getAttribute('parsedPhpDoc'),
            ])
            ->toArray();
    }

    private function extractTypeFromParameter($parameter)
    {
        $paramType = $parameter->schema->type;

        $paramType->setDescription($parameter->description);
        $paramType->example($parameter->example);

        return $paramType;
    }
}
