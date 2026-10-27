<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentAccountService;
use App\Support\DataScope;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Màn "Tài khoản học viên" (docs/tai-khoan-hoc-vien.md): Học vụ tạo tài khoản cổng học viên / cấp lại mật khẩu tạm theo lô
 * cho học viên trong phạm vi, rồi tải file đăng nhập. Mật khẩu tạm chỉ nằm trong session (mã hoá) tới lần tải đầu tiên.
 */
class StudentAccountController extends Controller
{
    /** Khoá session giữ thông tin đăng nhập chờ tải (mã hoá). */
    private const SESSION_KEY = 'student_accounts.credentials';

    private const MAX_BATCH = 200;

    public function __construct(private readonly StudentAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $students = $this->filteredStudents($user, $filters)
            ->with(['user:id,employee_code,email', 'currentClass:id,name', 'branch:id,name'])
            ->orderBy('name')
            ->paginate($request->perPage(50))->withQueryString();
        $pending = $this->pendingCredentials();

        return Inertia::render('Students/Accounts', [
            'students' => $students->through(fn (Student $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'class' => $s->currentClass?->name,
                'branch' => $s->branch?->name,
                'login' => $s->user?->loginIdentifier(),
            ]),
            'filters' => $filters,
            // Số học viên chưa có tài khoản khớp bộ lọc (mọi trang) — nút "Tạo tài khoản" khi chưa chọn dòng nào.
            'creatableTotal' => $this->filteredStudents($user, $filters)->whereNull('user_id')->count(),
            'statusOptions' => Ui::options(Student::STATUSES),
            'branchOptions' => Ui::options($this->visibleBranches($user), 'name'),
            'classOptions' => Ui::options($this->visibleClasses($user), 'name'),
            'download' => $pending ? ['count' => count($pending)] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['create', 'reset'])],
            // all = tạo cho mọi học viên chưa có tài khoản khớp bộ lọc (không cần chọn dòng), tối đa MAX_BATCH mỗi lần bấm.
            'scope' => ['nullable', Rule::in(['all'])],
            'student_ids' => 'required_unless:scope,all|array|min:1|max:'.self::MAX_BATCH,
            'student_ids.*' => 'integer|distinct',
        ], ['student_ids.required_unless' => 'Chọn ít nhất 1 học viên.']);
        $all = ($validated['scope'] ?? null) === 'all';
        abort_if($all && $validated['action'] !== 'create', 422);

        $remaining = 0;
        if ($all) {
            $query = $this->filteredStudents($request->user(), $this->filters($request))->whereNull('user_id');
            $total = (clone $query)->count();
            $students = $query->with(['user', 'currentClass:id,name'])->orderBy('name')->limit(self::MAX_BATCH)->get();
            $remaining = $total - $students->count();
        } else {
            $students = Student::visibleTo($request->user())->with(['user', 'currentClass:id,name'])
                ->whereIn('id', $validated['student_ids'])->orderBy('name')->get();
            abort_if($students->count() !== count($validated['student_ids']), 404);
        }

        $credentials = [];
        $skipped = [];
        foreach ($students as $student) {
            try {
                $password = $validated['action'] === 'create'
                    ? $this->createFor($student)
                    : $this->resetFor($student);
            } catch (ValidationException $e) {
                $skipped[] = rtrim($e->getMessage(), '.');

                continue;
            }
            $credentials[] = [$student->code, $student->name, $student->currentClass?->name, $student->user->loginIdentifier(), $password];
        }

        $done = count($credentials);
        if ($done > 0) {
            // Gộp với lô trước chưa tải để không mất mật khẩu đã cấp.
            $merged = [...($this->pendingCredentials() ?? []), ...$credentials];
            $request->session()->put(self::SESSION_KEY, Crypt::encryptString(json_encode($merged)));
        }
        // Nhật ký không chứa mật khẩu tạm.
        activity('Người dùng & Phân quyền')->causedBy($request->user())
            ->withProperties(['action' => $validated['action'], 'scope' => $all ? 'all' : 'selected', 'student_ids' => $students->pluck('id')->all(), 'done' => $done])
            ->log($validated['action'] === 'create' ? 'Tạo tài khoản học viên theo lô' : 'Cấp lại mật khẩu tạm học viên theo lô');

        $verb = $validated['action'] === 'create' ? 'Đã tạo tài khoản cho' : 'Đã cấp lại mật khẩu tạm cho';
        $message = "{$verb} {$done} học viên.".($skipped ? ' Bỏ qua: '.implode('; ', $skipped).'.' : '')
            .($remaining > 0 ? " Còn {$remaining} học viên chưa có tài khoản, bấm \"Tạo tài khoản\" lần nữa để tạo tiếp." : '');

        return redirect()->route('students.accounts.index', $all ? array_filter($this->filters($request)) : [])
            ->with($done > 0 ? 'status' : 'error', $done > 0 ? $message.' Tải file đăng nhập để gửi phụ huynh.' : $message);
    }

    /** File Excel tên đăng nhập + mật khẩu tạm — tải một lần rồi xoá khỏi session; đánh dấu "Đã gửi tài khoản". */
    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        $credentials = $this->pendingCredentials();
        $request->session()->forget(self::SESSION_KEY);
        if (! $credentials) {
            return redirect()->route('students.accounts.index')
                ->withErrors(['download' => 'File đăng nhập chỉ tải được một lần. Hãy cấp lại mật khẩu tạm cho học viên cần gửi.']);
        }

        $codes = array_column($credentials, 0);
        ClassEnrollment::query()->whereNull('left_at')
            ->whereIn('student_id', Student::visibleTo($request->user())->whereIn('code', $codes)->select('id'))
            ->update(['account_sent' => true]);

        return ArrayExport::download('tai-khoan-hoc-vien', ['Mã học viên', 'Họ tên', 'Lớp', 'Tên đăng nhập', 'Mật khẩu tạm'], $credentials);
    }

    /** @return array{status: string, account: ?string, branch_id: ?int, class_id: ?int, search: ?string} */
    private function filters(Request $request): array
    {
        return [
            'status' => array_key_exists((string) $request->input('status'), Student::STATUSES) ? $request->input('status') : 'studying',
            'account' => in_array($request->input('account'), ['none', 'has'], true) ? $request->input('account') : null,
            'branch_id' => $request->integer('branch_id') ?: null,
            'class_id' => $request->integer('class_id') ?: null,
            'search' => $request->input('search') ?: null,
        ];
    }

    /** Học viên trong phạm vi người xem khớp bộ lọc màn hình. */
    private function filteredStudents(User $user, array $filters)
    {
        return Student::visibleTo($user)
            ->where('status', $filters['status'])
            ->when($filters['account'] === 'none', fn ($q) => $q->whereNull('user_id'))
            ->when($filters['account'] === 'has', fn ($q) => $q->whereNotNull('user_id'))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['class_id'], fn ($q, $id) => $q->inClasses($id))
            ->when($filters['search'], fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")));
    }

    private function createFor(Student $student): string
    {
        return $this->accounts->create($student);
    }

    private function resetFor(Student $student): string
    {
        if (! $student->user) {
            throw ValidationException::withMessages(['student' => "{$student->code}: chưa có tài khoản."]);
        }
        try {
            return $this->accounts->resetPassword($student->user);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['student' => "{$student->code}: tài khoản dùng chung với nhân sự, liên hệ Admin."]);
        }
    }

    /** @return list<array<int, ?string>>|null thông tin đăng nhập chờ tải */
    private function pendingCredentials(): ?array
    {
        $payload = session(self::SESSION_KEY);

        return $payload ? json_decode(Crypt::decryptString($payload), true) : null;
    }

    private function visibleBranches(User $user)
    {
        return DataScope::isAll($user, 'student')
            ? Branch::orderBy('name')->get(['id', 'name'])
            : Branch::whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']);
    }

    private function visibleClasses(User $user)
    {
        $query = match (DataScope::level($user, 'student')) {
            DataScope::ALL => ClassModel::query(),
            DataScope::BRANCH => ClassModel::query()->whereIn('branch_id', $user->branchIds()),
            default => ClassModel::query()->visibleTo($user),
        };

        return $query->whereNotIn('status', ['completed', 'cancelled', 'closed'])->orderBy('name')->get(['id', 'name']);
    }
}
