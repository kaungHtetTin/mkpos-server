<section class="section documentation" id="documentation">
    <div class="container">
        <div class="section-heading"><div><span class="section-kicker">User operating manual</span><h2>MKPOS documentation</h2></div><p>New to MKPOS? Start with Getting started, then Products & stock and Selling & checkout. Each guide includes practical steps, examples and checks.</p></div>
        @php($guideTopics = $topics ?? \App\Http\Controllers\LandingController::TOPICS)
        <div class="docs-grid">
            @foreach(request()->routeIs('landing') ? array_slice($guideTopics, 0, 6, true) : $guideTopics as $key => $info)
                <a class="docs-card guide-link" href="{{ route('documentation.show', $key) }}"><h3>{{ $info[0] }} <span aria-hidden="true">&rarr;</span></h3><p>{{ $info[1] }}</p></a>
            @endforeach
        </div>
        @if(request()->routeIs('landing'))<p><a class="button button-secondary" href="{{ route('documentation.index') }}">Browse all {{ count($guideTopics) }} guides &rarr;</a></p>@endif
    </div>
</section>
