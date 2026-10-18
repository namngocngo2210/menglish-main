<?php

namespace Tests\Feature;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UPLOAD_STORAGE=r2: file công khai (/storage/..., /uploads/...) không còn trên máy chủ thì chuyển hướng sang
 * URL ký của R2; lệnh uploads:copy-to-r2 chép file cũ lên R2. Disk R2 được giả lập bằng Storage::fake.
 */
class UploadsOnR2Test extends TestCase
{
    private string $localDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->localDir = storage_path('app/public/_r2_copy_test');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->localDir);
        parent::tearDown();
    }

    private function fakeR2Disk(string $disk): void
    {
        config(["filesystems.disks.{$disk}.driver" => 's3']);
        Storage::fake($disk);
        Storage::disk($disk)->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiresAt) => "https://r2.test/{$disk}/{$path}?expires={$expiresAt->getTimestamp()}"
        );
    }

    public function test_public_file_on_r2_redirects_to_signed_url_that_stays_valid_while_cached(): void
    {
        $this->fakeR2Disk('public');
        Storage::disk('public')->put('placement_tests/2026/10/anh.png', 'png');

        $response = $this->get('/storage/placement_tests/2026/10/anh.png');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://r2.test/public/placement_tests/2026/10/anh.png?expires=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $maxAge = (int) $response->headers->getCacheControlDirective('max-age');
        $this->assertGreaterThan(0, $maxAge);
        $this->assertGreaterThanOrEqual(now()->addSeconds($maxAge)->getTimestamp(), (int) $query['expires'], 'URL ký còn hạn suốt thời gian trình duyệt cache chuyển hướng');
    }

    public function test_legacy_public_uploads_folder_on_r2_redirects_too(): void
    {
        $this->fakeR2Disk('uploads');
        Storage::disk('uploads')->put('tuition/receipts/bien-lai.jpg', 'jpg');

        $this->get('/uploads/tuition/receipts/bien-lai.jpg')
            ->assertRedirectContains('https://r2.test/uploads/tuition/receipts/bien-lai.jpg');
    }

    public function test_missing_or_traversal_paths_are_not_found(): void
    {
        $this->fakeR2Disk('public');
        Storage::disk('public')->put('a.png', 'png');

        $this->get('/storage/khong-co.png')->assertNotFound();
        $this->get('/storage/x/../a.png')->assertNotFound();
    }

    public function test_redirect_route_is_off_while_files_stay_on_the_server(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('a.png', 'png');

        $this->get('/storage/a.png')->assertNotFound();
    }

    public function test_copy_command_copies_new_files_once_and_can_delete_local_copies(): void
    {
        Storage::fake('r2_public');
        File::ensureDirectoryExists($this->localDir.'/2026');
        File::put($this->localDir.'/2026/bai-nop.mp3', 'audio');
        File::put($this->localDir.'/.gitignore', '*');

        $this->artisan('uploads:copy-to-r2', ['--only' => 'public'])->assertSuccessful();
        Storage::disk('r2_public')->assertExists('_r2_copy_test/2026/bai-nop.mp3');
        Storage::disk('r2_public')->assertMissing('_r2_copy_test/.gitignore');
        $this->assertSame('audio', Storage::disk('r2_public')->get('_r2_copy_test/2026/bai-nop.mp3'));

        $this->artisan('uploads:copy-to-r2', ['--only' => 'public'])
            ->expectsOutputToContain('đã chép 0 file')
            ->assertSuccessful();
        $this->assertFileExists($this->localDir.'/2026/bai-nop.mp3');

        $this->artisan('uploads:copy-to-r2', ['--only' => 'public', '--delete-local' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($this->localDir.'/2026/bai-nop.mp3');
        Storage::disk('r2_public')->assertExists('_r2_copy_test/2026/bai-nop.mp3');
    }

    public function test_copy_command_dry_run_writes_nothing(): void
    {
        Storage::fake('r2_public');
        File::ensureDirectoryExists($this->localDir);
        File::put($this->localDir.'/a.pdf', 'pdf');

        $this->artisan('uploads:copy-to-r2', ['--only' => 'public', '--dry-run' => true])
            ->expectsOutputToContain('cần chép')
            ->assertSuccessful();
        Storage::disk('r2_public')->assertMissing('_r2_copy_test/a.pdf');
    }
}
