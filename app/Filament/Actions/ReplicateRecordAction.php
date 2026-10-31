<?php

namespace App\Filament\Actions;

use App\Support\Replication\RecordReplicator;
use App\Support\Replication\Replicable;
use Closure;
use Filament\Actions\ReplicateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Replicate" — copy a record into a new one, configured by the model — docs/replicate-records.md.
 *
 * Built on Filament's ReplicateAction for its wording and icon slots, but the copying itself is the
 * model's {@see Replicable} declaration run through {@see RecordReplicator}: Filament's default fills
 * a form with every attribute, which would put an invoice's paid amount and journal entry in front of
 * the person and copy them if they pressed Save.
 *
 * Shown only to someone who may create the model — the policy's `create`, which the module gate
 * already denies when the module is off — and only for a record that answers `canBeReplicated()`.
 * The modal asks only for what must be unique (a code, a key); with nothing unique it is a plain
 * confirmation.
 */
class ReplicateRecordAction extends ReplicateAction
{
    public static function getDefaultName(): ?string
    {
        return 'replicateRecord';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Replicate'));

        // All three: the parent sets its own table and grouped icons, which would win over icon().
        $this->icon(Heroicon::OutlinedDocumentDuplicate);
        $this->tableIcon(Heroicon::OutlinedDocumentDuplicate);
        $this->groupedIcon(Heroicon::OutlinedDocumentDuplicate);

        $this->color('gray');

        // With nothing unique to ask, the modal is only a confirmation; with fields, a confirmation
        // still frames them, so pressing the button never copies anything without a second click.
        $this->requiresConfirmation();

        $this->visible(fn (?Model $record): bool => static::allowsReplicating($record));

        $this->modalHeading(fn (Model $record): string => __('Replicate :record', ['record' => $record instanceof Replicable ? $record->replicationLabel() : '']));

        $this->modalDescription(fn (Model $record): string => static::uniqueFields($record) === []
            ? __('A new record is created as a copy of this one, in its first state. Nothing on this record changes.')
            : __('A new record is created as a copy of this one, in its first state. Give the copy its own value for each field below.'));

        $this->modalSubmitActionLabel(__('Replicate'));

        $this->schema(fn (Model $record): array => collect(static::uniqueFields($record))
            ->map(fn (Closure $suggest, string $attribute): TextInput|DatePicker => (RecordReplicator::isDateAttribute($record, $attribute)
                ? DatePicker::make($attribute)
                : TextInput::make($attribute)->maxLength(191))
                ->label(__($attribute === 'sku' ? 'SKU' : Str::headline($attribute)))
                ->required()
                ->rules([
                    fn (): Closure => function (string $field, mixed $value, Closure $fail) use ($record, $attribute): void {
                        if (RecordReplicator::valueIsTaken($record, $attribute, $value)) {
                            $fail(__('This value is already used by another record.'));
                        }
                    },
                ]))
            ->values()
            ->all());

        $this->fillForm(fn (Model $record): array => collect(static::uniqueFields($record))
            ->map(fn (Closure $suggest): ?string => $suggest($record))
            ->all());

        $this->action(function (array $data, Model $record): void {
            $this->replica = app(RecordReplicator::class)->replicate($record, $data);

            $this->successNotificationTitle(__('Replicated. You are now on the copy.'));

            $this->success();

            if ($url = static::urlFor($this->replica)) {
                $this->redirect($url);
            }
        });
    }

    public static function allowsReplicating(?Model $record): bool
    {
        return $record instanceof Replicable
            && $record->canBeReplicated()
            && Gate::allows('create', $record::class);
    }

    /**
     * @return array<string, Closure(Model): ?string>
     */
    protected static function uniqueFields(Model $record): array
    {
        return $record instanceof Replicable ? $record->replicationUniqueFields() : [];
    }

    /** The copy's view page when the resource has one, otherwise its edit page. */
    public static function urlFor(Model $copy): ?string
    {
        $resource = Filament::getModelResource($copy);

        if ($resource === null) {
            return null;
        }

        foreach (['view', 'edit'] as $page) {
            if ($resource::hasPage($page)) {
                return $resource::getUrl($page, ['record' => $copy]);
            }
        }

        return $resource::getUrl('index');
    }
}
