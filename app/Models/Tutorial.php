<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tutorial extends Model
{
    protected $fillable = ['title', 'description', 'youtube_id', 'thumbnail_path', 'is_published', 'sort_order'];
    protected $hidden = ['thumbnail_path'];
    protected $casts = ['is_published' => 'boolean', 'sort_order' => 'integer'];
    protected $appends = ['youtube_url', 'thumbnail_url', 'has_thumbnail'];

    public function getYoutubeUrlAttribute(): string { return 'https://www.youtube.com/watch?v='.$this->youtube_id; }
    public function getHasThumbnailAttribute(): bool { return (bool) $this->thumbnail_path; }
    public function getThumbnailUrlAttribute(): string
    {
        return $this->thumbnail_path
            ? route('tutorials.thumbnail', $this->id).'?v='.$this->updated_at->timestamp
            : 'https://i.ytimg.com/vi/'.$this->youtube_id.'/hqdefault.jpg';
    }
}
