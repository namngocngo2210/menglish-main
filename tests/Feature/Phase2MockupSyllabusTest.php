<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2 — đối chiếu mockup nhóm Giáo trình & Big Test (roundcuoi-kieulien/01_Web_Admin/01..08,
 * 03_Cong_Giao_Vien/07..14) theo mô hình chặng Q4 (A6). Mỗi test khẳng định các phần tử chính của một màn.
 */
class Phase2MockupSyllabusTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    private User $teacher;

    private User $assistant;

    private ClassModel $class;

    private SyllabusCurriculum $curriculum;

    /** @var array<int, SyllabusStage> */
    private array $stages;

    /** @var array<int, SyllabusLesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.zalo.mode' => 'sandbox']);

        $this->branch = Branch::create(['name' => 'Cơ sở Mockup', 'code' => 'MK', 'is_active' => true]);
        $this->academic = User::factory()->create(['name' => 'Học Thuật Mockup', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->academic->assignRole('academic_lead');
        $this->teacher = User::factory()->create(['name' => 'GV Mockup', 'employee_code' => 'GV-MK-01', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->teacher->assignRole('teacher');
        $this->assistant = User::factory()->create(['name' => 'TG Mockup', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->assistant->assignRole('assistant');

        $this->curriculum = SyllabusCurriculum::create(['code' => 'CUR-MK', 'title' => 'Starter Mockup', 'version' => 'v1', 'stage_name' => 'Chặng 1: Nền tảng']);
        $this->stages = [
            $this->curriculum->stages()->firstOrFail(),
            SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 2, 'name' => 'Chặng 2: Giao tiếp cơ bản', 'description' => 'Thực hành giao tiếp hằng ngày']),
        ];
        $session = 1;
        foreach ($this->stages as $i => $stage) {
            $unit = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $stage->id, 'unit_number' => $i + 1, 'title' => 'Greetings '.($i + 1)]);
            foreach (range(1, 2) as $n) {
                $this->lessons[] = SyllabusLesson::create([
                    'unit_id' => $unit->id, 'session_no' => $session, 'title' => "Buổi mẫu {$session}",
                    'objectives' => "Mục tiêu {$session}", 'content' => "Hoạt động đóng vai {$session}", 'homework_guide' => "BTVN {$session}",
                ]);
                $session++;
            }
        }

        CourseLevel::create(['code' => 'MKL', 'name' => 'Starter', 'target' => 'A1', 'syllabus_curriculum_id' => $this->curriculum->id, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'MK-01', 'name' => 'Lớp Mockup 01', 'branch_id' => $this->branch->id, 'level' => 'MKL', 'room' => 'Phòng 402',
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id, 'status' => 'active',
        ]);
    }

    private function openStage(): SyllabusAssignment
    {
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id])
            ->assertSessionHasNoErrors();

        return SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
    }

    private function student(string $code): Student
    {
        return Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '0912345678', 'current_class_id' => $this->class->id, 'status' => 'studying']);
    }

    // ---- 01_Web_Admin/02 — Soạn syllabus theo chặng ----

    public function test_builder_matches_mockup_stage_info_and_lesson_cards(): void
    {
        $url = route('syllabus.builder', ['curriculum' => $this->curriculum->id]);

        $this->actingAs($this->academic)->get($url)->assertOk()
            ->assertSee('Soạn syllabus theo chặng')
            ->assertSee('Thiết lập cấu trúc chương trình học và nội dung chi tiết từng buổi')
            ->assertSee('Tổng số: 02 buổi')
            ->assertSee('Thêm buổi học mới vào chặng')
            ->assertSee('Hoạt động đóng vai 1')
            ->assertDontSee('tự động lưu');

        // Ô soạn chặng: thông tin chung, chính sách mở khóa theo A6, xem thử link tổng quan, thanh Lưu chặng học
        $this->actingAs($this->academic)->get($url.'&edit_stage='.$this->stages[1]->id)->assertOk()
            ->assertSee('Thông tin chung chặng học')
            ->assertSee('Chính sách mở khóa')
            ->assertSee('Hoàn thành Big Test chặng trước')
            ->assertSee('Xem thử')
            ->assertSee('Khu vực hiển thị preview ảnh mục lục tổng quan')
            ->assertSee('Lưu chặng học');

        // Ô soạn buổi: Mục tiêu (Target) / Nội dung bài học chính / Bài tập về nhà (Homework), lưu được nội dung chính
        $this->actingAs($this->academic)->get($url.'&edit_lesson='.$this->lessons[0]->id)->assertOk()
            ->assertSee('Mục tiêu buổi học (Target)')
            ->assertSee('Nội dung bài học chính')
            ->assertSee('Bài tập về nhà (Homework)');

        $this->actingAs($this->academic)->put(route('syllabus.lessons.update', $this->lessons[0]->id), [
            'session_no' => 1, 'title' => 'Introduction', 'objectives' => 'Tự giới thiệu', 'content' => 'Thực hành theo cặp', 'homework_guide' => 'Viết 5 câu',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Thực hành theo cặp', $this->lessons[0]->fresh()->content);

        // Giáo viên xem được nhưng không có nút soạn
        $this->actingAs($this->teacher)->get($url)->assertOk()->assertDontSee('Thêm buổi học mới vào chặng');
    }

    // ---- 01_Web_Admin/01 — Quản lý tài liệu giáo trình; 03_Cong_Giao_Vien/08 — Xem tài liệu giáo trình ----

    public function test_documents_pick_real_stage_and_teacher_view_has_mockup_tabs(): void
    {
        Storage::fake('local');
        $pdf = UploadedFile::fake()->createWithContent('starter.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n".str_repeat('x', 2048));

        $this->actingAs($this->academic)->get(route('syllabus.documents'))->assertOk()
            ->assertSee('Quản lý tài liệu giáo trình')
            ->assertSee('Chọn chặng học')
            ->assertSee('Chọn đối tượng xem')
            ->assertSee('Học vụ')
            ->assertSee('Kéo thả file vào đây hoặc')
            ->assertSee('Chọn file từ máy tính')
            ->assertSee('Khóa tải xuống — Giáo viên chỉ được phép xem trực tuyến để bảo vệ tài liệu.');

        // Chặng phải thuộc giáo trình đã chọn
        $other = SyllabusCurriculum::create(['code' => 'CUR-OT', 'title' => 'Khác', 'version' => 'v1']);
        $this->actingAs($this->academic)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $other->id, 'stage_id' => $this->stages[1]->id, 'title' => 'Sai chặng', 'file' => $pdf,
        ])->assertSessionHasErrors('stage_id');

        $this->actingAs($this->academic)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stages[1]->id, 'title' => 'Giáo trình Starter - Bài 3',
            'file' => $pdf, 'visible_to_teachers' => '1',
        ])->assertSessionHasNoErrors();
        $doc = SyllabusDocument::firstOrFail();
        $this->assertSame($this->stages[1]->id, $doc->stage_id);
        $this->assertSame('Chặng 2: Giao tiếp cơ bản', $doc->stage_name);

        $this->actingAs($this->academic)->get(route('syllabus.documents'))->assertOk()
            ->assertSee('Danh sách tài liệu đã tải lên')->assertSee('Trạng thái')->assertSee('Chỉ xem online')
            ->assertSee('Giáo trình Starter - Bài 3');

        // Màn GV: tab, nhóm theo chặng, watermark bảo mật, đánh dấu đã xem, tìm kiếm
        $this->openStage();
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view'))->assertOk()
            ->assertSee('Xem tài liệu giáo trình')
            ->assertSee('Tổng quan syllabus')
            ->assertSee('Nội dung buổi học')
            ->assertSee('Starter Mockup · Chặng 2: Giao tiếp cơ bản')
            ->assertSee('MENGLISH INTERNAL ONLY')
            ->assertSee($this->teacher->email)
            ->assertSee('Xem mục lục chặng')
            ->assertSee('Thực hành giao tiếp hằng ngày')
            ->assertSee('Hoạt động:')
            ->assertSee('Hoạt động đóng vai 1')
            ->assertSee('Đánh dấu đã xem');

        $this->actingAs($this->teacher)->post(route('syllabus.documents.viewed', $doc->id))->assertRedirect();
        $this->assertDatabaseHas('syllabus_document_views', ['document_id' => $doc->id, 'user_id' => $this->teacher->id]);
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view'))->assertOk()->assertDontSee('Đánh dấu đã xem');

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view', ['q' => 'không-có']))->assertOk()
            ->assertSee('Không tìm thấy tài liệu phù hợp');

        // Trợ giảng không được chia sẻ → không đánh dấu được
        $this->actingAs($this->assistant)->post(route('syllabus.documents.viewed', $doc->id))->assertNotFound();
    }

    // ---- 03_Cong_Giao_Vien/09 — Đề xuất sửa giáo trình; 01_Web_Admin/05 — Chi tiết đề xuất ----

    public function test_proposal_targets_lesson_and_detail_matches_mockup(): void
    {
        $lesson = $this->lessons[2];

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()
            ->assertSee('Chọn giáo trình')
            ->assertSee('Chọn buổi học (Tùy chọn)')
            ->assertSee('Mô tả thay đổi đề xuất')
            ->assertSee('Gửi đề xuất')
            ->assertSee('Lịch sử đề xuất')
            ->assertSee('Lọc: tất cả trạng thái');

        // Buổi phải thuộc giáo trình
        $other = SyllabusCurriculum::create(['code' => 'CUR-OT2', 'title' => 'Khác', 'version' => 'v1']);
        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $other->id, 'lesson_id' => $lesson->id, 'new_content' => 'x',
        ])->assertSessionHasErrors('lesson_id');

        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $this->curriculum->id, 'lesson_id' => $lesson->id,
            'old_content' => 'Viết 150 từ', 'new_content' => 'Viết tối thiểu 250 từ', 'reason' => 'Chuẩn đề thi thật',
        ])->assertSessionHasNoErrors();
        $proposal = SyllabusChangeProposal::firstOrFail();
        $this->assertSame($lesson->id, $proposal->lesson_id);
        $this->assertSame($lesson->unit_id, $proposal->unit_id);

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()
            ->assertSee('Giáo trình / Buổi')->assertSee('Buổi 3')->assertSee('Viết tối thiểu 250 từ');
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose', ['status' => 'rejected']))->assertOk()
            ->assertDontSee('Viết tối thiểu 250 từ');

        $detail = route('syllabus.versions', ['proposal' => $proposal->id]);
        $this->actingAs($this->academic)->get($detail)->assertOk()
            ->assertSee('Chi tiết đề xuất')
            ->assertSee('Thông tin chung')
            ->assertSee('Buổi học/Unit cần sửa')
            ->assertSee('Buổi 3: Buổi mẫu 3 (Unit 2)')
            ->assertSee('Người đề xuất')
            ->assertSee('Giáo viên Giảng dạy (Teacher)')
            ->assertSee('Nội dung thay đổi chi tiết')
            ->assertSee('Chưa phân công')
            ->assertSee('Phản hồi từ người duyệt')
            ->assertSee('Lịch sử xử lý')
            ->assertSee('Đang chờ xử lý')
            ->assertSee(route('syllabus.proposals.reject', $proposal->id), false);

        // Một ô phản hồi dùng chung: từ chối bắt buộc có phản hồi
        $this->actingAs($this->academic)->post(route('syllabus.proposals.reject', $proposal->id), ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->actingAs($this->academic)->post(route('syllabus.proposals.approve', $proposal->id), ['review_note' => 'Đồng ý'])->assertSessionHasNoErrors();

        // Chưa tự áp nội dung (chưa có quyết định BA): nhắc Học thuật mở buổi để cập nhật tay
        $this->actingAs($this->academic)->get($detail)->assertOk()
            ->assertSee('Học Thuật Mockup')
            ->assertSee('Mở buổi để cập nhật')
            ->assertSee('edit_lesson='.$lesson->id, false);
        $this->assertNotSame('Viết tối thiểu 250 từ', $lesson->fresh()->content);
    }

    // ---- 01_Web_Admin/03 — Giao chặng học cho giáo viên ----

    public function test_stage_assignment_screen_matches_mockup_and_edits_open_stage(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.assignments'))->assertOk()
            ->assertSee('Giao chặng học cho giáo viên')
            ->assertSee('Thiết lập chặng mới')
            ->assertSee('Chọn lớp học')
            ->assertSee('Chọn giáo viên')
            ->assertSee('Chọn chặng học')
            ->assertSee('Ngày bắt đầu')
            ->assertSee('Lưu ý nghiệp vụ (R19):')
            ->assertSee('Xác nhận giao chặng')
            ->assertSee('Lịch sử phân quyền chặng học')
            ->assertSee('Cần hỗ trợ?');

        // Ngày bắt đầu → mốc tính tiến độ của chặng
        $start = now()->subDays(3)->toDateString();
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id, 'start_date' => $start])
            ->assertSessionHasNoErrors();
        $assignment = SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
        $this->assertSame($start, $assignment->opened_at->toDateString());

        $this->actingAs($this->academic)->get(route('syllabus.assignments'))->assertOk()
            ->assertSee('Cơ sở Mockup - Phòng 402')
            ->assertSee('ID: GV-MK-01')
            ->assertSee('Tự động đóng theo sự kiện')
            ->assertSee('Đang hiệu lực')
            ->assertSee('title="Chỉnh sửa"', false);

        // Chỉnh sửa: đổi GV phụ trách, GV mới được báo
        $newTeacher = User::factory()->create(['is_active' => true]);
        $newTeacher->assignRole('teacher');
        $this->actingAs($this->teacher)->put(route('syllabus.assignments.update', $assignment->id), ['user_id' => $newTeacher->id])->assertForbidden();
        $this->actingAs($this->academic)->put(route('syllabus.assignments.update', $assignment->id), [
            'user_id' => $newTeacher->id, 'start_date' => $start, 'deadline' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame($newTeacher->id, $assignment->fresh()->user_id);
        $this->assertTrue(\App\Models\AdminNotification::where('user_id', $newTeacher->id)->exists());

        // Lọc trạng thái phía server
        $this->actingAs($this->academic)->get(route('syllabus.assignments', ['status' => 'completed']))->assertOk()
            ->assertSee('0 lượt');
    }

    // ---- 03_Cong_Giao_Vien/14 — Xin điều chỉnh tiến độ; 01_Web_Admin/04 — Duyệt yêu cầu điều chỉnh tiến độ ----

    public function test_progress_adjustment_screens_match_mockups(): void
    {
        // Chưa có chặng mở → trạng thái trống như mockup
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertSee('Xin điều chỉnh tiến độ')
            ->assertSee('Không có chặng học nào đang mở');

        $this->openStage();
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertSee('Gửi yêu cầu')
            ->assertSee('Lớp học / Chặng học')
            ->assertSee('Lớp Mockup 01 - Chặng 1: Nền tảng (MK-01)')
            ->assertSee('Lý do xin điều chỉnh')
            ->assertSee('Số buổi cần thêm')
            ->assertSee('1 buổi')->assertSee('2 buổi')
            ->assertDontSee('Xin thêm 02 buổi phụ đạo Speaking'); // bỏ loại điều chỉnh soạn sẵn

        // Mockup không có "loại điều chỉnh": hệ thống tự đặt theo số buổi
        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'reason' => 'Học sinh chưa nắm vững Speaking', 'extra_sessions' => 2,
        ])->assertSessionHasNoErrors();
        $req = \App\Models\SyllabusAdjustmentRequest::firstOrFail();
        $this->assertSame('Xin giãn tiến độ thêm 2 buổi', $req->request_type);
        $this->assertNotNull($req->syllabus_assignment_id);

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertSee('Danh sách yêu cầu đã gửi')
            ->assertSee('Lớp / Chặng')
            ->assertSee('Số buổi thêm')
            ->assertSee('Lớp Mockup 01 - Chặng 1: Nền tảng');

        // Màn duyệt: thẻ có SLA còn hạn / quá hạn, lớp - chặng, xin thêm N buổi, luồng từ chối có xác nhận
        $this->actingAs($this->academic)->get(route('syllabus.adjustment-requests'))->assertOk()
            ->assertSee('Duyệt yêu cầu xin điều chỉnh tiến độ')
            ->assertSee('Danh sách chờ duyệt')
            ->assertSee('1 Yêu cầu')
            ->assertSee('Còn hạn')
            ->assertSee('Xin thêm:')
            ->assertSee('Chi tiết yêu cầu')
            ->assertSee('Lý do xin giãn tiến độ')
            ->assertSee('Lý do từ chối (Bắt buộc)')
            ->assertSee('Xác nhận từ chối')
            ->assertSee('GV-MK-01');

        $req->forceFill(['created_at' => now()->subHours(\App\Models\SyllabusAdjustmentRequest::SLA_HOURS + 1)])->save();
        $this->actingAs($this->academic)->get(route('syllabus.adjustment-requests'))->assertOk()->assertSee('Quá hạn');

        $this->actingAs($this->academic)->post(route('syllabus.adjustment-requests.reject', $req->id), ['rejection_reason' => 'Chưa đủ căn cứ'])->assertRedirect();
        // Mặc định lọc "chờ duyệt"; "Tất cả" vẫn thấy yêu cầu đã xử lý
        $this->actingAs($this->academic)->get(route('syllabus.adjustment-requests'))->assertOk()->assertSee('0 Yêu cầu');
        $this->actingAs($this->academic)->get(route('syllabus.adjustment-requests', ['status' => 'all']))->assertOk()->assertSee('Chưa đủ căn cứ');
    }

    // ---- 03_Cong_Giao_Vien/07 — Chặng đang dạy & Order Test; 01_Web_Admin/06 — Duyệt & phân phối đề Big Test ----

    public function test_order_uses_open_stage_and_distribution_matches_mockup(): void
    {
        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertSee('Chặng đang dạy &amp; Order Test', false)
            ->assertSee('Chưa được giao chặng nào');

        $assignment = $this->openStage();
        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertSee('Lớp Mockup 01 - MK-01')
            ->assertSee('Chặng 1: Nền tảng')
            ->assertSee('Bắt đầu: '.now()->format('d/m/Y'))
            ->assertSee('Giáo viên chính')
            ->assertSee('Order đề Big Test');
        $this->actingAs($this->assistant)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertSee('Trợ giảng (Chỉ xem)')->assertDontSee('Order đề Big Test');

        // Order gắn chặng đang mở; chặng hiển thị chỉ đọc, không còn ô nhập tên chặng
        $this->actingAs($this->teacher)->get(route('teacher.order-test', $this->class->id))->assertOk()
            ->assertSee('Chặng đang dạy')->assertSee('Chặng 1: Nền tảng')->assertDontSee('name="stage_name"', false);
        $this->actingAs($this->teacher)->post(route('teacher.order-test.submit', $this->class->id), [
            'test_type' => 'big', 'exam_date' => now()->addDays(4)->toDateString(), 'note' => 'Nhờ chuẩn bị đề tập trung Speaking',
        ])->assertSessionHasNoErrors();
        $order = BigTestOrder::firstOrFail();
        $this->assertSame($assignment->stage_id, $order->syllabus_stage_id);
        $this->assertSame('Chặng 1: Nền tảng', $order->stage_name);
        // Không order trùng khi order trước còn chờ duyệt
        $this->actingAs($this->teacher)->post(route('teacher.order-test.submit', $this->class->id), ['test_type' => 'big'])->assertSessionHasErrors('stage');

        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))->assertOk()->assertSee('Đã order - Chờ HT duyệt');

        // Màn duyệt: tìm lớp, cảnh báo SLA (hạn = ngày thi − 3 ngày = ngày mai), class ID, xem trước tệp, ghi chú nghiệp vụ
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.distribution'))->assertOk()
            ->assertSee('Duyệt &amp; Phân phối đề Big Test', false)
            ->assertSee('Yêu cầu chờ duyệt')
            ->assertSee('Tìm tên lớp...')
            ->assertSee('Cảnh báo SLA')
            ->assertSee('CLASS ID: MK-01')
            ->assertSee('Yêu cầu từ Giáo viên')
            ->assertSee('Nhờ chuẩn bị đề tập trung Speaking')
            ->assertSee('Link đề Big Test (Folder lớp)')
            ->assertSee('Xem trước tệp')
            ->assertSee('GV chỉ được quyền xem phần Speaking của đề sau khi phân phối.')
            ->assertSee('Từ chối yêu cầu')
            ->assertSee('Phê duyệt &amp; Phân phối', false);
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.distribution', ['order_search' => 'Không có lớp này']))->assertOk()
            ->assertSee('Không có order đề nào');

        // Gắn chặng cho một đợt Big Test đã tạo (đợt thi cũ chưa gắn chặng)
        $test = BigTest::create([
            'code' => 'BT-MK-OLD', 'title' => 'Big Test cũ', 'class_id' => $this->class->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->addDays(4), 'room' => 'Lab', 'is_distributed' => true, 'status' => 'distributed',
        ]);
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.distribution'))->assertOk()
            ->assertSee('Chưa gắn chặng')->assertSee('Gắn chặng');
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.stage', $test->id), ['syllabus_stage_id' => $this->stages[0]->id])->assertForbidden();
        $foreign = SyllabusCurriculum::create(['code' => 'CUR-FX', 'title' => 'Khác', 'version' => 'v1']);
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.stage', $test->id), ['syllabus_stage_id' => $foreign->stages()->first()->id])
            ->assertSessionHasErrors('syllabus_stage_id');
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.stage', $test->id), ['syllabus_stage_id' => $this->stages[0]->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->stages[0]->id, $test->fresh()->syllabus_stage_id);

        // Duyệt order gắn vào đợt thi
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.approve', $order->id), ['test_link' => 'https://drive.example.com/de', 'big_test_id' => $test->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))->assertOk()->assertSee('Đã có đề');
    }

    // ---- 01_Web_Admin/08 — Nhắc lịch Big Test; 03_Cong_Giao_Vien/10–11 — Lịch dự kiến Big Test ----

    public function test_big_test_schedule_reminder_lists_stages_due_within_seven_days(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.schedules'))->assertOk()
            ->assertSee('Nhắc lịch Big Test')
            ->assertSee('Danh sách các chặng học sắp đến hạn thi Big Test (trong vòng 7 ngày) chưa được duyệt đề thi.')
            ->assertSee('Tổng số chặng')
            ->assertSee('Khẩn cấp (1-2 ngày)')
            ->assertSee('Tất cả đều ổn!');

        $assignment = $this->openStage();

        // GV chính đặt ngày dự kiến; trợ giảng chỉ xem
        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertSee('Ngày dự kiến Big Test:')->assertSee('Chưa đặt lịch')->assertSee('Chọn ngày')->assertSee('Tháng '.now()->month.', '.now()->year);
        $this->actingAs($this->assistant)->get(route('syllabus.teaching-stages'))->assertOk()->assertDontSee('Chọn ngày');
        $this->actingAs($this->assistant)->post(route('syllabus.assignments.expected-date', $assignment->id), ['expected_big_test_date' => now()->addDays(2)->toDateString()])
            ->assertForbidden();
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.expected-date', $assignment->id), ['expected_big_test_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('expected_big_test_date');
        $this->actingAs($this->teacher)->post(route('syllabus.assignments.expected-date', $assignment->id), ['expected_big_test_date' => now()->addDays(2)->toDateString()])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertSee(now()->addDays(2)->format('d/m/Y'))->assertSee('Sửa ngày')->assertSee('Cập nhật');

        // Màn nhắc lịch: chặng sắp thi (2 ngày → khẩn cấp), chưa order đề
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.schedules'))->assertOk()
            ->assertSee($assignment->fresh()->code)
            ->assertSee('Lớp Mockup 01')
            ->assertSee(now()->addDays(2)->format('d/m/Y'))
            ->assertSee('Chưa order đề')
            ->assertDontSee('Tất cả đều ổn!');

        // Đề đã duyệt → không còn trong danh sách nhắc
        BigTestOrder::create([
            'code' => 'ORDTEST-MK', 'class_id' => $this->class->id, 'syllabus_stage_id' => $assignment->stage_id, 'teacher_id' => $this->teacher->id,
            'stage_name' => 'Chặng 1: Nền tảng', 'test_type' => 'big', 'status' => 'approved', 'test_link' => 'https://x.test',
        ]);
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.schedules'))->assertOk()->assertSee('Tất cả đều ổn!');
    }

    // ---- 01_Web_Admin/07 — Duyệt kết quả Big Test & gửi phụ huynh ----

    public function test_result_review_panel_approves_and_sends_one_student_and_closes_stage(): void
    {
        $assignment = $this->openStage();
        $test = BigTest::create([
            'code' => 'BT-MK-1', 'title' => 'Big Test chặng 1', 'class_id' => $this->class->id, 'syllabus_stage_id' => $assignment->stage_id,
            'test_type' => 'midterm', 'scheduled_at' => now()->subDays(4), 'room' => 'Lab', 'is_distributed' => true, 'status' => 'distributed',
        ]);
        $present = $this->student('HV-MK-1');
        $absent = $this->student('HV-MK-2');
        $scored = BigTestResult::create([
            'big_test_id' => $test->id, 'student_id' => $present->id, 'status' => 'pending_review', 'graded_by' => $this->teacher->id,
            'listening_score' => 8.5, 'reading_score' => 7, 'writing_score' => 7.5, 'speaking_score' => 8, 'overall_score' => 7.8,
            'progress_note' => 'Nắm vững kiến thức cơ bản', 'video_url' => 'https://video.example.com/bt-mk-1.mp4',
        ]);
        $away = BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $absent->id, 'status' => 'pending_review', 'is_absent' => true, 'graded_by' => $this->teacher->id]);

        $page = route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $scored->id]);
        $this->actingAs($this->academic)->get($page)->assertOk()
            ->assertSee('Duyệt kết quả Big Test &amp; gửi phụ huynh', false)
            ->assertSee('Quản lý Big Test')
            ->assertSee('Thông tin chung')
            ->assertSee('Mã HV: HV-MK-1')
            ->assertSee('GV: GV Mockup')
            ->assertSee('Big Test - Chặng 1: Nền tảng')
            ->assertSee('Điểm chi tiết')
            ->assertSee('Link video bài thi')
            ->assertSee('https://video.example.com/bt-mk-1.mp4')
            ->assertSee('Nhận xét của giáo viên/HT')
            ->assertSee('Tổng điểm (Big Test)')
            ->assertSee('7.8')
            ->assertSee('Còn 3 ngày')        // hạn trả KQ = ngày thi + 7 ngày
            ->assertSee('Trạng thái dữ liệu')
            ->assertSee('Người gửi kết quả')
            ->assertSee('Người duyệt (Hiện tại)')
            ->assertSee('Duyệt &amp; Gửi phụ huynh', false)
            ->assertSee('Hợp lệ');
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', $test->id))->assertOk()->assertSee('Xem &amp; duyệt', false);

        // Giáo viên không duyệt / gửi được
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.approve-send', $scored->id))->assertForbidden();

        // Học viên vắng thi: chỉ duyệt; học viên có điểm: duyệt + gửi PH → Big Test hoàn tất, chặng đóng và chặng 2 tự mở
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.results.approve-send', $away->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $away->fresh()->status);
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.results.approve-send', $scored->id))->assertSessionHasNoErrors();
        $this->assertSame('sent', $scored->fresh()->status);
        $this->assertTrue($scored->fresh()->parent_notified);
        $this->assertSame(SyllabusAssignment::STATUS_CLOSED, $assignment->fresh()->status);
        $this->assertSame($this->stages[1]->id, SyllabusAssignment::open()->where('class_id', $this->class->id)->value('stage_id'));

        $this->actingAs($this->academic)->get($page)->assertOk()->assertSee('Đã gửi phụ huynh')->assertDontSee('Duyệt &amp; Gửi phụ huynh', false);
    }
}
