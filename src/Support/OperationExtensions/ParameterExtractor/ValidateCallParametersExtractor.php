<?php

namespace Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor;

use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\SchemaClassDocReflector;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class ValidateCallParametersExtractor implements ParameterExtractor
{
    use GeneratesParametersFromRules;

    public function __construct(
        private PrettyPrinter $printer,
        private TypeTransformer $openApiTransformer,
        protected ?Route $route = null,
    ) {}

    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        if (! $astNode = $routeInfo->methodNode()) {
            return $parameterExtractionResults;
        }

        [$callToValidate, $validationRules] = $this->getCallToValidateAndValidationRulesNodes($astNode);

        if (! $validationRules) {
            return $parameterExtractionResults;
        }

        $validationRulesNode = $validationRules instanceof Node\Arg ? $validationRules->value : $validationRules;

        $phpDocReflector = new SchemaClassDocReflector($callToValidate->getAttribute('parsedPhpDoc', new PhpDocNode([])));

        $parameterExtractionResults[] = new ParametersExtractionResult(
            parameters: $this->makeParameters(
                node: (new NodeFinder)->find(
                    $validationRulesNode instanceof Node\Expr\Array_ ? $validationRulesNode->items : [],
                    fn (Node $node) => $node instanceof Node\Expr\ArrayItem
                        && $node->key instanceof Node\Scalar\String_
                        && $node->getAttribute('parsedPhpDoc'),
                ),
                rules: $this->rules($astNode, $validationRulesNode),
                typeTransformer: $this->openApiTransformer,
                in: in_array(mb_strtolower($routeInfo->route->methods()[0]), RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY)
                    ? 'query'
                    : 'body',
            ),
            schemaName: $phpDocReflector->getSchemaName(),
            description: $phpDocReflector->getDescription(),
        );

        return $parameterExtractionResults;
    }

    private function getCallToValidateAndValidationRulesNodes(FunctionLike $methodNode)
    {
        // $request->validate, when $request is a Request instance
        /** @var Node\Expr\MethodCall $callToValidate */
        $callToValidate = (new NodeFinder())->findFirst(
            $methodNode,
            fn(Node $node) => $node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && is_a($this->getPossibleParamType($methodNode, $node->var), Request::class, true)
                && $node->name instanceof Node\Identifier
                && $node->name->name === 'validate',
        );
        $validationRules = $callToValidate->args[0] ?? null;

        if (! $validationRules) {
            // $this->validate($request, $rules), rules are second param. First should be $request, but no way to check type. So relying on convention.
            $callToValidate = (new NodeFinder())->findFirst(
                $methodNode,
                fn(Node $node) => $node instanceof Node\Expr\MethodCall
                    && count($node->args) >= 2
                    && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier && $node->name->name === 'validate'
                    && $node->args[0]->value instanceof Node\Expr\Variable
                    && is_a($this->getPossibleParamType($methodNode, $node->args[0]->value), Request::class, true)
                    && $node->name->name === 'validate',
            );
            $validationRules = $callToValidate->args[1] ?? null;
        }

        if (! $validationRules) {
            // Validator::make($request->...(), $rules), rules are second param. First should be $request, but no way to check type. So relying on convention.
            $callToValidate = (new NodeFinder())->findFirst(
                $methodNode,
                fn(Node $node) => $node instanceof Node\Expr\StaticCall
                    && count($node->args) >= 2
                    && $node->class instanceof Node\Name && is_a($node->class->toString(),
                        \Illuminate\Support\Facades\Validator::class,
                        true)
                    && $node->name instanceof Node\Identifier && $node->name->name === 'make'
                    && $node->args[0]->value instanceof Node\Expr\MethodCall && is_a($this->getPossibleParamType($methodNode,
                        $node->args[0]->value->var),
                        Request::class,
                        true),
            );
            $validationRules = $callToValidate->args[1] ?? null;
        }

        return [$callToValidate, $validationRules];
    }

    private function getPossibleParamType(FunctionLike $methodNode, Node\Expr\Variable $node): ?string
    {
        $paramsMap = collect($methodNode->getParams())
            ->mapWithKeys(function (Node\Param $param) {
                if (! isset($param->type->name)) {
                    return [];
                }

                try {
                    return [
                        $param->var->name => $param->type->name,
                    ];
                } catch (\Throwable $exception) {
                    throw $exception;
                }
            })
            ->toArray();

        return $paramsMap[$node->name] ?? null;

    }

    public function rules(FunctionLike $methodNode, $validationRules): array
    {
        if (! $validationRules) {
            return [];
        }

        $validationRulesCode = $this->printer->prettyPrint([$validationRules]);

        $injectableParams = collect($methodNode->getParams())
            ->filter(fn(Node\Param $param) => isset($param->type->name))
            ->filter(fn(Node\Param $param) => ! class_exists($className = (string) $param->type) || ! is_a($className,
                    Request::class,
                    true))
            ->filter(fn(Node\Param $param) => isset($param->var->name) && is_string($param->var->name))
            ->mapWithKeys(function (Node\Param $param) {
                try {
                    $type = (string) $param->type;
                    $primitives = [
                        'int' => 1,
                        'bool' => true,
                        'string' => '',
                        'float' => 1,
                    ];
                    $value = $primitives[$type] ?? app($type);

                    return [
                        $param->var->name => $value,
                    ];
                } catch (\Throwable $e) {
                    return [];
                }
            })
            ->all();

        extract($injectableParams);

        if ($this->route) {
            $rules = (fn() => eval("\$request = request(); return $validationRulesCode;"))
                ->call($this->route->getController());
        } else {
            $rules = eval("\$request = request(); return $validationRulesCode;");
        }

        return $rules ?? [];
    }
}
