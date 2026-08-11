<?php

namespace Tests\PHPStan;

use LogicException;
use Pest\Expectation;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Teaches PHPStan about the expectation methods registered by Pest Evals.
 *
 * @internal
 */
final class PestEvalsMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    private const METHODS = [
        'prompt',
        'toBeSafe',
        'toSatisfy',
        'toHaveToolCalls',
        'toFollowTrajectory',
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->is(Expectation::class)
            && in_array($methodName, self::METHODS, true);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        if (! in_array($methodName, self::METHODS, true)) {
            throw new LogicException("Unknown Pest Evals expectation method [{$methodName}].");
        }

        return $this->reflectionProvider
            ->getClass(PestEvalsExpectationMethods::class)
            ->getNativeMethod($methodName);
    }
}
