<?php

namespace App\Http\Controllers;

use App\Exports\StaffImportTemplateExport;
use App\Helpers\AclHelper;
use App\Jobs\ImportStaff;
use App\Models\BackgroundTask;
use App\Models\Branch;
use App\Services\StaffImportService;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Nhập nhân sự từ Excel — 3 bước: Tải file → Xem trước (lỗi / cảnh báo từng dòng) → Kết quả (mã đăng nhập vừa cấp).
 * Quyền tạo tài khoản (route can:user.create); vai trò / cơ sở kiểm tra theo người thao tác như form Thêm nhân sự.
 */
class StaffImportController extends Controller
{
    /** Mật khẩu ban đầu điền sẵn (quy ước của trung tâm); tài khoản mới vẫn bắt buộc đổi ở lần đăng nhập đầu. */
    public const DEFAULT_PASSWORD = 'staff@123';

    public function index(Request $request)
    {
        $preview = $request->filled('token') ? $request->session()->get('staff_import.'.$request->input('token')) : null;
        $done = BackgroundTask::importResult(BackgroundTask::STAFF_IMPORT, $request->input('task'), $request->user()->id);

        return Inertia::render('Users/Import', [
            'branches' => $this->branches($request)->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->values(),
            'roles' => collect(Rbac::assignableRoles($request->user()))
                ->reject(fn (string $role) => in_array($role, [Roles::ADMIN, Roles::STUDENT], true))
                ->map(fn (string $role) => ['value' => $role, 'label' => AclHelper::shortRoleLabel($role)])->values(),
            'preview' => $preview ? [
                'token' => $request->input('token'),
                'file_name' => $preview['file_name'],
                'rows' => collect($preview['rows'])->map(fn (array $r) => [
                    'line' => $r['line'],
                    'action' => $r['action'],
                    'employee_code' => $r['employee_code'],
                    'name' => $r['name'],
                    'email' => $r['email'],
                    'role' => $r['role_label'],
                    'branch' => $r['branch_name'],
                    'phone' => $r['profile']['phone'],
                    'date_of_birth' => $r['profile']['date_of_birth'] ? date('d/m/Y', strtotime($r['profile']['date_of_birth'])) : null,
                    'joined_on' => $r['profile']['joined_on'] ? date('d/m/Y', strtotime($r['profile']['joined_on'])) : null,
                    'errors' => $r['errors'],
                    'warnings' => $r['warnings'],
                ])->values(),
            ] : null,
            'result' => $done['result'],
            'backgroundTasks' => $done['backgroundTasks'],
            'headings' => implode(', ', StaffImportService::HEADINGS),
            'defaultPassword' => self::DEFAULT_PASSWORD,
        ]);
    }

    public function preview(Request $request, StaffImportService $importer)
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'role' => 'nullable|string|exists:roles,name',
            'password' => 'required|string|min:8',
            'excel_file' => 'required|file|max:10240|mimes:xlsx,xls,csv,txt',
        ], [
            'excel_file.required' => 'Vui lòng chọn file Excel nhân sự.',
            'excel_file.mimes' => 'Chỉ hỗ trợ file .xlsx, .xls hoặc .csv.',
            'password.required' => 'Vui lòng nhập mật khẩu ban đầu.',
            'password.min' => 'Mật khẩu ban đầu tối thiểu 8 ký tự.',
        ]);

        try {
            $parsed = $importer->parse(
                $request->file('excel_file'),
                isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                $validated['role'] ?? null,
                $request->user(),
            );
        } catch (\Throwable $e) {
            Log::warning('Không đọc được file nhập nhân sự: '.$e->getMessage());

            return redirect()->route('users.import')->withErrors(['excel_file' => 'Không đọc được file. Vui lòng dùng file mẫu (.xlsx).']);
        }

        if ($parsed['missing']) {
            return redirect()->route('users.import')->withErrors(['excel_file' => $parsed['missing']]);
        }
        if ($parsed['rows'] === []) {
            return redirect()->route('users.import')->withErrors(['excel_file' => 'File không có nhân sự nào.']);
        }

        // Chỉ giữ mật khẩu đã băm giữa hai bước.
        $token = (string) Str::uuid();
        $request->session()->put('staff_import.'.$token, $parsed + [
            'file_name' => $request->file('excel_file')->getClientOriginalName(),
            'password_hash' => Hash::make($validated['password']),
        ]);

        return redirect()->route('users.import', ['token' => $token]);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string']);
        $preview = $request->session()->pull('staff_import.'.$validated['token']);
        if (! $preview) {
            return redirect()->route('users.import')->withErrors(['excel_file' => 'Phiên xem trước đã hết hạn, vui lòng tải file lên lại.']);
        }

        // Quyền có thể đổi giữa hai bước: kiểm lại vai trò + cơ sở lúc ghi.
        $managed = DataScope::level($request->user(), 'user') === DataScope::BRANCH ? $request->user()->branchIds() : null;
        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== [] || $row['action'] !== 'create') {
                continue;
            }
            abort_unless(Rbac::canAssignRole($request->user(), $row['role']), 403, 'Bạn không được tạo tài khoản vai trò này.');
            abort_if($managed !== null && ! in_array((int) $row['branch_id'], $managed, true), 403, 'Bạn chỉ được tạo nhân sự cho cơ sở mình quản lý.');
        }

        // Tạo tài khoản chạy nền (job ImportStaff); trang kết quả tự cập nhật khi xong.
        $task = BackgroundTask::query()->create([
            'type' => BackgroundTask::STAFF_IMPORT,
            'user_id' => $request->user()->id,
            'status' => BackgroundTask::QUEUED,
            'title' => 'Nhập nhân sự: '.$preview['file_name'],
            'payload' => ['preview' => $preview],
        ]);
        ImportStaff::dispatch($task);

        return redirect()->route('users.import', ['task' => $task->id])
            ->with('status', 'Đang tạo tài khoản ở nền. Có thể rời trang, kết quả hiện ở đây và trong thông báo khi xong.');
    }

    public function template()
    {
        return Excel::download(new StaffImportTemplateExport, 'mau-nhap-nhan-su.xlsx');
    }

    private function branches(Request $request)
    {
        $managed = DataScope::level($request->user(), 'user') === DataScope::BRANCH ? $request->user()->branchIds() : null;

        return Branch::query()->active()
            ->when($managed !== null, fn ($q) => $q->whereIn('id', $managed))
            ->orderBy('name')->get(['id', 'name']);
    }
}
