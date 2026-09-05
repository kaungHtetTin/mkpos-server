<div class="tutorial-grid">
@forelse($tutorials as $tutorial)
    <article class="tutorial-card">
        <a class="tutorial-thumbnail" href="{{ $tutorial->youtube_url }}" target="_blank" rel="noopener noreferrer" aria-label="Watch {{ $tutorial->title }} on YouTube (new tab)">
            <img src="{{ $tutorial->thumbnail_url }}" alt="" width="480" height="270" loading="lazy" referrerpolicy="no-referrer">
            <span>▶ Watch on YouTube</span>
        </a>
        <div class="tutorial-copy"><h3><a href="{{ $tutorial->youtube_url }}" target="_blank" rel="noopener noreferrer">{{ $tutorial->title }}</a></h3>
            <p>{{ ($compact ?? false) ? \Illuminate\Support\Str::limit($tutorial->description, 160) : $tutorial->description }}</p>
        </div>
    </article>
@empty
    <div class="tutorial-empty"><h3>Video tutorials coming soon</h3><p>In the meantime, our detailed guides cover setup, sales, printing and daily operations.</p><a href="{{ route('documentation.index') }}">Read the documentation →</a></div>
@endforelse
</div>
