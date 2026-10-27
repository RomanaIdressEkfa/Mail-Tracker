@extends('layouts.site')

@section('title', 'Continue · '.config('app.name'))
@section('robots', 'noindex,nofollow')

@section('content')
    <h1>You're leaving {{ config('app.name') }}</h1>
    <p>This link points to:</p>
    <div class="card"><code>{{ $url }}</code></div>
    <p><a class="btn" href="{{ $url }}" rel="noopener noreferrer nofollow">Continue to {{ parse_url($url, PHP_URL_HOST) }}</a></p>
@endsection
