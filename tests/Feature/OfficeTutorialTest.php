<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tutorial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeTutorialTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): void
    {
        $this->actingAs(PlatformAdmin::create(['name' => 'QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]), 'office');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'QA tutorial '.Str::uuid(), 'description' => 'Learn to make your first sale.',
            'youtube_url' => 'https://youtu.be/abcdefghijk?si=share', 'is_published' => false, 'sort_order' => 0], $overrides);
    }

    public function test_management_requires_office_authentication(): void
    {
        $tutorial = Tutorial::create(['title' => 'QA private', 'description' => 'Private', 'youtube_id' => 'abcdefghijk']);
        $this->getJson('/api/office/tutorials')->assertUnauthorized();
        $this->postJson('/api/office/tutorials', $this->payload())->assertUnauthorized();
        $this->putJson('/api/office/tutorials/'.$tutorial->id, $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/office/tutorials/'.$tutorial->id)->assertUnauthorized();
        $this->getJson('/api/office/tutorials/'.$tutorial->id.'/thumbnail')->assertUnauthorized();
    }

    private function image(string $name = 'cover.png')
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='));
    }

    public function test_draft_publish_unpublish_and_escaped_public_content(): void
    {
        $this->login();
        $data = $this->payload(['description' => '<script>alert("x")</script>']);
        $id = $this->postJson('/api/office/tutorials', $data)->assertCreated()->assertJsonPath('youtube_url', 'https://www.youtube.com/watch?v=abcdefghijk')->json('id');
        $this->get('/tutorials')->assertOk()->assertDontSee($data['title']);
        $this->get('/')->assertOk()->assertDontSee($data['title']);
        $data['is_published'] = true;
        $this->putJson('/api/office/tutorials/'.$id, $data)->assertOk();
        $this->get('/tutorials')->assertOk()->assertSee($data['title'])->assertSee($data['description'])->assertDontSee($data['description'], false);
        $this->get('/')->assertOk()->assertSee($data['title']);
        $data['is_published'] = false;
        $this->putJson('/api/office/tutorials/'.$id, $data)->assertOk();
        $this->get('/tutorials')->assertDontSee($data['title']);
    }

    public function test_video_link_validation(): void
    {
        $this->login();
        foreach (['https://www.youtube.com/watch?v=abcdefghijk', 'https://youtu.be/abcdefghijk', 'https://youtube.com/shorts/abcdefghijk', 'https://m.youtube.com/live/abcdefghijk', 'https://youtube.com/embed/abcdefghijk'] as $url) {
            $this->postJson('/api/office/tutorials', $this->payload(['youtube_url' => $url]))->assertCreated()->assertJsonPath('youtube_id', 'abcdefghijk');
        }
        foreach (['javascript:alert(1)', 'https://youtube.com.evil.test/watch?v=abcdefghijk', 'https://evil.test', 'https://youtube.com/@channel', 'https://youtube.com/watch?v[]=abcdefghijk', 'https://youtu.be/short', 'https://user@youtube.com/watch?v=abcdefghijk'] as $url) {
            $this->postJson('/api/office/tutorials', $this->payload(['youtube_url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('youtube_url');
        }
        $this->postJson('/api/office/tutorials', $this->payload(['title' => '', 'is_published' => 'yes', 'sort_order' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['title', 'is_published', 'sort_order']);
    }

    public function test_thumbnail_lifecycle_and_draft_privacy(): void
    {
        Storage::fake('local');
        $this->login();
        $data = $this->payload();
        $id = $this->postJson('/api/office/tutorials', $data + ['thumbnail' => $this->image()])->assertCreated()->assertJsonPath('has_thumbnail', true)->json('id');
        $first = Tutorial::findOrFail($id)->thumbnail_path;
        Storage::disk('local')->assertExists($first);
        $this->get('/tutorials/'.$id.'/thumbnail')->assertNotFound();
        $this->get('/api/office/tutorials/'.$id.'/thumbnail')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $data['is_published'] = true;
        // Browser multipart updates use POST + method override.
        $this->post('/api/office/tutorials/'.$id, $data + ['_method' => 'PUT', 'thumbnail' => $this->image('new.png')], ['Accept' => 'application/json'])->assertOk();
        $second = Tutorial::findOrFail($id)->thumbnail_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        $this->get('/tutorials/'.$id.'/thumbnail')->assertOk();
        $this->putJson('/api/office/tutorials/'.$id, $data + ['remove_thumbnail' => true])->assertOk()->assertJsonPath('has_thumbnail', false)->assertJsonPath('thumbnail_url', 'https://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg');
        Storage::disk('local')->assertMissing($second);
        $this->putJson('/api/office/tutorials/'.$id, $data + ['thumbnail' => $this->image('last.png')])->assertOk();
        $last = Tutorial::findOrFail($id)->thumbnail_path;
        $this->deleteJson('/api/office/tutorials/'.$id)->assertOk();
        $this->assertDatabaseMissing('tutorials', ['id' => $id]);
        Storage::disk('local')->assertMissing($last);
        $this->deleteJson('/api/office/tutorials/'.$id)->assertNotFound();
        $this->postJson('/api/office/tutorials', $data + ['thumbnail' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('thumbnail');
        $this->postJson('/api/office/tutorials', $data + ['thumbnail' => $this->image('huge.png')->size(2049)])->assertUnprocessable()->assertJsonValidationErrors('thumbnail');
    }

    public function test_pagination_search_and_public_order(): void
    {
        $this->login();
        $prefix = 'QA '.Str::uuid();
        for ($i = 0; $i < 25; $i++) Tutorial::create(['title' => $prefix.' '.$i, 'description' => 'Guide', 'youtube_id' => 'abcdefghijk', 'is_published' => true, 'sort_order' => $i]);
        $this->getJson('/api/office/tutorials?q='.urlencode($prefix))->assertOk()->assertJsonPath('total', 25)->assertJsonCount(20, 'items')->assertJsonPath('items.0.title', $prefix.' 0');
        $this->getJson('/api/office/tutorials?q='.urlencode($prefix).'&page=999')->assertOk()->assertJsonPath('page', 2)->assertJsonCount(5, 'items')->assertJsonPath('items.0.title', $prefix.' 20');
        $this->get('/tutorials')->assertOk()->assertViewHas('tutorials', fn ($rows) => $rows->count() === 12 && $rows->hasMorePages());
        $this->get('/')->assertOk()->assertViewHas('tutorials', fn ($rows) => $rows->count() === 6);
    }
}
