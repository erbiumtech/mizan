<?php

namespace App\Filament\Concerns;

/**
 * Hydrates and persists custom field values on a resource's Create/Edit pages.
 * The custom field form components live under the `custom_fields` state path and
 * are `dehydrated(false)`, so they're read from `$this->data` and saved to the
 * record's custom_field_values here (not as model columns).
 */
trait InteractsWithCustomFields
{
    /** Edit page: preload existing values into the form. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (method_exists($this->getRecord(), 'customFieldsData')) {
            $data['custom_fields'] = $this->getRecord()->customFieldsData();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->persistCustomFields();
    }

    protected function afterSave(): void
    {
        $this->persistCustomFields();
    }

    protected function persistCustomFields(): void
    {
        $record = $this->getRecord();

        if (method_exists($record, 'saveCustomFields')) {
            $record->saveCustomFields($this->data['custom_fields'] ?? []);
        }
    }
}
