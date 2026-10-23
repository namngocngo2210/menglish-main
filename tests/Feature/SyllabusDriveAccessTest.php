<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusDriveGrant;
use App\Models\SyllabusLesson;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Tự mở / đóng quyền xem file Google Drive theo chặng lớp đang học (docs/kho-syllabus.md §7.4, Sheet 4 câu 12). */
class SyllabusDriveAccessTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private const FILE_PPT = '1PptFileAAAAAAAAAAAAAAAAAAAA';

    private const FILE_TEST = '1TestFileBBBBBBBBBBBBBBBBBBB';

    /** @var array<string, list<array{id: string, emailAddress: string, role: string}>> quyền hiện có theo file */
    private array $permissions = [];

    private ?string $failFor = null;

    private ?string $restrictFailFor = null;

    private ?string $timeoutFor = null;

    private bool $tokenError = false;

    private int $nextPermission = 1;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        Cache::flush();
        $this->assignment = $this->openStage($this->stage1, ['confirmed_at' => now()]);
        $lessons = SyllabusLesson::where('curriculum_id', $this->curriculum->id)->orderBy('session_no')->get();
        $lessons[2]->update(['ppt_url' => 'https://docs.google.com/presentation/d/'.self::FILE_PPT.'/edit?usp=drive_link', 'quizizz_url' => 'https://quizizz.com/admin/quiz/abc']);
        $lessons[3]->update(['test_url' => 'https://drive.google.com/file/d/'.self::FILE_TEST.'/view']);
    }

    public function test_not_configured_does_nothing(): void
    {
        config(['services.google_drive.key_path' => null]);
        Http::fake();

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, SyllabusDriveGrant::count());
    }

    public function test_open_stage_grants_reader_to_teacher_and_foreign_teacher_and_restricts_copy(): void
    {
        $this->fakeDrive();

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $grants = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/permissions'));
        $this->assertCount(4, $grants);
        foreach ($grants as [$request]) {
            $this->assertSame(['type' => 'user', 'role' => 'reader'], array_intersect_key($request->data(), ['type' => 1, 'role' => 1]));
            $this->assertStringContainsString('sendNotificationEmail=false', $request->url());
        }
        $this->assertEqualsCanonicalizing([$this->teacher->email, $this->teacher->email, $this->foreignTeacher->email, $this->foreignTeacher->email],
            array_values(array_map(fn ($pair) => $pair[0]->data()['emailAddress'], $grants->all())));
        $restricted = Http::recorded(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertCount(2, $restricted);
        $this->assertTrue($restricted->every(fn ($pair) => $pair[0]->data() === ['copyRequiresWriterPermission' => true]));
        $this->assertSame(4, SyllabusDriveGrant::where('status', 'granted')->count());

        // Chạy lại không gọi thêm API.
        $before = count(Http::recorded());
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();
        $this->assertCount($before, Http::recorded());
    }

    public function test_closing_stage_revokes_unless_still_needed_by_other_open_stage(): void
    {
        $this->fakeDrive();
        $other = ClassModel::create(['code' => 'QT-02', 'name' => 'Lớp QT 02', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        SyllabusAssignment::create(['class_id' => $other->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id, 'stage_name' => $this->stage1->label,
            'assigned_chapters' => $this->stage1->label, 'user_id' => $this->teacher->id, 'status' => SyllabusAssignment::STATUS_OPEN, 'opened_at' => now()]);
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => now()]);
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $deleted = Http::recorded(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertCount(2, $deleted, 'Chỉ thu hồi quyền của GVNN (GV chính vẫn cần cho lớp QT 02).');
        $this->assertSame(2, SyllabusDriveGrant::where('email', $this->foreignTeacher->email)->where('status', 'revoked')->count());
        $this->assertSame(2, SyllabusDriveGrant::where('email', $this->teacher->email)->where('status', 'granted')->count());
    }

    public function test_pre_existing_permission_is_never_revoked(): void
    {
        $this->fakeDrive();
        $this->permissions[self::FILE_PPT] = [['id' => 'perm-old', 'emailAddress' => strtoupper($this->teacher->email), 'role' => 'writer']];

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $row = SyllabusDriveGrant::where('email', $this->teacher->email)->where('file_id', self::FILE_PPT)->sole();
        $this->assertTrue($row->pre_existing);
        $this->assertSame(3, Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/permissions'))->count());

        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => now()]);
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assertFalse(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'perm-old'))->isNotEmpty());
        $this->assertSame(0, SyllabusDriveGrant::where('file_id', self::FILE_PPT)->where('email', $this->teacher->email)->count());
    }

    public function test_failure_is_recorded_and_retried_up_to_five_times(): void
    {
        $this->fakeDrive();
        $this->failFor = self::FILE_TEST;

        foreach (range(1, 7) as $run) {
            $this->artisan('syllabus:sync-drive-access')->assertSuccessful();
        }

        $failed = SyllabusDriveGrant::where('file_id', self::FILE_TEST)->get();
        $this->assertCount(2, $failed);
        $this->assertTrue($failed->every(fn ($g) => $g->status === 'failed' && $g->attempts === 5 && str_contains($g->error, 'không đủ quyền')));
        $this->assertSame(10, Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), self::FILE_TEST))->count());
        $this->assertSame(1, AdminNotification::where('user_id', $this->lead->id)->count(), 'Báo Học thuật 1 lần (gộp các quyền lỗi của lượt chạy).');
    }

    public function test_class_tab_shows_grant_status_and_lead_can_resync(): void
    {
        $this->fakeDrive();
        $this->failFor = self::FILE_TEST;
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->actingAs($this->lead)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'syllabus']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('syllabus.drive.configured', true)
                ->has('syllabus.drive.rows', 4)
                ->where('syllabus.drive.rows', fn ($rows) => collect($rows)->where('status', 'granted')->count() === 2
                    && collect($rows)->where('status', 'failed')->count() === 2)
                ->where('syllabus.drive.sync_url', route('classes.syllabus.drive-sync', $this->class->id))
                ->etc());

        $this->failFor = null;
        $this->actingAs($this->staff)->post(route('classes.syllabus.drive-sync', $this->class->id))->assertForbidden();
        $this->actingAs($this->lead)->post(route('classes.syllabus.drive-sync', $this->class->id))->assertRedirect();
        $this->assertSame(4, SyllabusDriveGrant::where('status', 'granted')->count());
    }

    public function test_restrict_copy_failure_keeps_system_grant_revocable(): void
    {
        $this->fakeDrive();
        $this->restrictFailFor = self::FILE_PPT;

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $rows = SyllabusDriveGrant::where('file_id', self::FILE_PPT)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($g) => $g->status === 'granted' && ! $g->pre_existing && $g->permission_id && str_contains((string) $g->error, 'chặn tải')));

        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => now()]);
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assertSame(4, Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->count(), 'Quyền hệ thống tạo luôn được thu hồi.');
    }

    public function test_failed_grant_that_created_a_permission_is_revoked_when_stage_closes(): void
    {
        $this->fakeDrive();
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();
        SyllabusDriveGrant::query()->update(['status' => 'failed', 'error' => 'lỗi cũ']);

        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => now()]);
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assertSame(4, Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->count());
    }

    public function test_connection_timeout_is_recorded_and_does_not_block_revocations(): void
    {
        $this->fakeDrive();
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();
        $slow = '1SlowFileCCCCCCCCCCCCCCCCCCCC';
        SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 7)->update(['ppt_url' => "https://drive.google.com/file/d/{$slow}/view"]);
        $other = ClassModel::create(['code' => 'QT-03', 'name' => 'Lớp QT 03', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        SyllabusAssignment::create(['class_id' => $other->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage2->id, 'stage_name' => $this->stage2->label,
            'assigned_chapters' => $this->stage2->label, 'user_id' => $this->teacher->id, 'status' => SyllabusAssignment::STATUS_OPEN, 'opened_at' => now()]);
        $this->timeoutFor = $slow;
        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED, 'closed_at' => now()]);

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assertSame(4, Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->count());
        $row = SyllabusDriveGrant::where('file_id', $slow)->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Không kết nối được Google Drive', $row->error);
    }

    public function test_token_failure_aborts_run_with_single_notification(): void
    {
        $this->fakeDrive();
        $this->tokenError = true;

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();
        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'googleapis.com/drive'))->count());
        $this->assertSame(0, SyllabusDriveGrant::count());
        $notification = AdminNotification::where('user_id', $this->lead->id)->sole();
        $this->assertStringContainsString('Không kết nối được Google Drive', $notification->title);
    }

    public function test_many_failures_in_one_run_send_one_grouped_notification(): void
    {
        $this->fakeDrive();
        $this->failFor = self::FILE_TEST;
        $this->restrictFailFor = null;
        SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 5)->update(['ppt_url' => 'https://drive.google.com/file/d/'.self::FILE_TEST.'/view?x=1']);

        $this->artisan('syllabus:sync-drive-access')->assertSuccessful();

        $this->assertSame(1, AdminNotification::where('user_id', $this->lead->id)->count());
        $this->assertStringContainsString('2 quyền', AdminNotification::where('user_id', $this->lead->id)->sole()->title);
    }

    public function test_sync_command_overlap_lock_expires_quickly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'syllabus:sync-drive-access'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    /** Khóa service account sinh trong test + Drive API giả lập (quyền theo file, lỗi 403 cho $failFor). */
    private function fakeDrive(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($path, json_encode(['type' => 'service_account', 'client_email' => 'syllabus@demo.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.google_drive.key_path' => $path]);

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token')) {
                return $this->tokenError
                    ? Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
                    : Http::response(['access_token' => 'token-demo', 'expires_in' => 3600]);
            }
            preg_match('#/files/([^/?]+)(/permissions(?:/([^/?]+))?)?#', $request->url(), $m);
            $file = $m[1] ?? '';
            if ($file === $this->timeoutFor) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            if ($file === $this->restrictFailFor && $request->method() === 'PATCH') {
                return Http::response(['error' => ['code' => 403, 'message' => 'Không đổi được cài đặt file.']], 403);
            }
            if ($file === $this->failFor && $request->method() !== 'DELETE') {
                return Http::response(['error' => ['code' => 403, 'message' => 'Service account không đủ quyền trên file.']], 403);
            }

            return match ($request->method()) {
                'GET' => Http::response(['permissions' => $this->permissions[$file] ?? []]),
                'POST' => Http::response(['id' => 'perm-'.$this->nextPermission++]),
                'PATCH' => Http::response(['id' => $file]),
                'DELETE' => Http::response(null, 204),
            };
        });
    }
}
