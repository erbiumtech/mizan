<?php

namespace App\Support\Replication;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Sensible defaults for {@see Replicable}: copy every attribute but the key and the timestamps, no
 * children, nothing unique, and save normally.
 *
 * @mixin Model
 */
trait ReplicatesRecords
{
    public function canBeReplicated(): bool
    {
        return true;
    }

    public function replicationExcept(): array
    {
        return [];
    }

    public function replicationResets(): array
    {
        return [];
    }

    public function replicationRelations(): array
    {
        return [];
    }

    public function replicationUniqueFields(): array
    {
        return [];
    }

    public function replicationUniqueScopes(): array
    {
        return [];
    }

    public function persistReplica(Model $original): Model
    {
        $this->save();

        return $this;
    }

    public function afterReplication(Model $original): void {}

    public function replicationLabel(): string
    {
        foreach (['invoice_number', 'quotation_number', 'key', 'code', 'number', 'name', 'title', 'subject'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (is_scalar($value) && filled($value)) {
                return (string) $value;
            }
        }

        return '#'.$this->getKey();
    }

    /**
     * A suggestion closure for a unique attribute: the original's value with "-COPY" appended,
     * then "-COPY2", "-COPY3" … until a value is free. Case follows the original, so a code that was
     * `ACC-100` suggests `ACC-100-COPY` and a slug that was `sales` suggests `sales-copy`.
     *
     * @return Closure(Model): ?string
     */
    protected static function suggestCopyOf(string $attribute, int $maxLength = 191): Closure
    {
        return static function (Model $original) use ($attribute, $maxLength): ?string {
            $value = (string) $original->getAttribute($attribute);

            if ($value === '') {
                return null;
            }

            $suffix = Str::upper($value) === $value ? '-COPY' : '-copy';

            for ($attempt = 1; $attempt < 100; $attempt++) {
                $tail = $suffix.($attempt > 1 ? $attempt : '');
                $candidate = Str::substr($value, 0, $maxLength - Str::length($tail)).$tail;

                if (! RecordReplicator::valueIsTaken($original, $attribute, $candidate)) {
                    return $candidate;
                }
            }

            return null;
        };
    }
}
