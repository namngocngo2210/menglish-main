<?php

namespace Tests\Feature;

use App\Exports\TuitionImportTemplateExport;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\TuitionImportService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Phase 4 — Nhập học phí từ Excel / CSV: xem trước kèm lỗi từng dòng, chỉ nhập dòng hợp lệ,
 * khoản đã đóng tạo phiếu thu CHỜ DUYỆT.
 */
class Phase4TuitionImportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Import', 'code' => 'IMP', 'is_active' => true]);
        $other = Branch::create(['name' => 'CN Khác', 'code' => 'OTH', 'is_active' => true]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->accountant->assignRole('accountant');

        $course = Course::create(['code' => 'IMP-C', 'name' => 'Khóa import', 'is_active' => true]);
        ClassModel::create(['code' => 'IMP-01', 'name' => 'Lớp Import 01', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active']);

        Student::create(['code' => 'HV-IMP-1', 'name' => 'Nguyễn Nhập Một', 'phone' => '0911000001', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $existing = Student::create(['code' => 'HV-IMP-2', 'name' => 'Trần Đã Có', 'phone' => '0911000002', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        Student::create(['code' => 'HV-IMP-3', 'name' => 'Lê Chi Nhánh Khác', 'phone' => '0911000003', 'branch_id' => $other->id, 'status' => 'studying']);

        StudentTuition::create([
            'student_id' => $existing->id, 'branch_id' => $this->branch->id, 'total_amount' => 4000000,
            'final_amount' => 4000000, 'paid_amount' => 0, 'debt_amount' => 4000000, 'due_date' => now()->addDays(5), 'status' => 'unpaid',
        ]);
    }

    private function csv(array $lines): UploadedFile
    {
        $content = "\xEF\xBB\xBF".implode("\n", array_map(fn ($cols) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $cols)), $lines));

        return UploadedFile::fake()->createWithContent('hoc-phi.csv', $content);
    }

    public function test_preview_shows_row_errors_and_confirm_imports_only_valid_rows(): void
    {
        $file = $this->csv([
            ['Mã học viên', 'Mã lớp', 'Học phí niêm yết', 'Giảm trừ', 'Phí khác', 'Hạn đóng', 'Số tiền đã đóng', 'Hình thức', 'Mã giao dịch', 'Ngày đóng', 'Ghi chú'],
            ['HV-IMP-1', 'IMP-01', '12.500.000', '500.000', '0', '15/10/2026', '5.000.000', 'chuyen_khoan', 'FT-IMP-001', '01/10/2026', 'Đợt 1'],
            ['HV-IMP-2', '', '', '', '', '', '1000000', 'tien_mat', '', '', 'Đóng thêm'],
            ['HV-IMP-2', '', '9000000', '', '', '15/10/2026', '', '', '', '', 'Ghi đè hợp đồng'],
            ['HV-IMP-3', '', '3000000', '', '', '15/10/2026', '', '', '', '', 'Sai chi nhánh'],
            ['HV-KHONG-CO', '', '3000000', '', '', '15/10/2026', '', '', '', '', ''],
            ['HV-IMP-1', '', '', '', '', '', '100000000', 'tien_mat', '', '', 'Vượt nợ'],
            ['HV-IMP-1', '', '', '', '', '', '1000000', 'chuyen_khoan', 'ft-imp-001', '', 'Trùng mã GD'],
        ]);

        $response = $this->actingAs($this->accountant)->post(route('tuition.import.store'), [
            'branch_id' => $this->branch->id,
            'excel_file' => $file,
        ]);
        $response->assertSessionHasNoErrors();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('token=', $location);

        $preview = $this->actingAs($this->accountant)->get($location);
        $preview->assertOk()
            ->assertSee('Xem trước')
            ->assertSee('Không tìm thấy học viên mã HV-KHONG-CO')
            ->assertSee('không thuộc chi nhánh đã chọn')
            ->assertSee('không ghi đè giá trị hợp đồng')
            ->assertSee('vượt công nợ còn lại')
            ->assertSee('đã được ghi nhận');

        $rows = collect($preview->viewData('preview')['rows']);
        $this->assertSame([2, 3], $rows->filter(fn ($r) => empty($r['errors']))->pluck('line')->values()->all());

        // Chưa xác nhận thì chưa ghi gì.
        $this->assertSame(1, StudentTuition::count());
        $this->assertSame(0, TuitionReceipt::count());

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->actingAs($this->accountant)->post(route('tuition.import.confirm'), ['token' => $query['token']])
            ->assertRedirect(route('tuition.import'))
            ->assertSessionHas('tuition_import_result', fn ($r) => $r['tuitions'] === 1 && $r['receipts'] === 2 && $r['skipped'] === 5);

        $newStudent = Student::where('code', 'HV-IMP-1')->first();
        $tuition = StudentTuition::where('student_id', $newStudent->id)->firstOrFail();
        $this->assertEquals(12000000, (float) $tuition->final_amount);
        $this->assertEquals(12000000, (float) $tuition->debt_amount, 'Khoản đã đóng chỉ là phiếu chờ duyệt, chưa trừ nợ');
        $this->assertSame('2026-10-15', $tuition->due_date->toDateString());
        $this->assertSame($this->branch->id, $tuition->branch_id);
        $this->assertSame('IMP-01', $tuition->classModel->code);

        $receipt = TuitionReceipt::where('student_tuition_id', $tuition->id)->firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertNull($receipt->invoice_number);
        $this->assertSame('FT-IMP-001', $receipt->transfer_reference);
        $this->assertSame('2026-10-01', $receipt->payment_date->toDateString());

        $this->assertSame(1, TuitionReceipt::where('payment_method', 'cash')->where('amount', 1000000)->where('status', 'pending')->count());

        // Token chỉ dùng được một lần.
        $this->actingAs($this->accountant)->post(route('tuition.import.confirm'), ['token' => $query['token']])
            ->assertSessionHasErrors('excel_file');
        $this->assertSame(2, TuitionReceipt::count());
    }

    public function test_xlsx_with_numeric_and_date_cells_is_parsed(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Mã học viên', 'Học phí niêm yết', 'Hạn đóng', 'Số tiền đã đóng', 'Hình thức'],
            ['HV-IMP-1', 8000000, null, 2000000, 'Tiền mặt'],
        ]);
        $sheet->setCellValue('C2', Date::PHPToExcel(new \DateTime('2026-11-20')));
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            $file = new UploadedFile($path, 'hoc-phi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $parsed = app(TuitionImportService::class)->parse($file, $this->branch->id);
        } finally {
            @unlink($path);
        }

        $row = $parsed['rows'][0];
        $this->assertSame([], $row['errors']);
        $this->assertEquals(8000000, $row['final_amount']);
        $this->assertSame('2026-11-20', $row['due_date']);
        $this->assertEquals(2000000, $row['paid_amount']);
        $this->assertSame('cash', $row['payment_method']);
    }

    public function test_file_without_student_code_column_is_rejected(): void
    {
        $file = $this->csv([['Tên', 'Số tiền'], ['A', '1000']]);

        $this->actingAs($this->accountant)->post(route('tuition.import.store'), [
            'branch_id' => $this->branch->id,
            'excel_file' => $file,
        ])->assertSessionHasErrors('excel_file');
    }

    public function test_template_download_and_import_permission(): void
    {
        Excel::fake();
        $this->actingAs($this->accountant)->get(route('tuition.import.template'))->assertOk();
        Excel::assertDownloaded('mau-nhap-hoc-phi.xlsx', fn (TuitionImportTemplateExport $export) => in_array('Mã học viên', $export->headings(), true));

        $this->actingAs($this->accountant)->get(route('tuition.import'))->assertOk()->assertSee('Tải file');

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('tuition.view');
        $this->actingAs($viewer)->post(route('tuition.import.store'), ['branch_id' => $this->branch->id])->assertForbidden();
    }
}
