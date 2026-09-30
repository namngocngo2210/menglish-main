<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\MerchandiseItem;
use App\Models\Student;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Sprint IX-2 — 14 luồng "Modal nhỏ" (docs/frontend-interaction-redesign.md §3):
 * cùng route trả trang đầy đủ (request thường) hoặc fragment modal (HX-Request); lỗi validate 422 ngay trong modal;
 * lưu xong 204 + HX-Trigger (close-modal, toast, sự kiện làm mới danh sách); request thường giữ redirect như cũ.
 */
class ModalFlowsTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private const HX = ['HX-Request' => 'true'];

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-MF', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Quản trị viên', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    // ── GET: trang đầy đủ ↔ fragment ─────────────────────────────────────────────────────────

    /** @return array<string, array{0: callable(self): string, 1: string, 2: string}> [url, id form trong modal, chữ có trong modal] */
    public static function formPages(): array
    {
        return [
            'nhập khách Excel' => [fn (self $t) => route('crm.import'), 'modal-crm-import-form', 'Nhập khách hàng loạt từ Excel'],
        ];
    }

    #[DataProvider('formPages')]
    public function test_form_route_returns_full_page_normally_and_fragment_for_htmx(callable $url, string $formId, string $title): void
    {
        $url = $url($this);

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertDontSee('id="'.$formId.'"', false);

        $this->actingAs($this->admin)->get($url, self::HX)->assertOk()
            ->assertHeader('Vary', 'HX-Request')
            ->assertDontSee('data-sidebar', false)
            ->assertDontSee('<html', false)
            ->assertSee($title)
            ->assertSee('id="'.$formId.'"', false)
            ->assertSee('form="'.$formId.'"', false);
    }

    /** @return array<string, array{0: string, 1: string, 2: callable(self): string, 3: string}> [route danh sách, sự kiện làm mới, URL mở modal, cỡ] */
    public static function listPages(): array
    {
        return [
        ];
    }

    #[DataProvider('listPages')]
    public function test_list_page_has_refresh_region_modal_triggers_and_no_native_confirm_for_delete(string $index, string $event, callable $opener, string $size): void
    {
        $this->item();
        $this->staff();

        $this->actingAs($this->admin)->get(route($index))->assertOk()
            ->assertSee('hx-trigger="'.$event.' from:body"', false)
            ->assertSee('hx-target="#remote-modal-body"', false)
            ->assertSee('data-modal-size="'.$size.'"', false)
            ->assertSee('hx-get="'.$opener($this).'"', false)
            ->assertDontSee('onsubmit="return confirm(\'Bạn có chắc', false)
            ->assertDontSee('onsubmit="return confirm(\'Xóa', false)
            ->assertDontSee('onsubmit="return confirm(\'Ngừng', false);
    }

    // ── Vật phẩm ────────────────────────────────────────────────────────────────────────────

    public function test_merchandise_list_and_form_open_in_modal(): void
    {
        $item = $this->item();

        // Danh sách (Vue): nút Thêm / Sửa mở trang form trong modal chung, Xóa qua hộp xác nhận (không confirm() của trình duyệt).
        $this->actingAs($this->admin)->get(route('merchandise.index'))->assertOk()
            ->assertSee('href="'.route('merchandise.create', absolute: false).'"', false)
            ->assertSee('href="'.route('merchandise.edit', $item, absolute: false).'"', false)
            ->assertDontSee('onsubmit="return confirm(', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Merchandise/Index')->where('items.data.0.code', 'BOOK-MF-01'));

        // Mở thẳng URL → trang đầy đủ; mở từ modal (X-Remote-Modal) → prop asModal.
        $this->actingAs($this->admin)->get(route('merchandise.create'))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('Thêm mới Hàng hóa')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Merchandise/Form')->where('asModal', false)->where('isEdit', false));
        $this->actingAs($this->admin)->get(route('merchandise.edit', $item), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Merchandise/Form')
                ->where('asModal', true)->where('isEdit', true)->where('item.code', 'BOOK-MF-01'));
    }

    public function test_merchandise_modal_flow(): void
    {
        $item = $this->item();
        $index = route('merchandise.index', ['q' => 'Sách']);

        // Lỗi validate từ modal → quay lại trang đang mở kèm lỗi (modal giữ nguyên, hiện lỗi dưới trường).
        $this->actingAs($this->admin)->from($index)->post(route('merchandise.store'), ['code' => 'BOOK-MF-01', 'name' => ''], self::MODAL)
            ->assertRedirect($index)
            ->assertSessionHasErrors(['code' => 'Mã hàng hóa này đã tồn tại trong hệ thống.', 'name' => 'Tên hàng hóa không được để trống.']);

        // Lưu xong từ modal → về lại trang đang mở (giữ bộ lọc) kèm thông báo.
        $payload = ['code' => 'UNI-MF-XL', 'name' => 'Áo Polo XL', 'category' => 'uniform', 'unit' => 'Chiếc', 'price' => 220000, 'stock_quantity' => 30, 'is_active' => 1];
        $this->actingAs($this->admin)->from($index)->post(route('merchandise.store'), $payload, self::MODAL)
            ->assertRedirect($index)->assertSessionHas('status', 'Đã thêm thành công mặt hàng [UNI-MF-XL] Áo Polo XL!');

        $this->actingAs($this->admin)->from($index)->put(route('merchandise.update', $item), [...$payload, 'code' => 'BOOK-MF-01', 'name' => 'Sách mới'], self::MODAL)
            ->assertRedirect($index)->assertSessionHas('status', 'Đã cập nhật thông tin mặt hàng [BOOK-MF-01] Sách mới!');

        $this->actingAs($this->admin)->from($index)->delete(route('merchandise.destroy', $item), [], self::MODAL)
            ->assertRedirect($index)->assertSessionHas('status', 'Đã xóa mặt hàng Sách mới vào thùng rác.');
        $this->assertSoftDeleted($item);

        // Request thường (không từ modal) giữ redirect về danh sách như cũ.
        $this->actingAs($this->admin)->from($index)->post(route('merchandise.store'), [...$payload, 'code' => 'UNI-MF-L', 'name' => 'Áo Polo L'])
            ->assertRedirect(route('merchandise.index'));
    }

    // ── Nhập khách từ Excel (2 bước trong modal) ─────────────────────────────────────────────

    public function test_crm_import_runs_both_steps_inside_modal(): void
    {
        $this->actingAs($this->admin)->get(route('crm.import'), self::HX)->assertOk()
            ->assertSee('href="'.route('crm.import.template').'"', false)->assertSee('hx-boost="false"', false);

        // Thiếu file / chi nhánh → 422, form bước 1 kèm lỗi (route crm.import.preview → màn cha crm.import).
        $this->actingAs($this->admin)->post(route('crm.import.preview'), ['default_source' => 'Hội thảo'], self::HX)
            ->assertStatus(422)->assertDontSee('data-sidebar', false)
            ->assertSee('id="modal-crm-import-form"', false)->assertSee('Vui lòng chọn file Excel / CSV.')->assertSee('value="Hội thảo"', false);

        $file = UploadedFile::fake()->createWithContent('khach.csv', "\xEF\xBB\xBFHọ tên,Số điện thoại\nNguyễn An,0912345678\nTrần Bình,12345\n");
        $this->actingAs($this->admin)->post(route('crm.import.preview'), ['file' => $file, 'branch_id' => $this->branch->id], self::HX)
            ->assertRedirect(route('crm.import'));

        // Trình duyệt đi theo redirect (vẫn gửi HX-Request) → bước 2 trong modal, nới rộng 4xl.
        $this->actingAs($this->admin)->get(route('crm.import'), self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('Xem trước dữ liệu nhập')->assertSee('SĐT sai định dạng')
            ->assertSee("size = '4xl'", false)
            ->assertSee('form="modal-crm-import-confirm"', false)->assertSee('Bỏ qua 1 dòng lỗi, nhập 1 khách');

        $response = $this->actingAs($this->admin)->post(route('crm.import.store'), [], self::HX);
        $response->assertNoContent()->assertHeader('HX-Redirect', route('crm.customers.index'));
        $this->assertSame(1, CrmCustomer::count());
        $this->assertStringStartsWith('Đã nhập 1 khách hàng mới', session('status'));
    }

    public function test_crm_import_reads_xlsx_directly_when_laravel_excel_fails(): void
    {
        // Hosting: Laravel Excel hỏng ở bước chép file tạm (thư mục không ghi được...) → vẫn đọc thẳng file upload.
        $xlsx = Excel::raw(new ArrayExport(['Họ tên', 'Số điện thoại'], [['Nguyễn An', '0912345678'], [null, null], ['Trần Bình', '0987654321']]), ExcelFormat::XLSX);
        Excel::shouldReceive('toArray')->once()->andThrow(new \ErrorException('mkdir(): Permission denied'));

        $file = UploadedFile::fake()->createWithContent('khach.xlsx', $xlsx);
        $this->actingAs($this->admin)->post(route('crm.import.preview'), ['file' => $file, 'branch_id' => $this->branch->id], self::HX)
            ->assertRedirect(route('crm.import'))->assertSessionHasNoErrors();
        $rows = session('crm_customer_import')['rows'];
        $this->assertSame(['Nguyễn An', 'Trần Bình'], array_column(array_column($rows, 'data'), 'name'));
        $this->assertSame('0912345678', $rows[0]['data']['phone']);
        $this->assertSame([[], []], array_column($rows, 'errors'));
    }

    public function test_crm_import_shows_reason_when_file_is_unreadable(): void
    {
        $file = UploadedFile::fake()->createWithContent('khach.xlsx', 'không phải file excel');
        $this->actingAs($this->admin)->post(route('crm.import.preview'), ['file' => $file, 'branch_id' => $this->branch->id], self::HX)
            ->assertStatus(422)->assertSee('Không đọc được file')->assertSee('Chi tiết:');
    }

    // ── Nhập học phí từ Excel (3 bước trong modal) ───────────────────────────────────────────

    public function test_tuition_import_runs_steps_inside_modal(): void
    {
        // Bước 1 trong modal: thiếu file → về lại bước 1 kèm lỗi.
        $this->actingAs($this->admin)->from(route('tuition.import'))->post(route('tuition.import.store'), ['branch_id' => $this->branch->id], self::MODAL)
            ->assertRedirect(route('tuition.import'))
            ->assertSessionHasErrors(['excel_file' => 'Vui lòng chọn file Excel (.xlsx) hoặc CSV để nhập.']);
        $this->actingAs($this->admin)->get(route('tuition.import'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/Import')->where('asModal', true)->where('preview', null));

        // Bước 2: redirect sang xem trước (token) — modal tải trang đó (Tuition/Import) và hiện lỗi từng dòng.
        $file = UploadedFile::fake()->createWithContent('hoc-phi.csv', "Mã học viên,Học phí niêm yết,Hạn đóng\nHV-KHONG-CO,3000000,15/10/2026\n");
        $response = $this->actingAs($this->admin)->post(route('tuition.import.store'), ['branch_id' => $this->branch->id, 'excel_file' => $file], self::MODAL);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('token=', $location);

        $this->actingAs($this->admin)->get($location, self::MODAL)->assertOk()
            ->assertSee('Không tìm thấy học viên mã HV-KHONG-CO')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/Import')
                ->where('asModal', true)
                ->where('preview.rows.0.errors', fn ($errors) => $errors->contains(fn ($e) => str_contains($e, 'Không tìm thấy học viên mã HV-KHONG-CO'))));

        // Hết hạn phiên xem trước → redirect về bước 1 kèm lỗi (hiện trong modal).
        $this->actingAs($this->admin)->post(route('tuition.import.confirm'), ['token' => 'khong-ton-tai'], self::MODAL)
            ->assertRedirect(route('tuition.import'));
        $this->actingAs($this->admin)->get(route('tuition.import'), self::MODAL)->assertOk()
            ->assertSee('Phiên xem trước đã hết hạn');
    }

    // ── Ảnh bằng chứng hoàn tiền (lightbox) ─────────────────────────────────────────────────

    public function test_refund_proof_opens_as_lightbox_in_modal_and_file_otherwise(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tuition/refund-proofs/unc.png', UploadedFile::fake()->image('unc.png')->getContent());
        $student = Student::create(['code' => 'HV-MF-1', 'name' => 'Lê Hoàn', 'phone' => '0912000111', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $refund = TuitionRefundRequest::create([
            'student_id' => $student->id, 'type' => 'refund', 'total_paid' => 3000000, 'refund_amount' => 1000000,
            'reason' => 'Chuyển nhà', 'requester_id' => $this->admin->id, 'status' => 'approved', 'proof_path' => 'tuition/refund-proofs/unc.png',
        ]);

        // Bảng hoàn phí: link ảnh bằng chứng (bấm → mở modal cỡ xl; mở tab mới vẫn là file ảnh).
        $this->actingAs($this->admin)->get(route('tuition.refunds'))->assertOk()
            ->assertSee('href="'.route('tuition.refunds.proof', $refund->id, false).'"', false)->assertSee('Ảnh bằng chứng');

        $this->actingAs($this->admin)->get(route('tuition.refunds.proof', $refund->id), self::MODAL)->assertOk()
            ->assertSee('Ảnh bằng chứng — Lê Hoàn')
            ->assertSee('<img src="'.route('tuition.refunds.proof', $refund->id).'"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/RefundProof')
                ->where('asModal', true)
                ->where('refund.student_name', 'Lê Hoàn')
                ->where('refund.proof_url', route('tuition.refunds.proof', $refund->id)));

        $file = $this->actingAs($this->admin)->get(route('tuition.refunds.proof', $refund->id))->assertOk();
        $this->assertStringStartsWith('image/', $file->headers->get('Content-Type'));
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────────────────────

    public function test_htmx_requests_still_respect_permissions(): void
    {
        $teacher = $this->staff();
        $item = $this->item();

        $this->actingAs($teacher)->get(route('merchandise.edit', $item), self::HX)->assertForbidden();
        $this->actingAs($teacher)->post(route('crm.import.preview'), [], self::HX)->assertForbidden();
        $this->actingAs($teacher)->post(route('tuition.import.store'), [], self::HX)->assertForbidden();
    }

    public function test_validation_render_requires_submit_route_to_cover_form_route_middleware(): void
    {
        // tuition.import (GET, chỉ tuition.view) ⊂ tuition.import.store (thêm tuition.create) → render form 422 được.
        // Người chỉ có tuition.view bị chặn ngay ở route submit (403), không bao giờ tới bước render form.
        $viewer = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $viewer->givePermissionTo('tuition.view');

        $this->actingAs($viewer)->post(route('tuition.import.store'), [], self::HX)->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────

    public function staff(): User
    {
        $user = User::query()->where('email', 'gv.modal@example.com')->first()
            ?? User::factory()->create(['name' => 'Giáo viên Modal', 'email' => 'gv.modal@example.com', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $user->syncRoles(['teacher']);

        return $user;
    }

    public function item(): MerchandiseItem
    {
        return MerchandiseItem::query()->firstOrCreate(['code' => 'BOOK-MF-01'], [
            'name' => 'Sách Test', 'category' => MerchandiseItem::CATEGORY_BOOK, 'unit' => 'Cuốn', 'price' => 200000, 'stock_quantity' => 50, 'is_active' => true,
        ]);
    }

    private function assertSaved(TestResponse $response, string $event, string $message): void
    {
        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertTrue($triggers['close-modal']);
        $this->assertTrue($triggers[$event]);
        $this->assertSame(['message' => $message, 'type' => 'success'], $triggers['toast']);
    }

    private function triggers(TestResponse $response): array
    {
        return json_decode($response->headers->get('HX-Trigger'), true, flags: JSON_THROW_ON_ERROR);
    }
}
