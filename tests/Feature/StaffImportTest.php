<?php

namespace Tests\Feature;

use App\Models\BackgroundTask;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Nhập nhân sự từ Excel: tạo tài khoản (mã MES tự cấp, mật khẩu ban đầu, bắt đổi mật khẩu) kèm hồ sơ; nhân sự đã có
 * (trùng email) chỉ được bổ sung trường trống; nhận cả sheet form "Trợ giảng" gốc; chạy lại không tạo trùng.
 */
class StaffImportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Menglish Đội Cấn', 'code' => 'DC', 'is_active' => true]);
        $this->other = Branch::create(['name' => 'Menglish Hoàng Hoa Thám', 'code' => 'HHT', 'is_active' => true]);

        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    /** @param  list<list<mixed>>  $rows  dòng đầu là tiêu đề */
    private function file(array $rows, string $title = 'Nhân sự'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle($title)->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'staff').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'nhan-su.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function normalizedFile(): UploadedFile
    {
        return $this->file([
            ['Họ tên', 'Vai trò', 'Cơ sở', 'Email công việc', 'Email cá nhân', 'SĐT', 'Ngày sinh', 'CCCD', 'Ngày cấp CCCD', 'Quê quán', 'Nơi ở hiện tại',
                'Người liên hệ khẩn cấp', 'SĐT người liên hệ', 'Tốt nghiệp', 'Chuyên ngành', 'Level giảng dạy', 'Chứng chỉ', 'Hình thức', 'Ngày bắt đầu làm việc',
                'Facebook', 'TK ngân hàng', 'Kênh liên lạc', 'Ghi chú'],
            ['Nguyễn Văn An', 'Giáo viên', 'Đội Cấn', 'msan.menglish@gmail.com', 'vanan@gmail.com', '912345678', '17/09/2001', '001201000001', '', 'Hà Nội', 'Cầu Giấy',
                'Bố', '0987654321', 'ĐH Ngoại Ngữ', 'Ngôn ngữ Anh', 'Tiểu học', 'IELTS 7.5', 'Part', '15/09/2023', '', '', 'Zalo', 'Nghỉ sinh'],
            ['Trần Thị Bình', '', 'Hoàng Hoa Thám', '', 'tranbinh@gmail.com', '0934567890', '28/09/2007', '00130700002', '22/12/2021', '', '',
                'Mẹ Lan 0976543210', '0976543210', '', '', '', 'Không', '', '01/12/2025', 'https://facebook.com/binh', '0934567890 - MB Bank', 'Zalo', ''],
            ['Lê Sai Email', 'Trợ giảng', 'Đội Cấn', 'khong-phai-email', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Cơ Sở Lạ', 'Trợ giảng', 'Cầu Giấy', 'lacoso@gmail.com', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Trùng Email', 'Trợ giảng', 'Đội Cấn', 'tranbinh@gmail.com', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Đã Có Tài Khoản', 'Giáo viên', 'Đội Cấn', 'daco@gmail.com', '', '0911222333', '01/01/1995', '', '', 'Nam Định', 'Hà Đông', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
    }

    public function test_preview_and_import_create_accounts_with_profile_and_rerun_is_idempotent(): void
    {
        $existing = User::factory()->create(['name' => 'Đã Có Tài Khoản', 'email' => 'daco@gmail.com', 'hometown' => 'Hà Nội', 'branch_id' => $this->branch->id]);
        $existing->assignRole('teacher_parttime');
        $passwordBefore = $existing->password;

        $this->actingAs($this->admin)
            ->post(route('users.import.store'), ['role' => 'assistant', 'password' => 'staff@123', 'excel_file' => $this->normalizedFile()])
            ->assertRedirect();

        $token = array_key_first(session('staff_import'));
        $this->actingAs($this->admin)->get(route('users.import', ['token' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Users/Import')
                ->where('defaultPassword', 'staff@123')
                ->has('preview.rows', 6)
                ->where('preview.rows.0.action', 'create')
                ->where('preview.rows.0.role', 'Giáo viên Part-time')
                ->where('preview.rows.0.phone', '0912345678')
                ->where('preview.rows.0.errors', [])
                ->where('preview.rows.1.role', 'Trợ giảng')
                ->where('preview.rows.1.email', 'tranbinh@gmail.com')
                ->where('preview.rows.1.warnings', ['CCCD "00130700002" không đủ 12 số: vẫn lưu, kiểm tra lại.'])
                ->where('preview.rows.2.errors', ['Email "khong-phai-email" không hợp lệ.'])
                ->where('preview.rows.3.errors', ['Không tìm thấy cơ sở "Cầu Giấy".'])
                ->where('preview.rows.4.errors', ['Trùng email với dòng 3.'])
                ->where('preview.rows.5.action', 'update')
                ->where('preview.rows.5.employee_code', $existing->employee_code));

        $this->actingAs($this->admin)->post(route('users.import.confirm'), ['token' => $token])->assertRedirect(route('users.import', ['task' => BackgroundTask::query()->max('id')]));
        $result = $this->importReport();
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(3, $result['skipped']);
        $this->assertSame([], $result['failed']);
        $this->assertCount(2, $result['accounts']);

        $an = User::where('email', 'msan.menglish@gmail.com')->firstOrFail();
        $this->assertTrue($an->hasRole('teacher_parttime'));
        $this->assertSame($this->branch->id, $an->branch_id);
        $this->assertStringStartsWith('MES', $an->employee_code);
        $this->assertSame($an->employee_code, $result['accounts'][0]['employee_code']);
        $this->assertTrue(Hash::check('staff@123', $an->password));
        $this->assertTrue($an->must_change_password);
        $this->assertSame('vanan@gmail.com', $an->personal_email);
        $this->assertSame('0912345678', $an->phone);
        $this->assertSame('2001-09-17', $an->date_of_birth->toDateString());
        $this->assertSame('Bố - 0987654321', $an->emergency_contact);
        $this->assertSame('Ngôn ngữ Anh', $an->major);
        $this->assertSame('Bán thời gian', $an->contract_type);
        $this->assertSame('2023-09-15', $an->joined_on->toDateString());
        $this->assertSame('Zalo', $an->contact_channel);
        $this->assertSame('Nghỉ sinh', $an->staff_note);

        $binh = User::where('email', 'tranbinh@gmail.com')->firstOrFail();
        $this->assertTrue($binh->hasRole('assistant'));
        $this->assertSame($this->other->id, $binh->branch_id);
        $this->assertNull($binh->personal_email);
        $this->assertSame('Mẹ Lan 0976543210', $binh->emergency_contact);
        $this->assertNull($binh->certificates);
        $this->assertSame('Cộng tác viên', $binh->contract_type);
        $this->assertSame('2021-12-22', $binh->id_card_issued_on->toDateString());
        $this->assertSame('0934567890 - MB Bank', $binh->bank_account);

        $existing->refresh();
        $this->assertSame('Hà Nội', $existing->hometown); // trường đã có giữ nguyên
        $this->assertSame('Hà Đông', $existing->current_address);
        $this->assertSame('1995-01-01', $existing->date_of_birth->toDateString());
        $this->assertSame($passwordBefore, $existing->password);
        $this->assertFalse(User::where('email', 'lacoso@gmail.com')->exists());

        // Chạy lại: không tạo trùng, không còn gì để bổ sung.
        $count = User::count();
        $this->actingAs($this->admin)->post(route('users.import.store'), ['role' => 'assistant', 'password' => 'staff@123', 'excel_file' => $this->normalizedFile()]);
        $token = collect(array_keys(session('staff_import')))->last();
        $this->actingAs($this->admin)->post(route('users.import.confirm'), ['token' => $token]);
        $this->assertSame(0, $this->importReport()['created']);
        $this->assertSame(0, $this->importReport()['updated']);
        $this->assertSame($count, User::count());
    }

    public function test_accepts_original_assistant_form_sheet_with_defaults(): void
    {
        $file = $this->file([
            ['Dấu thời gian', 'Họ và tên đầy đủ', 'Ngày sinh', 'Email', 'Căn cước công dân', 'Ngày cấp', 'Địa chỉ đang ở ?', 'Địa chỉ ở Quê nếu có', 'Số điện thoại cá nhân',
                'Link FB ( Nếu có)', 'Khi cần liên hệ với ai? ', 'Số điện thoại liên hệ người thân?', 'Tốt nghiệp trường nào ?', 'Chuyên ngành đào tạo?',
                'CC TA/ CC giảng dạy khác (nếu có) ', 'Ngày bắt đầu làm việc tại ME?', 'Tài khoản ngân hàng để nhận lương ? ( Ghi rõ tk - ngân hàng)', 'Phương Thức Liên lạc với Quản lý nhân sự'],
            ['11/10/2026 21:03:25', 'Phạm Văn Cường', '23/02/2006', 'cuong@gmail.com', '001206000003', '03/04/2021', 'Kim Mã, Hà Nội', 'Hải Phòng', '0398000001',
                'https://facebook.com/cuong', 'mẹ', '0378000002', 'ĐH Ngoại Ngữ', 'Ngôn ngữ Anh', 'Ielts 7.0', '20/07/2026', '0398000001 - MB bank', 'Zalo'],
        ], 'Trợ giảng T10.2026');

        $this->actingAs($this->admin)
            ->post(route('users.import.store'), ['role' => 'assistant', 'branch_id' => $this->other->id, 'password' => 'staff@123', 'excel_file' => $file])
            ->assertRedirect();
        $token = array_key_first(session('staff_import'));
        $this->actingAs($this->admin)->post(route('users.import.confirm'), ['token' => $token]);

        $user = User::where('email', 'cuong@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasRole('assistant'));
        $this->assertSame($this->other->id, $user->branch_id);
        $this->assertSame('2006-02-23', $user->date_of_birth->toDateString());
        $this->assertSame('Kim Mã, Hà Nội', $user->current_address);
        $this->assertSame('Hải Phòng', $user->hometown);
        $this->assertSame('mẹ - 0378000002', $user->emergency_contact);
        $this->assertSame('Ngôn ngữ Anh', $user->major);
        $this->assertSame('Ielts 7.0', $user->certificates);
        $this->assertSame('2026-07-20', $user->joined_on->toDateString());
        $this->assertSame('https://facebook.com/cuong', $user->facebook);
        $this->assertSame('Zalo', $user->contact_channel);
    }

    public function test_teachers_and_assistants_do_not_require_branch_but_other_staff_do(): void
    {
        $file = $this->file([
            ['Họ tên', 'Vai trò', 'Email'],
            ['Trợ Giảng Không Cơ Sở', 'Trợ giảng', 'trogiang@gmail.com'],
            ['Giáo Viên Không Cơ Sở', 'Giáo viên', 'giaovien@gmail.com'],
            ['Tư Vấn Không Cơ Sở', 'sales_consultant', 'tuvan@gmail.com'],
        ]);

        $this->actingAs($this->admin)
            ->post(route('users.import.store'), ['password' => 'staff@123', 'excel_file' => $file])
            ->assertRedirect();
        $token = array_key_first(session('staff_import'));
        $this->actingAs($this->admin)->get(route('users.import', ['token' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.rows.0.errors', [])
                ->where('preview.rows.0.branch', null)
                ->where('preview.rows.1.errors', [])
                ->where('preview.rows.1.branch', null)
                ->where('preview.rows.2.errors', ['Chưa có cơ sở: ghi cột Cơ sở hoặc chọn cơ sở mặc định.']));

        $this->actingAs($this->admin)->post(route('users.import.confirm'), ['token' => $token])->assertRedirect(route('users.import', ['task' => BackgroundTask::query()->max('id')]));

        $assistant = User::where('email', 'trogiang@gmail.com')->firstOrFail();
        $this->assertTrue($assistant->hasRole('assistant'));
        $this->assertNull($assistant->branch_id);
        $teacher = User::where('email', 'giaovien@gmail.com')->firstOrFail();
        $this->assertTrue($teacher->hasRole('teacher_parttime'));
        $this->assertNull($teacher->branch_id);
        $this->assertFalse(User::where('email', 'tuvan@gmail.com')->exists());
    }

    public function test_branch_scoped_importer_still_needs_branch_for_assistant(): void
    {
        $manager = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('user.create', 'user.assign_role.assistant');

        $file = $this->file([['Họ tên', 'Vai trò', 'Email'], ['Trợ Giảng Không Cơ Sở', 'Trợ giảng', 'trogiang@gmail.com']]);
        $this->actingAs($manager)
            ->post(route('users.import.store'), ['password' => 'staff@123', 'excel_file' => $file])
            ->assertRedirect();
        $token = array_key_first(session('staff_import'));
        $this->actingAs($manager)->get(route('users.import', ['token' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.rows.0.errors', ['Chưa có cơ sở: ghi cột Cơ sở hoặc chọn cơ sở mặc định.']));
    }

    public function test_requires_user_create_permission_and_template_downloads(): void
    {
        $teacher = User::factory()->create(['branch_id' => $this->branch->id]);
        $teacher->assignRole('teacher_parttime');
        $this->actingAs($teacher)->get(route('users.import'))->assertForbidden();
        $this->actingAs($teacher)->post(route('users.import.confirm'), ['token' => 'x'])->assertForbidden();

        $this->actingAs($this->admin)->get(route('users.import.template'))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=mau-nhap-nhan-su.xlsx');
    }

    public function test_user_form_saves_and_shows_new_profile_fields(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('teacher_parttime');

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'branch_id' => $this->branch->id, 'role' => 'teacher_parttime',
            'date_of_birth' => '2000-10-30', 'personal_email' => 'canhan@gmail.com', 'facebook' => 'fb.com/x', 'contact_channel' => 'Zalo',
            'id_card_issued_on' => '2021-05-13', 'major' => 'Ngôn ngữ Anh', 'joined_on' => '2023-03-01', 'bank_account' => '123 - MB', 'staff_note' => 'Ghi chú',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('2000-10-30', $user->date_of_birth->toDateString());
        $this->assertSame('canhan@gmail.com', $user->personal_email);
        $this->assertSame('123 - MB', $user->bank_account);
        $this->assertSame('2023-03-01', $user->joined_on->toDateString());

        $this->actingAs($this->admin)->get(route('users.show', $user))->assertOk()
            ->assertSee('canhan@gmail.com')->assertSee('30/10/2000')->assertSee('123 - MB');
    }

    /** Báo cáo của lần nhập chạy nền gần nhất (queue sync trong test: chạy xong ngay khi xác nhận). */
    private function importReport(): array
    {
        $task = BackgroundTask::query()->where('type', BackgroundTask::STAFF_IMPORT)->latest('id')->firstOrFail();
        $this->assertSame(BackgroundTask::DONE, $task->status, (string) $task->message);

        return $task->report;
    }
}
