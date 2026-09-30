<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Http\Concerns\RendersModals;
use App\Imports\RawRowsImport;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\User;
use App\Services\Crm\LeadOwners;
use App\Support\DataScope;
use App\Support\Ui;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * "Nhập khách hàng loạt từ Excel" (mockup CRM): tải file .xlsx / .csv → xem trước + lỗi từng dòng
 * (thiếu tên, SĐT sai định dạng, trùng trong file / trùng CRM, email sai) → nhập các dòng hợp lệ
 * vào chi nhánh + người phụ trách đã chọn (hoặc người ở cột "Người phụ trách" của từng dòng). Không liên quan màn nhập học phí.
 * Mở từ nút "Nhập Excel" → modal (Inertia, trang Crm/Import): mỗi bước từ modal quay lại trang đang mở (back),
 * form trong modal tải lại nội dung modal (bước kế tiếp hiện ngay trong modal); nhập xong → chuyển sang danh sách khách kèm kết quả.
 * Mở thẳng URL → trang đầy đủ (form + bảng xem trước), redirect về crm.import như cũ.
 */
class CrmImportController extends Controller
{
    use RendersModals;

    private const SESSION_KEY = 'crm_customer_import';

    private const MAX_ROWS = 1000;

    /** Cột trong file (sau khi bỏ dấu, viết thường, bỏ ký tự đặc biệt) => trường khách hàng. */
    private const HEADER_MAP = [
        'hoten' => 'name', 'hovaten' => 'name', 'ten' => 'name', 'tenkhach' => 'name', 'tenkhachhang' => 'name', 'name' => 'name',
        'sodienthoai' => 'phone', 'sdt' => 'phone', 'dienthoai' => 'phone', 'phone' => 'phone',
        'tenphuhuynh' => 'parent_name', 'phuhuynh' => 'parent_name', 'parentname' => 'parent_name',
        'sdtphuhuynh' => 'parent_phone', 'sodienthoaiphuhuynh' => 'parent_phone', 'parentphone' => 'parent_phone',
        'email' => 'email',
        'ngaysinh' => 'dob', 'dob' => 'dob',
        'gioitinh' => 'gender', 'gender' => 'gender',
        'diachi' => 'address', 'address' => 'address',
        'nguon' => 'source', 'nguonkhach' => 'source', 'source' => 'source',
        'khoahocquantam' => 'course_interest', 'khoaquantam' => 'course_interest', 'khoahoc' => 'course_interest', 'courseinterest' => 'course_interest',
        'ghichu' => 'notes', 'notes' => 'notes',
        'nguoiphutrach' => 'owner', 'phutrach' => 'owner', 'hocvuphutrach' => 'owner', 'owner' => 'owner',
    ];

    private const TEMPLATE_HEADINGS = ['Họ tên', 'Số điện thoại', 'Tên phụ huynh', 'SĐT phụ huynh', 'Email', 'Ngày sinh', 'Giới tính', 'Địa chỉ', 'Nguồn', 'Khóa học quan tâm', 'Ghi chú', 'Người phụ trách'];

    public function create(Request $request): InertiaResponse
    {
        $preview = $request->session()->get(self::SESSION_KEY);
        $user = $request->user();
        $options = $this->formOptions($user);
        $rows = collect($preview['rows'] ?? []);
        $validCount = $rows->filter(fn (array $row) => empty($row['errors']))->count();

        return $this->modalPage('Crm/Import', [
            'branches' => Ui::options($options['branches'], 'name'),
            'salesUsers' => Ui::options(LeadOwners::options($options['salesUsers'])),
            'canAssign' => $options['canAssign'],
            'userName' => $user->name,
            'defaultBranchId' => $preview['branch_id'] ?? ($options['branches']->count() === 1 ? $options['branches']->first()->id : null),
            'defaultAssigneeId' => $preview['assigned_user_id'] ?? null,
            'preview' => $preview ? [
                'file_name' => $preview['file_name'],
                'branch_name' => $preview['branch_name'],
                'assigned_user_name' => $preview['assigned_user_name'],
                'valid_count' => $validCount,
                'error_count' => $rows->count() - $validCount,
                'rows' => $rows->map(fn (array $row) => [
                    'line' => $row['line'],
                    'name' => $row['data']['name'] ?? null,
                    'phone' => $row['data']['phone'] ?? null,
                    'parent_name' => $row['data']['parent_name'] ?? null,
                    'parent_phone' => $row['data']['parent_phone'] ?? null,
                    'email' => $row['data']['email'] ?? null,
                    'source' => $row['data']['source'] ?? null,
                    'course_interest' => $row['data']['course_interest'] ?? null,
                    'owner_name' => $row['data']['owner_name'] ?? null,
                    'errors' => array_values($row['errors']),
                ])->values()->all(),
            ] : null,
        ]);
    }

    /** Sau mỗi bước: từ modal quay lại trang đang mở (modal tự tải lại bước kế tiếp); trang đầy đủ về crm.import. */
    private function backToImport(): RedirectResponse
    {
        return $this->isModalRequest() ? back() : redirect()->route('crm.import');
    }

    public function template()
    {
        return Excel::download(
            new ArrayExport(self::TEMPLATE_HEADINGS, [
                ['Nguyễn Văn An', '0912345678', 'Nguyễn Văn Bình', '0987654321', 'an.nguyen@example.com', '15/08/2015', 'Nam', 'Hà Nội', 'Facebook Ads', 'Starters', 'Muốn học buổi tối', ''],
            ]),
            'mau-nhap-khach-hang.xlsx',
            ExcelFormat::XLSX
        );
    }

    /**
     * Tải các dòng lỗi của lần xem trước (cùng cột với file mẫu + cột "Lỗi") để sửa ngay trong Excel
     * rồi tải lại — cột "Lỗi" không thuộc cột nhận diện nên được bỏ qua khi nhập.
     */
    public function errors(Request $request)
    {
        $preview = $request->session()->get(self::SESSION_KEY);
        $errorRows = collect($preview['rows'] ?? [])->filter(fn (array $row) => ! empty($row['errors']));
        if ($errorRows->isEmpty()) {
            return redirect()->route('crm.import');
        }

        $rows = $errorRows->map(fn (array $row) => [
            $row['data']['name'] ?? null,
            $row['data']['phone'] ?? null,
            $row['data']['parent_name'] ?? null,
            $row['data']['parent_phone'] ?? null,
            $row['data']['email'] ?? null,
            ($row['data']['dob_error'] ?? null) ?: (filled($row['data']['dob'] ?? null) ? Carbon::parse($row['data']['dob'])->format('d/m/Y') : null),
            $row['data']['gender'] ?? null,
            $row['data']['address'] ?? null,
            $row['data']['source'] ?? null,
            $row['data']['course_interest'] ?? null,
            $row['data']['notes'] ?? null,
            $row['data']['owner'] ?? null,
            'Dòng '.$row['line'].': '.implode('; ', $row['errors']),
        ])->values()->all();

        return Excel::download(
            new ArrayExport([...self::TEMPLATE_HEADINGS, 'Lỗi'], $rows),
            'dong-loi-nhap-khach-'.now()->format('Ymd-His').'.xlsx',
            ExcelFormat::XLSX
        );
    }

    public function preview(Request $request)
    {
        $user = $request->user();
        $options = $this->formOptions($user);
        $validated = $request->validate([
            'file' => 'required|file|max:5120|mimes:xlsx,xls,csv,txt',
            'branch_id' => 'required|integer|in:'.$options['branches']->pluck('id')->implode(','),
            'assigned_user_id' => 'nullable|integer',
            'default_source' => 'nullable|string|max:255',
        ], [
            'file.required' => 'Vui lòng chọn file Excel / CSV.',
            'file.mimes' => 'Chỉ hỗ trợ file .xlsx, .xls hoặc .csv.',
            'branch_id.in' => 'Chi nhánh không hợp lệ hoặc ngoài phạm vi của bạn.',
        ]);
        $assigneeId = $this->resolveAssignee($user, $validated['assigned_user_id'] ?? null, $options['salesUsers']);
        $assignee = User::find($assigneeId);
        if ($assigneeId !== $user->id && ! LeadOwners::belongsToBranch($assignee, (int) $validated['branch_id'])) {
            throw ValidationException::withMessages(['assigned_user_id' => "{$assignee->name} không thuộc chi nhánh nhận khách. Chọn Học vụ cùng cơ sở hoặc Admin."]);
        }
        // Cột "Người phụ trách" từng dòng: Học vụ của chi nhánh nhận khách hoặc Admin (theo tên / email).
        $rowOwners = $options['canAssign']
            ? $options['salesUsers']->filter(fn (User $owner) => LeadOwners::belongsToBranch($owner, (int) $validated['branch_id']))->values()
            : collect();

        try {
            $rawRows = RawRowsImport::firstSheetNumbered($request->file('file'));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'Không đọc được file: hãy dùng file mẫu .xlsx hoặc .csv (UTF-8). Chi tiết: '.Str::limit($e->getMessage(), 160)]);
        }
        if (count($rawRows) < 2) {
            throw ValidationException::withMessages(['file' => 'File không có dữ liệu (dòng 1 là tiêu đề, dữ liệu từ dòng 2).']);
        }

        // Khoá của $rawRows là số dòng thật trong file (dòng trống đã bỏ nhưng không đánh lại số).
        $headerLine = array_key_first($rawRows);
        $columns = $this->mapHeader($rawRows[$headerLine]);
        unset($rawRows[$headerLine]);
        if (! in_array('name', $columns, true) || ! in_array('phone', $columns, true)) {
            throw ValidationException::withMessages(['file' => 'File phải có cột "Họ tên" và "Số điện thoại" ở dòng tiêu đề (tải file mẫu để xem định dạng).']);
        }
        if (count($rawRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Mỗi lần chỉ nhập tối đa '.self::MAX_ROWS.' dòng.']);
        }

        $rows = [];
        $seenPhones = [];
        $seenEmails = [];
        foreach ($rawRows as $line => $raw) {
            $data = $this->rowData($columns, $raw);
            if (collect($data)->except('dob_error')->filter(fn ($v) => filled($v))->isEmpty()) {
                continue;
            }
            $data['source'] = $data['source'] ?: ($validated['default_source'] ?? null) ?: 'Nhập Excel';
            $ownerError = $this->resolveRowOwner($data, $options['canAssign'], $rowOwners);
            $errors = $this->validateRow($data, $seenPhones, $seenEmails, $line);
            if ($ownerError) {
                $errors[] = $ownerError;
            }
            $rows[] = ['line' => $line, 'data' => $data, 'errors' => $errors];
        }

        $request->session()->put(self::SESSION_KEY, [
            'file_name' => $request->file('file')->getClientOriginalName(),
            'branch_id' => (int) $validated['branch_id'],
            'branch_name' => $options['branches']->firstWhere('id', (int) $validated['branch_id'])?->name,
            'assigned_user_id' => $assigneeId,
            'assigned_user_name' => User::find($assigneeId)?->name,
            'rows' => $rows,
        ]);

        return $this->backToImport();
    }

    public function store(Request $request)
    {
        $preview = $request->session()->get(self::SESSION_KEY);
        if (! $preview || empty($preview['rows'])) {
            return $this->backToImport()->withErrors(['file' => 'Chưa có dữ liệu xem trước. Vui lòng tải file lên lại.']);
        }
        if ($request->boolean('cancel')) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->backToImport()->with('status', 'Đã hủy phiên nhập khách.');
        }

        $user = $request->user();
        $options = $this->formOptions($user);
        abort_unless($options['branches']->contains('id', $preview['branch_id']), 403);
        // Sales được chọn lúc xem trước có thể đã bị khoá / đổi vai trò trước khi bấm Nhập.
        $assignee = User::find($preview['assigned_user_id']);
        if (! $assignee?->is_active || ($assignee->id !== $user->id && ! $assignee->can('lead.be_assigned'))) {
            return $this->backToImport()->withErrors(['assigned_user_id' => 'Người phụ trách đã chọn không còn hoạt động. Vui lòng chọn lại và kiểm tra dữ liệu lần nữa.']);
        }
        // File lớn (tới 1.000 dòng) trên hosting có giới hạn thời gian chạy ngắn.
        @set_time_limit(300);

        $created = 0;
        $skipped = [];
        foreach ($preview['rows'] as $row) {
            if (! empty($row['errors'])) {
                $skipped[] = "Dòng {$row['line']}: ".implode('; ', $row['errors']);

                continue;
            }
            $data = $row['data'];
            // Kiểm tra lại lúc nhập: có thể đã có người tạo khách cùng SĐT sau khi xem trước.
            $phoneNormalized = CrmCustomer::normalizePhone($data['phone']);
            $seen = [];
            $seenEmails = [];
            $errors = $this->validateRow($data, $seen, $seenEmails);
            if (! empty($data['owner_id']) && ! LeadOwners::isCandidate(User::find($data['owner_id']))) {
                $errors[] = 'Người phụ trách '.($data['owner_name'] ?? '').' không còn hoạt động';
            }
            if ($errors) {
                $skipped[] = "Dòng {$row['line']}: ".implode('; ', $errors);

                continue;
            }

            try {
                DB::transaction(function () use ($data, $phoneNormalized, $preview, $user) {
                    $customer = CrmCustomer::create([
                        'code' => CrmCustomer::generateCode(),
                        'name' => $data['name'],
                        'phone' => $data['phone'],
                        'phone_normalized' => $phoneNormalized,
                        'parent_name' => $data['parent_name'] ?: null,
                        'parent_phone' => $data['parent_phone'] ?: null,
                        'email' => $data['email'] ?: null,
                        'dob' => $data['dob'] ?: null,
                        'gender' => $data['gender'] ?: null,
                        'address' => $data['address'] ?: null,
                        'source' => $data['source'],
                        'course_interest' => $data['course_interest'] ?: null,
                        'notes' => $data['notes'] ?: null,
                        'branch_id' => $preview['branch_id'],
                        'assigned_user_id' => ($data['owner_id'] ?? null) ?: $preview['assigned_user_id'],
                        'stage' => 'new',
                        'deal_value' => 0,
                    ]);
                    CrmCustomerHistory::create([
                        'customer_id' => $customer->id,
                        'user_id' => $user->id,
                        'type' => 'system',
                        'content' => 'Thêm khách hàng qua nhập Excel (file '.($preview['file_name'] ?? '').', nguồn: '.$customer->source.').',
                    ]);
                });
                $created++;
            } catch (UniqueConstraintViolationException) {
                $skipped[] = "Dòng {$row['line']}: SĐT hoặc email vừa được tạo bởi phiên khác.";
            } catch (QueryException $e) {
                // Một dòng dữ liệu lạ không được làm hỏng cả lần nhập (các dòng khác vẫn nhập tiếp).
                report($e);
                $skipped[] = "Dòng {$row['line']}: dữ liệu không lưu được, vui lòng kiểm tra lại các cột của dòng này.";
            }
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('crm.customers.index')
            ->with('status', "Đã nhập {$created} khách hàng mới vào {$preview['branch_name']}.".($skipped ? ' Bỏ qua '.count($skipped).' dòng lỗi.' : ''))
            ->with('import_skipped', $skipped);
    }

    /**
     * @return array{branches: Collection, salesUsers: Collection, canAssign: bool}
     */
    protected function formOptions(User $user): array
    {
        $allBranches = DataScope::isAll($user, 'lead');
        $branches = $allBranches
            ? Branch::where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : Branch::whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']);
        $canAssign = $user->can('lead.assign');
        // Người phụ trách: Admin + Học vụ (phạm vi chi nhánh: Học vụ các chi nhánh của mình).
        $salesUsers = $canAssign
            ? LeadOwners::candidates($allBranches ? null : $branches->pluck('id')->map(fn ($id) => (int) $id)->all())
            : collect([$user]);

        return compact('branches', 'salesUsers', 'canAssign');
    }

    protected function resolveAssignee(User $user, mixed $requested, Collection $allowed): int
    {
        if (! $user->can('lead.assign') || ! $requested) {
            return $user->id;
        }
        if (! $allowed->contains('id', (int) $requested)) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Người phụ trách không hợp lệ hoặc ngoài chi nhánh của bạn.']);
        }

        return (int) $requested;
    }

    /** Gán owner_id / owner_name cho dòng có cột "Người phụ trách"; trả lỗi nếu không hợp lệ. */
    protected function resolveRowOwner(array &$data, bool $canAssign, Collection $owners): ?string
    {
        $value = trim((string) ($data['owner'] ?? ''));
        $data['owner_id'] = null;
        $data['owner_name'] = null;
        if ($value === '') {
            return null;
        }
        if (! $canAssign) {
            return 'Bạn không có quyền chọn người phụ trách (xóa cột Người phụ trách)';
        }
        $owner = LeadOwners::match($owners, $value);
        if (! $owner) {
            return "Người phụ trách \"{$value}\" không phải Học vụ của chi nhánh nhận khách hoặc Admin (ghi đúng họ tên hoặc email)";
        }
        $data['owner_id'] = $owner->id;
        $data['owner_name'] = $owner->name;

        return null;
    }

    /** @return array<int, string|null> vị trí cột => trường */
    protected function mapHeader(array $header): array
    {
        $columns = [];
        foreach ($header as $position => $label) {
            $key = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $label)));
            $columns[$position] = self::HEADER_MAP[$key] ?? null;
        }

        return $columns;
    }

    /** @return array<string, string|null> */
    protected function rowData(array $columns, array $raw): array
    {
        $data = array_fill_keys(array_unique(array_values(self::HEADER_MAP)), null);
        foreach ($columns as $position => $field) {
            if (! $field) {
                continue;
            }
            $value = $raw[$position] ?? null;
            if ($field === 'dob') {
                $dob = $this->parseDob($value);
                $data['dob'] = $dob === false ? null : $dob;
                $data['dob_error'] = $dob === false ? trim((string) $value) : null;

                continue;
            }
            $value = is_float($value) && floor($value) === $value ? (string) (int) $value : trim((string) $value);
            // Excel hay mất số 0 đầu của SĐT (lưu dạng số): bổ sung lại nếu còn 9 số (di động) hoặc 10 số bắt đầu bằng 2 (máy bàn 02x).
            if (in_array($field, ['phone', 'parent_phone'], true) && preg_match('/^([1-9]\d{8}|2\d{9})$/', $value)) {
                $value = '0'.$value;
            }
            $data[$field] = $value === '' ? null : Str::limit($value, $field === 'notes' ? 1000 : 255, '');
        }

        return $data;
    }

    /**
     * Ngày sinh: số seri ngày của Excel, dd/mm/yyyy (chấp nhận - và .) hoặc yyyy-mm-dd.
     * Trả null khi ô trống, false khi không hiểu được / ngày không tồn tại (31/02...) để báo lỗi thay vì lưu sai ngày.
     */
    protected function parseDob(mixed $value): string|false|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        try {
            // Số seri Excel (ô kiểu Date). Số nhỏ (vd. chỉ gõ năm "2015") không phải ngày sinh hợp lệ.
            if (is_numeric($value)) {
                return (float) $value >= 10000
                    ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString()
                    : false;
            }
            if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
                [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$#', $value, $m)) {
                [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } else {
                return false;
            }
            if (! checkdate($month, $day, $year) || $year < 1900) {
                return false;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lỗi của 1 dòng (rỗng = hợp lệ). $seenPhones / $seenEmails dùng phát hiện trùng trong cùng file.
     *
     * @return list<string>
     */
    protected function validateRow(array $data, array &$seenPhones, array &$seenEmails, int $line = 0): array
    {
        $errors = [];
        $normalized = null;
        $email = null;
        if (blank($data['name'])) {
            $errors[] = 'Thiếu họ tên';
        }
        if (blank($data['phone'])) {
            $errors[] = 'Thiếu số điện thoại';
        } elseif (! CrmCustomer::isValidVietnamesePhone($data['phone'])) {
            $errors[] = 'SĐT sai định dạng (10 số bắt đầu bằng 0 hoặc +84)';
        } else {
            $normalized = CrmCustomer::normalizePhone($data['phone']);
            if (isset($seenPhones[$normalized])) {
                $errors[] = "Trùng SĐT với dòng {$seenPhones[$normalized]} trong file";
            } elseif ($existing = CrmCustomer::where('phone_normalized', $normalized)->first(['code'])) {
                $errors[] = "SĐT đã có trong CRM ({$existing->short_code})";
            }
        }
        if (filled($data['parent_phone']) && (! CrmCustomer::isValidVietnamesePhone($data['parent_phone']) || mb_strlen($data['parent_phone']) > 20)) {
            $errors[] = 'SĐT phụ huynh sai định dạng';
        }
        if (filled($data['email'])) {
            $email = Str::lower($data['email']);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email sai định dạng';
            } elseif (isset($seenEmails[$email])) {
                $errors[] = 'Trùng email trong file';
            } elseif (CrmCustomer::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $errors[] = 'Email đã có trong CRM';
            }
        }
        if (filled($data['dob_error'] ?? null)) {
            $errors[] = "Ngày sinh không hợp lệ ({$data['dob_error']}), nhập dạng ngày/tháng/năm, vd. 15/08/2015";
        }
        if (filled($data['gender']) && mb_strlen($data['gender']) > 20) {
            $errors[] = 'Giới tính quá dài (tối đa 20 ký tự, vd. Nam / Nữ)';
        }

        // Chỉ dòng hợp lệ mới "giữ chỗ" SĐT / email: dòng lỗi bị bỏ qua nên không được chặn dòng hợp lệ trùng số phía sau.
        if (! $errors) {
            if ($normalized !== null) {
                $seenPhones[$normalized] = $line;
            }
            if ($email !== null) {
                $seenEmails[$email] = true;
            }
        }

        return $errors;
    }
}
