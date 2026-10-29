@extends('layouts.public')
@section('title', 'Confirm a visit')
@section('body')
    <h1>Confirm a visit</h1>
    <p class="muted">{{ $companyName }}</p>

    @if($confirmed)
        <div class="card" style="border-color:#16a34a">
            <p style="font-weight:600;margin:0 0 .5rem">Thank you{{ $confirmedName ? ', '.$confirmedName : '' }}.</p>
            <p class="muted">The visit by {{ $visitorName }} on {{ $visitedOn }} is confirmed.</p>
        </div>
    @else
        <div class="card">
            <p style="margin:0 0 1rem">{{ $visitorName }} from {{ $companyName }} recorded a visit to you on <strong>{{ $visitedOn }}</strong>.</p>
            <form method="post" action="{{ $action }}">
                @csrf
                <label for="name" style="display:block;font-weight:500;margin-bottom:.25rem">Your name</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name') }}" style="width:100%;padding:.5rem;margin-bottom:.75rem;border:1px solid #d1d5db;border-radius:.375rem">
                @error('name')<p style="color:#dc2626;margin:0 0 .75rem">{{ $message }}</p>@enderror
                <button type="submit" class="button" style="padding:.6rem 1.2rem;border-radius:.375rem;background:#111827;color:#fff;border:0">Yes, this visit happened</button>
            </form>
            <p class="muted" style="margin-top:1rem;font-size:.9rem">This confirms the visit to {{ $visitorName }}'s manager. It is not a signature on any document, order or agreement.</p>
        </div>
    @endif
@endsection
