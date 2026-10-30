@extends('layouts.public')
@section('title', 'Confirm a visit')
@section('body')
    <h1>Confirm a visit</h1>
    <p class="muted">{{ $companyName }}</p>

    @if($confirmed)
        <div class="card" style="border-color:#16a34a">
            <p style="font-weight:600;margin:0 0 .5rem">Thank you{{ $confirmedName ? ', '.$confirmedName : '' }}.</p>
            <p class="muted">The visit by {{ $visitorName }} on {{ $visitedOn }} is confirmed.</p>
            @if($rating)<p class="muted">You rated it {{ $rating }}.</p>@endif
        </div>
    @else
        <div class="card">
            <p style="margin:0 0 1rem">{{ $visitorName }} from {{ $companyName }} recorded a visit to you on <strong>{{ $visitedOn }}</strong>.</p>
            <form method="post" action="{{ $action }}">
                @csrf
                <label for="name" style="display:block;font-weight:500;margin-bottom:.25rem">Your name</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name') }}" style="width:100%;padding:.5rem;margin-bottom:.75rem;border:1px solid #d1d5db;border-radius:.375rem">
                @error('name')<p style="color:#dc2626;margin:0 0 .75rem">{{ $message }}</p>@enderror

                {{--
                    How it went — docs/saas-launch-plan.md §14.10. Optional, and it stays
                    optional: the page is asking a favour of somebody who was not expecting
                    to be asked for anything, and a required rating is how a confirmation
                    rate falls.
                --}}
                <label for="rating" style="display:block;font-weight:500;margin-bottom:.25rem">How did it go? <span class="muted">(optional)</span></label>
                <select id="rating" name="rating" style="width:100%;padding:.5rem;margin-bottom:.75rem;border:1px solid #d1d5db;border-radius:.375rem">
                    <option value="">Rather not say</option>
                    @foreach($ratings as $value => $label)
                        <option value="{{ $value }}" @selected(old('rating') == $value)>{{ $value }} — {{ $label }}</option>
                    @endforeach
                </select>

                <label for="rating_comment" style="display:block;font-weight:500;margin-bottom:.25rem">Anything to add? <span class="muted">(optional)</span></label>
                <input id="rating_comment" name="rating_comment" type="text" maxlength="190" value="{{ old('rating_comment') }}" style="width:100%;padding:.5rem;margin-bottom:.75rem;border:1px solid #d1d5db;border-radius:.375rem">

                <button type="submit" class="button" style="padding:.6rem 1.2rem;border-radius:.375rem;background:#111827;color:#fff;border:0">Yes, this visit happened</button>
            </form>
            <p class="muted" style="margin-top:1rem;font-size:.9rem">This confirms the visit to {{ $visitorName }}'s manager. It is not a signature on any document, order or agreement.</p>
        </div>
    @endif
@endsection
