@extends('site.layout')
@section('body')
    <h1>{{ $page->title }}</h1>
    @if($page->published_at)<p class="muted">Last updated {{ $page->updated_at->toFormattedDateString() }}</p>@endif
    <div class="prose">{!! $page->html() !!}</div>
@endsection
