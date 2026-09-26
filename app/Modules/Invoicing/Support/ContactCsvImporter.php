<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Core\Models\CustomField;
use App\Modules\Invoicing\Models\Contact;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Collection;

/**
 * Clients and suppliers from a spreadsheet.
 *
 * Lived in `Core\Services\CsvImportService` and moved here because `Contact` is Invoicing's — see
 * docs/module-packaging-plan.md §9. Core still reads the file; this says what a row of it means.
 */
class ContactCsvImporter implements CsvImporter
{
    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return 'Clients and suppliers';
    }

    public function columns(): array
    {
        // Plus this tenant's custom fields, as `cf_<code>` columns (multi_select cells use `a|b`).
        return array_merge(
            ['name', 'kind', 'email', 'phone', 'ntn', 'cnic', 'address'],
            CustomField::csvColumns(Contact::class),
        );
    }

    public function example(): array
    {
        return array_merge(
            ['Erbium AG', 'customer', 'billing@erbium.example', '+41 44 000 0000', '', '', 'Zurich'],
            array_fill(0, count(CustomField::csvColumns(Contact::class)), ''),
        );
    }

    public function problemWith(array $row): ?string
    {
        return match (true) {
            $row['name'] === '' => 'no name',
            $row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL) => "\"{$row['email']}\" is not an email address",
            default => null,
        };
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            // By name, so running the same file twice corrects rather than duplicates.
            $contact = Contact::updateOrCreate(
                ['name' => $row['name']],
                [
                    // Somebody's spreadsheet saying "Client" should not cost them the row.
                    'kind' => in_array($row['kind'], [Contact::KIND_CUSTOMER, Contact::KIND_SUPPLIER, Contact::KIND_BOTH], true)
                        ? $row['kind']
                        : Contact::KIND_CUSTOMER,
                    'email' => $row['email'] ?: null,
                    'phone' => $row['phone'] ?: null,
                    'ntn' => $row['ntn'] ?: null,
                    'cnic' => $row['cnic'] ?: null,
                    'address_line_1' => $row['address'] ?: null,
                    'is_active' => true,
                ],
            );

            $contact->saveCustomFields(CustomField::csvValues(Contact::class, $row));

            $imported++;
        }

        return $imported;
    }

    public function dateField(): ?array
    {
        return null;
    }
}
