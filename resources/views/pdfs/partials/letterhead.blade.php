{{--
    The branded header shared by the income certificate and the experience letter.
    Styles live in `partials/letterhead-styles`, included from the document's <head>.

    @param array  $company  from App\Support\CompanyLetterhead::data()
    @param string $title    the document's own title, printed in the payslip's green
--}}
@php
    // Phone and email only. The website is on the green footer bar, and printing it twice on one page
    // spent a line of the header saying nothing new.
    $contact = array_filter([$company['phone'] ?? null, $company['email'] ?? null]);
    $numbers = array_filter([
        ($company['registration_no'] ?? null) ? 'Incorporation No.: '.$company['registration_no'] : null,
        ($company['ntn'] ?? null) ? 'NTN: '.$company['ntn'] : null,
    ]);
@endphp
<table class="lh">
    <tr>
        <td>
            <div class="lh-bars">
                <span class="lh-bar lh-bar-1"></span><span class="lh-bar lh-bar-2"></span><span class="lh-bar lh-bar-3"></span>
            </div>
            <div class="lh-name">{{ $company['legal_name'] }}</div>
            @if ($numbers)
                <div class="lh-sub">{{ implode('  |  ', $numbers) }}</div>
            @endif
        </td>
        <td class="lh-right">
            {{-- No address here: the green bar at the foot carries it, and a certificate printing the
                 registered office twice on one page reads as a template nobody finished. Phone and email
                 stay, because the header is where a reader looks to answer "who sent this". --}}
            @if ($contact)
                <div class="line">{{ implode('  |  ', $contact) }}</div>
            @endif
            @if (($title ?? '') !== '')
                <div class="lh-title">{{ $title }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="lh-divider"></div>
