<?php

namespace Tests\Support;

use Closure;
use LogicException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\MockInterface;
use Throwable;

final class MockExpectation
{
    private function __construct(
        private readonly ExpectationInterface $expectation,
    ) {}

    public static function for(MockInterface $mock, string $method): self
    {
        $expectation = $mock->expects($method);

        if (! $expectation instanceof ExpectationInterface) {
            throw new LogicException("Mockery did not create an expectation for [{$method}].");
        }

        return new self($expectation);
    }

    /** @param array<mixed>|Closure $arguments */
    public function withArgs(array|Closure $arguments): self
    {
        if ($this->expectation instanceof Expectation) {
            $this->expectation->withArgs($arguments);
        } elseif ($this->expectation instanceof CompositeExpectation) {
            $this->expectation->__call('withArgs', [$arguments]);
        }

        return $this;
    }

    public function with(mixed ...$arguments): self
    {
        if ($this->expectation instanceof Expectation) {
            $this->expectation->with(...$arguments);
        } elseif ($this->expectation instanceof CompositeExpectation) {
            $this->expectation->__call('with', $arguments);
        }

        return $this;
    }

    public function andReturn(mixed ...$values): self
    {
        $this->expectation->andReturn(...$values);

        return $this;
    }

    public function andReturnTrue(): self
    {
        if ($this->expectation instanceof Expectation) {
            $this->expectation->andReturnTrue();
        } elseif ($this->expectation instanceof CompositeExpectation) {
            $this->expectation->__call('andReturnTrue', []);
        }

        return $this;
    }

    public function andReturnUsing(Closure $callback): self
    {
        if ($this->expectation instanceof Expectation) {
            $this->expectation->andReturnUsing($callback);
        } elseif ($this->expectation instanceof CompositeExpectation) {
            $this->expectation->__call('andReturnUsing', [$callback]);
        }

        return $this;
    }

    public function once(): self
    {
        if ($this->expectation instanceof Expectation) {
            $this->expectation->once();
        } elseif ($this->expectation instanceof CompositeExpectation) {
            $this->expectation->__call('once', []);
        }

        return $this;
    }

    public function andThrow(Throwable $exception): self
    {
        if ($this->expectation instanceof Expectation) {
            $this->expectation->andThrow($exception);
        } elseif ($this->expectation instanceof CompositeExpectation) {
            $this->expectation->__call('andThrow', [$exception]);
        }

        return $this;
    }
}
