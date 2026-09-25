<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\MediaManagerService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class P0HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function plainUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('student');

        return $user;
    }

    /**
     * P0-1a: Media Manager từ chối file polyglot (nội dung ảnh + đuôi .php của client).
     */
    public function test_media_upload_rejects_php_extension_polyglot(): void
    {
        $admin = $this->admin();

        // Nội dung GIF thật (magic bytes) nhưng client đặt tên .php
        $gifContent = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $polyglot = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'poly'), 'shell.php', 'image/gif', null, true
        );
        file_put_contents($polyglot->getPathname(), $gifContent);

        $response = $this->actingAs($admin)
            ->post(route('media.upload'), ['files' => [$polyglot], 'folder' => '']);

        $response->assertRedirect();
        $noPhpInUploads = collect(File::glob(public_path('uploads/*')))
            ->merge(File::glob(public_path('uploads/**/*')))
            ->every(fn ($path) => ! str_ends_with($path, '.php'));
        $this->assertTrue($noPhpInUploads, 'Không được tồn tại file .php dưới public/uploads');

        // File ảnh thật vẫn lưu được (đuôi suy từ nội dung: jpeg/jpg; folder rỗng -> uploads/YYYY/MM)
        $normal = UploadedFile::fake()->image('anh-dep.jpg');
        $this->actingAs($admin)->post(route('media.upload'), ['files' => [$normal], 'folder' => ''])->assertRedirect();
        $uploadedTree = collect(File::allFiles(public_path('uploads')))
            ->map(fn ($f) => $f->getPathname());
        $this->assertTrue(
            $uploadedTree->contains(fn ($p) => preg_match('/\.(jpe?g)$/i', $p)),
            'File ảnh hợp lệ phải được lưu'
        );

        // Unit: helper đo theo NỘI DUNG — mã PHP thật được ngụy trang thành .jpg phải trả null
        $phpDisguised = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'disguise'), 'hinh-anh.jpg', 'image/jpeg', null, true
        );
        file_put_contents($phpDisguised->getPathname(), '<?php echo "pwn"; ');
        $this->assertNull(MediaManagerService::safeExtension($phpDisguised), 'safeExtension phải trả null cho mã PHP ngụy trang .jpg');

        // GIF named .php: đuôi client bị bỏ, lưu dưới .gif an toàn
        $this->assertSame('gif', MediaManagerService::safeExtension($polyglot));
    }

    /**
     * P0-1b: Đính kèm ticket không bao giờ được lưu với đuôi thực thi của client.
     */
    public function test_ticket_attachment_stores_content_based_extension(): void
    {
        $user = $this->plainUser(); // mọi role đều được tạo ticket

        $gifContent = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $polyglot = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'ticket'), 'exploit.php', 'image/gif', null, true
        );
        file_put_contents($polyglot->getPathname(), $gifContent);

        $response = $this->actingAs($user)->post(route('tickets.store'), [
            'title' => 'Ticket đính kèm polyglot',
            'category' => 'technical_issue',
            'priority' => 'low',
            'description' => 'Kiểm tra upload an toàn',
            'attachments' => [$polyglot],
        ]);

        // Hai lớp chặn: validation mimes: từ chối; nếu lọt thì handleUploadedFiles bỏ file
        $this->assertSame(0, SupportTicket::count(), 'Ticket kèm file .php phải bị từ chối cả request');
        $this->assertTrue(
            collect(File::glob(public_path('uploads/tickets/*')))->every(fn ($p) => ! str_ends_with($p, '.php')),
            'Không được tồn tại file .php trong thư mục đính kèm'
        );

        // File đính kèm hợp lệ (PNG thật) vẫn hoạt động bình thường
        File::ensureDirectoryExists(public_path('uploads/tickets'));
        $good = UploadedFile::fake()->image('minh-chung.png');
        $this->actingAs($user)->post(route('tickets.store'), [
            'title' => 'Ticket đính kèm hợp lệ',
            'category' => 'technical_issue',
            'priority' => 'low',
            'description' => 'Nội dung mô tả hợp lệ',
            'attachments' => [$good],
        ])->assertRedirect();

        $ticket = SupportTicket::firstOrFail();
        $paths = (array) ($ticket->attachment_path ?? []);
        $this->assertNotEmpty($paths, 'Đính kèm hợp lệ phải được lưu');
        foreach ($paths as $path) {
            $this->assertDoesNotMatchRegularExpression('/\.php$/i', $path);
        }
    }

    /**
     * P0-2: Các nhóm route quản trị phải chặn user thường (student/teacher).
     */
    public function test_admin_route_groups_reject_plain_users(): void
    {
        $student = $this->plainUser();

        $checks = [
            ['get', route('recruitment.index')],
            ['post', route('recruitment.jobs.store')],
            ['get', route('courses.index')],
            ['post', route('courses.store')],
            ['get', route('course-levels.index')],
            ['post', route('course-levels.store')],
            ['get', route('branches.index')],
            ['post', route('branches.store')],
            ['get', route('system-categories.index')],
            ['post', route('system-categories.store')],
            ['get', route('merchandise.index')],
            ['post', route('merchandise.store')],
            ['get', route('holidays.index')],
            ['post', route('holidays.store')],
            ['get', route('activity-logs.index')],
        ];

        foreach ($checks as [$method, $url]) {
            $this->actingAs($student)->{$method}($url, [])->assertForbidden("{$method} {$url} phải chặn user thường");
        }

        // Admin vẫn vào được
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('courses.index'))->assertOk();
        $this->actingAs($admin)->get(route('branches.index'))->assertOk();
        $this->actingAs($admin)->get(route('activity-logs.index'))->assertOk();
        $this->actingAs($admin)->get(route('recruitment.index'))->assertOk();
    }

    /**
     * P0-3: destroyFiltered không filter -> từ chối, không xóa gì.
     */
    public function test_destroy_filtered_requires_at_least_one_filter(): void
    {
        $admin = $this->admin();
        File::ensureDirectoryExists(public_path('uploads'));
        $sentinel = public_path('uploads/p0-sentinel-'.Str::random(5).'.txt');
        file_put_contents($sentinel, 'keep me');

        try {
            $response = $this->actingAs($admin)
                ->delete(route('media.destroy-filtered'), ['type' => 'all', 'directory' => 'all']);
            $response->assertRedirect();

            $this->assertFileExists($sentinel, 'Không filter tuyệt đối không được xóa file nào');
        } finally {
            @unlink($sentinel);
        }
    }

    /**
     * P0-4: Dashboard nhật ký sự vụ không còn 500 (relation user không tồn tại).
     */
    public function test_incidents_dashboard_renders_without_error(): void
    {
        SupportTicket::create([
            'code' => 'TK-P0-01',
            'title' => 'Ticket khẩn cấp test',
            'category' => 'technical_issue',
            'priority' => 'urgent',
            'description' => 'Nội dung',
            'creator_id' => $this->admin()->id,
            'status' => 'open',
        ]);

        $this->actingAs($this->admin())
            ->get(route('academic.dashboards.incidents'))
            ->assertOk();
    }
}
