<?php

namespace App\Support\Replication;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A model a person may copy with the Replicate action — docs/replicate-records.md.
 *
 * Implementing the interface is the decision; the {@see ReplicatesRecords} trait supplies defaults so
 * that most master data needs nothing more than `implements Replicable` and `use ReplicatesRecords`.
 * A model that is not here is not copyable, deliberately: posted ledger rows, payslips, attendance
 * days and anything issued to a third party have no draft state to copy into.
 */
interface Replicable
{
    /**
     * Whether this particular record may be copied. Most models answer true for every row; a model
     * with more than one kind of document (an invoice and its credit notes) refuses the kinds that
     * must not be duplicated.
     */
    public function canBeReplicated(): bool;

    /**
     * Attributes never copied, on top of the key, the timestamps and the soft-delete column.
     *
     * @return array<int, string>
     */
    public function replicationExcept(): array;

    /**
     * Attributes set to a fresh value on the copy: a status back to draft, a date back to today, a
     * paid amount back to nothing. A closure receives the original and the copy.
     *
     * @return array<string, mixed>
     */
    public function replicationResets(): array;

    /**
     * Child relations copied with the record — only rows the record owns outright, such as an
     * invoice's lines. A name copies every attribute but the key; `name => [attributes]` also
     * leaves those attributes behind. A BelongsToMany is linked to the same rows, not duplicated.
     *
     * @return array<int|string, string|array<int, string>>
     */
    public function replicationRelations(): array;

    /**
     * Attributes that must stay unique, each with a closure suggesting a free value from the
     * original. The Replicate action asks for these, prefilled with the suggestion.
     *
     * @return array<string, Closure(Model): ?string>
     */
    public function replicationUniqueFields(): array;

    /**
     * For a unique field whose index is composite, the columns it is unique within — an option's
     * value within its list. The copy keeps the original's values for those columns.
     *
     * @return array<string, array<int, string>>
     */
    public function replicationUniqueScopes(): array;

    /**
     * Save the copy. Most models simply save; one that numbers its rows under a lock (a work item)
     * hands the copy to the service that does that, and returns what it saved.
     */
    public function persistReplica(Model $original): Model;

    /** Anything the model must do once the copy and its children exist. */
    public function afterReplication(Model $original): void;

    /** How the original is named in the copy's activity log: "Replicated from INV-2026-000041". */
    public function replicationLabel(): string;
}
