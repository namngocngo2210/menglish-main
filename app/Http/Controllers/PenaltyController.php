<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\Penalty;
use App\Models\User;
use App\Services\SafeUploadService;
use App\Support\Money;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Kỷ luật nhân sự (BPMN 9b): ghi nhận vi phạm → giải trình → HT/CM chốt theo
 * loại lỗi và mức phạt → nộp trong 2 ngày → quá hạn chưa nộp thì trừ lương.
 */
class PenaltyController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        // Tài khoản cổng học viên không có hồ sơ kỷ luật nhân sự.
        abort_if($user->can('portal.student') && ! $user->can('violation.view'), 403);
        $canViewAll = $user->can('violation.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_merge(array_keys(Penalty::statusLabels()), ['overdue', 'open']))],
            'category' => ['nullable', Rule::in(array_keys(Penalty::CATEGORIES))],
            'step' => ['nullable', Rule::in(array_keys(Penalty::STEPS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $penalties = Penalty::with(['user', 'classModel', 'reporter', 'decider', 'remedier', 'payrollRecord.period'])
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('violation_type', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                            ->orWhere('employee_code', 'like', "%{$search}%"));
                });
            })
            ->when($validated['status'] ?? null, function ($query, string $status) {
                match ($status) {
                    'overdue' => $query->where('status', 'fined')->whereDate('due_date', '<', today()->toDateString()),
                    'open' => $query->whereIn('status', Penalty::OPEN_STATUSES),
                    default => $query->where('status', $status),
                };
            })
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('error_category', $category))
            ->when($validated['step'] ?? null, fn ($query, string $step) => $query->atStep($step))
            ->when($validated['from'] ?? null, fn ($query, string $from) => $query->whereDate('violation_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, string $to) => $query->whereDate('violation_date', '<=', $to))
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        $users = $canViewAll
            ? User::with('roles:id,name')->where('is_active', true)->whereNotIn('id', \App\Support\Rbac::scopeUsersWithPermission(User::query(), 'portal.student')->select('id'))->orderBy('name')->get()
            : collect();
        $classes = $canViewAll ? ClassModel::orderBy('name')->get() : collect();

        $counts = Penalty::query()
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $overdueCount = Penalty::query()
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->where('status', 'fined')->whereDate('due_date', '<', today()->toDateString())->count();

        // Ngày thuộc kỳ lương đã khóa: nút "Chốt mức phạt" bị khóa trên dòng tương ứng.

        return Inertia::render('Penalties/Index', [
            'penalties' => $penalties->through(function (Penalty $pen) use ($user) {
                [$employeeState, $employeeColor] = $pen->employee_state;

                return [
                    'id' => $pen->id,
                    'code' => $pen->code,
                    'user_name' => $pen->user?->name,
                    'employee_code' => $pen->user?->employee_code,
                    'violation_date' => $pen->violation_date->format('d/m/Y'),
                    // Giờ vi phạm chỉ có ở biên bản lập tay theo luật 24h (kèm bằng chứng); biên bản cũ / tự động chỉ có ngày.
                    'violation_time' => $pen->evidence_path && $pen->violation_at ? $pen->violation_at->format('H:i') : null,
                    'evidence_url' => $pen->evidence_path ? route('penalties.evidence', $pen->id) : null,
                    'source_label' => $pen->source_label,
                    'violation_type' => $pen->violation_type,
                    'category_label' => $pen->category_label,
                    'confirmer_label' => $pen->confirmer_label,
                    'step' => $pen->step,
                    'step_label' => $pen->step_label,
                    'status' => $pen->status,
                    'status_label' => $pen->status_label,
                    'overdue' => $pen->isOverdue(),
                    'amount' => (float) $pen->amount,
                    'due_date' => $pen->due_date?->format('d/m/Y'),
                    'paid_at' => $pen->paid_at?->format('d/m/Y'),
                    'employee_state' => $employeeState,
                    'employee_color' => $employeeColor,
                    'remedied' => (bool) $pen->remedied_at,
                    'class_name' => $pen->classModel?->name,
                    'reporter' => $pen->reporter?->name,
                    'notes' => $pen->notes,
                    'explanation' => $pen->explanation,
                    'decision_note' => $pen->decision_note,
                    'decider' => $pen->decider?->name,
                    'remedy' => $pen->remedied_at
                        ? $pen->remedied_at->format('d/m/Y').' — '.$pen->remedier?->name.($pen->remedy_note ? ': '.$pen->remedy_note : '')
                        : null,
                    'can_decide' => $user->can('violation.confirm_fine') && $pen->canBeDecidedBy($user)
                        && in_array($pen->status, ['pending', 'explained', 'confirmed'], true),
                    'can_explain' => $pen->user_id === $user->id && $pen->status === 'pending',
                ];
            }),
            'users' => $users->map(fn (User $u) => [
                'value' => $u->id,
                'label' => $u->name.($u->employee_code ? ' — '.$u->employee_code : '').' ('.$u->email.')',
                'roles' => $u->roles->pluck('name')->values(),
            ])->values(),
            'classes' => Ui::options($classes, 'name'),
            'canViewAll' => $canViewAll,
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'deciding' => (int) (($counts['explained'] ?? 0) + ($counts['confirmed'] ?? 0)),
                'fined' => max(0, (int) ($counts['fined'] ?? 0) - $overdueCount),
                'overdue' => $overdueCount,
            ],
            'steps' => Penalty::STEPS,
            'categoryOptions' => Ui::options(collect(Penalty::CATEGORIES)->map(fn ($c) => $c['label'])),
            'categoryConfirmerOptions' => Ui::options(collect(Penalty::CATEGORIES)->map(fn ($c) => $c['label'].' — '.$c['confirmer'])),
            'statusOptions' => Ui::options(['open' => 'Đang xử lý (chưa đóng)', 'overdue' => 'Quá hạn nộp'] + Penalty::statusLabels()),
            // Lỗi thường gặp theo vai trò: ô "Lỗi vi phạm" chỉ gợi ý lỗi của vai trò nhân sự được chọn.
            'roleViolations' => collect(array_keys(Penalty::ROLE_VIOLATIONS))->mapWithKeys(fn (string $role) => [$role => [
                'label' => AclHelper::shortRoleLabel($role),
                'violations' => collect(Penalty::commonViolationsFor([$role]))
                    ->map(fn (string $category, string $type) => ['value' => $type, 'category' => $category])->values(),
            ]]),
            'allViolations' => collect(Penalty::commonViolationsFor([]))
                ->map(fn (string $category, string $type) => ['value' => $type, 'category' => $category])->values(),
            'lockedPenalty' => session('locked_penalty'),
            'paymentDueDays' => Penalty::paymentDueDays(),
            // Ô "Thời điểm vi phạm" chỉ cho chọn trong N giờ gần nhất (SLA penalty.record_window; server kiểm tra lại khi lưu).
            'violationWindow' => [
                'hours' => Penalty::recordWindowHours(),
                'min' => now()->subHours(Penalty::recordWindowHours())->format('Y-m-d\\TH:i'),
                'max' => now()->format('Y-m-d\\TH:i'),
            ],
        ]);
    }

    /**
     * Ghi nhận vi phạm: chưa cần số tiền (mức phạt do HT/CM chốt sau khi nhân viên giải trình).
     * Chủ dự án chốt: chỉ ghi nhận trong vòng 24h kể từ lúc vi phạm và bắt buộc kèm bằng chứng (ảnh / PDF).
     * Biên bản hệ thống tự lập (quá SLA chăm sóc tháng đầu...) tạo thẳng qua model nên không bị giới hạn này.
     */
    public function storePenalty(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'error_category' => ['nullable', Rule::in(array_keys(Penalty::CATEGORIES))],
            'violation_type' => 'required|string|max:255',
            'violation_at' => 'required|date',
            // Mức phạt đề xuất (không bắt buộc) — mức chính thức do người chốt quyết định.
            'amount' => 'nullable|numeric|min:0',
            'class_id' => 'nullable|exists:classes,id',
            'notes' => 'nullable|string|max:500',
            'evidence' => 'required|file|max:'.Penalty::EVIDENCE_MAX_KB.'|mimes:'.implode(',', Penalty::EVIDENCE_EXTENSIONS),
        ], [
            'violation_at.required' => 'Vui lòng nhập thời điểm vi phạm.',
            'evidence.required' => 'Bắt buộc đính kèm bằng chứng vi phạm (ảnh hoặc PDF).',
            'evidence.max' => 'File bằng chứng tối đa 10MB.',
            'evidence.mimes' => 'Bằng chứng phải là ảnh (JPG, PNG, GIF, WEBP) hoặc PDF.',
        ]);

        // Cửa sổ ghi nhận: [now − N giờ, now] (SLA penalty.record_window). Không nhận thời điểm ở tương lai.
        $violationAt = Carbon::parse($validated['violation_at']);
        if ($violationAt->gt(now())) {
            throw ValidationException::withMessages(['violation_at' => 'Thời điểm vi phạm không được ở tương lai.']);
        }
        if ($violationAt->lt(now()->subHours(Penalty::recordWindowHours()))) {
            throw ValidationException::withMessages(['violation_at' => 'Vi phạm đã quá '.Penalty::recordWindowHours().'h — không thể ghi nhận.']);
        }

        // Vi phạm thuộc kỳ lương đã chốt vẫn ghi nhận được: tiền phạt trừ theo hạn nộp vào kỳ lương đang mở
        // (Penalty::scopeDeductibleFor), không sửa kỳ đã chốt — chủ dự án chốt 27/09/2026.

        $evidencePath = SafeUploadService::store(
            $request->file('evidence'), Penalty::EVIDENCE_DIRECTORY, Penalty::EVIDENCE_EXTENSIONS, 'evidence', Penalty::EVIDENCE_DISK
        );

        $penalty = Penalty::create([
            'code' => Penalty::generateCode(),
            'user_id' => $validated['user_id'],
            'class_id' => $validated['class_id'] ?? null,
            'violation_type' => $validated['violation_type'],
            'error_category' => $this->categoryFor($validated['violation_type'], (int) $validated['user_id'], $validated['error_category'] ?? null),
            'violation_date' => $violationAt->toDateString(),
            'violation_at' => $violationAt,
            'amount' => $validated['amount'] ?? 0,
            'reporter_id' => Auth::id(),
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'evidence_path' => $evidencePath,
        ]);

        return redirect()->route('penalties.index')
            ->with('status', "Đã ghi nhận vi phạm {$penalty->code} — chờ nhân sự giải trình, sau đó {$penalty->confirmer_label} chốt.");
    }

    /**
     * Xem file bằng chứng: người xem mọi biên bản (violation.view) hoặc chính nhân sự vi phạm.
     */
    public function evidence(Request $request, $id)
    {
        $penalty = Penalty::findOrFail($id);
        abort_unless($request->user()->can('violation.view') || $penalty->user_id === $request->user()->id, 403);
        abort_unless($penalty->evidence_path && Storage::disk(Penalty::EVIDENCE_DISK)->exists($penalty->evidence_path), 404);

        return Storage::disk(Penalty::EVIDENCE_DISK)->response($penalty->evidence_path);
    }

    /**
     * Nhân sự vi phạm gửi giải trình (chỉ chính người vi phạm, khi biên bản còn chờ giải trình).
     */
    public function explain(Request $request, $id)
    {
        $penalty = Penalty::findOrFail($id);
        abort_unless($penalty->user_id === $request->user()->id, 403, 'Chỉ nhân sự vi phạm mới được giải trình biên bản này.');
        abort_unless($penalty->status === 'pending', 422, 'Biên bản không còn ở bước giải trình.');

        $validated = $request->validate([
            'explanation' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'explanation.required' => 'Vui lòng nhập nội dung giải trình.',
            'explanation.min' => 'Nội dung giải trình cần ít nhất 10 ký tự.',
        ]);

        $penalty->update([
            'explanation' => $validated['explanation'],
            'explained_at' => now(),
            'status' => 'explained',
        ]);

        return redirect()->back()->with('status', "Đã gửi giải trình biên bản {$penalty->code} — chờ {$penalty->confirmer_label} chốt.");
    }

    /**
     * HT/CM chốt biên bản theo loại lỗi:
     * - decision=error: xác nhận có lỗi (chưa phạt tiền, có thể quyết phạt sau);
     * - decision=fine: quyết phạt với số tiền, nhân sự phải nộp trong 2 ngày, quá hạn trừ lương.
     */
    public function confirmPenalty(Request $request, $id)
    {
        $decision = $request->input('decision') === 'fine' ? 'fine' : 'error';
        abort_unless($request->user()->can("violation.confirm_{$decision}"), 403);

        $penalty = Penalty::findOrFail($id);
        abort_unless($penalty->canBeDecidedBy($request->user()), 403, "Biên bản {$penalty->category_label} do {$penalty->confirmer_label} chốt.");

        $validated = $request->validate([
            'decision' => ['nullable', 'in:error,fine'],
            'amount' => ['nullable', 'required_if:decision,fine', 'numeric', 'min:1000'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required_if' => 'Vui lòng nhập số tiền phạt khi quyết phạt.',
        ]);
        abort_unless(in_array($penalty->status, ['pending', 'explained', 'confirmed'], true), 422, 'Biên bản không ở trạng thái cho phép chốt.');

        $attributes = [
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $validated['decision_note'] ?? $penalty->decision_note,
        ];

        if ($decision === 'fine') {
            $attributes += [
                'status' => 'fined',
                'amount' => $validated['amount'],
                'due_date' => today()->addDays(Penalty::paymentDueDays())->toDateString(),
            ];
        } else {
            $attributes['status'] = 'confirmed';
        }

        $penalty->update($attributes);

        // Báo nhân sự vi phạm số tiền và hạn nộp (GV, CM, TA như nhau): quá hạn không nộp trực tiếp được nữa, trừ vào lương.
        if ($decision === 'fine') {
            AdminNotification::create([
                'user_id' => $penalty->user_id,
                'type' => 'penalty_fined',
                'title' => "Biên bản {$penalty->code}: phạt ".Money::format((float) $penalty->amount),
                'message' => 'Nộp phạt trước hết ngày '.$penalty->due_date->format('d/m/Y').' ('.Penalty::paymentDueDays()
                    .' ngày) — quá hạn chưa nộp sẽ trừ vào lương kỳ này.',
                'data' => ['penalty_id' => $penalty->id, 'link' => route('penalties.index', ['search' => $penalty->code])],
                'is_read' => false,
            ]);
        }

        $message = $decision === 'fine'
            ? "Đã quyết phạt {$penalty->code}: ".Money::format((float) $penalty->amount).' — hạn nộp '
                .$penalty->due_date->format('d/m/Y').', quá hạn chưa nộp sẽ trừ vào kỳ lương.'
            : "Đã xác nhận lỗi của biên bản {$penalty->code}!";

        return redirect()->back()->with('status', $message);
    }

    /**
     * Nhân sự đã nộp phạt trực tiếp (ngoài bảng lương) — không bị trừ lương nữa.
     */
    public function markPaidPenalty(Request $request, $id)
    {
        $penalty = Penalty::with('payrollRecord.period')->findOrFail($id);
        abort_unless($penalty->status === 'fined', 422, 'Chỉ biên bản đã quyết phạt mới ghi nhận nộp phạt.');
        if ($response = $this->rejectIfPayrollLocked($penalty)) {
            return $response;
        }
        // Chủ dự án chốt: nộp phạt trong N ngày (SLA penalty.payment_due); quá hạn thì không nhận nộp trực tiếp — bảng lương sẽ trừ.
        if ($penalty->isOverdue()) {
            $message = 'Quá hạn nộp phạt '.Penalty::paymentDueDays().' ngày — khoản phạt sẽ trừ vào lương kỳ này.';

            return redirect()->back()->withErrors(['penalty' => $message])->with('error', $message);
        }

        $penalty->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payroll_record_id' => null,
            'notes' => trim(($penalty->notes ? $penalty->notes."\n" : '').'Nộp phạt trực tiếp ngày '.now()->format('d/m/Y').' bởi '.Auth::user()?->name),
        ]);

        return redirect()->back()->with('status', "Đã ghi nhận nhân sự nộp phạt {$penalty->code} trực tiếp — không trừ vào bảng lương.");
    }

    /**
     * Đóng vụ mà không phạt tiền (nhắc nhở, đủ điều kiện miễn).
     */
    public function resolvePenalty(Request $request, $id)
    {
        $penalty = Penalty::with('payrollRecord.period')->findOrFail($id);
        abort_unless(in_array($penalty->status, Penalty::OPEN_STATUSES, true), 422, 'Biên bản đã đóng.');
        if ($response = $this->rejectIfPayrollLocked($penalty)) {
            return $response;
        }

        $penalty->update(['status' => 'resolved', 'payroll_record_id' => null]);

        return redirect()->back()->with('status', "Đã xử lý (miễn phạt) biên bản {$penalty->code}.");
    }

    /**
     * "Ghi nhận khắc phục" (mockup): biên bản đã nộp phạt / đã trừ lương → nhân sự đã khắc phục lỗi.
     * Chỉ lưu mốc, không đổi số tiền / trạng thái trừ lương nên vẫn ghi được khi kỳ lương đã khóa.
     */
    public function remedyPenalty(Request $request, $id)
    {
        $penalty = Penalty::findOrFail($id);
        abort_unless(in_array($penalty->status, ['paid', 'deducted'], true) && $penalty->remedied_at === null, 422, 'Chỉ biên bản đã nộp / đã trừ lương mới ghi nhận khắc phục.');
        $validated = $request->validate(['remedy_note' => ['nullable', 'string', 'max:1000']]);

        $penalty->update([
            'remedied_at' => now(),
            'remedied_by' => $request->user()->id,
            'remedy_note' => $validated['remedy_note'] ?? null,
        ]);

        return redirect()->back()->with('status', "Đã ghi nhận khắc phục biên bản {$penalty->code}.");
    }

    public function cancelPenalty($id)
    {
        $penalty = Penalty::with('payrollRecord.period')->findOrFail($id);
        abort_unless(in_array($penalty->status, ['pending', 'explained', 'confirmed'], true), 422, 'Biên bản đã vào vòng phạt không thể hủy — dùng Đóng vụ nếu cần miễn.');
        if ($response = $this->rejectIfPayrollLocked($penalty)) {
            return $response;
        }

        $penalty->update(['status' => 'cancelled']);

        return redirect()->back()->with('status', "Đã hủy bỏ biên bản vi phạm {$penalty->code}!");
    }

    /**
     * Loại lỗi của biên bản: lỗi thường gặp của vai trò nhân sự vi phạm thì theo lỗi (đúng người chốt); lỗi tự mô tả theo loại
     * người lập chọn, không chọn → lỗi vận hành.
     */
    private function categoryFor(string $violationType, int $userId, ?string $chosen): string
    {
        $roles = User::find($userId)?->getRoleNames() ?? [];

        return Penalty::commonViolationsFor($roles)[$violationType] ?? $chosen ?? 'operations';
    }

    /** Biên bản đã nằm trong kỳ lương đã duyệt/chi trả thì không được đổi trạng thái nữa. */
    private function rejectIfPayrollLocked(Penalty $penalty): ?RedirectResponse
    {
        if (! $penalty->isLockedByPayroll()) {
            return null;
        }

        $message = "Biên bản {$penalty->code} đã được trừ trong kỳ lương đã duyệt/đã chi trả — không thể thay đổi.";

        return redirect()->back()->withErrors(['penalty' => $message])->with('error', $message);
    }
}
