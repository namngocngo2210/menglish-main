<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MediaManagerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin Media',
            'email' => 'admin.media@menglish.edu.vn',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);

        // Tạo thư mục test chuyên biệt trong uploads
        $this->testDir = public_path('uploads/test_media_suite');
        if (!File::isDirectory($this->testDir)) {
            File::makeDirectory($this->testDir, 0755, true, true);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_can_view_media_manager_index_and_stats(): void
    {
        $testFile = $this->testDir . '/avatar_test.png';
        File::put($testFile, 'dummy image content');

        $response = $this->actingAs($this->admin)->get(route('media.index'));
        $response->assertStatus(200);
        $response->assertSee('Quản lý Media');
        $response->assertSee('avatar_test.png');
        $response->assertSee('Tổng số tệp tin');
    }

    public function test_can_filter_media_by_search_and_type(): void
    {
        $imgFile = $this->testDir . '/photo_sample.jpg';
        $docFile = $this->testDir . '/document_report.pdf';

        File::put($imgFile, 'photo binary data');
        File::put($docFile, 'pdf text data');

        // 1. Filter by search
        $resSearch = $this->actingAs($this->admin)->get(route('media.index', ['search' => 'photo_sample']));
        $resSearch->assertStatus(200);
        $resSearch->assertSee('photo_sample.jpg');
        $resSearch->assertDontSee('document_report.pdf');

        // 2. Filter by type = document
        $resDoc = $this->actingAs($this->admin)->get(route('media.index', ['type' => 'document']));
        $resDoc->assertStatus(200);
        $resDoc->assertSee('document_report.pdf');
        $resDoc->assertDontSee('photo_sample.jpg');
    }

    public function test_can_physically_delete_single_file_from_disk(): void
    {
        $filePath = $this->testDir . '/file_to_delete.png';
        File::put($filePath, 'content will be wiped');

        $this->assertTrue(File::exists($filePath), 'File must exist before deletion.');

        $encodedId = base64_encode($filePath);

        $response = $this->actingAs($this->admin)->delete(route('media.destroy', $encodedId));
        $response->assertRedirect();
        $response->assertSessionHas('status');

        // ASSERT: File is physically deleted from disk!
        $this->assertFalse(File::exists($filePath), 'File must be physically removed from disk!');
    }

    public function test_can_physically_bulk_delete_files_from_disk(): void
    {
        $file1 = $this->testDir . '/bulk_1.png';
        $file2 = $this->testDir . '/bulk_2.pdf';
        $fileKeep = $this->testDir . '/bulk_keep.docx';

        File::put($file1, 'data 1');
        File::put($file2, 'data 2');
        File::put($fileKeep, 'data keep');

        $this->assertTrue(File::exists($file1));
        $this->assertTrue(File::exists($file2));
        $this->assertTrue(File::exists($fileKeep));

        $idsToDelete = [
            base64_encode($file1),
            base64_encode($file2),
        ];

        $response = $this->actingAs($this->admin)->delete(route('media.bulk-destroy'), [
            'selected_files' => $idsToDelete,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // ASSERT: Selected files are physically deleted, unselected file remains!
        $this->assertFalse(File::exists($file1), 'File 1 must be wiped from disk.');
        $this->assertFalse(File::exists($file2), 'File 2 must be wiped from disk.');
        $this->assertTrue(File::exists($fileKeep), 'Kept file must still exist on disk.');
    }

    public function test_can_physically_clean_filtered_files_from_disk(): void
    {
        $cleanTarget1 = $this->testDir . '/clean_target_1.png';
        $cleanTarget2 = $this->testDir . '/clean_target_2.png';
        $otherFile = $this->testDir . '/other_kept.pdf';

        File::put($cleanTarget1, 'clean 1');
        File::put($cleanTarget2, 'clean 2');
        File::put($otherFile, 'other');

        // Xóa toàn bộ file có search query = 'clean_target'
        $response = $this->actingAs($this->admin)->delete(route('media.destroy-filtered', [
            'search' => 'clean_target',
        ]));

        $response->assertRedirect(route('media.index'));
        $response->assertSessionHas('status');

        // ASSERT: Matching files were physically wiped
        $this->assertFalse(File::exists($cleanTarget1));
        $this->assertFalse(File::exists($cleanTarget2));
        $this->assertTrue(File::exists($otherFile));
    }

    public function test_can_upload_files_via_drag_and_drop(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('uploaded_photo.jpg', 600, 600);
        $fakeDoc = \Illuminate\Http\UploadedFile::fake()->create('uploaded_doc.pdf', 200, 'application/pdf');

        $response = $this->actingAs($this->admin)->post(route('media.upload'), [
            'files' => [$fakeImage, $fakeDoc],
            'folder' => 'test_media_suite',
        ]);

        $response->assertRedirect(route('media.index', ['folder' => 'test_media_suite']));
        $response->assertSessionHas('status');

        $files = File::files($this->testDir);
        $this->assertCount(2, $files);
    }

    public function test_can_create_and_delete_folders(): void
    {
        $response = $this->actingAs($this->admin)->post(route('media.create-folder'), [
            'folder_name' => 'sub_folder_demo',
            'parent_folder' => 'test_media_suite',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $createdDir = $this->testDir . '/sub_folder_demo';
        $this->assertTrue(File::isDirectory($createdDir), 'Sub folder must be created on disk.');

        // Delete folder
        $resDelete = $this->actingAs($this->admin)->delete(route('media.delete-folder'), [
            'folder_path' => 'test_media_suite/sub_folder_demo',
        ]);

        $resDelete->assertRedirect();
        $this->assertFalse(File::isDirectory($createdDir), 'Sub folder must be removed from disk.');
    }

    public function test_can_move_files_between_folders(): void
    {
        $targetSubDir = $this->testDir . '/destination_folder';
        File::makeDirectory($targetSubDir, 0755, true, true);

        $fileToMove = $this->testDir . '/file_to_move.png';
        File::put($fileToMove, 'data');

        $this->assertTrue(File::exists($fileToMove));

        $response = $this->actingAs($this->admin)->post(route('media.move-files'), [
            'selected_files' => [base64_encode($fileToMove)],
            'target_folder' => 'test_media_suite/destination_folder',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $newPath = $targetSubDir . '/file_to_move.png';
        $this->assertTrue(File::exists($newPath), 'File must exist at destination path.');
        $this->assertFalse(File::exists($fileToMove), 'File must no longer exist at original path.');
    }
}
