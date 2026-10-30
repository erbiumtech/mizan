<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\BusinessCardOrder;
use App\Support\BrandKit;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Collection;

/**
 * The artwork for a business card order — docs/saas-launch-plan.md §14.10.
 *
 * What this adds over somebody emailing a printer is that the card says what the
 * record says. Forty people's cards then carry the same company name, spelt the same
 * way, and the one person whose title is different on the card is different because
 * somebody typed an override and it is on the order.
 *
 * No QR and no verification. A business card is not a document anybody checks; it is
 * a thing handed over in a meeting, and a QR on one is a QR nobody scans.
 */
class BusinessCards
{
    public function render(BusinessCardOrder $order): PdfDocument
    {
        return $this->pdf(collect([$order]), sheet: false)
            ->name('business-card-'.$order->getKey());
    }

    /** @param  Collection<int, BusinessCardOrder>  $orders */
    public function sheet(Collection $orders): PdfDocument
    {
        return $this->pdf($orders, sheet: true)->name('business-cards-'.now()->format('Y-m-d'));
    }

    /** @param  Collection<int, BusinessCardOrder>  $orders */
    private function pdf(Collection $orders, bool $sheet): PdfDocument
    {
        // One query with the employee attached, whatever kind of collection arrived —
        // see IdCards::pdf() for why that is not a `loadMissing`.
        $orders = BusinessCardOrder::query()
            ->whereKey($orders->map(fn (BusinessCardOrder $order): int => (int) $order->getKey())->all())
            ->with('employee.user')
            ->orderBy('id')
            ->get();

        $logo = CompanyLetterhead::logoDataUri();

        $pdf = Pdf::document('business-card', 'pdfs.business-card', [
            'cards' => $orders->map(fn (BusinessCardOrder $order): array => [
                'order' => $order,
                'employee' => $order->employee,
                'logo' => $logo,
            ])->all(),
            'sheet' => $sheet,
            'brand' => BrandKit::forTemplate(),
            'company' => CompanyLetterhead::data(),
        ]);

        // 90 × 55mm, which is what a Pakistani printer cuts to.
        return $sheet ? $pdf->format('a4') : $pdf->paperSize(90, 55);
    }
}
