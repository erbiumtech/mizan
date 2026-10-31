<?php

namespace App\Support\Sync;

use InvalidArgumentException;

/**
 * The workflows the inbox knows, by key. Modules register theirs in their service provider's boot,
 * the way Attendance registers its punch — and mount the routes under their own module gate, so a
 * company without the module has no endpoint for it rather than an empty one.
 */
class SyncWorkflows
{
    /** @var array<string, class-string<SyncWorkflow>> */
    private array $workflows = [];

    /**
     * The key is given rather than asked of an instance so that registering, which happens while
     * providers boot, builds nothing — a workflow's dependencies are the recorder's, and the recorder's
     * reach the database.
     *
     * @param  class-string<SyncWorkflow>  $class
     */
    public function register(string $key, string $class): void
    {
        if (isset($this->workflows[$key]) && $this->workflows[$key] !== $class) {
            throw new InvalidArgumentException("Two sync workflows claim the key [{$key}]: {$this->workflows[$key]} and {$class}.");
        }

        $this->workflows[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->workflows[$key]);
    }

    public function for(string $key): SyncWorkflow
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("No sync workflow is registered as [{$key}].");
        }

        return app($this->workflows[$key]);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->workflows);
    }
}
