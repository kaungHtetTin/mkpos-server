<section class="section documentation" id="documentation">
    <div class="container">
        <div class="section-heading"><div><span class="section-kicker">Step-by-step help</span><h2>Guides for everyday work.</h2></div><p>Learn setup, sales, stock and billing at your own pace.</p></div>
        @php($guideTopics = $topics ?? \App\Http\Controllers\LandingController::TOPICS)
        <div class="docs-grid">
            @foreach(request()->routeIs('landing') ? array_slice($guideTopics, 0, 3, true) : $guideTopics as $key => $info)
                <a class="docs-card guide-link" href="{{ route('documentation.show', $key) }}"><h3>{{ $info[0] }} <span aria-hidden="true">&rarr;</span></h3><p>{{ $info[1] }}</p></a>
            @endforeach
        </div>
        @if(request()->routeIs('landing'))<p><a class="button button-secondary" href="{{ route('documentation.index') }}">Browse all {{ count($guideTopics) }} guides &rarr;</a></p>@endif
    </div>
</section>
