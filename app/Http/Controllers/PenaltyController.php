<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PenaltyController extends Controller
{
    public function index()
    {
        $penalties = Penalty::with(['user', 'classModel', 'reporter'])->latest()->get();
        $users = User::all();
        $classes = ClassModel::all();

        return view('penalties.index', compact('penalties', 'users', 'classes'));
    }

    public function storePenalty(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'violation_type' => 'required|string|max:255',
            'violation_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'class_id' => 'nullable|exists:classes,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if (PayrollPeriod::isLockedFor($validated['violation_date'])) {
            return $this->rejectLockedDate($validated['violation_date']);
        }

        $code = Penalty::generateCode();

        $penalty = Penalty::create([
            'code' => $code,
            'user_id' => $validated['user_id'],
            'class_id' => $validated['class_id'] ?? null,
            'violation_type' => $validated['violation_type'],
            'violation_date' => $validated['violation_date'],
            'amount' => $validated['amount'],
            'reporter_id' => Auth::id(),
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('penalties.index')
            ->with('status', "Đã lập biên bản vi phạm {$penalty->code} thành công!");
    }

    /**
     * Xác nhận biên bản: decision=error (xác nhận lỗi, chưa quyết phạt)
     * hoặc decision=fine (quyết định phạt tiền — chờ trừ lương hoặc nộp trực tiếp).
     */
    public function confirmPenalty(Request $request, $id)
    {
        $validated = $request->validate([
            'decision' => ['nullable', 'in:error,fine'],
        ]);
        $decision = $validated['decision'] ?? 'error';

        abort_unless($request->user()->can("violation.confirm_{$decision}"), 403);

        $penalty = Penalty::findOrFail($id);
        abort_unless(in_array($penalty->status, ['pending', 'confirmed'], true), 422, 'Biên bản không ở trạng thái cho phép xác nhận.');
        if (PayrollPeriod::isLockedFor($penalty->violation_date)) {
            return $this->rejectLockedDate($penalty->violation_date);
        }

        $penalty->update(['status' => $decision === 'fine' ? 'fined' : 'confirmed']);

        $message = $decision === 'fine'
            ? "Đã quyết định phạt tiền biên bản {$penalty->code} — sẽ trừ vào bảng lương hoặc ghi nhận khi nhân sự nộp."
            : "Đã xác nhận lỗi của biên bản {$penalty->code}!";

        return redirect()->back()->with('status', $message);
    }

    /**
     * Nhân sự đã nộp phạt trực tiếp (ngoài bảng lương).
     */
    public function markPaidPenalty(Request $request, $id)
    {
        $penalty = Penalty::findOrFail($id);
        abort_unless($penalty->status === 'fined', 422, 'Chỉ biên bản đã quyết phạt mới ghi nhận nộp phạt.');

        $penalty->update(['status' => 'paid', 'notes' => trim(($penalty->notes ? $penalty->notes."\n" : '').'Nộp phạt trực tiếp ngày '.now()->format('d/m/Y').' bởi '.Auth::user()?->name)]);

        return redirect()->back()->with('status', "Đã ghi nhận nhân sự nộp phạt {$penalty->code} trực tiếp — không trừ vào bảng lương.");
    }

    /**
     * Đóng vụ mà không phạt tiền (nhắc nhở/da mềm, đủ điều kiện miễn).
     */
    public function resolvePenalty(Request $request, $id)
    {
        $penalty = Penalty::findOrFail($id);
        abort_unless(in_array($penalty->status, ['pending', 'confirmed', 'fined'], true), 422, 'Biên bản đã đóng.');

        $penalty->update(['status' => 'resolved']);

        return redirect()->back()->with('status', "Đã xử lý (miễn phạt) biên bản {$penalty->code}.");
    }

    public function cancelPenalty($id)
    {
        $penalty = Penalty::findOrFail($id);
        abort_unless(in_array($penalty->status, ['pending', 'confirmed'], true), 422, 'Biên bản đã vào vòng phạt không thể hủy — dùng Đóng vụ nếu cần miễn.');

        $penalty->update(['status' => 'cancelled']);

        return redirect()->back()->with('status', "Đã hủy bỏ biên bản vi phạm {$penalty->code}!");
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
}
