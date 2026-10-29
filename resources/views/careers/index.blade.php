@extends('layouts.public')
@section('title', 'Careers at '.$company['name'])
@section('body')
    @if($company['logo'])<img src="{{ $company['logo'] }}" alt="" style="max-height:3rem;margin-bottom:1rem">@endif
    <h1>Careers at {{ $company['name'] }}</h1>
    <p class="muted">{{ count($vacancies) === 1 ? 'One open position.' : count($vacancies).' open positions.' }}</p>

    @forelse($vacancies as $vacancy)
        <div class="card">
            <div style="font-weight:600;font-size:1.1rem"><a href="{{ route('recruitment.careers.show', ['company' => request()->route('company'), 'token' => $token, 'vacancy' => $vacancy['id']]) }}">{{ $vacancy['title'] }}</a></div>
            <p class="muted" style="margin:.25rem 0 0">
                {{ implode(' · ', array_filter([$vacancy['designation'], $vacancy['department'], $vacancy['employment_type'], $vacancy['salary'], $vacancy['openings'] > 1 ? $vacancy['openings'].' openings' : null])) }}
            </p>
        </div>
    @empty
        <div class="card muted">No open positions right now. Check back soon.</div>
    @endforelse
@endsection
