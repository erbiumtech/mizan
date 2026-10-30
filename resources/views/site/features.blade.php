@extends('site.layout')
@section('body')
    <h1>Features</h1>
    <p class="lede">Every module, grouped by the cheapest plan that includes it. A plan includes everything in the plans below it.</p>

    @foreach($groups as $planLabel => $modules)
        <h2>{{ $planLabel }}</h2>
        <div class="grid">
            @foreach($modules as $module)
                <div class="card">
                    <h3><a href="{{ route('site.feature', ['module' => $module['key']]) }}">{{ $module['label'] }}</a></h3>
                    <p class="muted" style="margin:0">{{ $module['description'] }}</p>
                </div>
            @endforeach
        </div>
    @endforeach
@endsection
