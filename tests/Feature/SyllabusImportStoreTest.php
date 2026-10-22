<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Syllabus\Import\SyllabusImportStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SyllabusImportStoreTest extends TestCase
{
    private const VISIBILITY = ['visible_to_teachers' => true, 'visible_to_assistants' => false, 'downloadable' => true];

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(SyllabusImportStore::root());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(SyllabusImportStore::root());
        parent::tearDown();
    }

    private function upload(string $name = 'goi.zip'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'PK-fake-content');
    }

    public function test_root_is_under_storage_private(): void
    {
        $this->assertSame(storage_path('app/private/syllabus-imports'), SyllabusImportStore::root());
    }

    public function test_put_and_get_roundtrip(): void
    {
        $user = User::factory()->create();
        $store = new SyllabusImportStore;

        $token = $store->put($this->upload(), $user, self::VISIBILITY);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token);
        $this->assertFileExists(SyllabusImportStore::root()."/{$token}.zip");

        $meta = $store->get($token, $user);
        $this->assertNotNull($meta);
        $this->assertSame(SyllabusImportStore::root()."/{$token}.zip", $meta['path']);
        $this->assertSame(SyllabusImportStore::root()."/{$token}", $meta['work_dir']);
        $this->assertSame('goi.zip', $meta['file_name']);
        $this->assertSame($user->id, $meta['user_id']);
        $this->assertSame(self::VISIBILITY, $meta['visibility']);
    }

    public function test_put_keeps_xlsx_extension(): void
    {
        $user = User::factory()->create();
        $token = (new SyllabusImportStore)->put($this->upload('bang.xlsx'), $user, self::VISIBILITY);

        $this->assertFileExists(SyllabusImportStore::root()."/{$token}.xlsx");
    }

    public function test_get_rejects_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = new SyllabusImportStore;
        $token = $store->put($this->upload(), $owner, self::VISIBILITY);

        $this->assertNull($store->get($token, $other));
    }

    public function test_get_returns_null_for_unknown_token(): void
    {
        $this->assertNull((new SyllabusImportStore)->get('7f0c0f0e-1d2b-4c3a-9e4f-123456789abc', User::factory()->create()));
    }

    public function test_get_expires_after_two_hours(): void
    {
        $user = User::factory()->create();
        $store = new SyllabusImportStore;
        $token = $store->put($this->upload(), $user, self::VISIBILITY);

        $this->travel(119)->minutes();
        $this->assertNotNull($store->get($token, $user));

        $this->travel(2)->minutes();
        $this->assertNull($store->get($token, $user));
    }

    public function test_get_returns_null_when_file_is_gone(): void
    {
        $user = User::factory()->create();
        $store = new SyllabusImportStore;
        $token = $store->put($this->upload(), $user, self::VISIBILITY);
        File::delete(SyllabusImportStore::root()."/{$token}.zip");

        $this->assertNull($store->get($token, $user));
    }

    public function test_get_rejects_crafted_token_without_touching_filesystem(): void
    {
        $user = User::factory()->create();
        $outside = dirname(SyllabusImportStore::root()).'/evil.zip';
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, 'x');
        session(['syllabus_import.../evil' => ['path' => $outside, 'user_id' => $user->id, 'created_at' => now()->timestamp]]);

        $this->assertNull((new SyllabusImportStore)->get('../evil', $user));
        (new SyllabusImportStore)->forget('../evil');

        $this->assertFileExists($outside);
        File::delete($outside);
    }

    public function test_forget_removes_files_and_session(): void
    {
        $user = User::factory()->create();
        $store = new SyllabusImportStore;
        $token = $store->put($this->upload(), $user, self::VISIBILITY);
        $dir = SyllabusImportStore::root()."/{$token}";
        File::ensureDirectoryExists($dir.'/sub');
        File::put($dir.'/sub/a.txt', 'a');

        $store->forget($token);

        $this->assertFileDoesNotExist(SyllabusImportStore::root()."/{$token}.zip");
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertNull(session("syllabus_import.{$token}"));
        $this->assertNull($store->get($token, $user));
    }

    public function test_prune_command_removes_only_old_entries(): void
    {
        $root = SyllabusImportStore::root();
        File::ensureDirectoryExists($root);
        File::put("$root/old.zip", 'x');
        File::put("$root/mau-0123456789abcdef.zip", 'x');
        File::put("$root/fresh.zip", 'x');
        File::ensureDirectoryExists("$root/olddir/deep");
        File::put("$root/olddir/deep/f.txt", 'x');
        $old = now()->subDays(2)->timestamp;
        touch("$root/olddir/deep/f.txt", $old);
        touch("$root/olddir/deep", $old);
        touch("$root/old.zip", $old);
        touch("$root/mau-0123456789abcdef.zip", $old);
        touch("$root/olddir", $old);

        $this->artisan('syllabus:prune-imports')->assertSuccessful();

        $this->assertFileDoesNotExist("$root/old.zip");
        $this->assertFileDoesNotExist("$root/mau-0123456789abcdef.zip");
        $this->assertDirectoryDoesNotExist("$root/olddir");
        $this->assertFileExists("$root/fresh.zip");
    }

    public function test_prune_returns_zero_when_root_missing(): void
    {
        $this->assertSame(0, (new SyllabusImportStore)->prune(now()));
    }
}
