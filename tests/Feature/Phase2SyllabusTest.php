<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusUnit;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2 — Giáo trình: soạn syllabus nhiều giáo trình, giao chặng 1 lớp 1 chặng,
 * tài liệu upload thật + phân quyền xem, đề xuất sửa giáo trình, giãn tiến độ tác động TKB,
 * Media Manager không chạm file của module khác.
 */
class Phase2SyllabusTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $teacher;

    private User $otherTeacher;

    private User $assistant;

    private Branch $branch;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở P2', 'code' => 'P2', 'is_active' => true]);
        $this->academic = $this->makeUser('academic_lead');
        $this->teacher = $this->makeUser('teacher');
        $this->otherTeacher = $this->makeUser('teacher');
        $this->assistant = $this->makeUser('assistant');

        $course = Course::create(['code' => 'P2-IE', 'name' => 'IELTS P2', 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'P2-01', 'name' => 'Lớp P2-01', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id, 'room' => 'P201', 'status' => 'active',
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id ?? null]);
        $user->assignRole($role);

        return $user;
    }

    private function curriculum(string $code = 'CUR-A', string $title = 'Giáo trình A'): SyllabusCurriculum
    {
        return SyllabusCurriculum::create(['code' => $code, 'title' => $title, 'version' => 'v1.0']);
    }

    // ---- 1. Builder ----

    public function test_builder_can_select_and_edit_any_curriculum_and_manage_lessons(): void
    {
        $first = $this->curriculum('CUR-A', 'Giáo trình A');
        $second = $this->curriculum('CUR-B', 'Giáo trình B');
        SyllabusUnit::create(['curriculum_id' => $first->id, 'unit_number' => 1, 'title' => 'Bài của A']);

        $page = $this->actingAs($this->academic)->get(route('syllabus.builder', ['curriculum' => $second->id]));
        $page->assertOk()
            ->assertSee('value="'.$second->id.'"', false)
            ->assertDontSee('Bài của A')
            ->assertDontSee('tự động đồng bộ');

        // Tạo bài cho giáo trình thứ hai (không còn bị ép vào giáo trình đầu tiên)
        $this->actingAs($this->academic)->post(route('syllabus.units.store'), [
            'curriculum_id' => $second->id, 'unit_number' => 1, 'title' => 'Bài 1 của B',
        ])->assertRedirect(route('syllabus.builder', ['curriculum' => $second->id]));
        $unit = SyllabusUnit::where('curriculum_id', $second->id)->firstOrFail();

        // Trùng số buổi trong cùng giáo trình bị chặn
        $this->actingAs($this->academic)->post(route('syllabus.units.store'), [
            'curriculum_id' => $second->id, 'unit_number' => 1, 'title' => 'Trùng',
        ])->assertSessionHasErrors('unit_number');

        // Sửa bài
        $this->actingAs($this->academic)->put(route('syllabus.units.update', $unit->id), [
            'unit_number' => 2, 'title' => 'Bài 2 của B (sửa)', 'objectives' => 'Mục tiêu mới',
        ])->assertRedirect();
        $this->assertDatabaseHas('syllabus_units', ['id' => $unit->id, 'unit_number' => 2, 'title' => 'Bài 2 của B (sửa)', 'objectives' => 'Mục tiêu mới']);

        // Lưu thông tin giáo trình; thông tin chặng lưu trên từng chặng (Q4)
        $this->actingAs($this->academic)->put(route('syllabus.curriculums.update', $second->id), [
            'code' => 'CUR-B', 'title' => 'Giáo trình B', 'version' => 'v1.1',
        ])->assertRedirect();
        $this->assertDatabaseHas('syllabus_curriculums', ['id' => $second->id, 'version' => 'v1.1']);
        $stage = $second->stages()->firstOrFail();
        $this->actingAs($this->academic)->put(route('syllabus.stages.update', $stage->id), [
            'name' => 'Chặng 2: Kỹ năng chuyên sâu', 'overview_link' => 'https://example.com/overview.jpg',
            'big_test_title' => 'Big Test chặng 2',
        ])->assertRedirect();
        $this->assertDatabaseHas('syllabus_stages', [
            'id' => $stage->id, 'name' => 'Chặng 2: Kỹ năng chuyên sâu', 'big_test_title' => 'Big Test chặng 2',
        ]);

        // Xóa bài
        $this->actingAs($this->academic)->delete(route('syllabus.units.destroy', $unit->id))->assertRedirect();
        $this->assertDatabaseMissing('syllabus_units', ['id' => $unit->id]);
    }

    public function test_teacher_cannot_edit_syllabus_lessons_directly(): void
    {
        $cur = $this->curriculum();
        $this->actingAs($this->teacher)->post(route('syllabus.units.store'), [
            'curriculum_id' => $cur->id, 'unit_number' => 1, 'title' => 'GV tự sửa',
        ])->assertForbidden();
        $this->actingAs($this->teacher)->put(route('syllabus.curriculums.update', $cur->id), [
            'code' => 'X', 'title' => 'X', 'version' => 'v9',
        ])->assertForbidden();
    }

    // ---- 2. Giao chặng ----

    public function test_class_cannot_have_two_active_stages(): void
    {
        $cur = $this->curriculum();
        $payload = [
            'user_id' => $this->teacher->id, 'curriculum_id' => $cur->id, 'class_id' => $this->class->id,
            'assigned_chapters' => 'Buổi 1 - 10', 'stage_name' => 'Chặng 1', 'deadline' => now()->addWeeks(4)->toDateString(),
        ];
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), $payload)->assertSessionHasNoErrors();

        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['stage_name' => 'Chặng 2'] + $payload)
            ->assertSessionHasErrors('class_id');
        $this->assertSame(1, SyllabusAssignment::where('class_id', $this->class->id)->count());

        // Đóng tay chặng hiện tại (bắt buộc lý do, không mở tiếp) rồi mới mở chặng khác
        $current = SyllabusAssignment::where('class_id', $this->class->id)->firstOrFail();
        $this->actingAs($this->academic)->post(route('syllabus.assignments.close', $current->id))->assertSessionHasErrors('reason');
        $this->actingAs($this->academic)->post(route('syllabus.assignments.close', $current->id), ['reason' => 'Lớp học lại chặng 1'])->assertRedirect();
        $this->assertSame('completed', $current->fresh()->status);

        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['stage_id' => $cur->stages()->value('id')] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SyllabusAssignment::where('class_id', $this->class->id)->where('status', 'in_progress')->count());
    }

    // ---- 3. Tài liệu giáo trình ----

    private function pdf(string $name = 'giao-trinh.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n".str_repeat('x', 2048));
    }

    public function test_document_upload_stores_real_file_type_size_and_visibility(): void
    {
        Storage::fake('local');
        $cur = $this->curriculum();

        $this->actingAs($this->academic)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $cur->id, 'title' => 'Student Book', 'stage_name' => 'Chặng 1',
            'file' => $this->pdf(), 'visible_to_teachers' => '1',
        ])->assertRedirect(route('syllabus.documents'))->assertSessionHasNoErrors();

        $doc = SyllabusDocument::firstOrFail();
        $this->assertSame('pdf', $doc->extension);
        $this->assertGreaterThan(2000, $doc->size_bytes);
        $this->assertTrue($doc->visible_to_teachers);
        $this->assertFalse($doc->visible_to_assistants);
        $this->assertFalse($doc->downloadable);
        $this->assertSame('giao-trinh.pdf', $doc->original_name);
        Storage::disk('local')->assertExists($doc->file_path);

        // Giáo viên xem được (inline) nhưng không tải về được; trợ giảng không thấy
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view'))->assertOk()->assertSee('Student Book');
        $this->actingAs($this->teacher)->get(route('syllabus.documents.file', $doc->id))->assertOk();
        $this->actingAs($this->teacher)->get(route('syllabus.documents.file', ['id' => $doc->id, 'download' => 1]))->assertForbidden();
        $this->actingAs($this->assistant)->get(route('syllabus.teacher-view'))->assertOk()->assertDontSee('Student Book');
        $this->actingAs($this->assistant)->get(route('syllabus.documents.file', $doc->id))->assertNotFound();

        // Học thuật tải về được
        $this->actingAs($this->academic)->get(route('syllabus.documents.file', ['id' => $doc->id, 'download' => 1]))->assertOk();

        // Danh sách hiển thị thông tin thật
        $this->actingAs($this->academic)->get(route('syllabus.documents'))->assertOk()
            ->assertSee('Student Book')->assertSee('Chặng 1')->assertSee('Chỉ xem online');
    }

    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'p2up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_document_upload_rejects_disguised_executable_and_requires_file(): void
    {
        Storage::fake('local');
        $cur = $this->curriculum();

        $this->actingAs($this->academic)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $cur->id, 'title' => 'Thiếu file',
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->academic)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $cur->id, 'title' => 'Shell',
            // File thật (không dùng fake) để đuôi được suy ra từ nội dung: PHP đội tên .pdf
            'file' => $this->realUpload('bai-giang.pdf', "<?php system(\$_GET['c']); ?>"),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, SyllabusDocument::count());
        $this->actingAs($this->teacher)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $cur->id, 'title' => 'GV upload', 'file' => $this->pdf(),
        ])->assertForbidden();
    }

    public function test_document_delete_removes_record_and_file(): void
    {
        Storage::fake('local');
        $cur = $this->curriculum();
        $this->actingAs($this->academic)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $cur->id, 'title' => 'Xóa tôi', 'file' => $this->pdf(),
        ]);
        $doc = SyllabusDocument::firstOrFail();

        $this->actingAs($this->teacher)->delete(route('syllabus.documents.destroy', $doc->id))->assertForbidden();
        $this->actingAs($this->academic)->delete(route('syllabus.documents.destroy', $doc->id))->assertRedirect();

        $this->assertDatabaseMissing('syllabus_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($doc->file_path);
    }

    // ---- 4. Đề xuất sửa giáo trình ----

    public function test_teacher_proposal_is_reviewed_by_academic(): void
    {
        Storage::fake('local');
        $cur = $this->curriculum();
        $unit = SyllabusUnit::create(['curriculum_id' => $cur->id, 'unit_number' => 1, 'title' => 'Writing Task 1']);

        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $cur->id, 'unit_id' => $unit->id, 'proposal_type' => 'Khác',
            'old_content' => '150 từ / 20 phút', 'new_content' => '250 từ / 40 phút', 'reason' => 'Theo format thi thật',
            'attachment' => $this->pdf('mau.pdf'),
        ])->assertRedirect(route('syllabus.teacher-propose'))->assertSessionHasNoErrors();

        $proposal = SyllabusChangeProposal::firstOrFail();
        $this->assertSame('pending', $proposal->status);
        $this->assertSame($this->teacher->id, $proposal->user_id);
        Storage::disk('local')->assertExists($proposal->attachment_path);

        // Giáo viên khác không thấy đề xuất; giáo viên không duyệt được
        $this->actingAs($this->otherTeacher)->get(route('syllabus.versions'))->assertOk()->assertDontSee('250 từ / 40 phút');
        $this->actingAs($this->otherTeacher)->get(route('syllabus.versions', ['proposal' => $proposal->id]))->assertNotFound();
        $this->actingAs($this->otherTeacher)->get(route('syllabus.proposals.attachment', $proposal->id))->assertNotFound();
        $this->actingAs($this->teacher)->post(route('syllabus.proposals.approve', $proposal->id))->assertForbidden();

        // Học thuật xem chi tiết và từ chối bắt buộc lý do
        $this->actingAs($this->academic)->get(route('syllabus.versions', ['proposal' => $proposal->id]))
            ->assertOk()->assertSee('250 từ / 40 phút')->assertSee('150 từ / 20 phút')->assertSee('Writing Task 1');
        $this->actingAs($this->academic)->post(route('syllabus.proposals.reject', $proposal->id))->assertSessionHasErrors('review_note');
        $this->actingAs($this->academic)->post(route('syllabus.proposals.approve', $proposal->id), ['review_note' => 'OK áp dụng từ khóa sau'])->assertRedirect();

        $proposal->refresh();
        $this->assertSame('approved', $proposal->status);
        $this->assertSame($this->academic->id, $proposal->reviewer_id);
        $this->assertNotNull($proposal->reviewed_at);

        // Đã xử lý thì không xử lý lại
        $this->actingAs($this->academic)->post(route('syllabus.proposals.reject', $proposal->id), ['review_note' => 'x'])
            ->assertSessionHasErrors('status');

        // Giáo viên thấy trạng thái trong lịch sử của mình
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()->assertSee('Đã duyệt');
    }

    public function test_rejected_proposal_keeps_reason(): void
    {
        $cur = $this->curriculum();
        $proposal = SyllabusChangeProposal::create([
            'curriculum_id' => $cur->id, 'user_id' => $this->teacher->id, 'new_content' => 'Đổi audio', 'status' => 'pending',
        ]);

        $this->actingAs($this->academic)->post(route('syllabus.proposals.reject', $proposal->id), ['review_note' => 'Audio cũ vẫn dùng được'])->assertRedirect();

        $this->assertDatabaseHas('syllabus_change_proposals', ['id' => $proposal->id, 'status' => 'rejected', 'review_note' => 'Audio cũ vẫn dùng được']);
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertSee('Audio cũ vẫn dùng được');
    }

    // ---- 5. Giãn tiến độ ----

    private function scheduleClass(): void
    {
        // Lớp học Thứ 2 & Thứ 4, buổi cuối cùng là Thứ 4 tuần sau
        ClassScheduleConfig::create([
            'class_id' => $this->class->id, 'academic_year' => '2026',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
            'slot2_day' => 'Thứ 4', 'slot2_start' => '18:00', 'slot2_end' => '19:30',
        ]);
        $lastWednesday = now()->startOfWeek()->addWeek()->addDays(2);
        foreach ([$lastWednesday->copy()->subDays(2), $lastWednesday] as $date) {
            ClassSession::create([
                'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date->toDateString(),
                'shift_name' => 'Slot', 'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P201',
                'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
            ]);
        }
        $this->class->update(['end_date' => $lastWednesday->toDateString()]);
    }

    public function test_approving_extension_adds_sessions_after_last_session_skipping_holidays(): void
    {
        $this->scheduleClass();
        $lastWednesday = Carbon::parse($this->class->fresh()->end_date);
        $nextMonday = $lastWednesday->copy()->addDays(5);
        Holiday::create([
            'code' => 'HL-P2', 'name' => 'Nghỉ lễ', 'start_date' => $nextMonday->toDateString(),
            'end_date' => $nextMonday->toDateString(), 'is_system_wide' => true,
        ]);
        // Giãn tiến độ gắn chặng đang mở (lớp chưa mở chặng thì server từ chối).
        app(\App\Services\SyllabusProgressionService::class)->open($this->class, $this->curriculum()->stages()->firstOrFail(), $this->teacher->id, $this->academic);

        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'request_type' => 'Giãn tiến độ 2 buổi', 'reason' => 'Lớp tiếp thu chậm', 'extra_sessions' => 2,
        ])->assertRedirect();
        $req = SyllabusAdjustmentRequest::firstOrFail();
        $this->assertSame(2, $req->extra_sessions);

        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.approve', $req->id))->assertRedirect()->assertSessionHasNoErrors();

        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertNotEmpty($req->applied_note);

        $newDates = ClassSession::where('class_id', $this->class->id)->whereDate('date', '>', $lastWednesday->toDateString())
            ->orderBy('date')->pluck('date')->map(fn ($d) => $d->toDateString())->all();
        // Thứ 2 tuần sau là ngày nghỉ → buổi thêm rơi vào Thứ 4 và Thứ 2 kế tiếp
        $this->assertSame([
            $lastWednesday->copy()->addDays(7)->toDateString(),
            $lastWednesday->copy()->addDays(12)->toDateString(),
        ], $newDates);
        $this->assertSame($lastWednesday->copy()->addDays(12)->toDateString(), $this->class->fresh()->end_date->toDateString());
    }

    public function test_extension_is_not_approved_when_class_has_no_schedule(): void
    {
        $req = SyllabusAdjustmentRequest::create([
            'class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'request_type' => 'Giãn',
            'reason' => 'x', 'extra_sessions' => 1, 'status' => 'pending',
        ]);

        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.approve', $req->id))->assertSessionHasErrors('extra_sessions');
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertSame(0, ClassSession::where('class_id', $this->class->id)->count());
    }

    public function test_rejecting_extension_requires_and_saves_reason(): void
    {
        $req = SyllabusAdjustmentRequest::create([
            'class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'request_type' => 'Giãn',
            'reason' => 'x', 'extra_sessions' => 1, 'status' => 'pending',
        ]);

        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.reject', $req->id), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.reject', $req->id), ['rejection_reason' => 'Chưa đủ căn cứ'])
            ->assertRedirect();

        $this->assertDatabaseHas('syllabus_adjustment_requests', ['id' => $req->id, 'status' => 'rejected', 'rejection_reason' => 'Chưa đủ căn cứ']);
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()->assertSee('Chưa đủ căn cứ');
    }

    public function test_teacher_cannot_request_adjustment_for_other_class(): void
    {
        $this->actingAs($this->otherTeacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'request_type' => 'Giãn', 'reason' => 'x',
        ])->assertForbidden();
    }

    // ---- 6. Media Manager ----

    public function test_media_manager_cannot_touch_other_modules_files(): void
    {
        $admin = $this->makeUser('admin');
        $cv = storage_path('app/public/candidate_cvs/p2-cv-test.pdf');
        $receipt = public_path('uploads/tuition/receipts/p2-receipt-test.png');
        $ticket = public_path('uploads/2026/09/25/p2-ticket-test.png');
        $media = public_path('uploads/media/p2_suite/p2-media-test.png');
        foreach ([$cv, $receipt, $ticket, $media] as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'data');
        }

        try {
            $index = $this->actingAs($admin)->get(route('media.index'));
            $index->assertOk()->assertSee('p2-media-test.png')
                ->assertDontSee('p2-cv-test.pdf')->assertDontSee('p2-receipt-test.png')->assertDontSee('p2-ticket-test.png');

            foreach ([$cv, $receipt, $ticket] as $path) {
                $this->actingAs($admin)->delete(route('media.destroy', base64_encode($path)))->assertSessionHas('error');
                $this->assertFileExists($path);
            }

            $this->actingAs($admin)->delete(route('media.bulk-destroy'), ['selected_files' => [base64_encode($cv), base64_encode($receipt)]]);
            $this->actingAs($admin)->post(route('media.move-files'), ['selected_files' => [base64_encode($cv), base64_encode($ticket)], 'target_folder' => 'p2_suite']);
            $this->assertFileExists($cv);
            $this->assertFileExists($ticket);
            $this->assertFileExists($receipt);

            // Xóa theo bộ lọc chỉ chạm vùng media
            $this->actingAs($admin)->delete(route('media.destroy-filtered', ['search' => 'p2-']))->assertRedirect();
            $this->assertFileDoesNotExist($media);
            $this->assertFileExists($cv);
            $this->assertFileExists($receipt);
            $this->assertFileExists($ticket);
        } finally {
            File::delete([$cv, $receipt, $ticket, $media]);
            File::deleteDirectory(public_path('uploads/media/p2_suite'));
        }
    }
}
