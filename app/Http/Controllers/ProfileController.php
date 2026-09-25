<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form with operational dashboard.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        // 1. Dữ liệu Lương & Phiếu lương cá nhân
        $latestPayroll = \App\Models\PayrollRecord::with('period')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        $recentPayrolls = \App\Models\PayrollRecord::with('period')
            ->where('user_id', $user->id)
            ->latest()
            ->take(6)
            ->get();

        // 2. Dữ liệu Chấm công & Giờ dạy cá nhân
        $monthlyTimesheets = \App\Models\TeacherTimesheet::with('classModel')
            ->where('user_id', $user->id)
            ->whereMonth('teaching_date', now()->month)
            ->whereYear('teaching_date', now()->year)
            ->get();

        $totalMonthlyHours = $monthlyTimesheets->sum('hours');
        $recentTimesheets = \App\Models\TeacherTimesheet::with('classModel')
            ->where('user_id', $user->id)
            ->latest('teaching_date')
            ->take(5)
            ->get();

        // 3. Nhiệm vụ & Công việc được giao
        $myTasks = \App\Models\WorkTask::where('assignee_id', $user->id)
            ->orWhere('creator_id', $user->id)
            ->latest()
            ->take(6)
            ->get();

        $pendingTasksCount = \App\Models\WorkTask::where('assignee_id', $user->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        // 4. Lớp học phụ trách (Giảng viên / Trợ giảng)
        $assignedClasses = \App\Models\ClassModel::with(['course', 'branch'])
            ->where('teacher_id', $user->id)
            ->orWhere('assistant_id', $user->id)
            ->latest()
            ->take(4)
            ->get();

        // 5. Ticket báo lỗi / Hỗ trợ cá nhân
        $myTickets = \App\Models\SupportTicket::where('creator_id', $user->id)
            ->orWhere('assignee_id', $user->id)
            ->latest()
            ->take(5)
            ->get();

        // 6. Hoạt động thao tác gần đây
        $myActivities = class_exists(\Spatie\Activitylog\Models\Activity::class)
            ? \Spatie\Activitylog\Models\Activity::where('causer_id', $user->id)->latest()->take(6)->get()
            : collect();

        return view('profile.edit', compact(
            'user',
            'latestPayroll',
            'recentPayrolls',
            'monthlyTimesheets',
            'totalMonthlyHours',
            'recentTimesheets',
            'myTasks',
            'pendingTasksCount',
            'assignedClasses',
            'myTickets',
            'myActivities'
        ));
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
