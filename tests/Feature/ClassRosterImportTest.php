<?php

namespace Tests\Feature;

use App\Models\BackgroundTask;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Services\ClassRosterImportService;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Nhập danh sách lớp học viên từ hệ thống cũ: tạo lớp + lịch + buổi học, khớp học viên có sẵn theo họ tên + ngày sinh
 * (không tạo / sửa hồ sơ), xếp lớp; xem trước báo lỗi / cảnh báo từng dòng; chạy lại không nhân đôi.
 */
class ClassRosterImportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Menglish Đội Cấn', 'code' => 'DC', 'is_active' => true]);
        Branch::create(['name' => 'Menglish Ngọc Hà', 'code' => 'NH', 'is_active' => true]);

        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->teacher = User::factory()->create(['name' => 'Nguyễn Thanh Mai', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->teacher->assignRole('teacher_fulltime');
    }

    private function makeStudent(string $name, string $dob, array $extra = []): Student
    {
        return Student::create($extra + ['code' => 'HS-'.Str::upper(Str::random(8)), 'name' => $name, 'dob' => $dob, 'phone' => '0911000000',
            'branch_id' => $this->branch->id, 'status' => 'studying']);
    }

    private function seedRosterStudents(): void
    {
        $this->makeStudent('Trịnh Đức Quang', '2018-10-06', ['phone' => '0385294492']);
        $this->makeStudent('Nguyễn Minh Châu', '2017-05-07');
        $this->makeStudent('Không Có Số', '2016-02-01', ['phone' => '']);
        $this->makeStudent('Học Viên 5M2', '2015-04-03');
    }

    /** @param  array<string, list<list<mixed>>>  $sheets  tên sheet => các dòng (dòng đầu là tiêu đề) */
    private function workbook(array $sheets): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'roster').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'danh-sach-lop.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function normalizedFile(): UploadedFile
    {
        return $this->workbook([
            'Lớp' => [
                ['Cơ sở', 'Mã lớp', 'Tên lớp', 'Giáo viên', 'Buổi 1 thứ', 'Buổi 1 bắt đầu', 'Buổi 1 kết thúc', 'Buổi 1 phòng', 'Buổi 2 thứ', 'Buổi 2 bắt đầu', 'Buổi 2 kết thúc', 'Buổi 2 phòng', 'Học phí'],
                ['Đội Cấn', 'CAM37', 'CAM37', 'Thanh Mai', 'Thứ 3', '19:30', '21:00', 'P201', 'Thứ 6', '19:30', '21:00', 'P401', ''],
                ['Đội Cấn', '5M2', '5M2', 'Cô Không Có', '', '', '', '', '', '', '', '', '3.600.000'],
                ['Hoàng Hoa Thám', 'KINDY07', 'KINDY07', 'Trà', 'Thứ 2', '18:00', '19:30', 'P201', 'Thứ 6', '18:00', '19:30', 'P302', ''],
            ],
            'Học viên' => [
                ['Cơ sở', 'Mã lớp', 'Họ tên', 'Ngày sinh', 'Ngày nhập học'],
                ['Đội Cấn', 'CAM37', 'Trịnh Đức Quang', '06/10/2018', '19/06/2026'],
                ['Đội Cấn', 'CAM37', '  NGUYEN  minh chau ', '07/05/2017', '24/06/2025'],
                ['Đội Cấn', '5M2', 'Không Có Số', '01/02/2016', '01/09/2026'],
                ['Đội Cấn', 'XYZ9', 'Lớp Không Có', '01/02/2016', ''],
                ['Đội Cấn', '5M2', 'Học Viên 5M2', '03/04/2015', '01/09/2026'],
                ['', '', '', '', 'Dòng chỉ có ghi chú chuyển lớp'],
            ],
        ]);
    }

    private function previewToken(UploadedFile $file, array $extra = []): string
    {
        $response = $this->actingAs($this->admin)->post(route('classes.import.store'), $extra + [
            'excel_file' => $file,
            'sessions_from' => today()->toDateString(),
            'sessions_to' => today()->addWeeks(4)->toDateString(),
        ]);
        $response->assertSessionHasNoErrors();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? null);

        return $query['token'];
    }

    public function test_preview_reports_errors_and_import_creates_classes_schedule_students(): void
    {
        // Trợ giảng trùng đuôi tên với giáo viên: lớp vẫn gán đúng giáo viên.
        User::factory()->create(['name' => 'Hoàng Thanh Mai', 'branch_id' => $this->branch->id, 'is_active' => true])->assignRole('assistant');
        $this->seedRosterStudents();
        $p201 = $this->makeRoom($this->branch->id, 'p201'); // phòng đã có (khác hoa thường) → dùng lại; P401 chưa có → tạo
        $token = $this->previewToken($this->normalizedFile());

        $this->actingAs($this->admin)->get(route('classes.import', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Classes/Import')
                ->where('preview.classes.0.code', 'CAM37')
                ->where('preview.classes.0.teacher_matched', true)
                ->where('preview.classes.0.warnings', ['Phòng P401 chưa có ở cơ sở: tạo mới khi nhập (loại "Phòng tiêu chuẩn", sửa sức chứa trên màn Phòng học).'])
                ->where('preview.classes.0.student_count', 2)
                ->where('preview.classes.2.errors.0', 'Chưa có cơ sở "Hoàng Hoa Thám" trong hệ thống — tạo cơ sở trước khi nhập.')
                ->where('preview.students.2.errors', [])
                ->where('preview.students.0.action', 'enroll')
                ->where('preview.students.1.name', 'Nguyễn Minh Châu') // khớp không phân biệt hoa thường / dấu / khoảng trắng
                ->where('preview.students.3.errors.0', 'Không có lớp XYZ9: thêm lớp vào sheet Lớp hoặc tạo lớp trước.')
                ->where('preview.students.3.errors.1', 'Không tìm thấy học sinh khớp trong hệ thống')
                ->has('preview.students', 5)); // dòng chỉ có ghi chú bị bỏ

        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $token])->assertRedirect(route('classes.import', ['task' => BackgroundTask::query()->max('id')]));

        $cam = ClassModel::where('code', 'CAM37')->firstOrFail();
        $this->assertSame($this->branch->id, $cam->branch_id);
        $this->assertSame($this->teacher->id, $cam->teacher_id);
        $this->assertSame('active', $cam->status);
        $this->assertSame('p201', $cam->room);
        $this->assertSame($p201->id, $cam->room_id);
        $this->assertSame(1, $this->importReport()['rooms']);
        $p401 = Room::where('branch_id', $this->branch->id)->where('name', 'P401')->firstOrFail();
        $this->assertSame('Phòng tiêu chuẩn', $p401->type->name);
        $config = ClassScheduleConfig::where('class_id', $cam->id)->firstOrFail();
        $this->assertSame(['Thứ 3', '19:30', '21:00', 'Thứ 6'], [$config->slot1_day, $config->slot1_start, $config->slot1_end, $config->slot2_day]);
        $sessions = ClassSession::where('class_id', $cam->id)->orderBy('date')->get();
        $this->assertGreaterThanOrEqual(7, $sessions->count());
        $this->assertTrue($sessions->every(fn (ClassSession $s) => in_array($s->date->isoWeekday(), [2, 5], true) && $s->date->gte(today())));
        $this->assertSame('P401', $sessions->first(fn (ClassSession $s) => $s->date->isoWeekday() === 5)->room);

        $fiveM2 = ClassModel::where('code', '5M2')->firstOrFail();
        $this->assertSame('pending_schedule', $fiveM2->status);
        $this->assertNull($fiveM2->teacher_id);
        $this->assertEquals(3600000, (float) $fiveM2->tuition_fee);
        $this->assertSame(0, ClassSession::where('class_id', $fiveM2->id)->count());
        $this->assertFalse(ClassModel::where('code', 'KINDY07')->exists());

        $quang = Student::where('name', 'Trịnh Đức Quang')->firstOrFail();
        $this->assertSame('0385294492', $quang->phone); // hồ sơ không bị sửa
        $this->assertSame($cam->id, $quang->current_class_id);

        $chau = Student::where('name', 'Nguyễn Minh Châu')->firstOrFail();
        $enrollment = ClassEnrollment::where('student_id', $quang->id)->firstOrFail();
        $this->assertSame(['completed', '2026-06-19'], [$enrollment->status, $enrollment->enrolled_at->toDateString()]);
        $this->assertNotNull($enrollment->confirmed_at);
        $this->assertSame($cam->id, $chau->current_class_id);

        $this->assertSame('', Student::where('name', 'Không Có Số')->value('phone'));
        $this->assertTrue(Student::where('name', 'Học Viên 5M2')->where('current_class_id', $fiveM2->id)->exists());
        $this->assertFalse(Student::where('name', 'Lớp Không Có')->exists());

        // Chạy lại cùng file: không tạo trùng lớp / học viên / lượt xếp lớp / phòng; lớp đã có mà chưa có phòng / giáo viên thì được gắn.
        $cam->update(['room_id' => null, 'teacher_id' => null]);
        ClassSession::where('class_id', $cam->id)->update(['teacher_id' => null]);
        $again = $this->previewToken($this->normalizedFile());
        $linked = collect(session()->get('class_roster_import.'.$again)['classes'])->firstWhere('code', 'CAM37');
        $this->assertTrue($linked['link_room'] && $linked['link_teacher']); // nút xác nhận vẫn bấm được dù không còn lớp / học viên mới
        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $again]);
        $this->assertSame(1, ClassModel::where('code', 'CAM37')->count());
        $this->assertSame(1, ClassScheduleConfig::where('class_id', $cam->id)->count());
        $this->assertSame($sessions->count(), ClassSession::where('class_id', $cam->id)->count());
        $this->assertSame(4, Student::count()); // không tạo thêm học viên
        $this->assertSame(4, ClassEnrollment::count());
        $this->assertSame(2, Room::count());
        $this->assertSame($p201->id, $cam->fresh()->room_id);
        $this->assertSame($this->teacher->id, $cam->fresh()->teacher_id);
        $this->assertSame(1, $this->importReport()['teachers']);
        $this->assertFalse(ClassSession::where('class_id', $cam->id)->whereNull('teacher_id')->exists());
    }

    public function test_existing_student_imported_into_other_class_is_transferred_from_old_main_class(): void
    {
        $kindy8 = ClassModel::create(['code' => 'KINDY8', 'name' => 'Kindy 8', 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 0]);
        $kindy10 = ClassModel::create(['code' => 'KINDY10', 'name' => 'Kindy 10', 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 0]);
        $minhAnh = $this->makeStudent('Nguyễn Minh Anh', '2018-03-09', ['current_class_id' => $kindy8->id]);
        ClassEnrollment::create(['student_id' => $minhAnh->id, 'class_id' => $kindy8->id, 'enrolled_at' => '2026-06-01', 'status' => 'completed']);
        $transferredOn = today()->subDays(2)->format('d/m/Y');
        ClassSession::create([
            'class_id' => $kindy8->id, 'branch_id' => $this->branch->id, 'date' => today()->subDays(4),
            'start_time' => '08:00', 'end_time' => '09:30', 'status' => 'scheduled',
        ]);
        // Buổi của lớp mới sau ngày chuyển không làm khoản đang dùng dở bị coi là đã dùng hết trước khi chuyển.
        ClassSession::create([
            'class_id' => $kindy10->id, 'branch_id' => $this->branch->id, 'date' => today()->subDay(),
            'start_time' => '08:00', 'end_time' => '09:30', 'status' => 'scheduled',
        ]);
        $tuitionData = [
            'student_id' => $minhAnh->id, 'class_id' => $kindy8->id, 'branch_id' => $this->branch->id,
            'session_count' => 1, 'total_amount' => 500000, 'final_amount' => 500000,
            'paid_amount' => 100000, 'debt_amount' => 400000, 'status' => 'partial', 'transfer_memo' => 'Nội dung đã chốt',
        ];
        $usedTuition = StudentTuition::create($tuitionData);
        $unfinishedTuition = StudentTuition::create($tuitionData);
        $unassignedTuition = StudentTuition::create(array_replace($tuitionData, ['class_id' => null]));
        $before = $unfinishedTuition->fresh()->getRawOriginal();

        $token = $this->previewToken($this->workbook([
            'Lớp' => [['Cơ sở', 'Mã lớp', 'Tên lớp'], ['Đội Cấn', 'KINDY10', 'Kindy 10']],
            'Học viên' => [
                ['Cơ sở', 'Mã lớp', 'Họ tên', 'Ngày sinh', 'Ngày nhập học'],
                ['Đội Cấn', 'KINDY10', 'Nguyễn Minh Anh', '09/03/2018', $transferredOn],
            ],
        ]));
        $row = session()->get('class_roster_import.'.$token)['students'][0];
        $this->assertStringContainsString('Học viên đang học lớp Kindy 8', implode(' ', $row['warnings']));

        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $token]);

        $this->assertSame([], $this->importReport()['failed']);
        $this->assertSame($kindy10->id, $minhAnh->fresh()->current_class_id);
        $old = ClassEnrollment::where('class_id', $kindy8->id)->firstOrFail();
        $this->assertSame([Student::ENROLLMENT_TRANSFERRED, today()->subDays(2)->toDateString()], [$old->status, $old->left_at->toDateString()]);
        $this->assertFalse($kindy8->hasOnRoster($minhAnh->id));
        $this->assertTrue($kindy10->hasOnRoster($minhAnh->id));
        $this->assertSame($kindy8->id, $usedTuition->fresh()->class_id);
        $this->assertSame($kindy10->id, $unfinishedTuition->fresh()->class_id);
        $this->assertSame($kindy10->id, $unassignedTuition->fresh()->class_id);
        $this->assertSame(array_replace($before, ['class_id' => $kindy10->id]), $unfinishedTuition->fresh()->getRawOriginal());
    }

    public function test_accepts_original_export_sheets_with_default_branch(): void
    {
        // Bảng "DS lớp - lịch" gốc (dòng tên bảng, ô LỊCH HỌC nhiều dòng) + file xuất gốc một lớp.
        $this->makeStudent('Quách Cẩm Đan', '2021-06-03');
        $this->makeStudent('Lê Huyền An', '2020-01-02');
        $file = $this->workbook([
            'Trang tính1' => [
                ['DANH SÁCH LỚP – ĐỘI CẤN'],
                ['STT', 'LỚP', 'CẶP', 'LỊCH HỌC (thứ · giờ · phòng)', 'GIÁO VIÊN', 'KHÓA HỌC', 'Học phí'],
                [1, 'KINDY08', '■ T2–T6', "T2   19:30–21:00   P202\nT6   19:30–21:00   P202", 'Thanh Mai', '', ''],
                [2, '4M3 (CAM6)', '● T2–T5', "T2   18:00–19:30   P501\nCN   16:30–18:00   P301", 'Thanh Mai', '', ''],
            ],
            'Học viên' => [
                ['Mã học viên', 'Họ tên', 'Điện thoại', 'Lớp', 'Ngày nhập học', 'Số buổi', 'Đã học', 'Còn lại', 'Ngày sinh', 'Giới tính', 'Email', 'Facebook', 'CCCD', 'Trường', 'Phụ huynh', 'Điện thoại PH', 'Email PH', 'Facebook PH', 'Địa chỉ', 'Ghi chú'],
                ['HS002495', 'Quách Cẩm Đan', '0963775999', 'KINDY8-THỨ 2 6 19H30-21H', '11/08/2025', '120', '111', '9', '03/06/2021', 'Nữ', '', '', '', 'Mầm non số 6', 'Hoàng Thúy Hằng', '0963775999', '', 'https://facebook.com/x', '', ''],
                ['HS000001', 'Lê Huyền An', '0911222333', '4m3-CAM6 (KID6)T2T5: 18-19h30 NEW 26.5.', '26/05/2026', '0', '38', '-38', '02/01/2020', 'Nữ', '', '', '', '', '', '', '', '', '', ''],
            ],
        ]);

        $token = $this->previewToken($file, ['branch_id' => $this->branch->id]);
        $this->actingAs($this->admin)->get(route('classes.import', ['token' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.classes.0.code', 'KINDY08')
                ->where('preview.classes.0.schedule.0', 'Thứ 2 19:30–21:00 · P202')
                ->where('preview.classes.1.code', '4M3')
                ->where('preview.students.0.class_code', 'KINDY08')
                ->where('preview.students.1.errors', []));

        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $token]);

        $kindy = ClassModel::where('code', 'KINDY08')->firstOrFail();
        $this->assertSame($this->branch->id, $kindy->branch_id);
        $dan = Student::where('name', 'Quách Cẩm Đan')->firstOrFail();
        $this->assertSame($kindy->id, $dan->current_class_id);
        $this->assertSame('Chủ nhật', ClassScheduleConfig::where('class_id', ClassModel::where('code', '4M3')->value('id'))->value('slot2_day'));
    }

    /** TK-2026-0150: 0 khớp → lỗi dòng, không ghi gì; không tạo học viên. */
    public function test_row_without_matching_student_is_hard_error_and_creates_nothing(): void
    {
        $this->makeStudent('Trần Văn An', '2015-01-01');
        $kindy = ClassModel::create(['code' => 'KINDY1', 'name' => 'Kindy 1', 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 0]);

        $token = $this->previewToken($this->workbook(['Học viên' => [
            ['Cơ sở', 'Mã lớp', 'Họ tên', 'Ngày sinh'],
            ['Đội Cấn', 'KINDY1', 'Trần Văn An', '02/01/2015'], // sai ngày sinh
            ['Đội Cấn', 'KINDY1', 'Trần Văn Bình', '01/01/2015'], // sai tên
            ['Đội Cấn', 'KINDY1', 'Trần Văn An', ''], // thiếu ngày sinh
        ]]));
        $rows = session()->get('class_roster_import.'.$token)['students'];
        $this->assertSame(['Không tìm thấy học sinh khớp trong hệ thống'], $rows[0]['errors']);
        $this->assertSame(['Không tìm thấy học sinh khớp trong hệ thống'], $rows[1]['errors']);
        $this->assertSame(['Thiếu ngày sinh hoặc ngày sinh không đọc được.'], $rows[2]['errors']);

        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $token]);

        $this->assertSame(1, Student::count());
        $this->assertSame(0, ClassEnrollment::count());
        $this->assertNull(Student::first()->current_class_id);
    }

    /** TK-2026-0150: ≥2 khớp → lỗi dòng, không ghi gì. */
    public function test_row_matching_several_students_is_hard_error_and_writes_nothing(): void
    {
        $this->makeStudent('Lê Hoa', '2016-05-05');
        $this->makeStudent('LE HOA', '2016-05-05');
        $kindy = ClassModel::create(['code' => 'KINDY1', 'name' => 'Kindy 1', 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 0]);

        $token = $this->previewToken($this->workbook(['Học viên' => [
            ['Cơ sở', 'Mã lớp', 'Họ tên', 'Ngày sinh'],
            ['Đội Cấn', 'KINDY1', 'Lê Hoa', '05/05/2016'],
        ]]));
        $this->assertSame(['Nhiều học sinh khớp cùng lúc — cần xử lý thủ công'], session()->get('class_roster_import.'.$token)['students'][0]['errors']);

        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $token]);

        $this->assertSame(2, Student::count());
        $this->assertSame(0, ClassEnrollment::count());
        $this->assertSame(0, Student::whereNotNull('current_class_id')->count());
    }

    /** TK-2026-0150: file cũ còn cột "Mã học viên" bị bỏ qua; khớp theo tên + ngày sinh, hồ sơ giữ nguyên. */
    public function test_matched_student_is_only_enrolled_profile_untouched_and_code_column_ignored(): void
    {
        $student = $this->makeStudent('Phạm Gia Bảo', '2014-09-09', ['phone' => '0911222333', 'email' => 'bao@example.com', 'school' => 'TH A']);
        $kindy = ClassModel::create(['code' => 'KINDY1', 'name' => 'Kindy 1', 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 0]);
        $before = $student->fresh()->getAttributes();

        $token = $this->previewToken($this->workbook(['Học viên' => [
            ['Cơ sở', 'Mã lớp', 'Mã học viên', 'Họ tên', 'SĐT học viên', 'Ngày sinh', 'Email', 'Trường'],
            ['Đội Cấn', 'KINDY1', 'HS999999', 'pham gia bao', '0999999999', '09/09/2014', 'khac@example.com', 'TH B'],
        ]]));
        $this->actingAs($this->admin)->get(route('classes.import', ['token' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preview.students.0.action', 'enroll')->where('preview.students.0.errors', []));

        $this->actingAs($this->admin)->post(route('classes.import.confirm'), ['token' => $token]);

        $this->assertSame([], $this->importReport()['failed']);
        $this->assertSame(1, Student::count()); // không tạo mới
        $this->assertSame($kindy->id, $student->fresh()->current_class_id);
        $this->assertSame(1, ClassEnrollment::where('student_id', $student->id)->where('class_id', $kindy->id)->count());
        $fresh = $student->fresh()->getAttributes();
        unset($before['current_class_id'], $fresh['current_class_id']);
        foreach (['updated_at'] as $k) {
            unset($before[$k], $fresh[$k]);
        }
        $this->assertSame($before, $fresh); // không sửa hồ sơ
    }

    public function test_template_has_no_student_code_column(): void
    {
        $this->assertNotContains('Mã học viên', ClassRosterImportService::STUDENT_HEADINGS);
        $this->assertContains('Ngày sinh', ClassRosterImportService::STUDENT_HEADINGS);
    }

    /** TK-2026-0147: chỉ Admin nhập danh sách lớp; 403 trước validation, kể cả khi vai trò/override được cấp quyền. */
    public function test_import_is_admin_only_for_every_endpoint_and_role(): void
    {
        $this->actingAs($this->admin)->get(route('classes.import'))->assertOk();

        foreach (array_diff(Roles::ALL, [Roles::ADMIN]) as $role) {
            $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
            $user->assignRole($role);
            // Dù được cấp thẳng các quyền liên quan, vẫn bị chặn.
            $user->givePermissionTo(['class.view', 'class.create', 'student.assign_class', 'class.import']);

            $this->actingAs($user)->get(route('classes.import'))->assertForbidden();
            $this->actingAs($user)->get(route('classes.import.template'))->assertForbidden();
            $this->actingAs($user)->post(route('classes.import.store'), [])->assertForbidden();
            $this->actingAs($user)->post(route('classes.import.confirm'), [])->assertForbidden();
            $this->actingAs($user)->get(route('classes.index'))->assertDontSee('Nhập danh sách lớp');
        }
    }

    public function test_guest_is_redirected_from_import(): void
    {
        $this->get(route('classes.import'))->assertRedirect(route('login'));
        $this->post(route('classes.import.store'), [])->assertRedirect(route('login'));
    }

    public function test_template_downloads(): void
    {
        $this->actingAs($this->admin)->get(route('classes.import.template'))->assertOk()->assertDownload('mau-nhap-danh-sach-lop.xlsx');
    }

    public function test_student_profile_saves_new_contact_fields(): void
    {
        $student = Student::create(['code' => 'HS-1', 'name' => 'Học Viên', 'phone' => '0911000009', 'branch_id' => $this->branch->id, 'status' => 'studying']);

        $this->actingAs($this->admin)->put(route('students.update', $student->id), [
            'name' => 'Học Viên', 'phone' => '0911000009',
            'parent2_name' => 'Bố Học Viên', 'parent2_phone' => '0988000111', 'parent_email' => 'me@example.com',
            'parent_facebook' => 'fb.com/me', 'facebook' => 'fb.com/hv', 'id_card_number' => '001192008455',
        ])->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame(['Bố Học Viên', '0988000111', 'me@example.com', 'fb.com/me', 'fb.com/hv', '001192008455'], [
            $student->parent2_name, $student->parent2_phone, $student->parent_email, $student->parent_facebook, $student->facebook, $student->id_card_number,
        ]);

        $this->actingAs($this->admin)->get(route('students.show', $student->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('student.parent2_name', 'Bố Học Viên')->where('student.id_card_number', '001192008455'));
    }

    /** Báo cáo của lần nhập chạy nền gần nhất (queue sync trong test: chạy xong ngay khi xác nhận). */
    private function importReport(): array
    {
        $task = BackgroundTask::query()->where('type', BackgroundTask::CLASS_ROSTER_IMPORT)->latest('id')->firstOrFail();
        $this->assertSame(BackgroundTask::DONE, $task->status, (string) $task->message);

        return $task->report;
    }
}
