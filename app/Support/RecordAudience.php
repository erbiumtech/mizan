<?php

namespace App\Support;

use App\Support\Contracts\NotifiesOnChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Who should hear that a record changed.
 *
 * **Columns rather than a registry, and the columns are the whole design.** Forty-two
 * tenant tables already name a person on the row — `user_id` on an MPR, a comment, an
 * employee; `created_by` on a journal entry, a quotation, a cost entry; `requested_by` on
 * a change request, a permit, a requisition. Reading those needs no per-model wiring, no
 * module to remember anything, and it is right by construction: the column exists because
 * somebody decided that person owns the row.
 *
 * Only columns holding a **landlord user id** are read. An `assignee_employee_id` points
 * at a module's Employee, which shared code may not follow — that is what
 * `App\Support\Contracts\NotifiesOnChange` is for, and a model implementing it overrides
 * everything here.
 *
 * **Whoever made the change never hears about it.** Told that they did what they just
 * did, a person learns to ignore the bell, and then it is worth nothing when it carries
 * something they did not do.
 */
final class RecordAudience
{
    /**
     * Row columns holding a landlord user id.
     *
     * `employee_id` and `assignee_employee_id` are deliberately absent: they hold an
     * Employee id, and an Employee id used as a user id notifies a stranger.
     *
     * @var array<int, string>
     */
    public const USER_COLUMNS = ['user_id', 'created_by', 'requested_by'];

    /**
     * @param  array<string, mixed>  $fallback  attributes to read when the row is gone — a
     *                                          delete's `properties.old`, which carries the
     *                                          owner columns the live row no longer has.
     * @return array<int, int> user ids, distinct, without the causer
     */
    public static function for(?Model $subject, ?int $causerId, array $fallback = []): array
    {
        $ids = $subject instanceof NotifiesOnChange
            ? self::idsFrom($subject->changeAudience())
            : self::fromColumns($subject, $fallback);

        return array_values(array_unique(array_filter(
            $ids,
            fn (int $id): bool => $id !== $causerId,
        )));
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<int, int>
     */
    private static function fromColumns(?Model $subject, array $fallback): array
    {
        $ids = [];

        foreach (self::USER_COLUMNS as $column) {
            $value = $subject?->getAttribute($column) ?? ($fallback[$column] ?? null);

            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * @param  iterable<int|Model|null>  $audience
     * @return array<int, int>
     */
    private static function idsFrom(iterable $audience): array
    {
        $ids = [];

        foreach ($audience as $member) {
            $id = $member instanceof Model ? $member->getKey() : $member;

            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
