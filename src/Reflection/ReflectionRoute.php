<?php

namespace Dedoc\Scramble\Reflection;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use WeakMap;

/**
 * @internal
 */
class ReflectionRoute
{
    private static WeakMap $cache;

    private function __construct(private Route $route) {}

    public static function createFromRoute(Route $route): static
    {
        static::$cache ??= new WeakMap();

        return static::$cache[$route] ??= new static($route);
    }

    /**
     * The goal here is to get the mapping of route names specified in route path to the parameters
     * used in a route definition. The mapping then is used to get more information about the parameters for
     * the documentation. For example, the description from PHPDoc will be used for a route path parameter
     * description.
     *
     * So given the route path `/emails/{email_id}/recipients/{recipient_id}` and the route's method:
     * `public function show(Request $request, string $emailId, string $recipientId)`, we get the mapping:
     * `['email_id' => 'emailId', 'recipient_id' => 'recipientId']`.
     *
     * The trick is to avoid mapping parameters like `Request $request`, but to correctly map the model bindings
     * (and other potential kind of bindings).
     *
     * During this method implementation, Laravel implicit binding checks against snake cased parameters.
     *
     * @see ImplicitRouteBinding::getParameterName
     */
    public function getSignatureParametersMap(): array
    {
        $paramNames = $this->route->parameterNames();

        $paramBoundTypes = $this->getBoundParametersTypes();

        $available = array_values($this->route->signatureParameters());

        $map = [];

        foreach ($paramNames as $name) {
            $boundType = $paramBoundTypes[$name] ?? null;
            $target = null;

            $candidateNames = [$name, Str::camel($name)];
            foreach ($available as $i => $rp) {
                if (in_array($rp->name, $candidateNames, true)) {
                    $target = $rp;
                    unset($available[$i]);
                    $available = array_values($available);
                    break;
                }
            }

            if (! $target && $boundType) {
                foreach ($available as $i => $rp) {
                    $t = $rp->getType();
                    if ($t instanceof ReflectionNamedType && ! $t->isBuiltin()) {
                        $className = Reflector::getParameterClassName($rp);
                        if (is_string($boundType) && is_a($boundType, $className, true)) {
                            $target = $rp;
                            unset($available[$i]);
                            $available = array_values($available);
                            break;
                        }
                    }
                }
            }

            $map[$name] = $target ? $target->name : $name;
        }

        return $map;
    }

    /**
     * Get bound parameters types – these are the name of classes that can be bound to the parameters.
     * This includes implicitly bound types (UrlRoutable, backedEnum) and explicitly bound parameters.
     *
     * @return array<string, string|null>
     */
    public function getBoundParametersTypes(): array
    {
        $paramNames = $this->route->parameterNames();

        $implicitlyBoundReflectionParams = collect()
            ->union($this->route->signatureParameters(UrlRoutable::class))
            ->union($this->route->signatureParameters(['backedEnum' => true]))
            ->keyBy('name');

        return collect($paramNames)
            ->mapWithKeys(function ($name) use ($implicitlyBoundReflectionParams) {
                if ($explicitlyBoundParamType = $this->getExplicitlyBoundParamType($name)) {
                    return [$name => $explicitlyBoundParamType];
                }

                /** @var ReflectionParameter $implicitlyBoundParam */
                $implicitlyBoundParam = $implicitlyBoundReflectionParams->first(
                    fn(ReflectionParameter $p) => $p->name === $name || Str::snake($p->name) === $name,
                );

                if ($implicitlyBoundParam) {
                    return [$name => Reflector::getParameterClassName($implicitlyBoundParam)];
                }

                return [
                    $name => null,
                ];
            })
            ->all();
    }

    private function getExplicitlyBoundParamType(string $name): ?string
    {
        if (! $binder = app(Router::class)->getBindingCallback($name)) {
            return null;
        }

        try {
            $reflection = new ReflectionFunction($binder);
        } catch (ReflectionException) {
            return null;
        }

        if ($returnType = $reflection->getReturnType()) {
            return $returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()
                ? $returnType->getName()
                : null;
        }

        // in case this is a model binder
        if (
            ($modelClass = $reflection->getClosureUsedVariables()['class'] ?? null)
            && is_string($modelClass)
        ) {
            return $modelClass;
        }

        return null;
    }
}
