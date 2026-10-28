<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\KpiCriterion;
use App\Models\MaterialOrder;
use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\StaffReportNote;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\BranchStaff;
use App\Services\Reports\AcademicPeriodMetrics;
use App\Services\Reports\DailyReportMetrics;
use App\Services\Reports\TeacherMonthlyMetrics;
use App\Services\Reports\WeeklyReportMetrics;
use App\Support\Approvals\AdminOnlyApprovals;
use App\Support\DailyReportDue;
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
use Illuminate\Support\Facades\DB;
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
     * Mục tích (checks) có ô ghi chú `{key}_note` khi `note` khác null. Số buổi, chuyên cần, BTVN, test, report đúng giờ, học sinh
     * cần lưu ý không còn nhập tay — lấy ở số liệu tự động TeacherMonthlyMetrics (chủ dự án 27/10/2026).
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
        'parent_contact' => ['label' => 'Báo cáo/Tương tác phụ huynh', 'note' => 'Ghi chú...'],
    ];

    public const TEACHER_QUALITY_CHECKS = [
        'grading' => ['label' => 'GV chấm chữa bài đầy đủ', 'note' => 'Ghi chú...'],
        'voice_feedback' => ['label' => 'GV nhận xét voice', 'note' => 'Ghi chú...'],
    ];

    /** Ô chữ của từng lớp: tên khóa giữ như bản 04/10 (situation / attention / solution / support_note) để Tổng quan đọc được. */
    public const TEACHER_CLASS_TEXTS = [
        'foreign_feedback' => 'Phản hồi của giáo viên nước ngoài',
        'academic_note' => 'Ghi chú học thuật khác',
        'operations_note' => 'Ghi chú vận hành khác',
        'attitude' => 'Thái độ học viên chung',
        'situation' => 'Vấn đề phát sinh trong lớp',
        'solution' => 'Giải pháp đã thực hiện',
        'support_note' => 'Đề xuất hỗ trợ từ quản lý',
    ];

    /** Lựa chọn 2 mức của khối A / C (giá trị lưu => nhãn). */
    public const TEACHER_CHOICES = [
        'program_progress' => ['label' => 'Tiến độ chương trình', 'options' => ['on_track' => 'Đúng tiến độ', 'behind' => 'Chậm tiến độ']],
    ];

    /** Khối của báo cáo tuần Học vụ có task follow-up: bảng KPI, chỉ số theo dõi, ghi chú tuần. */
    private const WEEKLY_TASK_SECTIONS = ['kpi', 'metrics', 'week'];

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

    /**
     * Báo cáo tháng Học thuật: số liệu từng khối tự động (AcademicPeriodMetrics), phần tự viết chỉ còn "Đánh giá chung tháng"
     * (chủ dự án 17/10/2026). Các mục tường thuật cũ vẫn đọc lại được ở báo cáo đã nộp trước đó.
     */
    public const MONTHLY_FIELDS = [
        'overall' => 'Đánh giá chung tháng',
    ];

    public const MONTHLY_LEGACY_FIELDS = [
        'test_syllabus_review' => 'Rà soát test & syllabus',
        'materials_transfers' => 'Chuẩn bị giáo trình & chuyển lớp',
        'teacher_notes' => 'Nhận xét riêng theo từng giáo viên',
    ];

    /** Báo cáo quý Học thuật: số liệu từng khối tự động, phần tự viết là "Đánh giá chung" và kế hoạch kỳ sau. */
    public const QUARTERLY_FIELDS = [
        'program_progress' => 'Tiến độ chương trình',
        'implementation_quality' => 'Chất lượng triển khai',
        'improvement_priorities' => 'Ưu tiên cải thiện',
        'next_plan' => 'Kế hoạch công việc kỳ sau',
    ];

    public const QUARTERLY_LEGACY_FIELDS = [
        'academic_work' => 'Công việc học thuật',
        'teacher_staffing' => 'Nhân sự giáo viên',
    ];

    private function guard(): void
    {
        abort_unless(Auth::user()?->can('staff_report.submit'), 403);
    }

    private function isPrivileged(): bool
    {
        return (bool) Auth::user()?->can('staff_report.view_all');
    }

    /**
     * Người xem tổng hợp không phải Admin (Quản lý cơ sở): chỉ thấy báo cáo / nhật ký của nhân sự chi nhánh mình (rà soát 16/10/2026).
     * null = không giới hạn (Admin). Chưa gán chi nhánh → chỉ báo cáo của chính mình (docs/rbac.md: "Chi nhánh" mà chưa có chi nhánh
     * thì không thấy dữ liệu của người khác).
     *
     * @return list<int>|null
     */
    private function viewerStaffIds(): ?array
    {
        $viewer = Auth::user();
        if (! $viewer) {
            return [];
        }
        if ($viewer->can(AdminOnlyApprovals::ABILITY)) {
            return null;
        }

        return self::staffIdsInBranches($viewer->branchIds(), (int) $viewer->id);
    }

    /**
     * Id nhân sự thuộc các chi nhánh (chi nhánh chính hoặc chi nhánh được cấp thêm), kèm $plus (thường là người xem).
     * Dùng chung cho màn tổng hợp báo cáo và dashboard Báo cáo / Nhật ký sự vụ (AcademicDashboardController).
     *
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    public static function staffIdsInBranches(array $branchIds, ?int $plus = null): array
    {
        $ids = $branchIds === [] ? collect() : User::query()->where(fn ($q) => $q->whereIn('branch_id', $branchIds)
            ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds)))
            ->pluck('id')->map(fn ($id) => (int) $id);

        return $ids->when($plus, fn ($c) => $c->push($plus))->unique()->values()->all();
    }

    /** Giới hạn truy vấn báo cáo theo phạm vi người xem (viewerStaffIds). */
    private function scopeViewer($query)
    {
        $ids = $this->viewerStaffIds();

        return $ids === null ? $query : $query->whereIn('user_id', $ids);
    }

    /** Học vụ (vai trò academic_staff) trong phạm vi người xem: Admin / Quản lý ghi sự việc vào tên một Học vụ. */
    private function journalSubjects(): Collection
    {
        $ids = $this->viewerStaffIds();

        return User::role(Roles::ACADEMIC_STAFF)->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'name']);
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
        } else {
            $this->scopeViewer($query);
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
                'logged_by' => $j->data['logged_by'] ?? null,
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
            // Admin / Quản lý ghi sự việc vào tên một Học vụ (KPI đếm cả sự việc này, không phụ thuộc Học vụ tự ghi).
            'subjects' => $isPriv ? Ui::options($this->journalSubjects(), 'name') : [],
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
            'subject_user_id' => 'nullable|integer',
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
        $subjectId = (int) Auth::id();
        if (! empty($validated['subject_user_id']) && (int) $validated['subject_user_id'] !== $subjectId) {
            abort_unless($this->isPrivileged() && $this->journalSubjects()->contains('id', (int) $validated['subject_user_id']), 403, 'Bạn không ghi nhật ký cho người này được.');
            $subjectId = (int) $validated['subject_user_id'];
        }

        $entry = StaffReport::create([
            'user_id' => $subjectId,
            'data' => $subjectId !== (int) Auth::id() ? ['logged_by' => Auth::user()->name, 'logged_by_id' => Auth::id()] : null,
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
        // Sự việc mức "Khẩn" báo ngay Admin và Quản lý cơ sở của người ghi.
        if ($entry->severity === 'urgent') {
            self::notifyUrgent($entry, Auth::user());
        }

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
            ->when(! $this->isPrivileged(), fn ($q) => $q->where('user_id', Auth::id()), fn ($q) => $this->scopeViewer($q))
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
     * Rà soát 16/10/2026: trước 10:00 mà hôm qua chưa nộp thì mở sẵn hôm qua; liệt kê các ngày còn thiếu 14 ngày gần nhất.
     */
    public function daily(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('daily_ops')) {
            return $redirect;
        }
        $user = Auth::user();
        $date = $this->pickDate($request->input('date'));
        $missing = DailyReportDue::missingDays($user, today()->subDays(14), today()->subDay());
        $yesterday = today()->subDay()->toDateString();
        if (! $request->filled('date') && now()->format('H:i') < DailyReportDue::OPEN_YESTERDAY_BEFORE
            && $missing->contains(fn (Carbon $d) => $d->toDateString() === $yesterday)) {
            $date = $yesterday;
        }
        $audience = StaffType::dailyReportAudience($user);
        $report = $this->structuredReport('daily_ops', $date);
        $snapshot = $report?->data['auto'] ?? null;
        $live = ! $snapshot || $request->boolean('refresh');
        $metrics = $live ? DailyReportMetrics::compute($user, $date, $audience) : $snapshot;
        // Báo cáo nhập tay trước khi chuyển sang số liệu tự động: hiện lại các ô đã nhập (chỉ đọc).
        $legacyFields = $report && ! $snapshot ? collect(self::DAILY_FIELDS)
            ->map(fn (array $f, string $key) => ['label' => $f['label'], 'value' => $report->data['fields'][$key] ?? null])
            ->filter(fn (array $f) => filled($f['value']))->values()->all() : [];
        $history = collect($this->structuredHistory('daily_ops', fn (string $key) => 'Ngày '.Carbon::parse($key)->format('d/m/Y'), 'date'))
            ->concat($missing->map(fn (Carbon $d) => [
                'period' => $d->toDateString(),
                'label' => 'Ngày '.$d->format('d/m/Y'),
                'href' => route('reports.periodic.daily', ['date' => $d->toDateString()]),
                'updated_at' => null,
                'draft' => false,
                'missing' => true,
            ]))->sortByDesc('period')->values()->take(20)->all();

        return Inertia::render('Reports/DailyOps', [
            'audience' => $audience,
            'tabs' => $this->reportTabs($user, 'daily_ops'),
            'date' => $date,
            'dateLabel' => Str::ucfirst(Carbon::parse($date)->locale('vi')->isoFormat('dddd, D [tháng] M, YYYY')),
            'today' => now()->toDateString(),
            'deadline' => DailyReportDue::deadline(Carbon::parse($date))->format('H:i d/m'),
            'deadlineAt' => DailyReportDue::deadline(Carbon::parse($date))->toIso8601String(),
            'week' => $this->dailyWeek($user),
            'sections' => $metrics['sections'],
            'computedAt' => $metrics['computed_at'] ?? null,
            'live' => $live,
            'noteLabels' => DailyReportMetrics::NOTE_LABELS,
            'notes' => (object) ($report?->data['notes'] ?? []),
            'draftKey' => "report-draft:daily:{$user->id}:{$date}",
            'legacyFields' => $legacyFields,
            'tasks' => $this->reportTasks($report?->data ?? [], true),
            'sectionTasks' => (object) $this->sectionTasks($report, 'daily_ops', $date),
            'assignees' => $this->taskAssignees(),
            'sessionRows' => $audience === DailyReportMetrics::AUDIENCE_ACADEMIC ? [] : DailyReportMetrics::sessionRows($user, $date),
            'verdicts' => ClassSessionFeedback::VERDICTS,
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'firstSubmittedAt' => $report?->created_at?->toIso8601String(),
            'late' => $report ? DailyReportDue::isLate($report) : false,
            'review' => $this->reviewState($report),
            'missingDays' => $missing->reverse()->values()->map(fn (Carbon $d) => [
                'date' => $d->toDateString(),
                'label' => $d->format('d/m'),
                'href' => route('reports.periodic.daily', ['date' => $d->toDateString()]),
            ])->all(),
            'history' => $history,
            'legacy' => $this->legacyReports('daily'),
        ]);
    }

    /**
     * Dãy 7 ngày gần nhất (tới hôm nay) trên đầu màn báo cáo ngày (thiết kế 27/10/2026): mỗi ngày một trạng thái
     * đã nộp / nộp trễ / chưa nộp (ngày phải nộp) / nghỉ (Chủ nhật, lễ, phép) / hôm nay đang mở.
     *
     * @return list<array{date: string, weekday: string, day: string, status: string, href: string}>
     */
    private function dailyWeek(User $user): array
    {
        $from = today()->subDays(6);
        $required = DailyReportDue::requiredDays($user, $from, today())->mapWithKeys(fn (Carbon $d) => [$d->toDateString() => true]);
        $reports = StaffReport::where('user_id', $user->id)->where('type', 'daily')->where('status', '!=', 'draft')
            ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', today()->toDateString())
            ->get(['id', 'type', 'report_date', 'created_at'])
            ->keyBy(fn (StaffReport $r) => $r->report_date->toDateString());

        return collect(range(0, 6))->map(function (int $i) use ($from, $required, $reports) {
            $d = $from->copy()->addDays($i);
            $key = $d->toDateString();
            $report = $reports->get($key);

            return [
                'date' => $key,
                'weekday' => $d->isSunday() ? 'CN' : 'T'.($d->dayOfWeek + 1),
                'day' => $d->format('d/m'),
                'status' => match (true) {
                    $report !== null => DailyReportDue::isLate($report) ? 'late' : 'submitted',
                    $d->isToday() => 'open',
                    $required->has($key) => 'missing',
                    default => 'off',
                },
                'href' => route('reports.periodic.daily', ['date' => $key]),
            ];
        })->all();
    }

    public function dailyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('daily_ops');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'refresh' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:5000'],
            ...self::TASK_RULES,
            'feedback' => ['nullable', 'array'],
            'feedback.*.verdict' => ['nullable', Rule::in(array_keys(ClassSessionFeedback::VERDICTS))],
            'feedback.*.content' => ['nullable', 'string', 'max:2000'],
        ], [
            'date.before_or_equal' => 'Không nộp báo cáo cho ngày chưa tới.',
            'tasks.*.due_date.after_or_equal' => 'Hạn xử lý phải từ hôm nay trở đi.',
            'tasks.*.*.required' => 'Nhập :attribute cho task.',
        ], [
            'tasks.*.title' => 'tiêu đề',
            'tasks.*.due_date' => 'hạn xử lý',
            'tasks.*.assignee_id' => 'người phụ trách',
        ]);

        $user = Auth::user();
        $date = Carbon::parse($data['date']);
        $audience = StaffType::dailyReportAudience($user);
        $existing = $this->structuredReport('daily_ops', $data['date']);
        // Sửa ghi chú báo cáo đã nộp giữ nguyên số liệu đã chụp (số Admin đã kiểm tra); chỉ tính lại khi người viết bấm
        // "Cập nhật số liệu" (form gửi refresh=1). Số liệu luôn tính ở máy chủ, không nhận số từ trình duyệt.
        $kept = $existing?->data['auto'] ?? null;
        $snapshot = $kept && ! $request->boolean('refresh')
            ? $kept + ['fields' => $existing->data['fields'] ?? []]
            : DailyReportMetrics::compute($user, $data['date'], $audience);
        $notes = collect($data['notes'] ?? [])->only(array_keys($snapshot['sections']))
            ->map(fn ($v) => filled($v) ? trim((string) $v) : null)->filter()->all();

        $this->checkTasks($data['tasks'] ?? [], array_keys($snapshot['sections']));
        // Góp ý từng buổi: chỉ nhận buổi trong ngày báo cáo thuộc phạm vi người viết (Học vụ).
        $rows = $audience === DailyReportMetrics::AUDIENCE_ACADEMIC ? collect() : collect(DailyReportMetrics::sessionRows($user, $data['date']))->keyBy('id');
        $feedback = collect($data['feedback'] ?? [])->filter(fn ($f, $id) => $rows->has((int) $id));

        DB::transaction(function () use ($data, $existing, $snapshot, $notes, $date, $feedback, $rows) {
            $report = $this->saveStructured('daily_ops', $data['date'], 'Báo cáo ngày '.$date->format('d/m/Y'), $data['date'], [
                ...($existing?->data ?? []),
                'auto' => ['audience' => $snapshot['audience'], 'sections' => $snapshot['sections'], 'computed_at' => $snapshot['computed_at']],
                'fields' => $snapshot['fields'],
                'notes' => $notes,
            ], '');

            $taskIds = $this->reportTaskIds($existing?->data ?? []);
            foreach ($data['tasks'] ?? [] as $task) {
                $taskIds[] = $this->createFollowUpTask($task, $data['date'], $report)->id;
            }
            $sessionFeedback = $this->saveSessionFeedback($report, $feedback, $rows);

            $text = DailyReportMetrics::text($snapshot, $notes);
            if ($sessionFeedback !== []) {
                $text .= "\n\nGóp ý từng buổi:\n".collect($sessionFeedback)->map(fn (array $f) => '- '.$f['session'].': '
                    .($f['verdict'] ? ClassSessionFeedback::VERDICTS[$f['verdict']] : '').($f['content'] ? ($f['verdict'] ? ' — ' : '').$f['content'] : '')
                    .($f['escalated'] ? ' [Đã báo Admin]' : ''))->implode("\n");
            }
            $report->update([
                'data' => [
                    ...$report->data,
                    'task_ids' => $taskIds,
                    // Khóa cũ (một task / báo cáo) giữ cho màn Báo cáo Admin đọc báo cáo trước 16/10.
                    'task_id' => $taskIds[0] ?? null,
                    'session_feedback' => $sessionFeedback,
                ],
                'content' => $text.($taskIds ? "\n\nTask follow-up: #".implode(', #', $taskIds) : ''),
            ]);
        });

        return redirect()->route('reports.periodic.daily', ['date' => $data['date']])
            ->with('success', 'Đã lưu báo cáo ngày '.$date->format('d/m/Y').'.');
    }

    /**
     * Lưu góp ý của người viết cho từng buổi (một phiếu / người / buổi). Bỏ trống cả đánh giá lẫn nội dung thì xóa phiếu, trừ phiếu
     * đã "Báo Admin gấp". Trả về bản chụp góp ý để Admin đọc trong chi tiết báo cáo.
     *
     * @param  Collection<int|string, array>  $feedback  [session_id => {verdict, content}]
     * @param  Collection<int, array>  $rows  buổi trong ngày (DailyReportMetrics::sessionRows), theo id
     * @return list<array<string, mixed>>
     */
    private function saveSessionFeedback(StaffReport $report, Collection $feedback, Collection $rows): array
    {
        foreach ($feedback as $sessionId => $input) {
            $verdict = $input['verdict'] ?? null;
            $content = filled($input['content'] ?? null) ? trim((string) $input['content']) : null;
            $match = ['class_session_id' => (int) $sessionId, 'source' => ClassSessionFeedback::SOURCE_STAFF, 'author_id' => Auth::id()];
            if (! $verdict && ! $content) {
                ClassSessionFeedback::where($match)->whereNull('escalation_id')->delete();

                continue;
            }
            ClassSessionFeedback::updateOrCreate($match, [
                'class_id' => $rows->get((int) $sessionId)['class_id'],
                'verdict' => $verdict,
                'content' => $content,
                'staff_report_id' => $report->id,
            ]);
        }

        return ClassSessionFeedback::where('source', ClassSessionFeedback::SOURCE_STAFF)->where('author_id', Auth::id())
            ->whereIn('class_session_id', $rows->keys())->orderBy('class_session_id')->get()
            ->map(fn (ClassSessionFeedback $f) => [
                'session_id' => $f->class_session_id,
                'session' => trim(($rows->get($f->class_session_id)['class'] ?? 'Buổi').' · '.($rows->get($f->class_session_id)['time'] ?? ''), ' ·'),
                'teacher' => $rows->get($f->class_session_id)['teacher'] ?? null,
                'verdict' => $f->verdict,
                'content' => $f->content,
                'escalated' => $f->escalation_id !== null,
            ])->values()->all();
    }

    /**
     * "Báo Admin gấp" ngay trên một buổi của báo cáo ngày (chủ dự án 17/10/2026): tạo sự việc mức "Khẩn" trong Nhật ký sự vụ gắn lớp /
     * buổi đó (Admin và Quản lý cơ sở nhận thông báo ngay, xử lý và trao đổi ở Nhật ký), đánh dấu góp ý của buổi là "Có vấn đề".
     */
    public function dailyEscalate(Request $request, int $session): RedirectResponse
    {
        $this->guardStructured('daily_ops');
        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ], ['content.required' => 'Nhập nội dung cần Admin xử lý.']);
        $user = Auth::user();
        abort_if(StaffType::dailyReportAudience($user) === DailyReportMetrics::AUDIENCE_ACADEMIC, 403);
        $classSession = ClassSession::with('classModel:id,name')->findOrFail($session);
        $row = collect(DailyReportMetrics::sessionRows($user, $classSession->date->toDateString()))->firstWhere('id', $classSession->id);
        abort_unless($row, 403, 'Buổi học này nằm ngoài phạm vi báo cáo của bạn.');

        $match = ['class_session_id' => $classSession->id, 'source' => ClassSessionFeedback::SOURCE_STAFF, 'author_id' => $user->id];
        $feedback = ClassSessionFeedback::firstOrNew($match);
        if ($feedback->escalation_id) {
            return back()->withErrors(['escalate' => 'Buổi này đã được báo Admin. Theo dõi xử lý trong Nhật ký sự vụ.']);
        }
        $content = trim($data['content']);
        DB::transaction(function () use ($feedback, $classSession, $row, $content, $user) {
            $entry = StaffReport::create([
                'user_id' => $user->id,
                'class_id' => $classSession->class_id,
                'type' => 'journal',
                'title' => Str::limit('Khẩn: '.$row['class'].' buổi '.$classSession->date->format('d/m').' '.$row['time'], 120, ''),
                'content' => $content,
                'severity' => 'urgent',
                'report_date' => $classSession->date->toDateString(),
                'status' => 'open',
                'data' => ['class_session_id' => $classSession->id, 'source' => 'daily_report'],
            ]);
            $feedback->fill([
                'class_id' => $classSession->class_id,
                'verdict' => 'problem',
                'content' => $feedback->content ?: $content,
                'escalation_id' => $entry->id,
                'staff_report_id' => $feedback->staff_report_id ?? $this->structuredReport('daily_ops', $classSession->date->toDateString())?->id,
            ])->save();
            self::notifyUrgent($entry, $user);
        });

        return back()->with('success', 'Đã báo Admin. Vấn đề được ghi vào Nhật ký sự vụ ở mức Khẩn.');
    }

    /** Sự việc mức "Khẩn" báo ngay Admin và Quản lý cơ sở của người ghi. */
    private static function notifyUrgent(StaffReport $entry, User $author): void
    {
        $entry->loadMissing('classModel:id,name');
        AdminNotification::notifyUsers(
            BranchStaff::admins()->pluck('id')->concat(BranchStaff::withRoles(Roles::MANAGER, $author->branch_id)->pluck('id')),
            'staff_journal_urgent',
            'Sự việc khẩn: '.$entry->title,
            "{$author->name} vừa ghi sự việc mức Khẩn".($entry->classModel ? ' ở lớp '.$entry->classModel->name : '').'.',
            route('reports.all', ['open' => $entry->id]),
            ['staff_report_id' => $entry->id],
            $author->id,
        );
    }

    /** Task follow-up gửi kèm báo cáo: tiêu đề, nội dung, hạn, người phụ trách, khối báo cáo và lớp (tạo từ một buổi học). */
    private const TASK_RULES = [
        'tasks' => ['nullable', 'array', 'max:20'],
        'tasks.*.title' => ['required', 'string', 'max:255'],
        'tasks.*.content' => ['nullable', 'string', 'max:5000'],
        'tasks.*.due_date' => ['required', 'date', 'after_or_equal:today'],
        'tasks.*.assignee_id' => ['nullable', 'integer'],
        'tasks.*.section' => ['nullable', 'string', 'max:40'],
        'tasks.*.class_id' => ['nullable', 'integer'],
    ];

    /**
     * Người phụ trách phải là người mình được giao việc, khối phải có trong báo cáo, lớp phải nằm trong phạm vi mình được xem.
     *
     * @param  list<string>  $sectionKeys
     */
    private function checkTasks(array $tasks, array $sectionKeys): void
    {
        if ($tasks === []) {
            return;
        }
        $assignees = collect($this->taskAssignees())->pluck('value');
        $classIds = ClassModel::query()->visibleTo(Auth::user())->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($tasks as $i => $task) {
            if (filled($task['assignee_id'] ?? null) && ! $assignees->contains((int) $task['assignee_id'])) {
                throw ValidationException::withMessages(["tasks.{$i}.assignee_id" => 'Bạn không giao việc được cho người này.']);
            }
            if (filled($task['section'] ?? null) && ! in_array($task['section'], $sectionKeys, true)) {
                throw ValidationException::withMessages(["tasks.{$i}.section" => 'Khối báo cáo không hợp lệ.']);
            }
            if (filled($task['class_id'] ?? null) && ! $classIds->contains((int) $task['class_id'])) {
                throw ValidationException::withMessages(["tasks.{$i}.class_id" => 'Lớp này nằm ngoài phạm vi bạn được xem.']);
            }
        }
    }

    /**
     * Việc follow-up tạo từ báo cáo ngày / tuần (rà soát 16/10/2026: mỗi báo cáo nhiều task, mỗi task có tiêu đề, nội dung, hạn từ hôm nay
     * và người phụ trách). Người phụ trách để trống là mình, hoặc người mình được giao việc (như màn Giao việc).
     */
    private function createFollowUpTask(array $input, string $reportDate, ?StaffReport $report = null): WorkTask
    {
        $user = Auth::user();
        $assigneeId = (int) ($input['assignee_id'] ?? 0) ?: (int) $user->id;
        // Admin giao việc ngay trên báo cáo của người khác (chủ dự án 17/10/2026): ghi rõ báo cáo của ai, chi nhánh theo người viết.
        $author = $report && (int) $report->user_id !== (int) $user->id ? $report->user : null;
        $source = ($report ? Str::lcfirst($report->title) : 'báo cáo ngày '.Carbon::parse($reportDate)->format('d/m/Y')).($author ? " của {$author->name}" : '');
        $task = WorkTask::create([
            'title' => trim($input['title']),
            'description' => trim(($input['content'] ?? '')."\n\nTạo từ {$source}".($author ? ", {$user->name} giao." : " của {$user->name}.")),
            'creator_id' => $user->id,
            'assignee_id' => $assigneeId,
            'branch_id' => $author?->branch_id ?? $user->branch_id,
            'class_id' => filled($input['class_id'] ?? null) ? (int) $input['class_id'] : null,
            // Task gắn khối báo cáo: chưa xong thì hiện lại đúng khối đó ở báo cáo kỳ sau (sectionTasks).
            'staff_report_id' => $report?->id,
            'report_section' => filled($input['section'] ?? null) ? $input['section'] : null,
            'due_date' => $input['due_date'],
            'due_time' => '18:00',
            'task_type' => 'one_time',
            'time_slot_category' => 'during',
            'status' => 'new',
        ]);
        if ($assigneeId !== (int) $user->id) {
            AdminNotification::notifyUser($assigneeId, 'task_assigned', "Bạn được giao việc: {$task->title}",
                "{$user->name} đã giao việc từ {$source}, hạn ".$task->due_date?->format('d/m/Y').'.',
                route('tasks.index', ['tab' => 'mine']), ['task_id' => $task->id]);
        }

        return $task;
    }

    /** @return list<int> Task follow-up của báo cáo ngày (`task_ids`, hoặc `task_id` của báo cáo trước 16/10). */
    private function reportTaskIds(array $data): array
    {
        return collect($data['task_ids'] ?? [])->push($data['task_id'] ?? null)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return list<array<string, mixed>> task follow-up của báo cáo; `$unsectioned` = chỉ task không gắn khối (báo cáo trước 17/10) */
    private function reportTasks(array $data, bool $unsectioned = false): array
    {
        $ids = $this->reportTaskIds($data);

        return $ids === [] ? [] : WorkTask::with('assignee:id,name')->whereIn('id', $ids)->when($unsectioned, fn ($q) => $q->whereNull('report_section'))->orderBy('id')->get()
            ->map(fn (WorkTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(),
                'assignee' => $task->assignee?->name,
                'status' => $task->status,
                'status_label' => $task->status_label,
            ])->all();
    }

    /**
     * Task follow-up theo khối của báo cáo (chủ dự án 17/10/2026): task tạo ở chính báo cáo này, cộng task chưa xong tạo từ báo cáo
     * cùng loại của cùng người viết ở kỳ trước (`carried` = ngày của báo cáo gốc) — việc tồn hiện lại cho tới khi hoàn thành / hủy.
     *
     * @return array<string, list<array<string, mixed>>> khóa khối => task
     */
    private function sectionTasks(?StaffReport $report, string $kind, string $period, ?int $userId = null): array
    {
        $userId ??= $report?->user_id ?? Auth::id();

        return WorkTask::with(['assignee:id,name', 'creator:id,name', 'staffReport:id,user_id,period_key,report_date'])
            ->whereNotNull('report_section')
            ->whereHas('staffReport', fn ($q) => $q->where('user_id', $userId)->where('kind', $kind))
            ->where(fn ($q) => $q->when($report, fn ($q) => $q->where('staff_report_id', $report->id))
                ->orWhere(fn ($q) => $q->whereNotIn('status', ['completed', 'canceled'])
                    ->whereHas('staffReport', fn ($r) => $r->where('period_key', '<', $period))))
            ->orderBy('id')
            ->get()
            ->map(fn (WorkTask $task) => [
                'id' => $task->id,
                'section' => $task->report_section,
                'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(),
                'assignee' => $task->assignee?->name,
                'status' => $task->status,
                'status_label' => $task->status_label,
                'overdue' => $task->status === 'overdue' || (in_array($task->status, WorkTask::OPEN_STATUSES, true) && $task->lateHours() > 0),
                'carried' => $report && (int) $task->staff_report_id === (int) $report->id ? null : $task->staffReport?->report_date?->format('d/m'),
                // Task Admin giao ngay trên báo cáo: hiện "giao bởi …" ở cả màn người viết lẫn chi tiết của Admin.
                'by' => (int) $task->creator_id !== (int) $task->staffReport?->user_id ? $task->creator?->name : null,
            ])
            ->groupBy('section')
            ->map(fn ($tasks) => $tasks->values()->all())
            ->all();
    }

    /** Trạng thái kiểm tra hiện cho người viết: đã kiểm tra / Admin yêu cầu bổ sung (kèm nhận xét). */
    private function reviewState(?StaffReport $report): ?array
    {
        if (! $report || $report->status === 'draft') {
            return null;
        }
        $report->loadMissing('reviewer:id,name');

        return [
            'status' => $report->status === 'returned' ? 'returned' : ($report->reviewed_at ? 'reviewed' : 'pending'),
            'note' => $report->review_note,
            'by' => $report->reviewer?->name,
            'at' => $report->reviewed_at?->toIso8601String(),
        ];
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
        $week = $this->pickWeek($request->input('week'));
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
            'review' => $this->reviewState($report),
            'notes' => self::notesPayload($report),
            'noteUrl' => $report && $report->status !== 'draft' ? route('reports.notes.store', $report->id) : null,
            'history' => $this->structuredHistory('academic_weekly', fn (string $key) => ReportPeriod::weekLabel($key), 'week'),
            'legacy' => $this->legacyReports('weekly'),
        ]);
    }

    public function academicWeeklyStore(Request $request): RedirectResponse
    {
        $this->guardStructured('academic_weekly');
        $rules = ['week' => ['required', 'regex:'.ReportPeriod::WEEK_PATTERN, $this->notFutureWeek()], 'rows' => ['nullable', 'array']];
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

        return back()->with('success', 'Đã lưu báo cáo '.Str::lcfirst(ReportPeriod::weekLabel($data['week'])).'.');
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
        $week = $this->pickWeek($request->input('week'));
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
            'draftKey' => 'report-draft:weekly-kpi:'.Auth::id().":{$week}",
            'sectionTasks' => (object) $this->sectionTasks($report, 'weekly_kpi', $week),
            'assignees' => $this->taskAssignees(),
            'today' => today()->toDateString(),
            'legacy' => $report && ! $snapshot ? $this->legacyWeeklyKpi($report) : [],
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'review' => $this->reviewState($report),
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
            'week' => ['required', 'regex:'.ReportPeriod::WEEK_PATTERN, $this->notFutureWeek()],
            'note' => ['nullable', 'string', 'max:5000'],
            'refresh' => ['nullable', 'boolean'],
            ...self::TASK_RULES,
        ], ['tasks.*.due_date.after_or_equal' => 'Hạn xử lý phải từ hôm nay trở đi.']);
        $this->checkTasks($data['tasks'] ?? [], self::WEEKLY_TASK_SECTIONS);

        // Số liệu tính trên server (không nhận số từ form) và lưu kèm tên / nhóm mục KPI để báo cáo cũ vẫn đọc được khi Admin
        // đổi cấu hình KPI. Sửa ghi chú tuần đã nộp giữ số đã chụp; chỉ tính lại khi người viết bấm "Cập nhật số liệu" (refresh=1).
        $existing = $this->structuredReport('weekly_kpi', $data['week']);
        $kept = $existing?->data['auto'] ?? null;
        $snapshot = $kept && ! $request->boolean('refresh') ? $kept : WeeklyReportMetrics::operations(Auth::user(), $data['week']);
        $counts = collect($snapshot['groups'])->flatMap(fn (array $g) => collect($g['items'])->map(fn (array $i) => [
            'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'group' => $g['name'], 'count' => $i['count'],
        ]))->values()->all();
        $metrics = collect($snapshot['metrics']['items'])->mapWithKeys(fn (array $i) => [$i['key'] => $i['count']])->all();
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        [$start] = ReportPeriod::weekRange($data['week']);

        DB::transaction(function () use ($data, $existing, $snapshot, $counts, $metrics, $note, $start) {
            $taskIds = $this->reportTaskIds($existing?->data ?? []);
            $text = WeeklyReportMetrics::operationsText($snapshot, $note);
            $report = $this->saveStructured('weekly_kpi', $data['week'], 'Báo cáo tuần '.ReportPeriod::weekLabel($data['week']), $start->toDateString(),
                ['auto' => $snapshot, 'counts' => $counts, 'metrics' => $metrics, 'note' => $note, 'task_ids' => $taskIds], $text);
            if (filled($data['tasks'] ?? null)) {
                foreach ($data['tasks'] as $task) {
                    $taskIds[] = $this->createFollowUpTask($task, $start->toDateString(), $report)->id;
                }
                $report->update(['data' => [...$report->data, 'task_ids' => $taskIds], 'content' => $text."\n\nTask follow-up: #".implode(', #', $taskIds)]);
            }
        });

        return back()->with('success', 'Đã lưu báo cáo '.Str::lcfirst(ReportPeriod::weekLabel($data['week'])).'.');
    }

    /**
     * Báo cáo tháng Học thuật (mockup 02/19 + chủ dự án 17/10/2026): khối số liệu tự động của tháng (ghi chú + task follow-up từng khối,
     * việc chưa xong tháng trước hiện lại), "Đánh giá chung tháng", bảng báo cáo tuần trong tháng và họp giáo viên.
     */
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
            ...$this->academicPeriodProps($request, $report, 'academic_monthly', $month, $from, $to, 'Tháng trước', self::MONTHLY_LEGACY_FIELDS),
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'review' => $this->reviewState($report),
            'weeklyReports' => $this->weeksOfMonth($from, $to),
            // Báo cáo họp do chính người lập báo cáo tháng ghi (kể cả về lớp đã ra khỏi phạm vi của họ).
            'meetings' => TeacherMeetingReport::withoutViewerScope()->with('teacher:id,name')
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
        $month = $request->validate(['month' => ['required', 'regex:'.ReportPeriod::MONTH_PATTERN]])['month'];
        [$from, $to] = ReportPeriod::monthRange($month);
        $this->storeAcademicPeriod($request, 'academic_monthly', $month, $from, $to, 'Tháng trước', self::MONTHLY_FIELDS,
            'Báo cáo tháng Học thuật — '.ReportPeriod::monthLabel($month));

        return back()->with('success', 'Đã lưu báo cáo '.mb_strtolower(ReportPeriod::monthLabel($month)).'.');
    }

    /**
     * Báo cáo tháng của giáo viên (mockup 03/17 "Báo cáo chung của giáo viên"): báo cáo chấm công tháng (ghi chú) + báo cáo giảng dạy
     * theo từng lớp (A. Học thuật, B. Vận hành lớp, C. Chất lượng lớp) và nhận xét chung tháng (tiến độ, khó khăn, đề xuất — 04/10/2026).
     * Chuyên cần / bài tập gợi ý sẵn từ số liệu lớp trong tháng. Lưu nháp được; nộp rồi thì lưu lại là cập nhật.
     * Từ 17/10/2026: số liệu tự động của tháng (TeacherMonthlyMetrics, chụp lại lúc lưu), học sinh cần chú ý và task follow-up theo
     * từng lớp thay cho ô "Đề xuất hỗ trợ từ quản lý" — cần ai giúp thì giao task thẳng cho người đó.
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
        $classes = $quality->classMetrics($quality->classesOf(Auth::user()));
        $kept = $report?->data['auto'] ?? null;
        $live = ! $kept || $request->boolean('refresh');

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
            'review' => $this->reviewState($report),
            'attendance' => [
                'note' => $attendance?->data['note'] ?? null,
                'submitted_at' => $attendance?->updated_at?->toIso8601String(),
            ],
            'proposeUrl' => Route::has('syllabus.teacher-propose') && Auth::user()->can('syllabus.propose_adjustment')
                ? route('syllabus.teacher-propose') : null,
            'auto' => $live ? TeacherMonthlyMetrics::compute(Auth::user(), $month) : $kept,
            'live' => $live,
            'sectionTasks' => (object) $this->sectionTasks($report, 'teacher_monthly', $month),
            'assignees' => $this->taskAssignees(),
            'today' => today()->toDateString(),
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
            ...collect(self::TEACHER_CHOICES)->mapWithKeys(fn ($c, $f) => ["classes.*.{$f}" => ['nullable', 'in:'.implode(',', array_keys($c['options']))]])->all(),
            'classes.*.checks' => ['nullable', 'array'],
            'classes.*.checks.*' => ['nullable', 'boolean'],
            'classes.*.notes' => ['nullable', 'array'],
            'classes.*.notes.*' => ['nullable', 'string', 'max:1000'],
            ...collect(self::TEACHER_CLASS_TEXTS)->keys()->mapWithKeys(fn ($f) => ["classes.*.{$f}" => ['nullable', 'string', 'max:5000']])->all(),
            'refresh' => ['nullable', 'boolean'],
            ...self::TASK_RULES,
        ], ['tasks.*.due_date.after_or_equal' => 'Hạn xử lý phải từ hôm nay trở đi.']);

        // Chỉ nhận lớp giáo viên đang giữ trong tháng báo cáo.
        $quality = new TeachingQuality($data['month']);
        $classes = $quality->classesOf(Auth::user())->keyBy('id');
        $existing = $this->structuredReport('teacher_monthly', $data['month']);
        // Task theo lớp: khối là lớp mình giữ, lớp của task lấy theo khối.
        $tasks = collect($data['tasks'] ?? [])->map(fn (array $t) => [...$t,
            'class_id' => preg_match('/^class_(\d+)$/', (string) ($t['section'] ?? ''), $m) ? (int) $m[1] : ($t['class_id'] ?? null)])->all();
        $this->checkTasks($tasks, $classes->keys()->map(fn ($id) => TeacherMonthlyMetrics::classKey((int) $id))->all());
        $general = collect(self::TEACHER_GENERAL_FIELDS)->keys()
            ->mapWithKeys(fn ($f) => [$f => filled($data['general'][$f] ?? null) ? $data['general'][$f] : null])->all();
        $classData = collect($data['classes'] ?? [])
            ->filter(fn ($row, $id) => $classes->has((int) $id))
            ->map(function (array $row, $id) use ($checks, $existing) {
                $clean = [
                    ...collect(self::TEACHER_CHOICES)->keys()->mapWithKeys(fn ($f) => [$f => filled($row[$f] ?? null) ? $row[$f] : null])->all(),
                    'checks' => collect($checks)->keys()->mapWithKeys(fn ($k) => [$k => (bool) ($row['checks'][$k] ?? false)])->all(),
                    'notes' => collect($checks)->filter(fn ($c) => $c['note'] !== null)->keys()
                        ->mapWithKeys(fn ($k) => [$k => filled($row['notes'][$k] ?? null) ? trim($row['notes'][$k]) : null])->all(),
                    ...collect(self::TEACHER_CLASS_TEXTS)->keys()->mapWithKeys(fn ($f) => [$f => filled($row[$f] ?? null) ? $row[$f] : null])->all(),
                ];
                // "Đề xuất hỗ trợ từ quản lý" đã thay bằng task; báo cáo cũ có ô này thì giữ nguyên.
                $clean['support_note'] ??= $existing?->data['classes'][$id]['support_note'] ?? null;
                $clean['need_support'] = $clean['support_note'] !== null;

                return $clean;
            })
            ->reject(fn (array $row) => collect($row)->except(['checks', 'notes', 'need_support'])->filter(fn ($v) => $v !== null)->isEmpty()
                && ! in_array(true, $row['checks'], true) && collect($row['notes'])->filter()->isEmpty())
            ->all();
        if (collect($general)->filter()->isEmpty() && $classData === [] && $tasks === []) {
            throw ValidationException::withMessages(['general' => 'Nhập ít nhất một mục của báo cáo.']);
        }

        $kept = $existing?->data['auto'] ?? null;
        $snapshot = $kept && ! $request->boolean('refresh') ? $kept : TeacherMonthlyMetrics::compute(Auth::user(), $data['month']);
        $lines = [DailyReportMetrics::text(['sections' => ['work' => $snapshot['work']]]), $this->narrativeText($general, self::TEACHER_GENERAL_FIELDS)];
        foreach ($classes as $id => $class) {
            $auto = $snapshot['classes'][TeacherMonthlyMetrics::classKey((int) $id)] ?? null;
            $text = trim(($auto ? collect(explode("\n", DailyReportMetrics::text(['sections' => ['class' => $auto]])))->slice(1)->implode("\n") : '')
                ."\n".(isset($classData[$id]) ? $this->teacherClassText($classData[$id], $checks) : ''));
            if ($text !== '') {
                $lines[] = 'Lớp '.$class->name.":\n".$text;
            }
        }
        // Đã nộp thì "Lưu nháp" không kéo báo cáo về nháp.
        $status = ($data['intent'] ?? 'submit') === 'draft' && $existing?->status !== 'submitted' ? 'draft' : 'submitted';
        [$from] = ReportPeriod::monthRange($data['month']);
        $content = trim(implode("\n\n", array_filter($lines)));
        DB::transaction(function () use ($data, $from, $existing, $general, $classData, $snapshot, $content, $status, $tasks) {
            $taskIds = $this->reportTaskIds($existing?->data ?? []);
            $report = $this->saveStructured('teacher_monthly', $data['month'], 'Báo cáo giảng dạy tháng — '.ReportPeriod::monthLabel($data['month']),
                $from->toDateString(), [
                    'general' => $general,
                    'classes' => $classData,
                    'auto' => ['work' => $snapshot['work'], 'classes' => $snapshot['classes'], 'computed_at' => $snapshot['computed_at']],
                    'task_ids' => $taskIds,
                ], $content, $status);
            foreach ($tasks as $task) {
                $taskIds[] = $this->createFollowUpTask($task, $from->toDateString(), $report)->id;
            }
            if ($tasks !== []) {
                $report->update(['data' => [...$report->data, 'task_ids' => $taskIds], 'content' => $content."\n\nTask follow-up: #".implode(', #', $taskIds)]);
            }
        });

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

    /** Báo cáo quý Học thuật: khối số liệu tự động của quý (ghi chú + task từng khối), "Đánh giá chung" và 3 báo cáo tháng trong quý. */
    public function academicQuarterly(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectUnlessStructured('academic_quarterly')) {
            return $redirect;
        }
        $quarter = ReportPeriod::pick($request->input('quarter'), ReportPeriod::QUARTER_PATTERN, ReportPeriod::currentQuarter());
        $report = $this->structuredReport('academic_quarterly', $quarter);
        [$from, $to] = ReportPeriod::quarterRange($quarter);
        $monthly = StaffReport::where('user_id', Auth::id())->where('kind', 'academic_monthly')
            ->whereIn('period_key', ReportPeriod::quarterMonths($quarter))->get()->keyBy('period_key');

        return Inertia::render('Reports/AcademicQuarterly', [
            'tabs' => $this->reportTabs(Auth::user(), 'academic_quarterly'),
            'quarter' => $quarter,
            'quarterLabel' => ReportPeriod::quarterLabel($quarter),
            'quarters' => ReportPeriod::quarterOptions(),
            'fields' => self::QUARTERLY_FIELDS,
            'values' => $report?->data['narrative'] ?? [],
            ...$this->academicPeriodProps($request, $report, 'academic_quarterly', $quarter, $from, $to, 'Quý trước', self::QUARTERLY_LEGACY_FIELDS),
            'submittedAt' => $report?->updated_at?->toIso8601String(),
            'review' => $this->reviewState($report),
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
        $quarter = $request->validate(['quarter' => ['required', 'regex:'.ReportPeriod::QUARTER_PATTERN]])['quarter'];
        [$from, $to] = ReportPeriod::quarterRange($quarter);
        $this->storeAcademicPeriod($request, 'academic_quarterly', $quarter, $from, $to, 'Quý trước', self::QUARTERLY_FIELDS,
            'Báo cáo quý Học thuật — '.ReportPeriod::quarterLabel($quarter));

        return back()->with('success', 'Đã lưu báo cáo quý '.$quarter.'.');
    }

    /**
     * Phần chung của báo cáo tháng / quý Học thuật: số liệu từng khối (kỳ chưa nộp tính trực tiếp; đã nộp hiện số lúc nộp, "Cập nhật
     * số liệu" tính lại), ghi chú, task theo khối (kể cả việc chưa xong kỳ trước) và các mục tường thuật cũ đã nhập (chỉ đọc).
     *
     * @param  array<string, string>  $legacyFields
     * @return array<string, mixed>
     */
    private function academicPeriodProps(Request $request, ?StaffReport $report, string $kind, string $period, Carbon $from, Carbon $to, string $prevLabel, array $legacyFields): array
    {
        $snapshot = $report?->data['auto'] ?? null;
        $live = ! $snapshot || $request->boolean('refresh');
        $metrics = $live ? AcademicPeriodMetrics::compute(Auth::user(), $from, $to, $prevLabel) : $snapshot;
        $narrative = $report?->data['narrative'] ?? [];

        return [
            'sections' => $metrics['sections'],
            'computedAt' => $metrics['computed_at'] ?? null,
            'live' => $live,
            'noteLabels' => AcademicPeriodMetrics::NOTE_LABELS,
            'notes' => (object) ($report?->data['notes'] ?? []),
            'draftKey' => "report-draft:{$kind}:".Auth::id().":{$period}",
            'sectionTasks' => (object) $this->sectionTasks($report, $kind, $period),
            'assignees' => $this->taskAssignees(),
            'today' => today()->toDateString(),
            'legacyFields' => collect($legacyFields)->filter(fn ($label, $key) => filled($narrative[$key] ?? null))
                ->map(fn ($label, $key) => ['label' => $label, 'value' => $narrative[$key]])->values()->all(),
        ];
    }

    /**
     * Lưu báo cáo tháng / quý Học thuật: phải có ít nhất một mục đánh giá chung; số liệu tính ở máy chủ (giữ số đã chụp khi sửa,
     * trừ khi người viết bấm "Cập nhật số liệu"); mục tường thuật cũ không còn trên form được giữ nguyên.
     *
     * @param  array<string, string>  $fields  mục tự viết
     */
    private function storeAcademicPeriod(Request $request, string $kind, string $period, Carbon $from, Carbon $to, string $prevLabel, array $fields, string $title): void
    {
        $data = $request->validate([
            'refresh' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:5000'],
            'narrative' => ['nullable', 'array'],
            ...collect($fields)->keys()->mapWithKeys(fn ($f) => ["narrative.{$f}" => ['nullable', 'string', 'max:10000']])->all(),
            ...self::TASK_RULES,
        ], ['tasks.*.due_date.after_or_equal' => 'Hạn xử lý phải từ hôm nay trở đi.']);
        $narrative = collect($fields)->keys()->mapWithKeys(fn ($f) => [$f => filled($data['narrative'][$f] ?? null) ? trim($data['narrative'][$f]) : null])->all();
        if (collect($narrative)->filter()->isEmpty()) {
            throw ValidationException::withMessages(['narrative' => 'Nhập '.Str::lcfirst(reset($fields)).' (ít nhất một mục đánh giá chung).']);
        }

        $existing = $this->structuredReport($kind, $period);
        $kept = $existing?->data['auto'] ?? null;
        $snapshot = $kept && ! $request->boolean('refresh') ? $kept : AcademicPeriodMetrics::compute(Auth::user(), $from, $to, $prevLabel);
        $notes = collect($data['notes'] ?? [])->only(array_keys($snapshot['sections']))
            ->map(fn ($v) => filled($v) ? trim((string) $v) : null)->filter()->all();
        $this->checkTasks($data['tasks'] ?? [], array_keys($snapshot['sections']));

        DB::transaction(function () use ($data, $kind, $period, $from, $title, $existing, $snapshot, $notes, $narrative, $fields) {
            $taskIds = $this->reportTaskIds($existing?->data ?? []);
            $text = trim(DailyReportMetrics::text($snapshot, $notes)."\n\n".$this->narrativeText($narrative, $fields));
            $report = $this->saveStructured($kind, $period, $title, $from->toDateString(), [
                ...($existing?->data ?? []),
                'auto' => ['sections' => $snapshot['sections'], 'computed_at' => $snapshot['computed_at']],
                'notes' => $notes,
                // Mục tường thuật cũ (đã bỏ khỏi form) giữ nguyên để báo cáo cũ vẫn đọc được.
                'narrative' => [...($existing?->data['narrative'] ?? []), ...$narrative],
                'task_ids' => $taskIds,
            ], $text);
            if (filled($data['tasks'] ?? null)) {
                foreach ($data['tasks'] as $task) {
                    $taskIds[] = $this->createFollowUpTask($task, $from->toDateString(), $report)->id;
                }
                $report->update(['data' => [...$report->data, 'task_ids' => $taskIds], 'content' => $text."\n\nTask follow-up: #".implode(', #', $taskIds)]);
            }
        });
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

    /** Tuần báo cáo hợp lệ và không phải tuần chưa tới, ngược lại tuần hiện tại. */
    private function pickWeek(?string $value): string
    {
        $week = ReportPeriod::pick($value, ReportPeriod::WEEK_PATTERN, ReportPeriod::currentWeek());

        return ReportPeriod::weekRange($week)[0]->gt(today()) ? ReportPeriod::currentWeek() : $week;
    }

    /** Luật kiểm tra: không nộp báo cáo cho tuần chưa tới. */
    private function notFutureWeek(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && preg_match(ReportPeriod::WEEK_PATTERN, $value) && ReportPeriod::weekRange($value)[0]->gt(today())) {
                $fail('Không nộp báo cáo cho tuần chưa tới.');
            }
        };
    }

    private function structuredReport(string $kind, string $period): ?StaffReport
    {
        return StaffReport::where('user_id', Auth::id())
            ->where('kind', $kind)
            ->where('period_key', $period)
            ->first();
    }

    /**
     * Mỗi người một báo cáo / loại / kỳ (khóa duy nhất staff_reports_user_kind_period_unique): updateOrCreate dùng createOrFirst nên
     * hai tab cùng bấm Lưu không tạo hai bản (rà soát 16/10/2026 B17).
     *
     * @param  array<string, mixed>  $data
     */
    private function saveStructured(string $kind, string $period, string $title, string $reportDate, array $data, string $content, string $status = 'submitted'): StaffReport
    {
        $before = StaffReport::where('user_id', Auth::id())->where('kind', $kind)->where('period_key', $period)->first(['id', 'status', 'reviewed_at', 'reviewed_by']);
        // Nộp (hoặc sửa bản đã nộp / bản Admin yêu cầu bổ sung) → về lại "Chờ kiểm tra" để Admin xem bản mới.
        $report = StaffReport::updateOrCreate(
            ['user_id' => Auth::id(), 'kind' => $kind, 'period_key' => $period],
            ['type' => self::STRUCTURED[$kind]['type'], 'title' => $title, 'report_date' => $reportDate, 'data' => $data, 'content' => $content, 'status' => $status]
                + ($status === 'submitted' ? ['reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null] : []),
        );
        if ($status !== 'submitted') {
            return $report;
        }
        // Bản Admin yêu cầu bổ sung đã nộp lại: báo đúng người đã trả về.
        if ($before?->status === 'returned' && $before->reviewed_by) {
            AdminNotification::notifyUser($before->reviewed_by, 'staff_report_resubmitted', 'Đã bổ sung: '.$report->title,
                (Auth::user()?->name ?? 'Nhân sự')." đã nộp lại {$report->title} sau khi bạn yêu cầu bổ sung.",
                route('reports.all', ['open' => $report->id]), ['staff_report_id' => $report->id]);

            return $report;
        }
        // Báo cáo ngày không báo từng bản (Admin nhận bản tổng hợp mỗi sáng: reports:daily-digest). Báo cáo kỳ khác: báo Admin khi
        // báo cáo mới vào hàng chờ kiểm tra (lần nộp đầu, nộp từ nháp, hoặc sửa sau khi đã kiểm tra); sửa tiếp khi đang chờ thì không báo lại.
        if ($kind !== 'daily_ops' && (! $before || $before->status !== 'submitted' || $before->reviewed_at !== null)) {
            self::notifyAdminsSubmitted($report, $before?->status === 'submitted');
        }

        return $report;
    }

    /** Báo cáo mọi vai trò nộp xong được đẩy về Admin kiểm tra (14/10/2026) ở "Tổng hợp báo cáo & nhật ký"; link mở thẳng báo cáo. */
    private static function notifyAdminsSubmitted(StaffReport $report, bool $updated = false): void
    {
        $name = Auth::user()?->name ?? 'Nhân sự';
        AdminNotification::notifyUsers(
            BranchStaff::admins()->pluck('id'),
            'staff_report_submitted',
            ($updated ? 'Báo cáo cập nhật: ' : 'Báo cáo mới: ').$report->title,
            "{$name} vừa ".($updated ? 'cập nhật' : 'nộp')." {$report->title}. Mở để kiểm tra.",
            route('reports.all', ['open' => $report->id]),
            ['staff_report_id' => $report->id],
            Auth::id(),
        );
    }

    /** Link màn nhập của người viết đúng kỳ báo cáo (thông báo "đã kiểm tra" / "yêu cầu bổ sung"). */
    private static function writerUrl(StaffReport $report): string
    {
        if (! $report->kind || ! isset(self::STRUCTURED[$report->kind])) {
            return route('reports.my');
        }
        $param = match (self::STRUCTURED[$report->kind]['type']) {
            'daily' => 'date',
            'weekly' => 'week',
            'quarterly' => 'quarter',
            default => 'month',
        };

        return route(self::STRUCTURED[$report->kind]['route'], [$param => $report->period_key]);
    }

    /** Báo cáo Admin kiểm tra được (không phải nhật ký, không phải nháp), trong phạm vi người xem. */
    private function reviewableReport(int $id): StaffReport
    {
        return $this->scopeViewer(StaffReport::query())->where('type', '!=', 'journal')->where('status', '!=', 'draft')->findOrFail($id);
    }

    /** Admin đánh dấu báo cáo đã kiểm tra / bỏ đánh dấu (route chỉ Admin); người viết được báo khi đã kiểm tra. */
    public function review(Request $request, int $id): RedirectResponse
    {
        $report = $this->reviewableReport($id);
        $reviewed = $request->boolean('reviewed', true);
        $this->markReviewed($report, $reviewed);

        return back()->with('success', $reviewed ? 'Đã đánh dấu đã kiểm tra.' : 'Đã chuyển về chờ kiểm tra.');
    }

    /** "Đã kiểm tra" hàng loạt ở màn Tổng hợp (chọn nhiều dòng). */
    public function reviewBulk(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']], ['ids.required' => 'Chọn ít nhất một báo cáo.']);
        $reports = $this->scopeViewer(StaffReport::query())->where('type', '!=', 'journal')->where('status', 'submitted')
            ->whereNull('reviewed_at')->whereIn('id', $data['ids'])->get();
        $reports->each(fn (StaffReport $r) => $this->markReviewed($r, true));

        return back()->with('success', "Đã đánh dấu {$reports->count()} báo cáo đã kiểm tra.");
    }

    private function markReviewed(StaffReport $report, bool $reviewed): void
    {
        $wasReviewed = $report->reviewed_at !== null;
        $report->update([
            'reviewed_by' => $reviewed ? Auth::id() : null,
            'reviewed_at' => $reviewed ? now() : null,
            'status' => $report->status === 'returned' && $reviewed ? 'submitted' : $report->status,
            'review_note' => $reviewed ? null : $report->review_note,
        ]);
        if ($reviewed && ! $wasReviewed && (int) $report->user_id !== (int) Auth::id()) {
            AdminNotification::notifyUser($report->user_id, 'staff_report_reviewed', 'Đã kiểm tra: '.$report->title,
                (Auth::user()?->name ?? 'Admin')." đã kiểm tra {$report->title}.", self::writerUrl($report), ['staff_report_id' => $report->id]);
        }
    }

    /**
     * Admin "Yêu cầu bổ sung" (rà soát 16/10/2026 B7): báo cáo về trạng thái `returned` kèm nhận xét, người viết được báo và sửa
     * lại trên màn nhập; nộp lại thì về "Chờ kiểm tra" và người trả về được báo.
     */
    public function requestChanges(Request $request, int $id): RedirectResponse
    {
        $report = $this->reviewableReport($id);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']], ['note.required' => 'Nhập nội dung cần bổ sung.']);
        $report->update(['status' => 'returned', 'review_note' => trim($data['note']), 'reviewed_by' => Auth::id(), 'reviewed_at' => null]);
        AdminNotification::notifyUser($report->user_id, 'staff_report_returned', 'Cần bổ sung: '.$report->title,
            (Auth::user()?->name ?? 'Admin').': '.Str::limit(trim($data['note']), 200), self::writerUrl($report), ['staff_report_id' => $report->id]);

        return back()->with('success', 'Đã gửi yêu cầu bổ sung cho '.($report->user?->name ?? 'người viết').'.');
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
            ->orderByDesc('period_key')->limit(12)->get(['period_key', 'updated_at', 'status', 'reviewed_at'])
            ->map(fn (StaffReport $r) => [
                'period' => $r->period_key,
                'label' => $label($r->period_key),
                'href' => route(self::STRUCTURED[$kind]['route'], [$param => $r->period_key]),
                'updated_at' => $r->updated_at?->toIso8601String(),
                'draft' => $r->status === 'draft',
                'returned' => $r->status === 'returned',
                'reviewed' => $r->reviewed_at !== null,
                'missing' => false,
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
     * Mục tự viết của báo cáo tháng / quý Học thuật để Admin đọc: mục hiện có trên form (kể cả trống) + mục tường thuật cũ đã nhập.
     *
     * @return list<array{label: string, value: ?string}>
     */
    /**
     * Màn chi tiết báo cáo tháng GV (từ 17/10/2026): khối chấm công + mỗi lớp một khối (số liệu lúc nộp, học sinh cần chú ý),
     * "ghi chú" của khối là phần giáo viên tự nhập (ghi chú chấm công / A–B–C của lớp), cuối là nhận xét chung tháng.
     *
     * @return array{auto: array, notes: array<string, string>, noteLabels: array<string, string>, narrative: list<array{label: string, value: ?string}>}
     */
    private function teacherMonthlyDetail(StaffReport $r, array $data): array
    {
        $checks = self::TEACHER_ACADEMIC_CHECKS + self::TEACHER_OPERATION_CHECKS + self::TEACHER_QUALITY_CHECKS;
        $classes = collect($data['auto']['classes'] ?? [])->map(function (array $section) {
            $attention = $section['attention'] ?? [];
            unset($section['attention']);
            $section['items'][] = [
                'key' => 'attention', 'label' => 'Học sinh cần chú ý (hệ thống gợi ý)', 'value' => null, 'count' => count($attention),
                'info' => 'Bài mini test dưới 7 điểm và học sinh vắng từ 2 buổi trong tháng, tính lúc giáo viên nộp.',
                'details' => array_map(fn (array $a) => ['text' => $a['text'], 'tag' => $a['tag'], 'tone' => 'warning'], $attention),
            ];

            return $section;
        });
        $attendance = StaffReport::where('user_id', $r->user_id)->where('kind', 'teacher_attendance')->where('period_key', $r->period_key)->first();
        $notes = ['work' => $attendance?->data['note'] ?? null];
        foreach ($data['classes'] ?? [] as $classId => $row) {
            $notes[TeacherMonthlyMetrics::classKey((int) $classId)] = $this->teacherClassText($row, $checks) ?: null;
        }

        return [
            'auto' => ['sections' => ['work' => $data['auto']['work'], ...$classes->all()], 'computed_at' => $data['auto']['computed_at'] ?? null],
            'notes' => array_filter($notes),
            'noteLabels' => ['work' => 'Ghi chú chấm công', ...$classes->map(fn () => 'Báo cáo của giáo viên (A. Học thuật · B. Vận hành · C. Chất lượng)')->all()],
            'narrative' => collect(self::TEACHER_GENERAL_FIELDS)->map(fn ($label, $key) => ['label' => $label, 'value' => $data['general'][$key] ?? null])->values()->all(),
        ];
    }

    private function narrativeRows(string $kind, array $narrative): array
    {
        [$fields, $legacy] = $kind === 'academic_monthly' ? [self::MONTHLY_FIELDS, self::MONTHLY_LEGACY_FIELDS] : [self::QUARTERLY_FIELDS, self::QUARTERLY_LEGACY_FIELDS];

        return collect($fields + $legacy)->filter(fn ($label, $key) => isset($fields[$key]) || filled($narrative[$key] ?? null))
            ->map(fn ($label, $key) => ['label' => $label, 'value' => $narrative[$key] ?? null])->values()->all();
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
    /**
     * Tổng hợp báo cáo & nhật ký. Rà soát 16/10/2026: Quản lý cơ sở chỉ thấy nhân sự chi nhánh mình (viewerStaffIds); lọc theo
     * người, chi nhánh, khoảng ngày (báo cáo tuần / tháng / quý tính theo kỳ giao với khoảng); xem trước bằng ghi chú người viết;
     * giờ nộp lần đầu + nhãn Trễ; "Đã kiểm tra" hàng loạt; tab "Chưa nộp" (báo cáo ngày, theo người × ngày).
     */
    public function allReports(Request $request): Response
    {
        $this->guard();
        abort_unless($this->isPrivileged(), 403, 'Chỉ Admin / Manager mới xem tổng hợp.');

        $view = in_array($request->input('view'), ['missing', 'followups'], true) ? $request->input('view') : 'list';
        $staffIds = $this->viewerStaffIds();
        // `date` (link cũ) = lọc đúng một ngày.
        $from = $this->filterDate($request->input('from') ?? $request->input('date'));
        $to = $this->filterDate($request->input('to') ?? $request->input('date'));
        $userId = $request->integer('user_id') ?: null;
        $branchId = $request->integer('branch_id') ?: null;
        $inBranch = fn ($q) => $q->where('branch_id', $branchId)->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branchId));

        $query = $this->scopeViewer(StaffReport::with(['user.roles', 'user.branch:id,name', 'followups', 'reviewer:id,name']))->where('status', '!=', 'draft');
        $query->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($branchId, fn ($q) => $q->whereHas('user', $inBranch))
            ->when($to, fn ($q) => $q->whereDate('report_date', '<=', $to->toDateString()))
            ->when($from, fn ($q) => $q->where(fn ($p) => $p
                ->where(fn ($d) => $d->whereNotIn('type', ['weekly', 'monthly', 'quarterly'])->whereDate('report_date', '>=', $from->toDateString()))
                ->orWhere(fn ($w) => $w->where('type', 'weekly')->whereDate('report_date', '>=', $from->copy()->subDays(6)->toDateString()))
                ->orWhere(fn ($m) => $m->where('type', 'monthly')->whereDate('report_date', '>=', $from->copy()->startOfMonth()->toDateString()))
                ->orWhere(fn ($m) => $m->where('type', 'quarterly')->whereDate('report_date', '>=', $from->copy()->firstOfQuarter()->toDateString()))));
        // Tab trạng thái (thiết kế lại 27/10/2026): mặc định "Chờ kiểm tra"; `review=all` = mọi báo cáo kể cả nhật ký sự vụ.
        // Hàng chờ Admin kiểm tra chỉ gồm báo cáo định kỳ (nhật ký sự vụ không cần kiểm tra ở đây).
        $review = in_array($request->input('review'), ['pending', 'done', 'returned', 'all'], true) ? $request->input('review') : 'pending';
        if ($review !== 'all') {
            $query->where('type', '!=', 'journal');
            match ($review) {
                'pending' => $query->where('status', 'submitted')->whereNull('reviewed_at'),
                'done' => $query->whereNotNull('reviewed_at'),
                'returned' => $query->where('status', 'returned'),
            };
        }

        // Số báo cáo theo loại trong tab + bộ lọc hiện tại (nút chọn loại), rồi mới lọc loại.
        $typeCounts = (clone $query)->setEagerLoads([])->reorder()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')
            ->map(fn ($n) => (int) $n)->all();
        $type = array_key_exists((string) $request->input('type'), StaffReport::TYPE_LABELS) ? $request->input('type') : null;
        $query->when($type, fn ($q) => $q->where('type', $type));

        $reports = $query->orderByRaw($review === 'pending' ? 'updated_at desc' : 'report_date desc')->latest('id')->paginate(20)->withQueryString()
            ->through(fn (StaffReport $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => $r->kind ? (self::STRUCTURED[$r->kind]['label'] ?? $r->type_label) : $r->type_label,
                // Báo cáo ngày dùng chung cho Học vụ và Học thuật → ghi theo chức danh người nộp.
                'for' => $r->kind === 'daily_ops' && $r->user ? StaffType::dailyReportAudience($r->user) : ($r->kind ? (self::STRUCTURED[$r->kind]['for'] ?? null) : null),
                'severity_label' => $r->type === 'journal' ? $r->severity_label : null,
                'title' => $r->title,
                'preview' => self::preview($r),
                'report_date' => $r->report_date->toDateString(),
                'submitted_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at && $r->created_at && $r->updated_at->diffInMinutes($r->created_at, true) >= 1 ? $r->updated_at->toIso8601String() : null,
                'late' => DailyReportDue::isLate($r),
                'user' => $r->user?->name,
                'branch' => $r->user?->branch?->name,
                'followups_count' => $r->followups->count(),
                'reviewable' => $r->type !== 'journal',
                'status' => $r->status,
                'review_note' => $r->review_note,
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'reviewed_by' => $r->reviewer?->name,
            ]);

        $scoped = fn () => $this->scopeViewer(StaffReport::query());
        $stats = [
            'journal' => $scoped()->where('type', 'journal')->count(),
            'daily' => $scoped()->where('type', 'daily')->count(),
            'weekly' => $scoped()->where('type', 'weekly')->count(),
            'monthly' => $scoped()->where('type', 'monthly')->count(),
            'urgent_open' => $scoped()->where('type', 'journal')->where('severity', 'urgent')->where('status', '!=', 'resolved')->count(),
            'pending_review' => $scoped()->where('type', '!=', 'journal')->where('status', 'submitted')->whereNull('reviewed_at')->count(),
            'returned' => $scoped()->where('type', '!=', 'journal')->where('status', 'returned')->count(),
        ];
        $writers = DailyReportDue::writers($staffIds);
        $yesterday = today()->subDay();
        $stats['missing_yesterday'] = $writers->filter(fn (User $u) => DailyReportDue::missingDays($u, $yesterday, $yesterday)->isNotEmpty())->count();
        // Nhãn "N quá hạn" trên tab Việc follow-up: task tạo từ báo cáo trong tháng này còn quá hạn.
        $followUps = $view === 'followups' ? $this->followUpSummary($staffIds, $from ?? today()->startOfMonth(), $to ?? today(), $userId, $branchId) : null;
        $stats['followups_overdue'] = collect($followUps && ! $from && ! $to && ! $userId && ! $branchId ? $followUps['rows'] : $this->followUpSummary($staffIds, today()->startOfMonth(), today(), null, null)['rows'])->sum('overdue');

        $openId = $request->integer('open') ?: null;

        return Inertia::render('Reports/All', [
            'view' => $view,
            'reports' => $reports,
            'missing' => $view === 'missing' ? $this->missingReports($writers, $from ?? today()->subDays(7), $to ?? $yesterday, $userId, $branchId) : null,
            'followUps' => $followUps,
            'stats' => $stats,
            'filters' => [
                'type' => $type,
                'review' => $review,
                'user_id' => $userId,
                'branch_id' => $branchId,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'canReview' => (bool) Auth::user()?->can(AdminOnlyApprovals::ABILITY),
            'types' => Ui::options(StaffReport::TYPE_LABELS),
            'typeCounts' => $typeCounts,
            // Ngày hôm nay theo múi giờ ứng dụng: khoảng ngày chọn nhanh (7 ngày qua, tháng này…) tính từ đây, không theo đồng hồ máy người xem.
            'today' => today()->toDateString(),
            'users' => User::whereIn('id', $scoped()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            'branches' => Branch::where('is_active', true)->when($staffIds !== null, fn ($q) => $q->whereIn('id', Auth::user()->branchIds()))
                ->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->all(),
            'openId' => $openId && $scoped()->where('status', '!=', 'draft')->whereKey($openId)->exists() ? $openId : null,
        ]);
    }

    private function filterDate(mixed $value): ?Carbon
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? Carbon::parse($value)->startOfDay() : null;
    }

    /**
     * Tab "Chưa nộp": người phải nộp báo cáo ngày (DailyReportDue) còn thiếu ngày nào trong khoảng (mặc định 7 ngày qua).
     *
     * @param  Collection<int, User>  $writers
     * @return array{from: string, to: string, rows: list<array<string, mixed>>}
     */
    /**
     * Bảng "Việc follow-up từ báo cáo" ở màn Tổng hợp (chủ dự án 17/10/2026): task tạo từ báo cáo định kỳ (kể cả Admin giao ngay trên
     * báo cáo) và từ báo cáo trực lớp, gom theo người viết báo cáo — đã tạo / đã xong / đang mở / quá hạn trong khoảng ngày tạo, kèm danh sách việc.
     *
     * @param  list<int>|null  $staffIds
     */
    private function followUpSummary(?array $staffIds, Carbon $from, Carbon $to, ?int $userId, ?int $branchId): array
    {
        $tasks = WorkTask::with(['assignee:id,name', 'creator:id,name', 'staffReport:id,user_id,title', 'sourceClassReport:id,reporter_id,class_id,session_date', 'sourceClassReport.classModel:id,name'])
            ->where(fn ($q) => $q->whereNotNull('staff_report_id')->orWhereNotNull('class_report_id'))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('id')->get()
            ->map(function (WorkTask $t) {
                $t->setAttribute('author_id', (int) ($t->staffReport?->user_id ?? $t->sourceClassReport?->reporter_id ?? 0));

                return $t;
            })
            ->filter(fn (WorkTask $t) => $t->author_id && ($staffIds === null || in_array($t->author_id, $staffIds, true)) && (! $userId || $t->author_id === $userId));
        $authors = User::with(['roles', 'branch:id,name', 'branches:id'])->whereIn('id', $tasks->pluck('author_id')->unique())->get()
            ->when($branchId, fn ($c) => $c->filter(fn (User $u) => in_array($branchId, array_map('intval', $u->branchIds()), true)))->keyBy('id');
        $overdue = fn (WorkTask $t) => $t->status === 'overdue' || (in_array($t->status, WorkTask::OPEN_STATUSES, true) && $t->lateHours() > 0);
        $rank = fn (WorkTask $t) => $overdue($t) ? 0 : (in_array($t->status, ['completed', 'canceled'], true) ? 2 : 1);

        $rows = $tasks->filter(fn (WorkTask $t) => $authors->has($t->author_id))->groupBy('author_id')
            ->map(function (Collection $items, $authorId) use ($authors, $overdue, $rank) {
                $u = $authors->get($authorId);

                return [
                    'user_id' => (int) $authorId,
                    'user' => $u->name,
                    'role' => ($role = $u->roles->first()?->name) ? AclHelper::shortRoleLabel($role) : null,
                    'branch' => $u->branch?->name,
                    'total' => $items->count(),
                    'done' => $items->where('status', 'completed')->count(),
                    'open' => $items->reject(fn (WorkTask $t) => in_array($t->status, ['completed', 'canceled'], true))->count(),
                    'overdue' => $items->filter($overdue)->count(),
                    'tasks' => $items->sortBy(fn (WorkTask $t) => [$rank($t), $t->due_date?->toDateString()])->take(30)->map(fn (WorkTask $t) => [
                        'id' => $t->id,
                        'title' => $t->title,
                        'assignee' => $t->assignee?->name,
                        'due_date' => $t->due_date?->toDateString(),
                        'status' => $t->status,
                        'status_label' => $t->status_label,
                        'overdue' => $overdue($t),
                        'source' => $t->staffReport?->title ?? ('Báo cáo trực lớp '.($t->sourceClassReport?->classModel?->name ?? '').($t->sourceClassReport?->session_date ? ' · '.$t->sourceClassReport->session_date->format('d/m') : '')),
                        'by' => (int) $t->creator_id !== (int) $authorId ? $t->creator?->name : null,
                    ])->values()->all(),
                ];
            })
            ->sortBy(fn (array $r) => [-$r['overdue'], -$r['open'], -$r['total']])->values()->all();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows];
    }

    /** Khối báo cáo Admin giao task được: các khối số liệu đã chụp lúc nộp (báo cáo cũ không có khối thì không giao theo khối). */
    private function reportSectionKeys(StaffReport $report): array
    {
        $auto = $report->data['auto'] ?? null;
        if (! is_array($auto)) {
            return [];
        }

        return match ($report->kind) {
            'daily_ops', 'academic_monthly', 'academic_quarterly' => array_keys($auto['sections'] ?? []),
            'weekly_kpi' => self::WEEKLY_TASK_SECTIONS,
            'teacher_monthly' => ['work', ...array_keys($auto['classes'] ?? [])],
            default => [],
        };
    }

    /**
     * Admin tạo task ngay trong một khối khi xem báo cáo (chủ dự án 17/10/2026): task gắn báo cáo + khối, mặc định giao người viết,
     * chưa xong thì hiện lại đúng khối ở báo cáo kỳ sau của người viết như task họ tự tạo.
     */
    public function reportTaskStore(Request $request, int $id): RedirectResponse
    {
        $report = $this->reviewableReport($id);
        $report->loadMissing('user:id,name,branch_id');
        $data = $request->validate(['tasks' => ['required', 'array', 'min:1', 'max:20'], 'tasks.*.section' => ['required', 'string', 'max:40']] + self::TASK_RULES, [
            'tasks.required' => 'Thêm ít nhất một task.',
            'tasks.*.title.required' => 'Nhập tiêu đề task.',
            'tasks.*.due_date.after_or_equal' => 'Hạn xử lý phải từ hôm nay trở đi.',
        ]);
        $keys = $this->reportSectionKeys($report);
        if ($keys === []) {
            throw ValidationException::withMessages(['tasks' => 'Báo cáo này chưa chia khối nên không giao task theo khối được.']);
        }
        $this->checkTasks($data['tasks'], $keys);

        DB::transaction(function () use ($data, $report) {
            foreach ($data['tasks'] as $task) {
                // Khối của một lớp (báo cáo tháng GV): task gắn luôn lớp đó.
                if ($report->kind === 'teacher_monthly' && str_starts_with($task['section'], 'class_') && blank($task['class_id'] ?? null)) {
                    $task['class_id'] = (int) substr($task['section'], 6);
                }
                $this->createFollowUpTask($task, $report->report_date->toDateString(), $report);
            }
        });

        return back()->with('success', 'Đã giao '.count($data['tasks']).' task từ báo cáo của '.($report->user?->name ?? 'người viết').'.');
    }

    /** @return list<array{value: int, label: string}> người nhận task Admin giao trên báo cáo: người viết trước, rồi "Tôi" và người khác */
    private function reportTaskAssignees(StaffReport $report): array
    {
        $all = collect($this->taskAssignees());
        $author = $all->firstWhere('value', (int) $report->user_id);

        return $author ? [['value' => $author['value'], 'label' => $author['label'].' (người viết)'], ...$all->where('value', '!=', (int) $report->user_id)->values()->all()] : $all->all();
    }

    private function missingReports(Collection $writers, Carbon $from, Carbon $to, ?int $userId, ?int $branchId): array
    {
        $rows = $writers
            ->when($userId, fn ($c) => $c->where('id', $userId))
            ->when($branchId, fn ($c) => $c->filter(fn (User $u) => in_array($branchId, array_map('intval', $u->branchIds()), true)))
            ->map(fn (User $u) => [
                'user_id' => $u->id,
                'user' => $u->name,
                'branch' => $u->branch?->name,
                'for' => StaffType::dailyReportAudience($u),
                'days' => DailyReportDue::missingDays($u, $from, $to)->map(fn (Carbon $d) => [
                    'date' => $d->toDateString(),
                    'label' => Str::ucfirst($d->locale('vi')->isoFormat('dd D/M')),
                ])->all(),
            ])->filter(fn (array $row) => $row['days'] !== [])->sortByDesc(fn (array $row) => count($row['days']))->values()->all();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows];
    }

    /** Xem trước ở danh sách: ghi chú của người viết (báo cáo ngày / tuần KPI), không có thì nội dung chữ. */
    private static function preview(StaffReport $r): ?string
    {
        $data = $r->data ?? [];
        $notes = collect($data['notes'] ?? [])->filter()->map(fn ($text, $key) => Str::ucfirst(Str::after(DailyReportMetrics::NOTE_LABELS[$key] ?? 'Ghi chú khác', 'Ghi chú ')).': '.$text);
        if (filled($data['note'] ?? null)) {
            $notes->push('Tuần: '.$data['note']);
        }
        $text = $notes->isNotEmpty() ? $notes->implode(' · ') : ($r->kind === 'daily_ops' && isset($data['auto']) ? 'Không có ghi chú (chỉ số liệu hệ thống).' : $r->content);

        return $text === null ? null : Str::limit(preg_replace('/\s+/u', ' ', $text), 280);
    }

    /** Ghi chú báo cáo tuần Học thuật (Sheet 4 câu 9): chính người làm báo cáo hoặc Quản lý / Admin trong phạm vi xem. */
    public function noteStore(Request $request, int $id): RedirectResponse
    {
        $report = StaffReport::where('kind', 'academic_weekly')->findOrFail($id);
        abort_unless($this->canNote($report), 403);
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']], ['note.required' => 'Nhập nội dung ghi chú.']);
        $report->notes()->create(['user_id' => Auth::id(), 'note' => $validated['note']]);

        return redirect()->back()->with('status', 'Đã thêm ghi chú vào báo cáo tuần.');
    }

    private function canNote(StaffReport $report): bool
    {
        return $report->kind === 'academic_weekly' && $report->status !== 'draft' && ((int) $report->user_id === (int) Auth::id()
            || ($this->isPrivileged() && $this->scopeViewer(StaffReport::query())->whereKey($report->id)->exists()));
    }

    /** @return list<array{user: ?string, note: string, created_at: string}> */
    private static function notesPayload(?StaffReport $report): array
    {
        return $report ? $report->notes()->with('user:id,name')->get()
            ->map(fn (StaffReportNote $n) => ['user' => $n->user?->name, 'note' => $n->note, 'created_at' => $n->created_at->toIso8601String()])->all() : [];
    }

    /**
     * Chi tiết một báo cáo cho Admin (modal "Chi tiết" ở màn Tổng hợp, chủ dự án 15/10/2026): hiện như màn người viết nhập —
     * số liệu tự động + ghi chú theo khối (báo cáo ngày), bảng KPI + chỉ số (báo cáo tuần Học vụ), 3 bảng (báo cáo tuần Học thuật).
     * Báo cáo ngày / tuần nhập tay bản cũ → đủ mọi ô của form cũ (`legacy`); báo cáo ngày cũ kèm số liệu hệ thống tính lại cho ngày đó. Loại chưa có màn có cấu trúc → nội dung chữ.
     */
    public function reportDetail(int $id): Response
    {
        $this->guard();
        abort_unless($this->isPrivileged(), 403, 'Chỉ Admin / Manager mới xem tổng hợp.');
        $r = $this->scopeViewer(StaffReport::with(['user.roles', 'reviewer:id,name', 'classModel:id,name', 'followups.user:id,name']))
            ->where('status', '!=', 'draft')->findOrFail($id);
        $data = $r->data ?? [];

        $view = match (true) {
            $r->type === 'journal' => 'journal',
            $r->kind === 'daily_ops' && isset($data['auto']) => 'daily',
            $r->kind === 'daily_ops' && isset($data['fields']) => 'daily_legacy',
            $r->kind === 'weekly_kpi' && isset($data['auto']) => 'weekly_kpi',
            $r->kind === 'weekly_kpi' => 'weekly_legacy',
            $r->kind === 'academic_weekly' && isset($data['rows']) => 'academic_weekly',
            in_array($r->kind, ['academic_monthly', 'academic_quarterly'], true) && isset($data['auto']) => 'academic_period',
            $r->kind === 'teacher_monthly' && isset($data['auto']) => 'teacher_monthly',
            default => 'text',
        };
        $teacher = $view === 'teacher_monthly' ? $this->teacherMonthlyDetail($r, $data) : null;
        // Báo cáo nhập tay bản cũ: hiện đủ mọi ô như màn nhập lúc đó (ô trống hiện "—"), không lọc bớt ô chưa nhập.
        $legacy = match ($view) {
            'daily_legacy' => ['sections' => self::DAILY_SECTIONS, 'fields' => self::DAILY_FIELDS, 'values' => (object) ($data['fields'] ?? [])],
            'weekly_legacy' => [
                'groups' => collect($data['counts'] ?? [])->groupBy(fn (array $c) => $c['group'] ?? 'Khác')
                    ->map(fn ($items, $group) => ['name' => $group, 'items' => $items->map(fn (array $c) => [
                        'id' => $c['id'] ?? $c['code'] ?? $c['name'], 'code' => $c['code'] ?? null, 'name' => $c['name'], 'count' => (int) ($c['count'] ?? 0),
                    ])->values()->all()])->values()->all(),
                'metrics' => collect(self::WEEKLY_METRICS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => $data['metrics'][$key] ?? null])->values()->all(),
            ],
            default => null,
        };
        // Báo cáo kế tiếp đang chờ kiểm tra (nút "Kế tiếp" trong cửa sổ chi tiết).
        $nextId = $r->type === 'journal' ? null : $this->scopeViewer(StaffReport::query())->where('type', '!=', 'journal')->where('status', 'submitted')
            ->whereNull('reviewed_at')->whereKeyNot($r->id)->orderByDesc('updated_at')->orderByDesc('id')->value('id');

        return Inertia::render('Reports/Detail', [
            'report' => [
                'id' => $r->id,
                'title' => $r->title,
                'type_label' => $r->kind ? (self::STRUCTURED[$r->kind]['label'] ?? $r->type_label) : $r->type_label,
                'for' => $r->kind === 'daily_ops' && $r->user ? StaffType::dailyReportAudience($r->user) : ($r->kind ? (self::STRUCTURED[$r->kind]['for'] ?? null) : null),
                'user' => $r->user?->name,
                'report_date' => $r->report_date->toDateString(),
                'submitted_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at?->toIso8601String(),
                'late' => DailyReportDue::isLate($r),
                'deadline' => $r->type === 'daily' ? DailyReportDue::deadline($r->report_date)->format('H:i d/m') : null,
                'status' => $r->status,
                'review_note' => $r->review_note,
                'reviewable' => $r->type !== 'journal',
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'reviewed_by' => $r->reviewer?->name,
                'content' => $r->content,
                'severity_label' => $r->type === 'journal' ? $r->severity_label : null,
                'kpi_code' => $r->kpi_code,
                'class' => $r->classModel?->name,
                'followups' => $r->followups->map(fn (StaffReportFollowup $f) => ['user' => $f->user?->name, 'content' => $f->content, 'created_at' => $f->created_at->toIso8601String()])->all(),
                'notes' => self::notesPayload($r),
                'note_url' => $this->canNote($r) ? route('reports.notes.store', $r->id) : null,
            ],
            'view' => $view,
            'auto' => match (true) {
                in_array($view, ['daily', 'weekly_kpi', 'academic_period'], true) => $data['auto'],
                $view === 'teacher_monthly' => $teacher['auto'],
                // Báo cáo ngày nhập tay cũ chỉ lưu con số: tính lại số liệu + chi tiết của ngày đó theo phạm vi người viết, như màn Học vụ đang thấy.
                $view === 'daily_legacy' && $r->user !== null => DailyReportMetrics::compute($r->user, $r->report_date->toDateString(), StaffType::dailyReportAudience($r->user)),
                default => null,
            },
            'notes' => (object) ($teacher['notes'] ?? $data['notes'] ?? []),
            'noteLabels' => $teacher['noteLabels'] ?? ($view === 'academic_period' ? AcademicPeriodMetrics::NOTE_LABELS : DailyReportMetrics::NOTE_LABELS),
            // Báo cáo tháng / quý Học thuật: đánh giá chung (kể cả mục tường thuật cũ đã nhập); báo cáo tháng GV: nhận xét chung tháng.
            'narrative' => $teacher['narrative'] ?? ($view === 'academic_period' ? $this->narrativeRows($r->kind, $data['narrative'] ?? []) : []),
            'overallTitle' => $view === 'teacher_monthly' ? 'Nhận xét chung tháng' : 'Đánh giá chung',
            'note' => $data['note'] ?? null,
            'tasks' => $this->reportTasks($data, in_array($view, ['daily', 'academic_period', 'teacher_monthly'], true)),
            // Báo cáo ngày / tuần Học vụ từ 17/10/2026: task theo khối (kể cả việc tồn kỳ trước của người viết) + góp ý từng buổi đã chụp lúc lưu.
            'sectionTasks' => (object) (in_array($view, ['daily', 'weekly_kpi', 'academic_period', 'teacher_monthly'], true) ? $this->sectionTasks($r, $r->kind, $r->period_key, $r->user_id) : []),
            'sessionFeedback' => $view === 'daily' ? ($data['session_feedback'] ?? []) : [],
            'verdicts' => ClassSessionFeedback::VERDICTS,
            'canReview' => (bool) Auth::user()?->can(AdminOnlyApprovals::ABILITY),
            // Admin tạo task ngay trong từng khối (báo cáo đã chia khối).
            'canAddTasks' => $canAdd = Auth::user()?->can(AdminOnlyApprovals::ABILITY) && $r->type !== 'journal' && $this->reportSectionKeys($r) !== [],
            'assignees' => $canAdd ? $this->reportTaskAssignees($r) : [],
            'today' => today()->toDateString(),
            'nextId' => $nextId,
            'legacy' => $legacy,
            'rows' => (object) ($view === 'academic_weekly' ? $data['rows'] : []),
            'sections' => self::WEEKLY_SECTIONS,
            'columns' => self::WEEKLY_COLUMNS,
        ]);
    }
}
