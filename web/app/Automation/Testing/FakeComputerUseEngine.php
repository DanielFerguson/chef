<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\Data\ComputerUseStep;
use App\Models\AutomationRun;
use RuntimeException;

class FakeComputerUseEngine implements ComputerUseEngine
{
    /** @var ComputerUseStep[] */
    private array $steps = [];

    /** @var int[] */
    private array $runIds = [];

    public function queue(ComputerUseStep ...$steps): self
    {
        array_push($this->steps, ...$steps);

        return $this;
    }

    public function reset(): self
    {
        $this->steps = [];
        $this->runIds = [];

        return $this;
    }

    public function loadFixture(string $path): self
    {
        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($fixture['steps'] ?? [] as $step) {
            $this->steps[] = ComputerUseStep::fromArray($step);
        }

        return $this;
    }

    public function advance(AutomationRun $run): ComputerUseStep
    {
        $this->runIds[] = $run->id;

        return array_shift($this->steps)
            ?? throw new RuntimeException('The fake computer-use engine has no queued step.');
    }

    /** @return int[] */
    public function runIds(): array
    {
        return $this->runIds;
    }
}
