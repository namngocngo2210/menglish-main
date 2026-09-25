<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\StudentAttendance;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AcademicDashboardController extends Controller
{
    /**
     * Dashboard Báo cáo Admin:
     * Tổng hợp báo cáo ngày của Học vụ, tuần của Học thuật, tháng của Giáo viên.
     */
    public function reports(Request $request): View
    {
        $currentBranchId = $request->get('branch_id');
        $tab = $request->get('tab', 'daily'); // 'daily', 'weekly', 'monthly'
        $branches = Branch::where('is_active', true)->get();

        // 1. Báo cáo ngày Học vụ
        $dailyReportsQuery = AcademicRecord::forScreen('06_bao_cao_ngay_hoc_vu')->with('user')->latest();
        $classReports = ClassReport::with(['classModel', 'reporter'])->withCount('studentSupports')->latest()->take(10)->get();
        $dailyReports = $dailyReportsQuery->take(15)->get();

        // 2. Báo cáo tuần Học thuật
        $weeklyReports = AcademicRecord::whereIn('screen_key', ['07_nhap_bao_cao_tuan_hoc_vu', '18_bao_cao_tuan_hoc_thuat'])
            ->with('user')
            ->latest()
            ->take(10)
            ->get();

        // 3. Báo cáo tháng Giáo viên
        $monthlyReports = AcademicRecord::whereIn('screen_key', ['17_bao_cao_chung_cua_giao_vien', '19_bao_cao_thang_hoc_thuat'])
            ->with('user')
            ->latest()
            ->take(10)
            ->get();

        // Thống kê tổng hợp (số liệu thật; không có dữ liệu thì view hiện "Chưa có dữ liệu")
        $totalClasses = ClassModel::count();
        $activeClasses = ClassModel::where('status', 'active')->count();
        $totalDailyReportsToday = ClassReport::whereDate('created_at', today())->count()
            + AcademicRecord::forScreen('06_bao_cao_ngay_hoc_vu')->whereDate('created_at', today())->count();

        $todayAttendance = StudentAttendance::whereDate('session_date', today())->get(['status']);
        $attendanceRateToday = $todayAttendance->isEmpty()
            ? null
            : round($todayAttendance->whereIn('status', ['present', 'late'])->count() * 100 / $todayAttendance->count(), 1);

        // Sĩ số / có mặt theo lớp + ngày của từng báo cáo trực lớp
        $reportAttendance = [];
        foreach ($classReports as $cr) {
            if (! $cr->class_id || ! $cr->session_date) {
                continue;
            }
            $rows = StudentAttendance::where('class_id', $cr->class_id)
                ->whereDate('session_date', $cr->session_date)
                ->get(['status']);
            if ($rows->isNotEmpty()) {
                $reportAttendance[$cr->id] = [
                    'total' => $rows->count(),
                    'present' => $rows->whereIn('status', ['present', 'late'])->count(),
                    'excused' => $rows->where('status', 'excused')->count(),
                    'absent' => $rows->where('status', 'absent')->count(),
                ];
            }
        }

        // Tuần này: số liệu học thuật thật
        $weekStart = now()->startOfWeek();
        $weeklyStats = [
            'adjustments_pending' => SyllabusAdjustmentRequest::where('status', 'pending')->count(),
            'adjustments_week' => SyllabusAdjustmentRequest::where('created_at', '>=', $weekStart)->count(),
            'big_tests_upcoming' => BigTest::whereBetween('scheduled_at', [now(), now()->addDays(7)])->count(),
            'big_tests_distributed' => BigTest::where('is_distributed', true)->where('distributed_at', '>=', $weekStart)->count(),
        ];

        return view('academic.dashboards.reports', compact(
            'branches',
            'tab',
            'dailyReports',
            'classReports',
            'weeklyReports',
            'monthlyReports',
            'totalClasses',
            'activeClasses',
            'totalDailyReportsToday',
            'attendanceRateToday',
            'reportAttendance',
            'weeklyStats'
        ));
    }

    /**
     * Dashboard Nhật ký sự vụ Admin:
     * Tổng hợp các sự vụ nổi cộm từ các cơ sở, phân loại mức độ và tiến độ xử lý.
     */
    public function incidents(Request $request): View
    {
        $severity = in_array($request->get('severity'), ['urgent', 'high', 'medium', 'low'], true) ? $request->get('severity') : 'all';
        $status = in_array($request->get('status'), ['open', 'resolved'], true) ? $request->get('status') : 'all';
        $branchId = $request->get('branch_id');

        $branches = Branch::where('is_active', true)->get();

        // Dữ liệu nhật ký sự vụ từ academic_records (05_nhat_ky_hoc_vu) — bỏ bản ghi seed demo.
        $incidentsQuery = AcademicRecord::forScreen('05_nhat_ky_hoc_vu')
            ->where('is_seed', false)
            ->with('user.branch')
            ->latest();
        if ($branchId) {
            $incidentsQuery->whereHas('user', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($status === 'resolved') {
            $incidentsQuery->whereIn('status', ['resolved', 'completed', 'closed']);
        } elseif ($status === 'open') {
            $incidentsQuery->whereNotIn('status', ['resolved', 'completed', 'closed']);
        }
        $incidents = $severity === 'all' ? $incidentsQuery->take(20)->get() : collect();

        // Support tickets nổi cộm (model có creator/assignee, không có relation user)
        $ticketsQuery = SupportTicket::with(['creator.branch', 'assignee'])
            ->whereIn('priority', $severity === 'all' ? ['urgent', 'high'] : [$severity])
            ->latest();
        if ($branchId) {
            $ticketsQuery->whereHas('creator', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($status === 'resolved') {
            $ticketsQuery->whereIn('status', ['resolved', 'closed']);
        } elseif ($status === 'open') {
            $ticketsQuery->whereIn('status', ['open', 'in_progress']);
        }
        $urgentTickets = $ticketsQuery->take(20)->get();

        // Thống kê sự vụ
        $totalIncidents = $incidents->count() + $urgentTickets->count();
        $resolvedCount = $incidents->whereIn('status', ['resolved', 'completed', 'closed'])->count()
            + $urgentTickets->whereIn('status', ['resolved', 'closed'])->count();
        $openCount = max(0, $totalIncidents - $resolvedCount);
        $urgentCount = $urgentTickets->where('priority', 'urgent')->whereIn('status', ['open', 'in_progress'])->count();

        return view('academic.dashboards.incidents', compact(
            'branches',
            'severity',
            'status',
            'incidents',
            'urgentTickets',
            'totalIncidents',
            'resolvedCount',
            'openCount',
            'urgentCount',
            'branchId'
        ));
    }
}
