<?php
// Read-only visual fixture: renders sample content without writing database rows.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$items = collect(range(1, 6))->map(fn ($id) => (object) [
    'title' => $id === 1 ? 'Getting started: set up your shop and make your first sale' : 'MKPOS tutorial '.$id,
    'description' => 'Learn the daily workflow with a clear walkthrough. Set up your shop, manage stock and complete checkout confidently.',
    'youtube_url' => 'https://www.youtube.com/watch?v=abcdefghijk',
    'thumbnail_url' => 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="480" height="270"><rect width="480" height="270" fill="#0b4a3b"/><text x="36" y="135" fill="#dafa60" font-size="30">MKPOS · Preview '.$id.'</text></svg>'),
]);
$tutorials = new Illuminate\Pagination\LengthAwarePaginator($items, 24, 12, 1, ['path' => '/tutorials']);
echo view('landing.tutorials', compact('tutorials'))->render();
