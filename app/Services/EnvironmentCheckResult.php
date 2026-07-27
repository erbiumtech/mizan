<?php

namespace App\Services;

/**
 * The outcome of one environment health check.
 */
class EnvironmentCheckResult
{
    public function __construct(
        public bool $isUp,
        public ?int $statusCode = null,
        public ?int $latencyMs = null,
        public ?string $error = null,
    ) {}

    public function summary(): string
    {
        $parts = [$this->isUp ? 'up' : 'down'];

        if ($this->statusCode) {
            $parts[] = 'HTTP '.$this->statusCode;
        }

        if ($this->latencyMs !== null) {
            $parts[] = $this->latencyMs.'ms';
        }

        if ($this->error) {
            $parts[] = $this->error;
        }

        return implode(' · ', $parts);
    }
}
