<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\KpiCriterion;
use App\Models\MaterialOrder;
use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\BranchStaff;
use App\Services\Reports\DailyReportMetrics;
use App\Services\Reports\WeeklyReportMetrics;
use App\Support\Approvals\AdminOnlyApprovals;
use App\Support\Dashboard\TeachingQuality;
use App\Support\MonthlyReportDue;
use App\Support\ReportPeriod;
use App\Support\Roles;
use App\Support\StaffType;
use App\Support\Ui;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Báo cáo & Nhật ký (quyền staff_report.*):
 *  - staff_report.submit: ghi nhật ký sự vụ, nộp báo cáo định kỳ của mình. Kỳ báo cáo theo chức danh (StaffType):
 *    Học vụ NGÀY, Học thuật TUẦN, giáo viên / trợ giảng THÁNG.
 *  - Báo cáo có cấu trúc (StaffType::structuredReports, theo bộ mockup roundcuoi-kieulien): Học vụ báo cáo NGÀY (02/06) + TUẦN theo mục KPI
 *    (02/07); Học thuật báo cáo TUẦN dạng bảng (02/18) + THÁNG (02/19) / QUÝ; giáo viên báo cáo THÁNG theo lớp + chấm công tháng (03/17).
 *    Mỗi người 1 báo cáo / loại (staff_reports.kind) / kỳ (period_key), nộp lại trong kỳ là cập nhật. `content` lưu bản chữ để màn
 *    Tổng hợp đọc được như báo cáo thường. Báo cáo chữ tự do cũ (period_key null) vẫn giữ và hiện ở cuối màn tương ứng.
 *  - staff_report.view_all: xem tổng tất cả nhật ký & báo cáo của mọi người (mặc định Admin / Quản lý cơ sở).
 */
class StaffReportController extends Controller
{
    /**
     * Báo cáo có cấu trúc: khóa = staff_reports.kind; type = loại kỳ (ngày / tuần / tháng / quý) cho màn tổng hợp; route màn nhập.
     * `tab` = false: không có tab riêng (phần chấm công tháng nằm trong màn báo cáo tháng của giáo viên).
     */
    public const STRUCTURED = [
        'daily_ops' => ['label' => 'Báo cáo ngày', 'type' => 'daily', 'route' => 'reports.periodic.daily', 'for' => 'Học vụ / Học thuật'],
        'weekly_kpi' => ['label' => 'Báo cáo tuần (KPI)', 'type' => 'weekly', 'route' => 'reports.periodic.weekly-kpi', 'for' => 'Học vụ'],
        'academic_weekly' => ['label' => 'Báo cáo tuần', 'type' => 'weekly', 'route' => 'reports.periodic.academic-weekly', 'for' => 'Học thuật'],
        'academic_monthly' => ['label' => 'Báo cáo tháng', 'type' => 'monthly', 'route' => 'reports.periodic.academic-monthly', 'for' => 'Học thuật'],
        'academic_quarterly' => ['label' => 'Báo cáo quý', 'type' => 'quarterly', 'route' => 'reports.periodic.academic-quarterly', 'for' => 'Học thuật'],
        'teacher_monthly' => ['label' => 'Báo cáo tháng', 'type' => 'monthly', 'route' => 'reports.periodic.teacher-monthly', 'for' => 'giáo viên'],
        'teacher_attendance' => ['label' => 'Báo cáo chấm công tháng', 'type' => 'monthly', 'route' => 'reports.periodic.teacher-monthly', 'for' => 'giáo viên', 'tab' => false],
    ];

    /**
     * Ô nhập tay của báo cáo ngày Học vụ bản cũ (mockup 02/06, trước 15/10/2026). Từ 15/10 số liệu lấy tự động (DailyReportMetrics);
     * hai hằng số này chỉ còn để hiện lại báo cáo đã nộp theo bản cũ.
     */
    public const DAILY_SECTIONS = [
        'operations' => ['title' => 'Vận hành lớp học', 'icon' => 'school', 'rows' => [
            ['classes_planned', 'classes_actual'], ['classes_note'], ['teachers', 'teachers_note'], ['assistants', 'assistants_note'],
            ['class_situation'], ['facilities'],
        ]],
        'enrollment' => ['title' => 'Tuyển sinh / Học viên', 'icon' => 'group_add', 'rows' => [
            ['support_students', 'support_note'], ['new_data', 'data_note'], ['test_students', 'test_note'], ['fees', 'fee_detail'],
        ]],
        'media' => ['title' => 'Truyền thông', 'icon' => 'campaign', 'rows' => [['media_posts']]],
    ];

    public const DAILY_FIELDS = [
        'classes_planned' => ['label' => 'Số lớp kế hoạch', 'type' => 'number'],
        'classes_actual' => ['label' => 'Số lớp thực tế', 'type' => 'number'],
        'classes_note' => ['label' => 'Ghi chú lớp học', 'type' => 'text', 'placeholder' => 'Nhập danh sách tên lớp...'],
        'teachers' => ['label' => 'Số giáo viên', 'type' => 'number'],
        'teachers_note' => ['label' => 'Ghi chú giáo viên', 'type' => 'text', 'placeholder' => 'Ghi chú chi tiết...'],
        'assistants' => ['label' => 'Số trợ giảng', 'type' => 'number'],
        'assistants_note' => ['label' => 'Ghi chú trợ giảng', 'type' => 'text', 'placeholder' => 'Ghi chú chi tiết...'],
        'class_situation' => ['label' => 'Tình hình lớp học', 'type' => 'text', 'placeholder' => 'Nhập thông tin tình hình lớp học tự do...'],
        'facilities' => ['label' => 'Cơ sở vật chất', 'type' => 'text', 'placeholder' => 'Ghi nhận sự cố thiết bị / phòng ốc (nếu có)...'],
        'support_students' => ['label' => 'Số học sinh bổ trợ', 'type' => 'short', 'placeholder' => 'VD: 7/10'],
        'support_note' => ['label' => 'Ghi chú bổ trợ', 'type' => 'text', 'placeholder' => 'Ghi chú chi tiết...'],
        'new_data' => ['label' => 'Data mới nhận', 'type' => 'number'],
        'data_note' => ['label' => 'Ghi chú data', 'type' => 'text', 'placeholder' => 'Ghi chú chi tiết...'],
        'test_students' => ['label' => 'Số học sinh test', 'type' => 'number'],
        'test_note' => ['label' => 'Ghi chú test', 'type' => 'text', 'placeholder' => 'Ghi chú chi tiết...'],
        'fees' => ['label' => 'Số lượng thu phí', 'type' => 'number'],
        'fee_detail' => ['label' => 'Chi tiết thu phí', 'type' => 'text', 'rows' => 3, 'placeholder' => 'Liệt kê tên học sinh + số tiền...'],
        'media_posts' => ['label' => 'Bài đăng truyền thông', 'type' => 'text', 'placeholder' => 'Nhập nội dung kèm link bài viết...'],
    ];

    /** Báo cáo tuần Học thuật (mockup 02/18): 3 bảng nhập theo dòng. */
    public const WEEKLY_SECTIONS = [
        'done' => 'Công việc đã làm trong tuần',
        'planned' => 'Công việc dự kiến tuần tới',
        'requests' => 'Quản lý yêu cầu',
    ];

    /** Cột của mỗi dòng báo cáo tuần Học thuật (ngoài STT / thao tác). */
    public const WEEKLY_COLUMNS = [
        'content' => 'Nội dung',
        'detail' => 'Chi tiết',
        'deadline' => 'Deadline',
        'progress' => 'Tiến độ',
        'link' => 'Link sản phẩm',
        'report' => 'Báo cáo',
        'support' => 'Đề xuất HT',
        'note' => 'Ghi chú',
    ];

    public const WEEKLY_PROGRESS = [0, 25, 50, 75, 100];

    /** Báo cáo tháng của giáo viên — nhận xét chung (chủ dự án 04/10/2026: tiến độ, khó khăn, đề xuất). */
    public const TEACHER_GENERAL_FIELDS = [
        'progress' => 'Tiến độ giảng dạy',
        'difficulties' => 'Khó khăn',
        'proposals' => 'Đề xuất',
    ];

    /**
     * Báo cáo giảng dạy tháng theo lớp (mockup 03/17 "Báo cáo chung của giáo viên"): A. Học thuật, B. Vận hành lớp, C. Chất lượng lớp.
     * Mục tích (checks) có ô ghi chú `{key}_note` khi `note` khác null.
     */
    public const TEACHER_ACADEMIC_CHECKS = [
        'syllabus_provided' => ['label' => 'Syllabus đã cung cấp', 'note' => null],
        'syllabus_adjust' => ['label' => 'Syllabus cần điều chỉnh', 'note' => null],
        'actual_dates' => ['label' => 'Đã điền ngày thực tế vào syllabus', 'note' => null],
        'materials_printed' => ['label' => 'Tài liệu in ấn đầy đủ', 'note' => null],
        'materials_order' => ['label' => 'Tài liệu cần order thêm', 'note' => 'Ghi chú loại tài liệu...'],
        'academic_issue' => ['label' => 'Tình hình báo cáo vấn đề học thuật', 'note' => 'Ghi chú vấn đề...'],
    ];

    public const TEACHER_OPERATION_CHECKS = [
        'ops_support' => ['label' => 'Hỗ trợ từ bộ phận vận hành', 'note' => 'Ghi chú chi tiết...'],
        'facilities' => ['label' => 'Thiết bị/CSVC (order trước 17h)', 'note' => 'Ghi chú tình trạng thiết bị...'],
        'punctuality' => ['label' => 'Giờ giấc (đúng giờ 100%)', 'note' => 'Ghi chú lý do đi trễ (nếu có)...'],
        'report_on_time' => ['label' => 'Nộp report lớp đúng giờ', 'note' => 'Ghi chú...'],
        'parent_contact' => ['label' => 'Báo cáo/Tương tác phụ huynh', 'note' => 'Ghi chú...'],
    ];

    public const TEACHER_QUALITY_CHECKS = [
        'grading' => ['label' => 'GV chấm chữa bài đầy đủ', 'note' => 'Ghi chú...'],
        'voice_feedback' => ['label' => 'GV nhận xét voice', 'note' => 'Ghi chú...'],
        'test_held' => ['label' => 'Có tổ chức mini/big test', 'note' => 'Tên bài test...'],
    ];

    /** Ô chữ của từng lớp: tên khóa giữ như bản 04/10 (situation / attention / solution / support_note) để Tổng quan đọc được. */
    public const TEACHER_CLASS_TEXTS = [
        'foreign_feedback' => 'Phản hồi của giáo viên nước ngoài',
        'academic_note' => 'Ghi chú học thuật khác',
        'operations_note' => 'Ghi chú vận hành khác',
        'test_result' => 'Kết quả test (tỉ lệ đạt)',
        'attitude' => 'Thái độ học viên chung',
        'attention' => 'Học sinh cần lưu ý',
        'situation' => 'Vấn đề phát sinh trong lớp',
        'solution' => 'Giải pháp đã thực hiện',
        'support_note' => 'Đề xuất hỗ trợ từ quản lý',
    ];

    /** Lựa chọn 2 mức của khối A / C (giá trị lưu => nhãn). */
    public const TEACHER_CHOICES = [
        'program_progress' => ['label' => 'Tiến độ chương trình', 'options' => ['on_track' => 'Đúng tiến độ', 'behind' => 'Chậm tiến độ']],
        'attendance_level' => ['label' => 'Chuyên cần học sinh', 'options' => ['high' => '95%-100%', 'low' => '< 95%']],
        'homework_level' => ['label' => 'Tỉ lệ hoàn thành bài tập', 'options' => ['high' => 'Cao (>80%)', 'low' => 'Thấp']],
    ];

    /** Chỉ số quy mô trong báo cáo tuần Học vụ (theo dõi, không tính KPI). Số tính tự động ở WeeklyReportMetrics; nhãn giữ để đọc báo cáo nhập tay cũ. */
    public const WEEKLY_METRICS = [
        'classes_running' => 'Số lớp đang chạy',
        'students' => 'Số học sinh',
        'teachers' => 'Số GV phụ trách',
        'new_leads' => 'Data mới nhận',
        'new_closed' => 'HV mới chốt',
        'transfers' => 'HV chuyển lớp',
        'makeups' => 'HV học bù',
        'care_due_classes' => 'Lớp đến hạn chăm sóc',
        'upcoming_test_classes' => 'Số lớp sắp lịch test',
    ];

    /** Phần tường thuật báo cáo tháng Học thuật. */
    public const MONTHLY_FIELDS = [
        'test_syllabus_review' => 'Rà soát test & syllabus',
        'materials_transfers' => 'Chuẩn bị giáo trình & chuyển lớp',
        'overall' => 'Đánh giá chung tháng',
        'teacher_notes' => 'Nhận xét riêng theo từng giáo viên (tùy chọn)',
    ];

    /** Báo cáo quý Học thuật: nhóm "Nội dung báo cáo" và "Đánh giá chung". */
    public const QUARTERLY_SECTIONS = [
        'Nội dung báo cáo' => [
            'academic_work' => 'Công việc học thuật',
            'teacher_staffing' => 'Nhân sự giáo viên',
            'next_plan' => 'Kế hoạch công việc kỳ sau',
        ],
        'Đánh giá chung' => [
            'program_progress' => 'Tiến độ chương trình',
            'implementation_quality' => 'Chất lượng triển khai',
            'improvement_priorities' => 'Ưu tiên cải thiện',
        ],
    ];

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
    public function journal(Request $request): Response
    {
        $this->guard();
        $isPriv = $this->isPrivileged();

        $query = StaffReport::with(['user.roles', 'seriousConfirmer:id,name', 'followups.user', 'classModel:id,code,name'])
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

        $journals = $query->latest('report_date')->latest('id')->paginate(15)->withQueryString()
            ->through(fn (StaffReport $j) => [
                'id' => $j->id,
                'title' => $j->title,
                'content' => $j->content,
                'severity' => $j->severity,
                'severity_label' => $j->severity_label,
                'kpi_code' => $j->kpi_code,
                'serious' => $j->serious_confirmed_at !== null,
                'serious_by' => $j->seriousConfirmer?->name,
                'status' => $j->status,
                'report_date' => $j->report_date->toDateString(),
                'created_at' => $j->created_at?->toIso8601String(),
                'class' => $j->classModel ? ['id' => $j->class_id, 'code' => $j->classModel->code] : null,
                'user' => $j->user?->name,
                'user_role' => ($role = $j->user?->roles->first()?->name) ? AclHelper::shortRoleLabel($role) : null,
                'followups' => $j->followups->map(fn (StaffReportFollowup $f) => [
                    'id' => $f->id,
                    'user' => $f->user?->name,
                    'content' => $f->content,
                    'created_at' => $f->created_at->toIso8601String(),
                ])->all(),
            ]);
        // Lớp gắn được sự vụ: chỉ lớp người ghi thấy (đang mở).
        $classes = ClassModel::visibleTo(Auth::user())->where('status', '!=', 'cancelled')->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('Reports/Journal', [
            'journals' => $journals,
            'isPriv' => $isPriv,
            'classes' => Ui::options($classes, fn (ClassModel $c) => $c->code.' · '.$c->name),
            'kpiOptions' => self::journalKpiCodes()->map(fn (string $name, string $code) => ['value' => $code, 'label' => "KPI {$code} · {$name}"])->values(),
            // Chỉ Admin đánh dấu "Lỗi nghiêm trọng" (mockup 02/05).
            'canConfirmSerious' => (bool) Auth::user()?->can(AdminOnlyApprovals::ABILITY),
            'today' => now()->toDateString(),
        ]);
    }

    /** Mã mục KPI Học vụ đang áp dụng (mã => tên) để gắn vào nhật ký. */
    private static function journalKpiCodes(): Collection
    {
        return KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->active()->ordered()->whereNotNull('code')->get()
            ->mapWithKeys(fn (KpiCriterion $c) => [(string) $c->code => $c->name]);
    }

    public function journalStore(Request $request)
    {
        $this->guard();
        // Nhật ký Học vụ (mockup 02/05) chỉ có mã KPI + nội dung; tab "Sự vụ" của Trang lớp gửi thêm tiêu đề / mức độ / lớp.
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'required_without:title|nullable|string',
            'severity' => 'nullable|in:normal,important,urgent',
            'kpi_code' => ['nullable', 'string', Rule::in(self::journalKpiCodes()->keys()->all())],
            'report_date' => 'nullable|date',
            'class_id' => 'nullable|integer',
        ], [
            'content.required_without' => 'Vui lòng nhập nội dung sự việc.',
            'kpi_code.in' => 'Mã KPI không có trong bộ KPI Học vụ đang áp dụng.',
        ]);
        $classId = null;
        if (! empty($validated['class_id'])) {
            $class = ClassModel::visibleTo(Auth::user())->find($validated['class_id']);
            abort_unless($class, 403, 'Lớp học này nằm ngoài phạm vi bạn được xem.');
            $classId = $class->id;
        }
        $content = $validated['content'] ?? null;

        StaffReport::create([
            'user_id' => Auth::id(),
            'class_id' => $classId,
            'type' => 'journal',
            // Không có tiêu đề riêng → lấy dòng đầu nội dung (danh sách sự vụ ở dashboard / Trang lớp hiện tiêu đề).
            'title' => ($validated['title'] ?? null) ?: Str::limit(Str::squish(Str::before((string) $content, "\n")), 120),
            'content' => $content,
            'severity' => $validated['severity'] ?? 'normal',
            'kpi_code' => $validated['kpi_code'] ?? null,
            'report_date' => $validated['report_date'] ?? now()->toDateString(),
            'status' => 'open',
        ]);

        return back()->with('success', 'Đã thêm nhật ký!');
    }

    /** Admin bật / tắt "Lỗi nghiêm trọng" cho một sự việc; lưu người xác nhận để Học vụ thấy "Xác nhận bởi". */
    public function journalSerious(Request $request, int $id): RedirectResponse
    {
        $report = StaffReport::query()->where('type', 'journal')->findOrFail($id);
        $serious = $request->boolean('serious');
        $report->update([
            'serious_confirmed_by' => $serious ? Auth::id() : null,
            'serious_confirmed_at' => $serious ? now() : null,
        ]);

        return back()->with('success', $serious ? 'Đã xác nhận lỗi nghiêm trọng.' : 'Đã bỏ đánh dấu lỗi nghiêm trọng.');
    }

    public function journalFollowup(Request $request, int $id)
    {
        $this->guard();
        $report = $this->journalEntry($id);
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
        $report = $this->journalEntry($id);
        $validated = $request->validate([
            'status' => 'required|in:open,following,resolved',
        ]);
        $report->update(['status' => $validated['status']]);

        return back()->with('success', 'Đã cập nhật trạng thái sự vụ!');
    }

    /** Sự vụ trong nhật ký mà người dùng được xử lý: của chính mình, hoặc người xem mọi báo cáo (staff_report.view_all). */
    private function journalEntry(int $id): StaffReport
    {
        return StaffReport::query()->where('type', 'journal')
            ->when(! $this->isPrivileged(), fn ($q) => $q->where('user_id', Auth::id()))
            ->findOrFail($id);
    }

    // ───────────────────────── BÁO CÁO ĐỊNH KỲ ─────────────────────────
    /**
     * Báo cáo định kỳ chính. Vai trò có màn báo cáo có cấu trúc cho đúng kỳ chính (Học vụ ngày, Học thuật tuần, giáo viên tháng)
     * → mở màn đó; vai trò khác vẫn nộp báo cáo chữ tự do.
     */
    public function myReports(Request $request): Response|RedirectResponse
    {
        $this->guard();
        if ($kind = self::primaryStructuredKind(Auth::user())) {
            return redirect()->route(self::STRUCTURED[$kind]['route']);
        }
        $type = $this->primaryType(Auth::user());

        $reports = StaffReport::where('user_id', Auth::id())
            ->where('type', $type)
            ->whereNull('period_key')
            ->latest('report_date')
            ->paginate(10)
            ->through(fn (StaffReport $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'content' => $r->content,
                'report_date' => $r->report_date->toDateString(),
            ]);

        return Inertia::render('Reports/My', [
            'reports' => $reports,
            'label' => StaffReport::TYPE_LABELS[$type] ?? 'Báo cáo',
            'today' => now()->toDateString(),
            // Báo cáo tháng (trợ giảng): hạn Chủ nhật cuối tháng — chỉ nhắc, không phạt.
            'monthlyDue' => $type === 'monthly' ? [
                'due_label' => 'Chủ nhật '.MonthlyReportDue::dueDate(now())->format('d/m'),
                'month_label' => now()->format('m/Y'),
                'submitted' => self::submittedCurrentPeriod(Auth::user()),
            ] : null,
            'tabs' => $this->reportTabs(Auth::user(), null),
        ]);
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

        $report = StaffReport::create([
            'user_id' => Auth::id(),
            'class_id' => $classId,
            'type' => $type,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'report_date' => $validated['report_date'] ?? now()->toDateString(),
            'status' => 'submitted',
        ]);
        self::notifyAdminsSubmitted($report);

        return back()->with('success', 'Đã nộp '.(StaffReport::TYPE_LABELS[$type] ?? 'báo cáo').' thành công!');
    }

    /** Màn có cấu trúc thay cho báo cáo định kỳ chính của người này (cùng loại kỳ), null nếu không có. */
    public static function primaryStructuredKind(User $user): ?string
    {
        $type = StaffType::reportCadence($user);

        return collect(StaffType::structuredReports($user))->first(fn (string $kind) => self::STRUCTURED[$kind]['type'] === $type);
    }

    /**
     * Đã nộp báo cáo định kỳ chính của kỳ chứa $at chưa (thẻ Tổng quan cá nhân, nhắc hạn báo cáo tháng).
     * Báo cáo có cấu trúc tính theo kỳ (bản nháp không tính); báo cáo chữ tự do tính theo ngày báo cáo trong kỳ.
     */
    public static function submittedCurrentPeriod(User $user, ?CarbonInterface $at = null): bool
    {
        $at = Carbon::instance($at ?? now());
        $cadence = StaffType::reportCadence($user);
        [$from, $to, $period] = match ($cadence) {
            'weekly' => [$at->copy()->startOfWeek(), $at->copy()->endOfWeek(), ReportPeriod::weekKey($at)],
            'monthly' => [$at->copy()->startOfMonth(), $at->copy()->endOfMonth(), $at->format('Y-m')],
            default => [$at->copy()->startOfDay(), $at->copy()->endOfDay(), $at->toDateString()],
        };
        $kind = self::primaryStructuredKind($user);

        return StaffReport::where('user_id', $user->id)
            ->where(fn ($q) => $q
                ->when($kind, fn ($s) => $s->orWhere(fn ($k) => $k->where('kind', $kind)->where('period_key', $period)->where('status', 'submitted')))
                ->orWhere(fn ($free) => $free->where('type', $cadence)->whereNull('period_key')
                    ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])))
            ->exists();
    }

    // ───────────────────────── BÁO CÁO CÓ CẤU TRÚC ─────────────────────────
    /**
     * Báo cáo ngày Học vụ / Học thuật (mockup 02/06 + chủ dự án 15/10/2026): mọi số liệu lấy từ hệ thống (DailyReportMetrics),
     * người viết chỉ nhập ghi chú từng khối + tạo việc follow-up. Ngày chưa nộp → tính trực tiếp; đã nộp → hiện số liệu đã chụp lúc nộp
     * (`refresh=1` tính lại để xem số mới, Lưu lại là cập nhật). Báo cáo nộp trước 15/10 (nhập tay, `data.fields`) vẫn hiện bản cũ.
     */
    public function daily(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('daily_ops')) {
            return $redirect;
        }
        $user = Auth::user();
        $date = $this->pickDate($request->input('date'));
        $audience = StaffType::dailyReportAudience($user);
        $report = $this->structuredReport('daily_ops', $date);
        $snapshot = $report?->data['auto'] ?? null;
        $live = ! $snapshot || $request->boolean('refresh');
        $metrics = $live ? DailyReportMetrics::compute($user, $date, $audience) : $snapshot;
        $task = ($taskId = $report?->data['task_id'] ?? null) ? WorkTask::with('assignee:id,name')->find($taskId) : null;
        // Báo cáo nhập tay trước khi chuyển sang số liệu tự động: hiện lại các ô đã nhập (chỉ đọc).
        $legacyFields = $report && ! $snapshot ? collect(self::DAILY_FIELDS)
            ->map(fn (array $f, string $key) => ['label' => $f['label'], 'value' => $report->data['fields'][$key] ?? null])
            ->filter(fn (array $f) => filled($f['value']))->values()->all() : [];

        return Inertia::render('Reports/DailyOps', [
            'audience' => $audience,
            'tabs' => $this->reportTabs($user, 'daily_ops'),
            'date' => $date,
            'dateLabel' => Str::ucfirst(Carbon::parse($date)->locale('vi')->isoFormat('dddd, D [tháng] M, YYYY')),
            'today' => now()->toDateString(),
            'sections' => $metrics['sections'],
            'computedAt' => $metrics['computed_at'] ?? null,
            'live' => $live,
            'noteLabels' => DailyReportMetrics::NOTE_LABELS,
            'notes' => (object) ($report?->data['notes'] ?? []),
            'legacyFields' => $legacyFields,
            'task' => $task ? [
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(),
                'assignee' => $task->assignee?->name,
                'status' => $task->status,
            ] : null,
            'assignees' => $this->taskAssignees(),
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'history' => $this->structuredHistory('daily_ops', fn (string $key) => 'Ngày '.Carbon::parse($key)->format('d/m/Y'), 'date'),
            'legacy' => $this->legacyReports('daily'),
        ]);
    }

    public function dailyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('daily_ops');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:5000'],
            'task' => ['nullable', 'array'],
            'task.enabled' => ['nullable', 'boolean'],
            'task.due_date' => ['nullable', 'required_if:task.enabled,1', 'date'],
            'task.assignee_id' => ['nullable', 'integer'],
        ], [
            'date.before_or_equal' => 'Không nộp báo cáo cho ngày chưa tới.',
            'task.*.required_if' => 'Bật "Tạo Task" thì phải nhập :attribute.',
        ], [
            'task.due_date' => 'ngày giải quyết',
            'task.assignee_id' => 'người phụ trách',
        ]);

        // Số liệu luôn tính lại ở máy chủ lúc nộp (không nhận số từ trình duyệt), chụp lại để Admin xem đúng số tại thời điểm nộp.
        $user = Auth::user();
        $date = Carbon::parse($data['date']);
        $snapshot = DailyReportMetrics::compute($user, $data['date'], StaffType::dailyReportAudience($user));
        $notes = collect($data['notes'] ?? [])->only(array_keys($snapshot['sections']))
            ->map(fn ($v) => filled($v) ? trim((string) $v) : null)->filter()->all();
        $text = DailyReportMetrics::text($snapshot, $notes);

        $existing = $this->structuredReport('daily_ops', $data['date']);
        $taskId = $existing?->data['task_id'] ?? null;
        if (! $taskId && $request->boolean('task.enabled')) {
            $taskId = $this->createFollowUpTask($data['task'], $data['date'], $text)->id;
        }

        $this->saveStructured('daily_ops', $data['date'], 'Báo cáo ngày '.$date->format('d/m/Y'), $data['date'], [
            'auto' => ['audience' => $snapshot['audience'], 'sections' => $snapshot['sections'], 'computed_at' => $snapshot['computed_at']],
            'fields' => $snapshot['fields'],
            'notes' => $notes,
            'task_id' => $taskId,
        ], $text.($taskId ? "\n\nTask follow-up: #{$taskId}" : ''));

        return redirect()->route('reports.periodic.daily', ['date' => $data['date']])
            ->with('success', 'Đã lưu báo cáo ngày '.$date->format('d/m/Y').'.');
    }

    /**
     * Việc follow-up tạo từ báo cáo ngày (mockup 02/06 chỉ có "Ngày giải quyết" + "Người phụ trách"): tiêu đề theo ngày báo cáo,
     * nội dung là chính báo cáo; người phụ trách để trống là mình, hoặc người mình được giao việc (như màn Giao việc).
     */
    private function createFollowUpTask(array $input, string $reportDate, string $reportText): WorkTask
    {
        $user = Auth::user();
        $assigneeId = (int) ($input['assignee_id'] ?? 0) ?: (int) $user->id;
        if (! collect($this->taskAssignees())->contains('value', $assigneeId)) {
            throw ValidationException::withMessages(['task.assignee_id' => 'Bạn không giao việc được cho người này.']);
        }

        $label = Carbon::parse($reportDate)->format('d/m/Y');
        $task = WorkTask::create([
            'title' => 'Follow-up báo cáo ngày '.$label,
            'description' => "Xử lý tiếp các việc trong báo cáo ngày {$label}:\n\n".$reportText,
            'creator_id' => $user->id,
            'assignee_id' => $assigneeId,
            'branch_id' => $user->branch_id,
            'due_date' => $input['due_date'],
            'due_time' => '18:00',
            'task_type' => 'one_time',
            'time_slot_category' => 'during',
            'status' => 'new',
        ]);
        if ($assigneeId !== (int) $user->id) {
            AdminNotification::notifyUser($assigneeId, 'task_assigned', "Bạn được giao việc: {$task->title}",
                "{$user->name} đã giao việc từ báo cáo ngày, hạn ".$task->due_date?->format('d/m/Y').'.',
                route('tasks.index', ['tab' => 'mine']), ['task_id' => $task->id]);
        }

        return $task;
    }

    /** @return list<array{value: int, label: string}> Người phụ trách việc follow-up: "Tôi" + người mình giao việc được. */
    private function taskAssignees(): array
    {
        $user = Auth::user();
        $others = $user->can('work_task.create') || $user->can('work_task.request')
            ? WorkTaskController::assignableUsers($user)->reject(fn (User $u) => (int) $u->id === (int) $user->id)
            : collect();

        return [
            ['value' => (int) $user->id, 'label' => $user->name.' (Tôi)'],
            ...$others->map(fn (User $u) => ['value' => (int) $u->id, 'label' => $u->name])->values()->all(),
        ];
    }

    /** Báo cáo tuần Học thuật (mockup 02/18): công việc đã làm, dự kiến tuần tới, quản lý yêu cầu — nhập theo dòng. */
    public function academicWeekly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('academic_weekly')) {
            return $redirect;
        }
        $week = ReportPeriod::pick($request->input('week'), ReportPeriod::WEEK_PATTERN, ReportPeriod::currentWeek());
        $report = $this->structuredReport('academic_weekly', $week);
        // Số tuần + dòng gợi ý từ hệ thống (WeeklyReportMetrics): tuần chưa nộp thì điền sẵn vào 3 bảng, đã nộp thì bấm "Thêm từ hệ thống".
        $metrics = WeeklyReportMetrics::academic(Auth::user(), $week);

        return Inertia::render('Reports/AcademicWeekly', [
            'tabs' => $this->reportTabs(Auth::user(), 'academic_weekly'),
            'week' => $week,
            'weeks' => ReportPeriod::weekOptions(),
            'sections' => self::WEEKLY_SECTIONS,
            'columns' => self::WEEKLY_COLUMNS,
            'progressOptions' => array_map(fn (int $p) => ['value' => (string) $p, 'label' => $p.'%'], self::WEEKLY_PROGRESS),
            'rows' => (object) ($report?->data['rows'] ?? []),
            'summary' => $metrics['summary'],
            'suggestions' => $metrics['suggestions'],
            'computedAt' => $metrics['computed_at'],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'history' => $this->structuredHistory('academic_weekly', fn (string $key) => ReportPeriod::weekLabel($key), 'week'),
            'legacy' => $this->legacyReports('weekly'),
        ]);
    }

    public function academicWeeklyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('academic_weekly');
        $rules = ['week' => ['required', 'regex:'.ReportPeriod::WEEK_PATTERN], 'rows' => ['nullable', 'array']];
        foreach (array_keys(self::WEEKLY_SECTIONS) as $section) {
            $rules += [
                "rows.{$section}" => ['nullable', 'array', 'max:50'],
                "rows.{$section}.*.content" => ['nullable', 'string', 'max:500'],
                "rows.{$section}.*.detail" => ['nullable', 'string', 'max:5000'],
                "rows.{$section}.*.deadline" => ['nullable', 'date'],
                "rows.{$section}.*.progress" => ['nullable', 'in:'.implode(',', self::WEEKLY_PROGRESS)],
                "rows.{$section}.*.link" => ['nullable', 'url', 'max:500'],
                "rows.{$section}.*.report" => ['nullable', 'string', 'max:1000'],
                "rows.{$section}.*.support" => ['nullable', 'string', 'max:1000'],
                "rows.{$section}.*.note" => ['nullable', 'string', 'max:1000'],
                "rows.{$section}.*.source" => ['nullable', 'string', 'max:100'],
            ];
        }
        $data = $request->validate($rules, [
            'rows.*.*.link.url' => 'Link sản phẩm phải là đường dẫn (https://...).',
            'rows.*.*.deadline.date' => 'Deadline không phải ngày hợp lệ.',
        ]);

        $rows = [];
        $errors = [];
        foreach (array_keys(self::WEEKLY_SECTIONS) as $section) {
            $rows[$section] = [];
            foreach ($data['rows'][$section] ?? [] as $i => $row) {
                $clean = collect(self::WEEKLY_COLUMNS)->keys()
                    ->mapWithKeys(fn ($col) => [$col => filled($row[$col] ?? null) ? trim((string) $row[$col]) : null])->all();
                if (collect($clean)->filter()->isEmpty()) {
                    continue;
                }
                if ($clean['content'] === null) {
                    $errors["rows.{$section}.{$i}.content"] = 'Nhập nội dung công việc.';
                }
                $clean['progress'] = $clean['progress'] === null ? null : (int) $clean['progress'];
                // Dòng điền sẵn từ hệ thống giữ mã nguồn (milestone:12, task:5…) để lần sau không gợi ý trùng.
                $clean['source'] = filled($row['source'] ?? null) ? (string) $row['source'] : null;
                $rows[$section][] = $clean;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (collect($rows)->flatten(1)->isEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Nhập ít nhất một công việc.']);
        }

        $text = collect($rows)->filter()->map(fn (array $items, string $section) => self::WEEKLY_SECTIONS[$section].":\n"
            .collect($items)->values()->map(function (array $r, int $i) {
                $meta = collect([
                    $r['deadline'] ? 'deadline '.Carbon::parse($r['deadline'])->format('d/m/Y') : null,
                    $r['progress'] !== null ? 'tiến độ '.$r['progress'].'%' : null,
                ])->filter()->implode(', ');
                $extra = collect(['detail', 'link', 'report', 'support', 'note'])->filter(fn ($c) => $r[$c] !== null)
                    ->map(fn ($c) => '   '.self::WEEKLY_COLUMNS[$c].': '.$r[$c]);

                return ($i + 1).'. '.$r['content'].($meta ? " ({$meta})" : '').($extra->isEmpty() ? '' : "\n".$extra->implode("\n"));
            })->implode("\n"))->implode("\n\n");
        [$start] = ReportPeriod::weekRange($data['week']);

        $this->saveStructured('academic_weekly', $data['week'], 'Báo cáo tuần '.ReportPeriod::weekLabel($data['week']), $start->toDateString(),
            ['rows' => $rows], $text);

        return back()->with('success', 'Đã lưu báo cáo tuần '.$data['week'].'.');
    }

    /**
     * Báo cáo tuần Học vụ: số lần phát sinh theo từng mục KPI đang áp dụng + chỉ số quy mô, tính tự động cho cả tuần
     * (WeeklyReportMetrics). Người viết chỉ nhập ghi chú tuần; tuần đã nộp hiện số lúc nộp, "Cập nhật số liệu" tính lại.
     */
    public function weeklyKpi(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('weekly_kpi')) {
            return $redirect;
        }
        $week = ReportPeriod::pick($request->input('week'), ReportPeriod::WEEK_PATTERN, ReportPeriod::currentWeek());
        $report = $this->structuredReport('weekly_kpi', $week);
        $snapshot = $report?->data['auto'] ?? null;
        $live = ! $snapshot || $request->boolean('refresh');
        $metrics = $live ? WeeklyReportMetrics::operations(Auth::user(), $week) : $snapshot;

        return Inertia::render('Reports/WeeklyKpi', [
            'tabs' => $this->reportTabs(Auth::user(), 'weekly_kpi'),
            'week' => $week,
            'weeks' => ReportPeriod::weekOptions(),
            'groups' => $metrics['groups'],
            'metrics' => $metrics['metrics'],
            'daily' => $metrics['daily'],
            'computedAt' => $metrics['computed_at'] ?? null,
            'live' => $live,
            'note' => $report?->data['note'] ?? null,
            'legacy' => $report && ! $snapshot ? $this->legacyWeeklyKpi($report) : [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'history' => $this->structuredHistory('weekly_kpi', fn (string $key) => ReportPeriod::weekLabel($key), 'week'),
        ]);
    }

    /** Báo cáo tuần nhập tay trước khi chuyển sang số liệu tự động: các số đã nhập (chỉ đọc). */
    private function legacyWeeklyKpi(StaffReport $report): array
    {
        return [
            ...collect($report->data['counts'] ?? [])->filter(fn ($c) => ($c['count'] ?? 0) > 0)
                ->map(fn ($c) => ['label' => $c['name'], 'value' => $c['count'].' lần'])->values()->all(),
            ...collect($report->data['metrics'] ?? [])->filter(fn ($v) => $v !== null)
                ->map(fn ($v, $k) => ['label' => self::WEEKLY_METRICS[$k] ?? $k, 'value' => (string) $v])->values()->all(),
        ];
    }

    public function weeklyKpiStore(Request $request): RedirectResponse
    {
        $this->guardStructured('weekly_kpi');
        $data = $request->validate([
            'week' => ['required', 'regex:'.ReportPeriod::WEEK_PATTERN],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        // Số liệu tính lại trên server lúc nộp (không nhận số từ form) và lưu kèm tên / nhóm mục KPI để báo cáo cũ
        // vẫn đọc được khi Admin đổi cấu hình KPI.
        $snapshot = WeeklyReportMetrics::operations(Auth::user(), $data['week']);
        $counts = collect($snapshot['groups'])->flatMap(fn (array $g) => collect($g['items'])->map(fn (array $i) => [
            'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'group' => $g['name'], 'count' => $i['count'],
        ]))->values()->all();
        $metrics = collect($snapshot['metrics']['items'])->mapWithKeys(fn (array $i) => [$i['key'] => $i['count']])->all();
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        [$start] = ReportPeriod::weekRange($data['week']);

        $this->saveStructured('weekly_kpi', $data['week'], 'Báo cáo tuần '.ReportPeriod::weekLabel($data['week']), $start->toDateString(),
            ['auto' => $snapshot, 'counts' => $counts, 'metrics' => $metrics, 'note' => $note],
            WeeklyReportMetrics::operationsText($snapshot, $note));

        return back()->with('success', 'Đã lưu báo cáo tuần '.$data['week'].'.');
    }

    /** Báo cáo tháng Học thuật (mockup 02/19): tường thuật + bảng báo cáo tuần trong tháng và họp giáo viên. */
    public function academicMonthly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('academic_monthly')) {
            return $redirect;
        }
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        [$from, $to] = ReportPeriod::monthRange($month);
        $report = $this->structuredReport('academic_monthly', $month);

        return Inertia::render('Reports/AcademicMonthly', [
            'tabs' => $this->reportTabs(Auth::user(), 'academic_monthly'),
            'month' => $month,
            'months' => ReportPeriod::monthOptions(),
            'fields' => self::MONTHLY_FIELDS,
            'values' => $report?->data['narrative'] ?? [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'weeklyReports' => $this->weeksOfMonth($from, $to),
            'meetings' => TeacherMeetingReport::with('teacher:id,name')
                ->where('author_id', Auth::id())
                ->whereDate('week_start', '>=', $from->copy()->startOfWeek()->toDateString())
                ->whereDate('week_start', '<=', $to->toDateString())
                ->orderByDesc('week_start')->get()
                ->map(fn (TeacherMeetingReport $m) => [
                    'id' => $m->id,
                    'week_start' => $m->week_start->toDateString(),
                    'teacher' => $m->teacher?->name,
                    'status_label' => TeacherMeetingReport::STATUSES[$m->status] ?? $m->status,
                    'status_color' => TeacherMeetingReport::STATUS_COLORS[$m->status] ?? 'neutral',
                ])->values(),
            'history' => $this->structuredHistory('academic_monthly', fn (string $key) => ReportPeriod::monthLabel($key), 'month'),
        ]);
    }

    /**
     * Các tuần (ISO) chạm vào tháng, mới nhất trước: "Tuần n", khoảng ngày cắt theo tháng, người nộp (PIC) báo cáo tuần của mình;
     * tuần chưa có báo cáo có cấu trúc thì lấy báo cáo tuần chữ tự do cũ nộp trong tuần đó (nếu có).
     *
     * @return list<array<string, mixed>>
     */
    private function weeksOfMonth(Carbon $from, Carbon $to): array
    {
        $weeks = [];
        for ($day = $from->copy()->startOfWeek(); $day->lte($to); $day->addWeek()) {
            $weeks[] = ReportPeriod::weekKey($day);
        }
        $structured = StaffReport::with('user:id,name')->where('user_id', Auth::id())->where('kind', 'academic_weekly')
            ->whereIn('period_key', $weeks)->get()->keyBy('period_key');
        $legacy = StaffReport::with('user:id,name')->where('user_id', Auth::id())->where('type', 'weekly')->whereNull('period_key')
            ->whereDate('report_date', '>=', $from->copy()->startOfWeek()->toDateString())->whereDate('report_date', '<=', $to->toDateString())
            ->orderBy('report_date')->get()->keyBy(fn (StaffReport $r) => ReportPeriod::weekKey($r->report_date));

        return collect($weeks)->map(function (string $key, int $i) use ($from, $to, $structured, $legacy) {
            [$start, $end] = ReportPeriod::weekRange($key);
            $report = $structured->get($key) ?? $legacy->get($key);

            return [
                'week' => $key,
                'label' => 'Tuần '.($i + 1),
                'range' => $start->max($from)->format('d/m').' - '.$end->min($to)->format('d/m'),
                'pic' => $report?->user?->name,
                'title' => $report?->title,
                'content' => $report?->content,
                'href' => route('reports.periodic.academic-weekly', ['week' => $key]),
            ];
        })->reverse()->values()->all();
    }

    public function academicMonthlyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('academic_monthly');
        $data = $this->validatedNarrative($request, 'month', ReportPeriod::MONTH_PATTERN, self::MONTHLY_FIELDS);
        [$from] = ReportPeriod::monthRange($data['month']);

        $this->saveStructured('academic_monthly', $data['month'], 'Báo cáo tháng Học thuật — '.ReportPeriod::monthLabel($data['month']),
            $from->toDateString(), ['narrative' => $data['narrative']], $this->narrativeText($data['narrative'], self::MONTHLY_FIELDS));

        return back()->with('success', 'Đã lưu báo cáo '.mb_strtolower(ReportPeriod::monthLabel($data['month'])).'.');
    }

    /**
     * Báo cáo tháng của giáo viên (mockup 03/17 "Báo cáo chung của giáo viên"): báo cáo chấm công tháng (ghi chú) + báo cáo giảng dạy
     * theo từng lớp (A. Học thuật, B. Vận hành lớp, C. Chất lượng lớp) và nhận xét chung tháng (tiến độ, khó khăn, đề xuất — 04/10/2026).
     * Chuyên cần / bài tập gợi ý sẵn từ số liệu lớp trong tháng. Lưu nháp được; nộp rồi thì lưu lại là cập nhật.
     */
    public function teacherMonthly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('teacher_monthly')) {
            return $redirect;
        }
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        $report = $this->structuredReport('teacher_monthly', $month);
        $attendance = $this->structuredReport('teacher_attendance', $month);
        $quality = new TeachingQuality($month);
        $sessions = ClassSession::query()->forStaff(Auth::id())
            ->whereBetween('date', [$quality->from->toDateString(), min($quality->to->toDateString(), now()->toDateString())])
            ->selectRaw('class_id, count(*) as total')->groupBy('class_id')->pluck('total', 'class_id');
        $classes = collect($quality->classMetrics($quality->classesOf(Auth::user())))
            ->map(fn (array $c) => [...$c, 'sessions_done' => (int) ($sessions[$c['id']] ?? 0)])->all();

        return Inertia::render('Reports/TeacherMonthly', [
            'tabs' => $this->reportTabs(Auth::user(), 'teacher_monthly'),
            'month' => $month,
            'monthLabel' => ReportPeriod::monthLabel($month),
            'months' => ReportPeriod::monthOptions(),
            'dueLabel' => 'Chủ nhật '.MonthlyReportDue::dueDate($quality->from)->format('d/m'),
            'generalFields' => self::TEACHER_GENERAL_FIELDS,
            'academicChecks' => self::TEACHER_ACADEMIC_CHECKS,
            'operationChecks' => self::TEACHER_OPERATION_CHECKS,
            'qualityChecks' => self::TEACHER_QUALITY_CHECKS,
            'classTexts' => self::TEACHER_CLASS_TEXTS,
            'choices' => self::TEACHER_CHOICES,
            'general' => $report?->data['general'] ?? [],
            'classValues' => (object) ($report?->data['classes'] ?? []),
            'classes' => $classes,
            'status' => $report?->status,
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'attendance' => [
                'note' => $attendance?->data['note'] ?? null,
                'submitted_at' => $attendance?->updated_at?->toIso8601String(),
            ],
            'proposeUrl' => Route::has('syllabus.teacher-propose') && Auth::user()->can('syllabus.propose_adjustment')
                ? route('syllabus.teacher-propose') : null,
            'history' => $this->structuredHistory('teacher_monthly', fn (string $key) => ReportPeriod::monthLabel($key), 'month'),
            'legacy' => $this->legacyReports('monthly'),
        ]);
    }

    public function teacherMonthlyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('teacher_monthly');
        $checks = self::TEACHER_ACADEMIC_CHECKS + self::TEACHER_OPERATION_CHECKS + self::TEACHER_QUALITY_CHECKS;
        $data = $request->validate([
            'month' => ['required', 'regex:'.ReportPeriod::MONTH_PATTERN],
            'intent' => ['nullable', 'in:draft,submit'],
            'general' => ['nullable', 'array'],
            ...collect(self::TEACHER_GENERAL_FIELDS)->keys()->mapWithKeys(fn ($f) => ["general.{$f}" => ['nullable', 'string', 'max:10000']])->all(),
            'classes' => ['nullable', 'array'],
            'classes.*.sessions' => ['nullable', 'integer', 'min:0', 'max:500'],
            ...collect(self::TEACHER_CHOICES)->mapWithKeys(fn ($c, $f) => ["classes.*.{$f}" => ['nullable', 'in:'.implode(',', array_keys($c['options']))]])->all(),
            'classes.*.checks' => ['nullable', 'array'],
            'classes.*.checks.*' => ['nullable', 'boolean'],
            'classes.*.notes' => ['nullable', 'array'],
            'classes.*.notes.*' => ['nullable', 'string', 'max:1000'],
            ...collect(self::TEACHER_CLASS_TEXTS)->keys()->mapWithKeys(fn ($f) => ["classes.*.{$f}" => ['nullable', 'string', 'max:5000']])->all(),
        ], ['classes.*.sessions.min' => 'Số buổi không âm.']);

        // Chỉ nhận lớp giáo viên đang giữ trong tháng báo cáo.
        $quality = new TeachingQuality($data['month']);
        $classes = $quality->classesOf(Auth::user())->keyBy('id');
        $general = collect(self::TEACHER_GENERAL_FIELDS)->keys()
            ->mapWithKeys(fn ($f) => [$f => filled($data['general'][$f] ?? null) ? $data['general'][$f] : null])->all();
        $classData = collect($data['classes'] ?? [])
            ->filter(fn ($row, $id) => $classes->has((int) $id))
            ->map(function (array $row) use ($checks) {
                $clean = [
                    'sessions' => is_numeric($row['sessions'] ?? null) ? (int) $row['sessions'] : null,
                    ...collect(self::TEACHER_CHOICES)->keys()->mapWithKeys(fn ($f) => [$f => filled($row[$f] ?? null) ? $row[$f] : null])->all(),
                    'checks' => collect($checks)->keys()->mapWithKeys(fn ($k) => [$k => (bool) ($row['checks'][$k] ?? false)])->all(),
                    'notes' => collect($checks)->filter(fn ($c) => $c['note'] !== null)->keys()
                        ->mapWithKeys(fn ($k) => [$k => filled($row['notes'][$k] ?? null) ? trim($row['notes'][$k]) : null])->all(),
                    ...collect(self::TEACHER_CLASS_TEXTS)->keys()->mapWithKeys(fn ($f) => [$f => filled($row[$f] ?? null) ? $row[$f] : null])->all(),
                ];
                $clean['need_support'] = $clean['support_note'] !== null;

                return $clean;
            })
            ->reject(fn (array $row) => collect($row)->except(['checks', 'notes', 'need_support'])->filter(fn ($v) => $v !== null)->isEmpty()
                && ! in_array(true, $row['checks'], true) && collect($row['notes'])->filter()->isEmpty())
            ->all();
        if (collect($general)->filter()->isEmpty() && $classData === []) {
            throw ValidationException::withMessages(['general' => 'Nhập ít nhất một mục của báo cáo.']);
        }

        $lines = [$this->narrativeText($general, self::TEACHER_GENERAL_FIELDS)];
        foreach ($classData as $id => $row) {
            $lines[] = 'Lớp '.$classes->get((int) $id)->name.":\n".$this->teacherClassText($row, $checks);
        }
        $existing = $this->structuredReport('teacher_monthly', $data['month']);
        // Đã nộp thì "Lưu nháp" không kéo báo cáo về nháp.
        $status = ($data['intent'] ?? 'submit') === 'draft' && $existing?->status !== 'submitted' ? 'draft' : 'submitted';
        [$from] = ReportPeriod::monthRange($data['month']);
        $this->saveStructured('teacher_monthly', $data['month'], 'Báo cáo giảng dạy tháng — '.ReportPeriod::monthLabel($data['month']),
            $from->toDateString(), ['general' => $general, 'classes' => $classData], trim(implode("\n\n", array_filter($lines))), $status);

        return back()->with('success', ($status === 'draft' ? 'Đã lưu nháp báo cáo ' : 'Đã nộp báo cáo giảng dạy ').mb_strtolower(ReportPeriod::monthLabel($data['month'])).'.');
    }

    /** Báo cáo chấm công tháng của giáo viên (khối đầu màn 03/17): ghi chú tùy chọn, mỗi tháng 1 bản. */
    public function teacherAttendanceStore(Request $request): RedirectResponse
    {
        $this->guardStructured('teacher_monthly');
        $data = $request->validate([
            'month' => ['required', 'regex:'.ReportPeriod::MONTH_PATTERN],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        [$from] = ReportPeriod::monthRange($data['month']);

        $this->saveStructured('teacher_attendance', $data['month'], 'Báo cáo chấm công — '.ReportPeriod::monthLabel($data['month']),
            $from->toDateString(), ['note' => $note], $note ?? 'Không có ghi chú thêm.');

        return back()->with('success', 'Đã nộp báo cáo chấm công '.mb_strtolower(ReportPeriod::monthLabel($data['month'])).'.');
    }

    /** @param array<string, array{label: string, note: ?string}> $checks */
    private function teacherClassText(array $row, array $checks): string
    {
        $lines = collect();
        if ($row['sessions'] !== null) {
            $lines->push('- Số buổi đã dạy: '.$row['sessions']);
        }
        foreach (self::TEACHER_CHOICES as $field => $choice) {
            if ($row[$field] !== null) {
                $lines->push('- '.$choice['label'].': '.$choice['options'][$row[$field]]);
            }
        }
        foreach ($checks as $key => $check) {
            $note = $row['notes'][$key] ?? null;
            if ($row['checks'][$key] || $note) {
                $lines->push('- '.($row['checks'][$key] ? '[x] ' : '[ ] ').$check['label'].($note ? ' — '.$note : ''));
            }
        }
        foreach (self::TEACHER_CLASS_TEXTS as $field => $label) {
            if ($row[$field] !== null) {
                $lines->push('- '.$label.': '.$row[$field]);
            }
        }

        return $lines->implode("\n");
    }

    /**
     * Order học thuật của giáo viên trong tháng (tạo trong tháng hoặc dùng trong tháng).
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public static function academicOrders(array $userIds, TeachingQuality $quality, int $limit = 20): array
    {
        return MaterialOrder::with(['requester:id,name', 'classRoom:id,name'])
            ->whereIn('requester_id', $userIds)
            ->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->where(fn ($q) => $q->whereBetween('created_at', [$quality->from, $quality->to])
                ->orWhereBetween('use_date', [$quality->from->toDateString(), $quality->to->toDateString()]))
            ->latest()->limit($limit)->get()
            ->map(fn (MaterialOrder $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'teacher' => $o->requester?->name,
                'class' => $o->classRoom?->name,
                'use_date' => $o->use_date?->toDateString(),
                'status_label' => MaterialOrder::STATUSES[$o->status] ?? $o->status,
                'open' => in_array($o->status, MaterialOrder::OPEN_STATUSES, true),
            ])->values()->all();
    }

    /** Báo cáo quý Học thuật: tường thuật + 3 báo cáo tháng trong quý. */
    public function academicQuarterly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('academic_quarterly')) {
            return $redirect;
        }
        $quarter = ReportPeriod::pick($request->input('quarter'), ReportPeriod::QUARTER_PATTERN, ReportPeriod::currentQuarter());
        $report = $this->structuredReport('academic_quarterly', $quarter);
        $monthly = StaffReport::where('user_id', Auth::id())->where('kind', 'academic_monthly')
            ->whereIn('period_key', ReportPeriod::quarterMonths($quarter))->get()->keyBy('period_key');

        return Inertia::render('Reports/AcademicQuarterly', [
            'tabs' => $this->reportTabs(Auth::user(), 'academic_quarterly'),
            'quarter' => $quarter,
            'quarterLabel' => ReportPeriod::quarterLabel($quarter),
            'quarters' => ReportPeriod::quarterOptions(),
            'sections' => self::QUARTERLY_SECTIONS,
            'values' => $report?->data['narrative'] ?? [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'monthlyReports' => collect(ReportPeriod::quarterMonths($quarter))->map(fn (string $m) => [
                'month' => $m,
                'label' => 'Báo cáo '.ReportPeriod::monthLabel($m),
                'submitted' => $monthly->has($m),
                'updated_at' => $monthly->get($m)?->updated_at?->toIso8601String(),
            ])->values(),
            'history' => $this->structuredHistory('academic_quarterly', fn (string $key) => ReportPeriod::quarterLabel($key), 'quarter'),
        ]);
    }

    public function academicQuarterlyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('academic_quarterly');
        $fields = array_merge(...array_values(self::QUARTERLY_SECTIONS));
        $data = $this->validatedNarrative($request, 'quarter', ReportPeriod::QUARTER_PATTERN, $fields);
        [$from] = ReportPeriod::quarterRange($data['quarter']);

        $this->saveStructured('academic_quarterly', $data['quarter'], 'Báo cáo quý Học thuật — '.ReportPeriod::quarterLabel($data['quarter']),
            $from->toDateString(), ['narrative' => $data['narrative']], $this->narrativeText($data['narrative'], $fields));

        return back()->with('success', 'Đã lưu báo cáo quý '.$data['quarter'].'.');
    }

    /** Mở màn báo cáo không thuộc vai trò mình (link cũ / mockup): quay về báo cáo định kỳ chính thay vì báo lỗi. */
    private function redirectUnlessStructured(string $kind): ?RedirectResponse
    {
        $this->guard();
        if (in_array($kind, StaffType::structuredReports(Auth::user()), true)) {
            return null;
        }
        $home = self::primaryStructuredKind(Auth::user());

        return redirect()->route($home ? self::STRUCTURED[$home]['route'] : 'reports.my')
            ->with('info', self::STRUCTURED[$kind]['label'].' chỉ dành cho '.self::STRUCTURED[$kind]['for'].'.');
    }

    private function guardStructured(string $kind): void
    {
        $this->guard();
        abort_unless(in_array($kind, StaffType::structuredReports(Auth::user()), true), 403, 'Báo cáo này không thuộc vai trò của bạn.');
    }

    /** Ngày báo cáo hợp lệ (Y-m-d, không sau hôm nay), ngược lại hôm nay. */
    private function pickDate(?string $value): string
    {
        $today = now()->toDateString();

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false && $value <= $today ? $value : $today;
    }

    private function structuredReport(string $kind, string $period): ?StaffReport
    {
        return StaffReport::where('user_id', Auth::id())
            ->where('kind', $kind)
            ->where('period_key', $period)
            ->first();
    }

    /** @param array<string, mixed> $data */
    private function saveStructured(string $kind, string $period, string $title, string $reportDate, array $data, string $content, string $status = 'submitted'): void
    {
        $before = StaffReport::where('user_id', Auth::id())->where('kind', $kind)->where('period_key', $period)->first(['id', 'status', 'reviewed_at']);
        // Nộp (hoặc sửa bản đã nộp) → về lại "Chờ kiểm tra" để Admin xem bản mới.
        $report = StaffReport::updateOrCreate(
            ['user_id' => Auth::id(), 'kind' => $kind, 'period_key' => $period],
            ['type' => self::STRUCTURED[$kind]['type'], 'title' => $title, 'report_date' => $reportDate, 'data' => $data, 'content' => $content, 'status' => $status]
                + ($status === 'submitted' ? ['reviewed_by' => null, 'reviewed_at' => null] : []),
        );
        // Báo Admin khi báo cáo mới vào hàng chờ kiểm tra (lần nộp đầu, nộp từ nháp, hoặc sửa sau khi Admin đã kiểm tra);
        // sửa tiếp khi vẫn đang chờ kiểm tra thì không báo lại.
        if ($status === 'submitted' && (! $before || $before->status !== 'submitted' || $before->reviewed_at !== null)) {
            self::notifyAdminsSubmitted($report, $before?->status === 'submitted');
        }
    }

    /** Báo cáo mọi vai trò nộp xong được đẩy về Admin kiểm tra (14/10/2026) ở "Tổng hợp báo cáo & nhật ký". */
    private static function notifyAdminsSubmitted(StaffReport $report, bool $updated = false): void
    {
        $name = Auth::user()?->name ?? 'Nhân sự';
        AdminNotification::notifyUsers(
            BranchStaff::admins()->pluck('id'),
            'staff_report_submitted',
            ($updated ? 'Báo cáo cập nhật: ' : 'Báo cáo mới: ').$report->title,
            "{$name} vừa ".($updated ? 'cập nhật' : 'nộp')." {$report->title}. Mở để kiểm tra.",
            route('reports.all', ['review' => 'pending']),
            ['staff_report_id' => $report->id],
            Auth::id(),
        );
    }

    /** Admin đánh dấu báo cáo đã kiểm tra / bỏ đánh dấu (route chỉ Admin). */
    public function review(Request $request, int $id): RedirectResponse
    {
        $report = StaffReport::query()->where('type', '!=', 'journal')->where('status', '!=', 'draft')->findOrFail($id);
        $reviewed = $request->boolean('reviewed', true);
        $report->update(['reviewed_by' => $reviewed ? Auth::id() : null, 'reviewed_at' => $reviewed ? now() : null]);

        return back()->with('success', $reviewed ? 'Đã đánh dấu đã kiểm tra.' : 'Đã chuyển về chờ kiểm tra.');
    }

    /**
     * Các kỳ đã nộp (mới nhất trước) để chuyển nhanh sang xem / sửa.
     *
     * @return list<array{period: string, label: string, href: string, updated_at: string|null, draft: bool}>
     */
    private function structuredHistory(string $kind, \Closure $label, string $param): array
    {
        return StaffReport::where('user_id', Auth::id())
            ->where('kind', $kind)
            ->whereNotNull('period_key')
            ->orderByDesc('period_key')->limit(12)->get(['period_key', 'updated_at', 'status'])
            ->map(fn (StaffReport $r) => [
                'period' => $r->period_key,
                'label' => $label($r->period_key),
                'href' => route(self::STRUCTURED[$kind]['route'], [$param => $r->period_key]),
                'updated_at' => $r->updated_at?->toIso8601String(),
                'draft' => $r->status === 'draft',
            ])->values()->all();
    }

    /**
     * Báo cáo chữ tự do đã nộp trước khi có màn có cấu trúc (giữ để người nộp vẫn xem lại được).
     *
     * @return list<array{id: int, title: string, content: ?string, report_date: string}>
     */
    private function legacyReports(string $type): array
    {
        return StaffReport::where('user_id', Auth::id())->where('type', $type)->whereNull('period_key')
            ->latest('report_date')->latest('id')->limit(10)->get(['id', 'title', 'content', 'report_date'])
            ->map(fn (StaffReport $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'content' => $r->content,
                'report_date' => $r->report_date->toDateString(),
            ])->values()->all();
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{narrative: array<string, string|null>}
     */
    private function validatedNarrative(Request $request, string $periodField, string $pattern, array $fields): array
    {
        $data = $request->validate([
            $periodField => ['required', 'regex:'.$pattern],
            'narrative' => ['required', 'array'],
            ...collect($fields)->keys()->mapWithKeys(fn ($f) => ["narrative.{$f}" => ['nullable', 'string', 'max:10000']])->all(),
        ], ['narrative.required' => 'Nhập ít nhất một mục của báo cáo.']);

        $narrative = collect($fields)->keys()->mapWithKeys(fn ($f) => [$f => filled($data['narrative'][$f] ?? null) ? $data['narrative'][$f] : null])->all();
        if (collect($narrative)->filter()->isEmpty()) {
            throw ValidationException::withMessages(['narrative' => 'Nhập ít nhất một mục của báo cáo.']);
        }

        return [$periodField => $data[$periodField], 'narrative' => $narrative];
    }

    /** @param array<string, string> $fields */
    private function narrativeText(array $narrative, array $fields): string
    {
        return collect($narrative)->filter()->map(fn ($text, $f) => $fields[$f].":\n".$text)->implode("\n\n");
    }

    /**
     * Tab của màn "Báo cáo định kỳ của tôi": báo cáo định kỳ chính (chữ tự do, chỉ khi vai trò chưa có màn có cấu trúc cho kỳ chính)
     * + báo cáo có cấu trúc theo vai trò. Chỉ một tab → không hiện thanh tab.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function reportTabs(User $user, ?string $current): array
    {
        $tabs = array_map(fn (string $kind) => [
            'label' => self::STRUCTURED[$kind]['label'],
            'href' => route(self::STRUCTURED[$kind]['route']),
            'active' => $current === $kind,
        ], array_values(array_filter(StaffType::structuredReports($user), fn ($kind) => self::STRUCTURED[$kind]['tab'] ?? true)));
        if (self::primaryStructuredKind($user) === null) {
            array_unshift($tabs, ['label' => StaffReport::TYPE_LABELS[$this->primaryType($user)] ?? 'Báo cáo', 'href' => route('reports.my'), 'active' => $current === null]);
        }

        return count($tabs) > 1 ? $tabs : [];
    }

    // ───────────────────────── ADMIN XEM TỔNG ─────────────────────────
    public function allReports(Request $request): Response
    {
        $this->guard();
        abort_unless($this->isPrivileged(), 403, 'Chỉ Admin / Manager mới xem tổng hợp.');

        $query = StaffReport::with(['user.roles', 'followups', 'reviewer:id,name'])->where('status', '!=', 'draft');
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($date = $request->input('date')) {
            $query->whereDate('report_date', $date);
        }
        // Hàng chờ Admin kiểm tra (báo cáo định kỳ; nhật ký sự vụ không cần kiểm tra ở đây).
        $review = $request->input('review');
        if (in_array($review, ['pending', 'done'], true)) {
            $query->where('type', '!=', 'journal')->when($review === 'pending', fn ($q) => $q->whereNull('reviewed_at'), fn ($q) => $q->whereNotNull('reviewed_at'));
        }

        $reports = $query->orderByRaw($review === 'pending' ? 'updated_at desc' : 'report_date desc')->latest('id')->paginate(20)->withQueryString()
            ->through(fn (StaffReport $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => $r->kind ? (self::STRUCTURED[$r->kind]['label'] ?? $r->type_label) : $r->type_label,
                // Báo cáo ngày dùng chung cho Học vụ và Học thuật → ghi theo chức danh người nộp.
                'for' => $r->kind === 'daily_ops' && $r->user ? StaffType::dailyReportAudience($r->user) : ($r->kind ? (self::STRUCTURED[$r->kind]['for'] ?? null) : null),
                'severity_label' => $r->type === 'journal' ? $r->severity_label : null,
                'title' => $r->title,
                'content' => $r->content,
                'report_date' => $r->report_date->toDateString(),
                'submitted_at' => $r->updated_at?->toIso8601String(),
                'user' => $r->user?->name,
                'followups_count' => $r->followups->count(),
                'reviewable' => $r->type !== 'journal',
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'reviewed_by' => $r->reviewer?->name,
            ]);

        $stats = [
            'journal' => StaffReport::where('type', 'journal')->count(),
            'daily' => StaffReport::where('type', 'daily')->count(),
            'weekly' => StaffReport::where('type', 'weekly')->count(),
            'monthly' => StaffReport::where('type', 'monthly')->count(),
            'urgent_open' => StaffReport::where('type', 'journal')->where('severity', 'urgent')->where('status', '!=', 'resolved')->count(),
            'pending_review' => StaffReport::where('type', '!=', 'journal')->where('status', '!=', 'draft')->whereNull('reviewed_at')->count(),
        ];

        return Inertia::render('Reports/All', [
            'reports' => $reports,
            'stats' => $stats,
            'canReview' => (bool) Auth::user()?->can(AdminOnlyApprovals::ABILITY),
            'types' => Ui::options(StaffReport::TYPE_LABELS),
        ]);
    }
}
