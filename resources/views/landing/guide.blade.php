@extends('landing.layout')
@section('title', ($topic ? $topics[$topic][0] : 'Documentation').' — MKPOS')
@section('content')
<main>
    @if($topic === null)
        @include('landing.documentation-index')
    @else
        <div class="container guide-page">
            <nav class="guide-sidebar" aria-label="Documentation navigation">
                <a href="{{ route('documentation.index') }}">All documentation</a>
                @foreach($topics as $key => $info)
                    <a href="{{ route('documentation.show', $key) }}" @if($key === $topic) aria-current="page" @endif>{{ $info[0] }}</a>
                @endforeach
                <a href="{{ route('plans.index') }}">Package plans</a>
            </nav>
            <div class="guide-content">
                <nav class="docs-links" aria-label="Breadcrumb"><a href="{{ route('landing') }}">Home</a><a href="{{ route('documentation.index') }}">Documentation</a></nav>
                <h1>{{ $topics[$topic][0] }}</h1>
                @php
                    $manualContent = view('landing.docs.'.$topic)->render();
                    preg_match_all('/<h2 id="([^"]+)">(.*?)<\/h2>/s', $manualContent, $chapterSections, PREG_SET_ORDER);
                @endphp
                <nav class="manual-contents" aria-label="On this page">
                    <strong>On this page</strong>
                    @foreach($chapterSections as $section)
                        <a href="#{{ $section[1] }}">{{ strip_tags($section[2]) }}</a>
                    @endforeach
                </nav>
                <article class="docs-card manual-chapter">{!! $manualContent !!}</article>
                <nav class="docs-links guide-pagination" aria-label="Previous and next guide">
                    @php($keys = array_keys($topics))
                    @php($position = array_search($topic, $keys, true))
                    @if($position > 0)<a href="{{ route('documentation.show', $keys[$position - 1]) }}">&larr; {{ $topics[$keys[$position - 1]][0] }}</a>@endif
                    @if($position < count($keys) - 1)<a href="{{ route('documentation.show', $keys[$position + 1]) }}">{{ $topics[$keys[$position + 1]][0] }} &rarr;</a>@endif
                </nav>
            </div>
        </div>
    @endif
</main>
@endsection
