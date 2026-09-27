<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\User;
use App\Support\StaffType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Báo cáo & Nhật ký (quyền staff_report.*):
 *  - staff_report.submit: ghi nhật ký sự vụ, nộp báo cáo định kỳ của mình. Kỳ báo cáo theo chức danh (StaffType):
 *    Học vụ NGÀY, Học thuật TUẦN, giáo viên / trợ giảng THÁNG.
 *  - staff_report.view_all: xem tổng tất cả nhật ký & báo cáo của mọi người (mặc định Admin / Quản lý cơ sở).
 */
class StaffReportController extends Controller
{
    private function guard(): void
    {
        abort_unless(Auth::user()?->can('staff_report.submit'), 403);
    }

    private function isPrivileged(): bool
    {
        return (bool) Auth::user()?->can('staff_report.view_all');
    }

    /** Loại báo cáo định kỳ chính theo chức danh. */
    private function primaryType(User $u): string
    {
        return StaffType::reportCadence($u);
    }

    // ───────────────────────── NHẬT KÝ ─────────────────────────
    public function journal(Request $request)
    {
        $this->guard();
        $isPriv = $this->isPrivileged();

        $query = StaffReport::with(['user', 'followups.user', 'classModel:id,code,name'])
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

        if ($classId = $request->integer('class_id')) {
            $query->where('class_id', $classId);
        }

        $journals = $query->latest('report_date')->latest('id')->paginate(15)->withQueryString();
        // Lớp gắn được sự vụ: chỉ lớp người ghi thấy (đang mở).
        $classes = ClassModel::visibleTo(Auth::user())->where('status', '!=', 'cancelled')->orderBy('code')->get(['id', 'code', 'name']);

        return view('reports.journal', compact('journals', 'isPriv', 'classes'));
    }

    public function journalStore(Request $request)
    {
        $this->guard();
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'severity' => 'required|in:normal,important,urgent',
            'report_date' => 'nullable|date',
            'class_id' => 'nullable|integer',
        ]);
        $classId = null;
        if (! empty($validated['class_id'])) {
            $class = ClassModel::visibleTo(Auth::user())->find($validated['class_id']);
            abort_unless($class, 403, 'Lớp học này nằm ngoài phạm vi bạn được xem.');
            $classId = $class->id;
        }

        StaffReport::create([
            'user_id' => Auth::id(),
            'class_id' => $classId,
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
            'class_id' => 'nullable|integer',
        ]);
        $classId = null;
        if (! empty($validated['class_id'])) {
            $class = ClassModel::visibleTo(Auth::user())->find($validated['class_id']);
            abort_unless($class, 403, 'Lớp học này nằm ngoài phạm vi bạn được xem.');
            $classId = $class->id;
        }

        StaffReport::create([
            'user_id' => Auth::id(),
            'class_id' => $classId,
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
