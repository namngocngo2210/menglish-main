<?php

namespace App\Http\Controllers;

use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Báo cáo & Nhật ký theo vai trò:
 *  - Học vụ (academic_staff): Nhật ký sự vụ + Báo cáo NGÀY
 *  - Học thuật (academic_lead): Báo cáo TUẦN
 *  - Giáo viên (teacher*): Báo cáo THÁNG (tổng kết)
 *  - Admin / Manager: xem tổng tất cả nhật ký & báo cáo của mọi người
 */
class StaffReportController extends Controller
{
    private const REPORT_ROLES = [
        'academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime',
        'teacher_parttime', 'assistant', 'manager', 'admin',
    ];

    private function guard(): void
    {
        $u = Auth::user();
        abort_unless($u && $u->hasAnyRole(self::REPORT_ROLES), 403);
    }

    private function isPrivileged(): bool
    {
        $u = Auth::user();
        return $u && ($u->hasRole('admin') || $u->hasRole('manager'));
    }

    /** Loại báo cáo định kỳ chính theo vai trò. */
    private function primaryType(User $u): string
    {
        if ($u->hasRole('academic_staff')) {
            return 'daily';
        }
        if ($u->hasRole('academic_lead')) {
            return 'weekly';
        }
        if ($u->hasAnyRole(['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'])) {
            return 'monthly';
        }
        return 'daily';
    }

    // ───────────────────────── NHẬT KÝ ─────────────────────────
    public function journal(Request $request)
    {
        $this->guard();
        $isPriv = $this->isPrivileged();

        $query = StaffReport::with(['user', 'followups.user'])
            ->where('type', 'journal');
        if (! $isPriv) {
            $query->where('user_id', Auth::id());
        }
        if ($sev = $request->input('severity')) {
            $query->where('severity', $sev);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $journals = $query->latest('report_date')->latest('id')->paginate(15)->withQueryString();

        return view('reports.journal', compact('journals', 'isPriv'));
    }

    public function journalStore(Request $request)
    {
        $this->guard();
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'severity' => 'required|in:normal,important,urgent',
            'report_date' => 'nullable|date',
        ]);

        StaffReport::create([
            'user_id' => Auth::id(),
            'type' => 'journal',
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'severity' => $validated['severity'],
            'report_date' => $validated['report_date'] ?? now()->toDateString(),
            'status' => 'open',
        ]);

        return back()->with('success', 'Đã ghi nhận sự vụ vào nhật ký!');
    }

    public function journalFollowup(Request $request, int $id)
    {
        $this->guard();
        $report = StaffReport::findOrFail($id);
        $validated = $request->validate([
            'content' => 'required|string',
        ], ['content.required' => 'Vui lòng nhập nội dung tác vụ / follow-up.']);

        StaffReportFollowup::create([
            'staff_report_id' => $report->id,
            'user_id' => Auth::id(),
            'content' => $validated['content'],
        ]);

        if ($report->status === 'open') {
            $report->update(['status' => 'following']);
        }

        return back()->with('success', 'Đã thêm follow-up cho sự vụ!');
    }

    public function journalStatus(Request $request, int $id)
    {
        $this->guard();
        $report = StaffReport::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:open,following,resolved',
        ]);
        $report->update(['status' => $validated['status']]);

        return back()->with('success', 'Đã cập nhật trạng thái sự vụ!');
    }

    // ───────────────────────── BÁO CÁO ĐỊNH KỲ ─────────────────────────
    public function myReports(Request $request)
    {
        $this->guard();
        $type = $this->primaryType(Auth::user());

        $reports = StaffReport::where('user_id', Auth::id())
            ->where('type', $type)
            ->latest('report_date')
            ->paginate(10);

        return view('reports.my', compact('reports', 'type'));
    }

    public function reportStore(Request $request)
    {
        $this->guard();
        $type = $this->primaryType(Auth::user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'report_date' => 'nullable|date',
        ]);

        StaffReport::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'report_date' => $validated['report_date'] ?? now()->toDateString(),
            'status' => 'submitted',
        ]);

        return back()->with('success', 'Đã nộp ' . (StaffReport::TYPE_LABELS[$type] ?? 'báo cáo') . ' thành công!');
    }

    // ───────────────────────── ADMIN XEM TỔNG ─────────────────────────
    public function allReports(Request $request)
    {
        $this->guard();
        abort_unless($this->isPrivileged(), 403, 'Chỉ Admin / Manager mới xem tổng hợp.');

        $query = StaffReport::with(['user', 'followups']);
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($date = $request->input('date')) {
            $query->whereDate('report_date', $date);
        }

        $reports = $query->latest('report_date')->latest('id')->paginate(20)->withQueryString();

        $stats = [
            'journal' => StaffReport::where('type', 'journal')->count(),
            'daily' => StaffReport::where('type', 'daily')->count(),
            'weekly' => StaffReport::where('type', 'weekly')->count(),
            'monthly' => StaffReport::where('type', 'monthly')->count(),
            'urgent_open' => StaffReport::where('type', 'journal')->where('severity', 'urgent')->where('status', '!=', 'resolved')->count(),
        ];

        return view('reports.all', compact('reports', 'stats'));
    }
}
