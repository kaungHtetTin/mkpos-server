<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Tutorial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TutorialController extends Controller
{
    public function index(Request $request): array
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:200']]);
        $query = Tutorial::query();
        if ($q = trim($request->query('q', ''))) $query->where('title', 'like', '%'.$q.'%');
        $total = $query->count();
        $page = min((int) $request->query('page', 1), max(1, (int) ceil($total / 20)));
        return ['items' => $query->orderBy('sort_order')->orderByDesc('id')->offset(($page - 1) * 20)->limit(20)->get(),
            'total' => $total, 'page' => $page, 'last_page' => max(1, (int) ceil($total / 20))];
    }

    public function store(Request $request) { return response()->json($this->save($request, new Tutorial()), 201); }
    public function update(Request $request, Tutorial $tutorial) { return $this->save($request, $tutorial); }
    public function destroy(Tutorial $tutorial): array
    {
        $path = $tutorial->thumbnail_path;
        $tutorial->delete();
        if ($path) Storage::disk('local')->delete($path);
        return ['ok' => true];
    }
    public function thumbnail(Tutorial $tutorial)
    {
        abort_unless($tutorial->thumbnail_path && Storage::disk('local')->exists($tutorial->thumbnail_path), 404);
        return response()->file(Storage::disk('local')->path($tutorial->thumbnail_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function save(Request $request, Tutorial $tutorial): Tutorial
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:5000'],
            'youtube_url' => ['required', 'string', 'max:1000'], 'is_published' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
            'remove_thumbnail' => ['sometimes', 'boolean'],
        ]);
        $parts = parse_url($data['youtube_url']);
        $host = strtolower($parts['host'] ?? '');
        $path = trim($parts['path'] ?? '', '/');
        $video = null;
        if ($parts && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['port'])) {
            if ($host === 'youtu.be') $video = $path;
            elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
                parse_str($parts['query'] ?? '', $query);
                if ($path === 'watch') $video = $query['v'] ?? null;
                elseif (preg_match('~^(?:shorts|embed|live)/([^/]+)$~', $path, $matches)) $video = $matches[1];
            }
        }
        if (!is_string($video) || !preg_match('/^[a-zA-Z0-9_-]{11}$/', $video)) {
            throw ValidationException::withMessages(['youtube_url' => 'Enter a valid YouTube video link (watch, youtu.be, Shorts or live).']);
        }
        $previous = $tutorial->thumbnail_path;
        $newPath = $request->hasFile('thumbnail') ? $request->file('thumbnail')->store('tutorial-thumbnails', 'local') : null;
        abort_if($request->hasFile('thumbnail') && !$newPath, 500, 'Thumbnail could not be stored.');
        try {
            $tutorial->fill(['title' => trim($data['title']), 'description' => trim($data['description']), 'youtube_id' => $video,
                'is_published' => $data['is_published'], 'sort_order' => $data['sort_order'],
                'thumbnail_path' => $newPath ?: ($request->boolean('remove_thumbnail') ? null : $previous)]);
            $tutorial->save();
        } catch (\Throwable $error) {
            if ($newPath) Storage::disk('local')->delete($newPath);
            throw $error;
        }
        if ($previous && $previous !== $tutorial->thumbnail_path) Storage::disk('local')->delete($previous);
        return $tutorial->refresh();
    }
}
