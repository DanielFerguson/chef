<?php

namespace Tests\PHPStan;

use Closure;
use LogicException;
use Pest\Expectation;

/**
 * Declares Pest Evals' runtime expectation extensions for PHPStan.
 *
 * @internal
 */
final class PestEvalsExpectationMethods
{
    /**
     * @param  array<int, mixed>  $attachments
     * @return Expectation<string>
     */
    public function prompt(string $prompt, array $attachments = []): Expectation
    {
        throw new LogicException('Static analysis declaration only.');
    }

    /** @return Expectation<string> */
    public function toBeSafe(float $threshold = 0.7): Expectation
    {
        throw new LogicException('Static analysis declaration only.');
    }

    /** @return Expectation<string> */
    public function toSatisfy(string $criteria, float $threshold = 0.7): Expectation
    {
        throw new LogicException('Static analysis declaration only.');
    }

    /**
     * @param  array<string, array<string, mixed>|Closure>  $expected
     * @return Expectation<string>
     */
    public function toHaveToolCalls(array $expected, float $threshold = 0.7): Expectation
    {
        throw new LogicException('Static analysis declaration only.');
    }

    /**
     * @param  array<int, string>  $steps
     * @return Expectation<string>
     */
    public function toFollowTrajectory(array $steps, float $threshold = 0.7, bool $strictOrder = true): Expectation
    {
        throw new LogicException('Static analysis declaration only.');
    }
}
