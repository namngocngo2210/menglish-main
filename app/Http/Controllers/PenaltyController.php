<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Kỷ luật nhân sự (BPMN 9b): ghi nhận vi phạm → giải trình → HT/CM chốt theo
 * loại lỗi và mức phạt → nộp trong 2 ngày → quá hạn chưa nộp thì trừ lương.
 */
class PenaltyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_if($user->hasRole('student'), 403);
        $canViewAll = $user->can('violation.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_merge(array_keys(Penalty::statusLabels()), ['overdue', 'open']))],
            'category' => ['nullable', Rule::in(array_keys(Penalty::CATEGORIES))],
        ]);

        $penalties = Penalty::with(['user', 'classModel', 'reporter', 'decider'])
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('violation_type', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
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
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        $users = $canViewAll
            ? User::where('is_active', true)->whereDoesntHave('roles', fn ($q) => $q->where('name', 'student'))->orderBy('name')->get()
            : collect();
        $classes = $canViewAll ? ClassModel::orderBy('name')->get() : collect();

        $counts = Penalty::query()
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $overdueCount = Penalty::query()
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->where('status', 'fined')->whereDate('due_date', '<', today()->toDateString())->count();

        return view('penalties.index', compact('penalties', 'users', 'classes', 'canViewAll', 'counts', 'overdueCount'));
    }

    /**
     * Ghi nhận vi phạm: chưa cần số tiền (mức phạt do HT/CM chốt sau khi nhân viên giải trình).
     */
    public function storePenalty(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'error_category' => ['nullable', Rule::in(array_keys(Penalty::CATEGORIES))],
            'violation_type' => 'required|string|max:255',
            'violation_date' => 'required|date',
            // Mức phạt đề xuất (không bắt buộc) — mức chính thức do người chốt quyết định.
            'amount' => 'nullable|numeric|min:0',
            'class_id' => 'nullable|exists:classes,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if (PayrollPeriod::isLockedFor($validated['violation_date'])) {
            return $this->rejectLockedDate($validated['violation_date']);
        }

        $penalty = Penalty::create([
            'code' => Penalty::generateCode(),
            'user_id' => $validated['user_id'],
            'class_id' => $validated['class_id'] ?? null,
            'violation_type' => $validated['violation_type'],
            'error_category' => $validated['error_category'] ?? $this->guessCategory($validated['violation_type']),
            'violation_date' => $validated['violation_date'],
            'amount' => $validated['amount'] ?? 0,
            'reporter_id' => Auth::id(),
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('penalties.index')
            ->with('status', "Đã ghi nhận vi phạm {$penalty->code} — chờ nhân sự giải trình, sau đó {$penalty->confirmer_label} chốt.");
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
        if (PayrollPeriod::isLockedFor($penalty->violation_date)) {
            return $this->rejectLockedDate($penalty->violation_date);
        }

        $attributes = [
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $validated['decision_note'] ?? $penalty->decision_note,
        ];

        if ($decision === 'fine') {
            $attributes += [
                'status' => 'fined',
                'amount' => $validated['amount'],
                'due_date' => today()->addDays(Penalty::PAYMENT_DUE_DAYS)->toDateString(),
            ];
        } else {
            $attributes['status'] = 'confirmed';
        }

        $penalty->update($attributes);

        $message = $decision === 'fine'
            ? "Đã quyết phạt {$penalty->code}: ".number_format((float) $penalty->amount, 0, ',', '.').'đ — hạn nộp '
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

    /** Loại lỗi mặc định theo danh sách lỗi thường gặp (không khớp → lỗi vận hành). */
    private function guessCategory(string $violationType): string
    {
        foreach (Penalty::COMMON_VIOLATIONS as $category => $types) {
            if (in_array($violationType, $types, true)) {
                return $category;
            }
        }

        return 'operations';
    }

    /**
     * Ngày vi phạm thuộc kỳ lương đã khoá: kỳ sau không quét lại ngày này nên
     * biên bản sẽ không bao giờ được trừ lương.
     */
    private function rejectLockedDate($date): RedirectResponse
    {
        $message = PayrollPeriod::lockedMessage($date);

        return redirect()->back()->withInput()->withErrors(['violation_date' => $message])->with('error', $message);
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
