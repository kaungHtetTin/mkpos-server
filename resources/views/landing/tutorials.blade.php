@extends('landing.layout')
@section('title', 'Video tutorials — MKPOS')
@section('content')
<main class="section"><div class="container">
    <div class="tutorial-section-heading"><div><span class="section-kicker">MKPOS learning library</span><h1>Video tutorials</h1><p>Learn at your own pace. Videos open on YouTube in a new tab.</p></div><a href="{{ route('documentation.index') }}">Step-by-step documentation →</a></div>
    @include('landing.tutorial-cards')
    @if($tutorials->hasPages())
    <nav class="tutorial-pagination" aria-label="Tutorial pagination">
        @if($tutorials->onFirstPage())<span>Previous</span>@else<a href="{{ $tutorials->previousPageUrl() }}" rel="prev">← Previous</a>@endif
        <span>Page {{ $tutorials->currentPage() }} of {{ $tutorials->lastPage() }}</span>
        @if($tutorials->hasMorePages())<a href="{{ $tutorials->nextPageUrl() }}" rel="next">Next →</a>@else<span>Next</span>@endif
    </nav>
    @endif
</div></main>
@endsection
