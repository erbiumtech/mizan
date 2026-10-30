<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeIdCard;
use App\Support\BrandKit;
use App\Support\CompanyLetterhead;
use App\Support\DocumentVerification;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Staff ID cards — docs/saas-launch-plan.md §14.10.
 *
 * **Issuing records the card; printing renders it.** The two are separate because a
 * card is a thing that exists in the world: it is issued once, it can be lost, and
 * the record of which serial is live has to survive somebody reprinting the artwork.
 *
 * A card carries the QR that every other document here carries, so a guard with a
 * phone gets the same answer a bank gets off a salary certificate: it recomputes from
 * the live record and says whether the card still matches it.
 */
class IdCards
{
    /** How long a card is good for, where a company has not said otherwise. */
    public const DEFAULT_YEARS = 3;

    /**
     * Issue a card, cancelling whatever that person was holding.
     *
     * One live card each. Somebody who has lost theirs gets a new serial and the old
     * one stops verifying, which is the entire point of the serial being its own
     * number rather than the employee code.
     */
    public function issue(Employee $employee, ?string $expiresOn = null, ?string $replacing = null): EmployeeIdCard
    {
        EmployeeIdCard::query()
            ->where('employee_id', $employee->getKey())
            ->live()
            ->update(['revoked_at' => now(), 'revoked_reason' => $replacing ?: 'Replaced by a new card']);

        return EmployeeIdCard::create([
            'employee_id' => $employee->getKey(),
            'serial' => EmployeeIdCard::nextSerial(),
            'issued_on' => now()->toDateString(),
            'expires_on' => $expiresOn ?: now()->addYears(self::DEFAULT_YEARS)->toDateString(),
            'issued_by' => auth()->id(),
        ]);
    }

    public function revoke(EmployeeIdCard $card, string $reason): EmployeeIdCard
    {
        if (! $card->isRevoked()) {
            $card->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        }

        return $card->refresh();
    }

    /** One card, on card stock. */
    public function render(EmployeeIdCard $card): PdfDocument
    {
        return $this->pdf(collect([$card]), sheet: false)
            ->name('id-card-'.$card->serial);
    }

    /**
     * Several to an A4 sheet, for a company printing a batch.
     *
     * Dashed cut lines rather than crop marks: these are printed on an office printer
     * and cut with scissors, and a crop mark that needs a guillotine is a crop mark
     * nobody uses.
     *
     * @param  Collection<int, EmployeeIdCard>  $cards
     */
    public function sheet(Collection $cards): PdfDocument
    {
        return $this->pdf($cards, sheet: true)->name('id-cards-'.now()->format('Y-m-d'));
    }

    /** @param  Collection<int, EmployeeIdCard>  $cards */
    private function pdf(Collection $cards, bool $sheet): PdfDocument
    {
        // Re-read with the employee eager-loaded, in one query, whatever kind of
        // collection arrived: a bulk action hands over an Eloquent collection and
        // `render()` hands over a plain one, and `loadMissing` exists only on the first.
        $cards = EmployeeIdCard::query()
            ->whereKey($cards->map(fn (EmployeeIdCard $card): int => (int) $card->getKey())->all())
            ->with('employee.user')
            ->orderBy('id')
            ->get();

        $logo = CompanyLetterhead::logoDataUri();

        $entries = $cards->map(fn (EmployeeIdCard $card): array => [
            'card' => $card,
            'employee' => $card->employee,
            'photo' => $card->employee?->photoDataUri(),
            'logo' => $logo,
            // Absent where verification is switched off, and the card is still a card.
            'qr' => DocumentVerification::qrFor(EmployeeIdCard::KIND, (int) $card->getKey(), $card->verificationFacts()),
        ])->all();

        $pdf = Pdf::document('id-card', 'pdfs.id-card', [
            'cards' => $entries,
            'sheet' => $sheet,
            'brand' => BrandKit::forTemplate(),
            'company' => CompanyLetterhead::data(),
        ]);

        // A card is 85.6 × 54mm — the size of every bank card in a wallet. The sheet is
        // ordinary A4, because that is what the printer has.
        return $sheet ? $pdf->format('a4') : $pdf->paperSize(85.6, 54);
    }

    /** What the QR answers with. Registered from the service provider. */
    public function verificationFacts(int $id): ?array
    {
        return EmployeeIdCard::query()->with('employee.user')->find($id)?->verificationFacts();
    }

    public function expiryFor(?string $years = null): string
    {
        return Carbon::now()->addYears(max(1, (int) ($years ?: self::DEFAULT_YEARS)))->toDateString();
    }
}
