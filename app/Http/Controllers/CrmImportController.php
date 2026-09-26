<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Imports\RawRowsImport;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * "Nhập khách hàng loạt từ Excel" (mockup CRM): tải file .xlsx / .csv → xem trước + lỗi từng dòng
 * (thiếu tên, SĐT sai định dạng, trùng trong file / trùng CRM, email sai) → nhập các dòng hợp lệ
 * vào chi nhánh + Sales phụ trách đã chọn. Không liên quan màn nhập học phí.
 */
class CrmImportController extends Controller
{
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
    ];

    private const TEMPLATE_HEADINGS = ['Họ tên', 'Số điện thoại', 'Tên phụ huynh', 'SĐT phụ huynh', 'Email', 'Ngày sinh', 'Giới tính', 'Địa chỉ', 'Nguồn', 'Khóa học quan tâm', 'Ghi chú'];

    public function create(Request $request)
    {
        $preview = $request->session()->get(self::SESSION_KEY);

        return view('crm.import', $this->formOptions($request->user()) + ['preview' => $preview]);
    }

    public function template()
    {
        return Excel::download(
            new ArrayExport(self::TEMPLATE_HEADINGS, [
                ['Nguyễn Văn An', '0912345678', 'Nguyễn Văn Bình', '0987654321', 'an.nguyen@example.com', '15/08/2015', 'Nam', 'Hà Nội', 'Facebook Ads', 'Starters', 'Muốn học buổi tối'],
            ]),
            'mau-nhap-khach-hang.xlsx',
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

        try {
            $sheets = Excel::toArray(new RawRowsImport, $request->file('file'));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'Không đọc được file: hãy dùng file mẫu .xlsx hoặc .csv (UTF-8).']);
        }
        $rawRows = $sheets[0] ?? [];
        if (count($rawRows) < 2) {
            throw ValidationException::withMessages(['file' => 'File không có dữ liệu (dòng 1 là tiêu đề, dữ liệu từ dòng 2).']);
        }

        $columns = $this->mapHeader(array_shift($rawRows));
        if (! in_array('name', $columns, true) || ! in_array('phone', $columns, true)) {
            throw ValidationException::withMessages(['file' => 'File phải có cột "Họ tên" và "Số điện thoại" ở dòng tiêu đề (tải file mẫu để xem định dạng).']);
        }
        if (count($rawRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Mỗi lần chỉ nhập tối đa '.self::MAX_ROWS.' dòng.']);
        }

        $rows = [];
        $seenPhones = [];
        $seenEmails = [];
        foreach ($rawRows as $index => $raw) {
            $data = $this->rowData($columns, $raw);
            if (collect($data)->filter(fn ($v) => filled($v))->isEmpty()) {
                continue;
            }
            $data['source'] = $data['source'] ?: ($validated['default_source'] ?? null) ?: 'Nhập Excel';
            $errors = $this->validateRow($data, $seenPhones, $seenEmails, $index + 2);
            $rows[] = ['line' => $index + 2, 'data' => $data, 'errors' => $errors];
        }

        $request->session()->put(self::SESSION_KEY, [
            'file_name' => $request->file('file')->getClientOriginalName(),
            'branch_id' => (int) $validated['branch_id'],
            'branch_name' => $options['branches']->firstWhere('id', (int) $validated['branch_id'])?->name,
            'assigned_user_id' => $assigneeId,
            'assigned_user_name' => User::find($assigneeId)?->name,
            'rows' => $rows,
        ]);

        return redirect()->route('crm.import');
    }

    public function store(Request $request)
    {
        $preview = $request->session()->get(self::SESSION_KEY);
        if (! $preview || empty($preview['rows'])) {
            return redirect()->route('crm.import')->withErrors(['file' => 'Chưa có dữ liệu xem trước. Vui lòng tải file lên lại.']);
        }
        if ($request->boolean('cancel')) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('crm.import')->with('status', 'Đã hủy phiên nhập khách.');
        }

        $user = $request->user();
        $options = $this->formOptions($user);
        abort_unless($options['branches']->contains('id', $preview['branch_id']), 403);

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
                        'assigned_user_id' => $preview['assigned_user_id'],
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
        $allBranches = \App\Support\DataScope::isAll($user, 'lead');
        $branches = $allBranches
            ? Branch::where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : Branch::whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']);
        $canAssign = $user->can('lead.assign');
        $salesUsers = $canAssign
            ? \App\Support\Rbac::scopeUsersWithPermission(User::query()->where('is_active', true), 'lead.be_assigned')
                ->when(! $allBranches, fn ($q) => $q->where(fn ($inner) => $inner
                    ->whereIn('branch_id', $branches->pluck('id'))
                    ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branches->pluck('id')))))
                ->orderBy('name')->get(['id', 'name', 'email'])
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
                $data['dob'] = $this->parseDob($value);

                continue;
            }
            $value = is_float($value) && floor($value) === $value ? (string) (int) $value : trim((string) $value);
            // Excel hay mất số 0 đầu của SĐT (lưu dạng số): bổ sung lại nếu còn 9 số.
            if (in_array($field, ['phone', 'parent_phone'], true) && preg_match('/^[1-9]\d{8}$/', $value)) {
                $value = '0'.$value;
            }
            $data[$field] = $value === '' ? null : Str::limit($value, $field === 'notes' ? 1000 : 255, '');
        }

        return $data;
    }

    protected function parseDob(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }
            $value = trim((string) $value);

            return (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value) ? Carbon::createFromFormat('d/m/Y', $value) : Carbon::parse($value))->toDateString();
        } catch (\Throwable) {
            return null;
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
            } else {
                $seenPhones[$normalized] = $line;
                $existing = CrmCustomer::where('phone_normalized', $normalized)->first(['code']);
                if ($existing) {
                    $errors[] = "SĐT đã có trong CRM ({$existing->code})";
                }
            }
        }
        if (filled($data['parent_phone']) && ! CrmCustomer::isValidVietnamesePhone($data['parent_phone'])) {
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
            } else {
                $seenEmails[$email] = true;
            }
        }

        return $errors;
    }
}
