@extends('landing.layout')
@section('title', 'Package plans — MKPOS')
@section('content')
<main class="section">
    <div class="container">
        <div class="section-heading"><div><span class="section-kicker">Subscriptions</span><h1>Package plans</h1></div><p>Published packages from MKPOS billing. Prices apply to the stated number of days, not an assumed calendar month.</p></div>
        <div class="plan-grid">
            <article class="docs-card plan-card"><span class="section-kicker">Get started</span><h2>Free trial</h2><p class="plan-price">Free <small>/ one month</small></p><p>Explore MKPOS with a new business account.</p><ul><li>Set up products and daily operations.</li><li>Backup download and restore require a paid plan.</li></ul><a class="button button-primary" href="{{ url('/app') }}/#/signup">Start free trial</a></article>
            @forelse($plans as $plan)
                <article class="docs-card plan-card"><span class="section-kicker">Subscription package</span><h2>{{ $plan->name }}</h2><p class="plan-price">{{ number_format($plan->price) }} {{ $plan->currency }} <small>/ {{ $plan->duration_days }} days</small></p><p>{{ $plan->description }}</p>
                    <ul>@foreach((json_decode($plan->features ?? '[]', true) ?: []) as $feature)<li>{{ $feature }}</li>@endforeach</ul>
                    <a class="button button-primary" href="{{ url('/app') }}/#/login">Sign in to choose plan</a>
                </article>
            @empty
                <article class="docs-card"><h2>Paid packages</h2><p>No paid packages are currently published. Sign in and check Billing for availability. No prices or package limits are assumed here.</p></article>
            @endforelse
        </div>
        <div class="docs-card plan-help"><h3>How to subscribe</h3><p>Sign in, open Billing, select an available package and payment method, then submit the requested payment evidence. Activation follows approval; submitting a request does not immediately activate a plan.</p><a class="button button-secondary" href="{{ route('documentation.show', 'data') }}">Read backup & update guidance</a></div>
    </div>
</main>
@endsection
