@extends('layouts.public')
@section('title', $vacancy['title'].' at '.$company['name'])
@section('body')
    <p class="muted"><a href="{{ route('recruitment.careers.index', ['company' => request()->route('company'), 'token' => $token]) }}">← All positions</a></p>
    <h1>{{ $vacancy['title'] }}</h1>
    <p class="muted">{{ implode(' · ', array_filter([$company['name'], $vacancy['designation'], $vacancy['department'], $vacancy['employment_type'], $vacancy['salary']])) }}</p>

    @if($vacancy['description'])
        <div class="card" style="white-space:pre-line">{{ $vacancy['description'] }}</div>
    @endif

    <form method="post" action="{{ route('recruitment.careers.apply', ['company' => request()->route('company'), 'token' => $token, 'vacancy' => $vacancy['id']]) }}" class="card" enctype="multipart/form-data">
        @csrf
        <h2 style="font-size:1.1rem;margin:0 0 .5rem">Apply</h2>

        <label for="name">Your name</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
        @error('name')<p class="error">{{ $message }}</p>@enderror

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email">
        @error('email')<p class="error">{{ $message }}</p>@enderror

        <label for="phone">Phone</label>
        <input id="phone" name="phone" value="{{ old('phone') }}" maxlength="40" autocomplete="tel">

        <label for="resume">CV (PDF or Word, up to {{ round($maxResumeKb / 1024) }} MB)</label>
        <input id="resume" name="resume" type="file" accept=".pdf,.doc,.docx">
        @error('resume')<p class="error">{{ $message }}</p>@enderror

        <label for="cover">A few words about yourself</label>
        <textarea id="cover" name="cover" rows="5" maxlength="2000" style="width:100%;padding:.55rem .7rem;border:1px solid var(--line);border-radius:.5rem;background:var(--bg);color:var(--text);font:inherit">{{ old('cover') }}</textarea>

        <div class="hp" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit">Send application</button>
    </form>
@endsection
