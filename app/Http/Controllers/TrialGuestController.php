<?php

namespace App\Http\Controllers;

use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\User;
use App\Services\CrmStageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Khách học thử (lead) trên buổi dạy của giáo viên. Giáo viên xem khách của buổi mình dạy
 * và ghi phản hồi — phản hồi lưu vào booking gắn với lead, đồng thời ghi lịch sử CRM.
 */
class TrialGuestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $bookings = CrmTrialBooking::query()
            ->with(['customer:id,code,name,parent_name,phone,test_score', 'session', 'classModel.course', 'feedbackBy'])
            ->when(! $user->hasRole('admin'), fn (Builder $query) => $query->where(
                fn (Builder $scope) => $this->teachingScope($scope, $user)
            ))
            ->where('crm_trial_bookings.status', '!=', 'cancelled')
            ->join('class_sessions', 'class_sessions.id', '=', 'crm_trial_bookings.class_session_id')
            ->orderByDesc('class_sessions.date')
            ->select('crm_trial_bookings.*')
            ->paginate($request->perPage(20))
            ->withQueryString();

        return view('teacher.trial-guests', compact('bookings'));
    }

    public function feedback(Request $request, CrmTrialBooking $booking)
    {
        $user = $request->user();
        abort_unless($this->canGiveFeedback($booking, $user), 403, 'Bạn không dạy buổi học thử này.');

        $validated = $request->validate([
            'status' => 'required|in:attended,no_show',
            'rating' => 'required_if:status,attended|nullable|integer|min:1|max:5',
            'feedback' => 'required_if:status,attended|nullable|string|max:3000',
        ]);
        if ($booking->status === 'cancelled') {
            return back()->withErrors(['feedback' => 'Buổi học thử đã bị hủy.']);
        }

        $booking->update([
            'status' => $validated['status'],
            'rating' => $validated['status'] === 'attended' ? $validated['rating'] : null,
            'feedback' => $validated['feedback'] ?? null,
            'feedback_by' => $user->id,
            'feedback_at' => now(),
        ]);

        CrmCustomerHistory::create([
            'customer_id' => $booking->customer_id,
            'user_id' => $user->id,
            'type' => 'trial',
            'content' => $validated['status'] === 'attended'
                ? "Phản hồi học thử ({$validated['rating']}/5) buổi #{$booking->class_session_id}: {$validated['feedback']}"
                : "Khách vắng buổi học thử #{$booking->class_session_id}.".(! empty($validated['feedback']) ? " Ghi chú: {$validated['feedback']}" : ''),
        ]);

        return back()->with('status', 'Đã lưu phản hồi học thử.');
    }

    private function canGiveFeedback(CrmTrialBooking $booking, User $user): bool
    {
        if ($user->hasAnyRole(CrmStageService::CM_ROLES)) {
            return true;
        }

        return CrmTrialBooking::whereKey($booking->id)
            ->where(fn (Builder $scope) => $this->teachingScope($scope, $user))
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
