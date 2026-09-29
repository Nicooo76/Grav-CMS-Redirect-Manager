<?php

declare(strict_types=1);

namespace Grav\Common\Scheduler {
    if (!class_exists(Job::class, false)) {
        /** Records the configuration calls SchedulerJobs::register() makes; chainable like Grav's Job. */
        class Job
        {
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $calls = [];

            public function __construct(public mixed $command, public mixed $args = [], public ?string $id = null)
            {
            }

            public function at(string $expression): static
            {
                $this->calls[] = ['at', [$expression]];

                return $this;
            }

            public function output(string $filename, bool $append = false): static
            {
                $this->calls[] = ['output', [$filename, $append]];

                return $this;
            }

            public function backlink(?string $link = null): static
            {
                $this->calls[] = ['backlink', [$link]];

                return $this;
            }

            public function onlyOne(?string $tempDir = null): static
            {
                $this->calls[] = ['onlyOne', [$tempDir]];

                return $this;
            }

            public function arg(string $name): mixed
            {
                foreach ($this->calls as [$call, $args]) {
                    if ($call === $name) {
                        return $args[0];
                    }
                }

                return null;
            }
        }
    }

    if (!class_exists(Scheduler::class, false)) {
        class Scheduler
        {
            /** @var array<string, Job> */
            public array $jobs = [];

            public function addFunction(callable $fn, mixed $args = [], ?string $id = null): Job
            {
                $job = new Job($fn, $args, $id);
                $this->jobs[$id ?? (string) count($this->jobs)] = $job;

                return $job;
            }
        }
    }
}
