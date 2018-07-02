<?php

namespace Phpactor\CodeTransform\Adapter\WorseReflection\Macro;

use Phpactor\CodeBuilder\Domain\Builder\SourceCodeBuilder;
use Phpactor\CodeBuilder\Domain\Code;
use Phpactor\CodeBuilder\Domain\Prototype\Visibility;
use Phpactor\CodeBuilder\Domain\Updater;
use Phpactor\CodeTransform\Domain\Macro\Macro;
use Phpactor\CodeTransform\Domain\Refactor\GenerateMethod;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\Core\Reflection\ReflectionArgument;
use Phpactor\CodeTransform\Domain\SourceCode;
use Phpactor\WorseReflection\Core\SourceCode as WorseSourceCode;
use Phpactor\WorseReflection\Core\Inference\Variable;
use Phpactor\WorseReflection\Core\Reflection\ReflectionClassLike;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMethodCall;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\CodeTransform\Domain\Exception\TransformException;
use Phpactor\CodeBuilder\Domain\BuilderFactory;

class WorseGenerateMethod implements Macro, GenerateMethod
{
    /**
     * @var Reflector
     */
    private $reflector;

    /**
     * @var Updater
     */
    private $updater;

    /** @var int
     */
    private $methodSuffixIndex = 0;

    /**
     * @var BuilderFactory
     */
    private $factory;

    public function __construct(Reflector $reflector, BuilderFactory $factory, Updater $updater)
    {
        $this->reflector = $reflector;
        $this->updater = $updater;
        $this->factory = $factory;
    }

    public function name()
    {
        return 'generate_method';
    }

    public function __invoke(SourceCode $sourceCode, int $offset, $methodName = null): SourceCode
    {
        $contextType = $this->contextType($sourceCode, $offset);
        $worseSourceCode = WorseSourceCode::fromPathAndString((string) $sourceCode->path(), (string) $sourceCode);
        $methodCall = $this->reflector->reflectMethodCall($worseSourceCode, $offset);
        $this->validate($methodCall);
        $visibility = $this->determineVisibility($contextType, $methodCall->class());

        $prototype = $this->addMethodCallToBuilder($methodCall, $visibility, $methodName);
        $sourceCode = $this->resolveSourceCode($sourceCode, $methodCall, $visibility);

        return SourceCode::fromStringAndPath(
            (string) $this->updater->apply($prototype, Code::fromString((string) $sourceCode)),
            $sourceCode->path()
        );
    }

    private function resolveSourceCode(SourceCode $sourceCode, ReflectionMethodCall $methodCall, $visibility)
    {
        $containerSourceCode = SourceCode::fromStringAndPath(
            (string) $methodCall->class()->sourceCode(),
            $methodCall->class()->sourceCode()->path()
        );

        if ($sourceCode->path() != $containerSourceCode->path()) {
            return $containerSourceCode;
        }

        return $sourceCode;
    }

    /**
     * @return ReflectionClassLike
     */
    private function contextType(SourceCode $sourceCode, int $offset): Type
    {
        $worseSourceCode = WorseSourceCode::fromPathAndString((string) $sourceCode->path(), (string) $sourceCode);
        $reflectionOffset = $this->reflector->reflectOffset($worseSourceCode, $offset);

        /**
         * @var Variable $variable
         */
        foreach ($reflectionOffset->frame()->locals()->byName('$this') as $variable) {
            return $variable->symbolContext()->type();
        }
    }

    private function addMethodCallToBuilder(
        ReflectionMethodCall $methodCall,
        Visibility $visibility,
        $methodName
    ) {

        $methodName = $methodName ?: $methodCall->name();

        $reflectionClass = $methodCall->class();
        $builder = $this->factory->fromSource((string) $reflectionClass->sourceCode());

        if ($reflectionClass->isClass()) {
            $classBuilder = $builder->class($reflectionClass->name()->short());
        } else {
            $classBuilder = $builder->interface($reflectionClass->name()->short());
        }

        $methodBuilder = $classBuilder->method($methodName);
        $methodBuilder->visibility((string) $visibility);

        /** @var ReflectionArgument $argument */
        foreach ($methodCall->arguments() as $argument) {
            $type = $argument->type();

            $argumentBuilder = $methodBuilder->parameter($argument->guessName());

            if ($type->isDefined()) {
                if ($type->isPrimitive()) {
                    $argumentBuilder->type((string) $type);
                }

                if ($type->isClass()) {
                    $argumentBuilder->type($type->className()->short());
                }
            }
        }

        return $builder->build();
    }

    private function sourceFromSymbolContext(SymbolContext $info): SourceCode
    {
        $containingClass = $this->reflector->reflectClassLike($info->containerType()->className());
        $worseSourceCode = $containingClass->sourceCode();

        return SourceCode::fromStringAndPath(
            $worseSourceCode->__toString(),
            $worseSourceCode->path()
        );
    }

    private function determineVisibility(Type $contextType, ReflectionClassLike $targetClass): Visibility
    {
        if ($contextType->isClass() && $contextType->className() == $targetClass->name()) {
            return Visibility::private();
        }

        return Visibility::public();
    }

    private function validate(ReflectionMethodCall $methodCall)
    {
        if (false === $methodCall->class()->isClass() && false === $methodCall->class()->isInterface()) {
            throw new TransformException(sprintf(
                'Can only generate methods on classes or intefaces (trying on %s)',
                get_class($methodCall->class()->name())
            ));
        }
    }
}
