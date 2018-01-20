<?php

namespace Phpactor\CodeTransform\Adapter\WorseReflection\Refactor;

use Phpactor\CodeTransform\Domain\SourceCode;
use Phpactor\CodeBuilder\Domain\Updater;
use Phpactor\WorseReflection\Reflector;
use Microsoft\PhpParser\Parser;
use Phpactor\CodeBuilder\Domain\BuilderFactory;
use Phpactor\CodeBuilder\Domain\Code;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Phpactor\WorseReflection\Core\Inference\Assignments;
use Phpactor\WorseReflection\Core\Inference\Variable;
use Phpactor\CodeTransform\Domain\Refactor\ExtractMethod;
use Phpactor\CodeBuilder\Domain\Builder\SourceCodeBuilder;
use Phpactor\CodeBuilder\Domain\Builder\MethodBuilder;
use Phpactor\CodeTransform\Domain\Exception\TransformException;
use Phpactor\CodeTransform\Domain\Utils\TextUtils;

class WorseExtractMethod implements ExtractMethod
{
    /**
     * @var Reflector
     */
    private $reflector;

    /**
     * @var Updater
     */
    private $updater;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var BuilderFactory
     */
    private $factory;

    public function __construct(Reflector $reflector, BuilderFactory $factory, Updater $updater)
    {
        $this->reflector = $reflector;
        $this->updater = $updater;
        $this->parser = new Parser();
        $this->factory = $factory;
    }

    public function extractMethod(SourceCode $source, int $offsetStart, int $offsetEnd, string $name): SourceCode
    {
        $selection = $source->extractSelection($offsetStart, $offsetEnd);
        $builder = $this->factory->fromSource($source);

        $methodBuilder = $this->createMethodBuilder($source, $offsetEnd, $builder, $name);
        $methodBuilder->body()->line($this->removeIndentation($selection));

        $locals = $this->scopeLocalVariables($source, $offsetStart, $offsetEnd);

        // TODO: Add lessThan method
        $parameterVariables = $this->parameterVariables($locals->lessThanOrEqualTo($offsetStart - 1), $selection, $offsetStart);
        $args = $this->addParametersAndGetArgs($parameterVariables, $methodBuilder, $builder);

        $returnVariables = $this->returnVariables($locals, (string) $source, $offsetEnd);
        $returnAssignment = $this->addReturnAndGetAssignment($returnVariables, $methodBuilder, $args);

        $prototype = $builder->build();
        $source = $source->replaceSelection(
            $this->replacement($name, $args, $returnAssignment),
            $offsetStart,
            $offsetEnd
        );

        return $source->withSource(
            (string) $this->updater->apply($prototype, Code::fromString((string) $source))
        );
    }

    private function parameterVariables(Assignments $locals, string $selection, int $offsetStart)
    {
        $variableNames = $this->variableNames($selection);

        $parameterVariables = [];
        foreach ($variableNames as $variable) {
            $variables = $locals->lessThanOrEqualTo($offsetStart)->byName($variable);
            if ($variables->count()) {
                $parameterVariables[$variable] = $variables->last();
            }
        }

        return $parameterVariables;
    }

    private function returnVariables(Assignments $locals, string $source, int $offsetEnd)
    {
        $variableNames = $this->variableNames(mb_substr($source, $offsetEnd));

        $returnVariables = [];
        foreach ($variableNames as $variable) {
            $variables = $locals->byName($variable)->lessThanOrEqualTo($offsetEnd);

            if ($variables->count()) {
                $returnVariables[$variable] = $variables->last();
            }
        }

        return $returnVariables;
    }

    private function removeIndentation(string $selection)
    {
        TextUtils::removeIndentation($selection);
    }

    private function createMethodBuilder(SourceCode $source, int $offsetEnd, SourceCodeBuilder $builder, string $name): MethodBuilder
    {
        $offset = $this->reflector->reflectOffset((string) $source, $offsetEnd);
        $thisVariable = $offset->frame()->locals()->byName('this');

        if (empty($thisVariable)) {
            throw new TransformException('Cannot extract method, not in class scope');
        }

        $className = $thisVariable->last()->symbolInformation()->type()->className();

        $reflectionClass = $this->reflector->reflectClass((string) $className);

        if ($reflectionClass->methods()->belongingTo($className)->has($name)) {
            throw new TransformException(sprintf('Class "%s" already has method "%s"', (string) $className, $name));
        }

        $methodBuilder = $builder->class(
            $className->short()
        )->method($name);
        $methodBuilder->visibility('private');

        return $methodBuilder;
    }

    private function addParametersAndGetArgs(array $freeVariables, $methodBuilder, SourceCodeBuilder $builder): array
    {
        $args = [];

        /** @var Variable $freeVariable */
        foreach ($freeVariables as $freeVariable) {

            if (in_array($freeVariable->name(), [ 'this', 'self' ])) {
                continue;
            }

            $parameterBuilder = $methodBuilder->parameter($freeVariable->name());

            $variableType = $freeVariable->symbolInformation()->type();

            if ($variableType->isDefined()) {
                $parameterBuilder->type($variableType->short());
                if ($variableType->isClass()) {
                    $builder->use((string) $variableType);
                }
            }

            $args[] = '$' . $freeVariable->name();
        }

        return $args;
    }

    private function scopeLocalVariables(SourceCode $source, int $offsetStart, int $offsetEnd): Assignments
    {
        return $this->reflector->reflectOffset(
            (string) $source,
            $offsetStart
        )->frame()->locals();
    }

    private function variableNames(string $selection)
    {
        $code = '<?php ' . $selection;
        $node = $this->parser->parseSourceFile($code);
        $variables = [];

        /** @var Token $token */
        foreach ($node->getDescendantTokens() as $token) {
            if ($token->kind == TokenKind::VariableName) {
                $name = substr($token->getText($code), 1);
                $variables[$name] = $name;
            }
        }

        return array_values($variables);
    }

    private function addReturnAndGetAssignment(array $returnVariables, MethodBuilder $methodBuilder, array $args)
    {
        $returnVariables = array_filter($returnVariables, function (Variable $variable) {
            return false === in_array($variable->name(), ['self', 'this']);
        });

        $returnVariables = array_filter($returnVariables, function (Variable $variable) use ($args) {
            return false === in_array('$' . $variable->name(), $args);
        });

        if (empty($returnVariables)) {
            return null;
        }

        if (count($returnVariables) === 1) {
            $variable = reset($returnVariables);
            $methodBuilder->body()->line('return $' . $variable->name() . ';');

            return '$' . $variable->name();
        }

        $names = implode(', ', array_map(function (Variable $variable) {
            return '$' . $variable->name();
        }, $returnVariables));

        $methodBuilder->body()->line('return [' . $names . '];');

        return 'list(' . $names . ')';
    }

    private function replacement(string $name, array $args, string $returnAssignment = null)
    {
        $callString = '$this->'  . $name . '(' . implode(', ', $args) . ');';

        if (empty($returnAssignment)) {
            return $callString;
        }

        return $returnAssignment . ' = ' . $callString;
    }
}