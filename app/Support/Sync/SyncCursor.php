<?php

namespace App\Support\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The cursor of an incremental read: `updated_at` and the id, together.
 *
 * `updated_at` alone loses rows — two saved in the same second as the one the page ended on would be
 * skipped by a strict `>` and repeated by `>=`. The id breaks the tie, so a page ends at a point and
 * the next begins exactly after it. Opaque to the client: base64 of the two, which a client has no
 * reason to read and no licence to edit; one it has edited into nonsense reads as "from the start".
 */
final class SyncCursor
{
    public function __construct(public readonly Carbon $updatedAt, public readonly int $id) {}

    public static function fromModel(Model $model): self
    {
        return new self(Carbon::parse($model->getAttribute('updated_at')), (int) $model->getKey());
    }

    public static function decode(?string $cursor): ?self
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $decoded, $m)) {
            return null;
        }

        return new self(Carbon::parse($m[1]), (int) $m[2]);
    }

    public function encode(): string
    {
        return base64_encode($this->updatedAt->format('Y-m-d H:i:s').'|'.$this->id);
    }

    /** Everything strictly after the cursor, in the order the cursor advances. */
    public static function apply(Builder $query, ?self $after): Builder
    {
        $table = $query->getModel()->getTable();

        if ($after !== null) {
            $at = $after->updatedAt->format('Y-m-d H:i:s');

            $query->where(function (Builder $q) use ($table, $at, $after): void {
                $q->where("{$table}.updated_at", '>', $at)
                    ->orWhere(fn (Builder $tie) => $tie->where("{$table}.updated_at", '=', $at)->where("{$table}.id", '>', $after->id));
            });
        }

        return $query->orderBy("{$table}.updated_at")->orderBy("{$table}.id");
    }
}
