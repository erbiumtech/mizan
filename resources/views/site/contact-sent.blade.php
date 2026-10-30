@extends('site.layout')
@section('body')
    <h1>Thank you</h1>
    <p class="lede">Your message has been received. Somebody will write back to the address you gave.</p>
    <p><a href="{{ route('site.home') }}">Back to the home page</a></p>
@endsection
