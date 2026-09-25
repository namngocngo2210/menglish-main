<?php

namespace App\Http\Controllers;

use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\User;
use App\Services\CrmStageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * "Nhận xét học thử" — khách học thử (lead chưa chốt) trên buổi dạy của giáo viên (BA Q1 — học thử bổ sung).
 * Giáo viên buổi đó điểm danh và nhận xét khách như học sinh chính thức; nhận xét lưu theo khách
 * (crm_trial_bookings.customer_id), không theo học viên, đồng thời ghi nhật ký tuyển sinh của khách.
 */
class TrialGuestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $scope = $request->input('scope') === 'past' ? 'past' : 'upcoming';

        $bookings = CrmTrialBooking::query()
            ->with(['customer:id,code,name,parent_name,phone,test_score', 'session', 'classModel.course', 'feedbackBy'])
            ->when(! $user->hasRole('admin'), fn (Builder $query) => $query->where(
                fn (Builder $inner) => $this->teachingScope($inner, $user)
            ))
            ->where('crm_trial_bookings.status', '!=', 'cancelled')
            ->join('class_sessions', 'class_sessions.id', '=', 'crm_trial_bookings.class_session_id')
            ->when($scope === 'past',
                fn (Builder $query) => $query->whereDate('class_sessions.date', '<', today())->orderByDesc('class_sessions.date'),
                fn (Builder $query) => $query->whereDate('class_sessions.date', '>=', today())->orderBy('class_sessions.date')->orderBy('class_sessions.start_time'))
            ->select('crm_trial_bookings.*')
            ->paginate($request->perPage(20))
            ->withQueryString();

        return view('teacher.trial-guests', compact('bookings', 'scope'));
    }

    public function feedback(Request $request, CrmTrialBooking $booking)
    {
        $user = $request->user();
        abort_unless($this->canGiveFeedback($booking, $user), 403, 'Bạn không dạy buổi học thử này.');

        $validated = $request->validate([
            'status' => 'required|in:attended,no_show',
            'rating' => 'required_if:status,attended|nullable|integer|min:1|max:5',
            'remarks' => 'nullable|array',
            'remarks.grammar' => 'nullable|string|max:255',
            'remarks.attitude' => 'nullable|string|max:255',
            'remarks.result' => 'nullable|string|max:255',
            'feedback' => 'required_if:status,attended|nullable|string|max:3000',
        ], [
            'feedback.required_if' => 'Vui lòng nhập nhận xét chi tiết cho khách học thử.',
            'rating.required_if' => 'Vui lòng chọn mức đánh giá.',
        ]);
        if ($booking->status === 'cancelled') {
            return back()->withErrors(['feedback' => 'Buổi học thử đã bị hủy.']);
        }
        $booking->loadMissing('session');
        if (! $booking->sessionHasStarted()) {
            return back()->withErrors(['feedback' => 'Buổi học thử chưa diễn ra — chỉ điểm danh / nhận xét từ ngày học.']);
        }

        $attended = $validated['status'] === 'attended';
        $remarks = $attended
            ? collect($validated['remarks'] ?? [])->only(array_keys(CrmTrialBooking::REMARK_FIELDS))->map(fn ($v) => filled($v) ? trim($v) : null)->filter()->all()
            : [];

        $booking->update([
            'status' => $validated['status'],
            'rating' => $attended ? $validated['rating'] : null,
            'remarks' => $remarks ?: null,
            'feedback' => $validated['feedback'] ?? null,
            'feedback_by' => $user->id,
            'feedback_at' => now(),
        ]);

        $sessionLabel = ($booking->classModel?->name ?? 'lớp #'.$booking->class_id).' ngày '.$booking->session?->date?->format('d/m/Y');
        $summary = $booking->remarksSummary();
        CrmCustomerHistory::create([
            'customer_id' => $booking->customer_id,
            'user_id' => $user->id,
            'type' => 'trial',
            'content' => $attended
                ? "Nhận xét học thử ({$validated['rating']}/5) buổi {$sessionLabel}: ".($summary !== '' ? "{$summary}. " : '').$validated['feedback']
                : "Khách vắng buổi học thử {$sessionLabel}.".(! empty($validated['feedback']) ? " Ghi chú: {$validated['feedback']}" : ''),
        ]);

        return back()->with('status', 'Đã lưu nhận xét học thử vào hồ sơ khách.');
    }

    private function canGiveFeedback(CrmTrialBooking $booking, User $user): bool
    {
        if ($user->hasAnyRole(CrmStageService::CM_ROLES)) {
            return true;
        }

        return CrmTrialBooking::whereKey($booking->id)
            ->where(fn (Builder $query) => $this->teachingScope($query, $user))
            ->exists();
    }

    /** Buổi do user dạy / trợ giảng, hoặc lớp user phụ trách. */
    private function teachingScope(Builder $query, User $user): Builder
    {
        return $query
            ->whereHas('session', fn (Builder $session) => $session
                ->where('teacher_id', $user->id)->orWhere('assistant_id', $user->id))
            ->orWhereHas('classModel', fn (Builder $class) => $class
                ->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id));
    }
}
