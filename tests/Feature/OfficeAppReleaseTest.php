<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficeAppReleaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_office_admin_can_publish_and_replace_an_android_release(): void
    {
        Storage::fake('local');
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'office');
        $this->upload('/api/office/app-releases/android', '1.2.0', 'MKPOS.apk', 'first-apk')
            ->assertOk()
            ->assertJsonPath('release.version', '1.2.0')
            ->assertJsonPath('release.platform', 'android');

        $firstPath = AppRelease::where('platform', 'android')->value('file_path');
        Storage::disk('local')->assertExists($firstPath);

        $this->upload('/api/office/app-releases/android', '1.3.0', 'MKPOS-release.apk', 'replacement-apk')
            ->assertOk()
            ->assertJsonPath('release.version', '1.3.0');

        $release = AppRelease::where('platform', 'android')->firstOrFail();
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($release->file_path);
        $this->assertSame(hash('sha256', 'replacement-apk'), $release->checksum_sha256);
    }

    public function test_published_release_is_presented_and_downloadable_from_the_public_site(): void
    {
        Storage::fake('local');
        $this->actingAs($this->createAdmin(), 'office');
        $this->upload('/api/office/app-releases/windows', '0.3.0', 'MKPOS.exe', 'windows-installer')->assertOk();

        $this->get('/')
            ->assertOk()
            ->assertSee('Version 0.3.0')
            ->assertSee(route('downloads.show', ['platform' => 'windows']), false);

        $this->get('/downloads/windows')
            ->assertOk()
            ->assertDownload('MKPOS-Windows-0.3.0.exe');

        $this->assertSame(1, AppRelease::where('platform', 'windows')->value('download_count'));
    }

    public function test_release_upload_requires_office_authentication_and_correct_file_extension(): void
    {
        $this->upload('/api/office/app-releases/android', '1.0.0', 'MKPOS.apk', 'apk')
            ->assertUnauthorized();

        $this->actingAs($this->createAdmin('release-extension@example.com'), 'office');
        $this->upload('/api/office/app-releases/android', '1.0.0', 'MKPOS.exe', 'not-an-apk')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_streamed_release_upload_is_not_limited_by_php_form_post_size(): void
    {
        Storage::fake('local');
        $this->actingAs($this->createAdmin('large-release@example.com'), 'office');

        $this->upload(
            '/api/office/app-releases/windows',
            '0.4.0',
            'MKPOS.exe',
            'streamed-installer',
            100 * 1024 * 1024
        )->assertOk()->assertJsonPath('release.version', '0.4.0');
    }

    private function upload(string $uri, string $version, string $fileName, string $content, ?int $declaredContentLength = null)
    {
        $query = http_build_query([
            'version' => $version,
            'original_name' => $fileName,
            'file_size' => strlen($content),
            'release_notes' => 'Release test notes',
        ]);

        return $this->call('PUT', $uri.'?'.$query, [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'CONTENT_LENGTH' => $declaredContentLength ?? strlen($content),
            'HTTP_ACCEPT' => 'application/json',
        ], $content);
    }

    private function createAdmin(string $email = 'release-admin@example.com'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Release Admin',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
    }
}
