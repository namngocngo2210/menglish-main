<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\SupportTicket;
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
        $classReports = ClassReport::with(['classModel', 'reporter'])->latest()->take(10)->get();
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

        // Thống kê tổng hợp
        $totalClasses = ClassModel::count();
        $activeClasses = ClassModel::where('status', 'active')->count();
        $totalDailyReportsToday = AcademicRecord::forScreen('06_bao_cao_ngay_hoc_vu')->whereDate('created_at', today())->count();
        $attendanceRateToday = 94.8; // %

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
            'attendanceRateToday'
        ));
    }

    /**
     * Dashboard Nhật ký sự vụ Admin:
     * Tổng hợp các sự vụ nổi cộm từ các cơ sở, phân loại mức độ và tiến độ xử lý.
     */
    public function incidents(Request $request): View
    {
        $severity = $request->get('severity', 'all'); // 'all', 'high', 'medium', 'low'
        $status = $request->get('status', 'all'); // 'all', 'open', 'resolved'
        $branchId = $request->get('branch_id');

        $branches = Branch::where('is_active', true)->get();

        // Dữ liệu nhật ký sự vụ từ academic_records (05_nhat_ky_hoc_vu)
        $incidentsQuery = AcademicRecord::forScreen('05_nhat_ky_hoc_vu')->with('user')->latest();
        $incidents = $incidentsQuery->take(20)->get();

        // Support tickets nổi cộm (model có creator/assignee, không có relation user)
        $urgentTickets = SupportTicket::with(['creator', 'assignee'])
            ->whereIn('priority', ['urgent', 'high'])
            ->latest()
            ->take(10)
            ->get();

        // Thống kê sự vụ
        $totalIncidents = $incidents->count() + $urgentTickets->count();
        $resolvedCount = $incidents->where('status', 'resolved')->count() + $urgentTickets->where('status', 'closed')->count();
        $openCount = max(0, $totalIncidents - $resolvedCount);
        $urgentCount = $urgentTickets->count();

        return view('academic.dashboards.incidents', compact(
            'branches',
            'severity',
            'status',
            'incidents',
            'urgentTickets',
            'totalIncidents',
            'resolvedCount',
            'openCount',
            'urgentCount'
        ));
    }
}
