<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CandidateCv;
use App\Models\JobPosting;
use App\Services\SafeUploadService;
use App\Support\DataScope;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RecruitmentController extends Controller
{
    /** Trạng thái hồ sơ CV cho bộ lọc và form cập nhật (khớp validate của updateCvStatus). */
    private const CV_STATUS_OPTIONS = [
        'pending' => 'Chờ xử lý',
        'reviewing' => 'Đang đánh giá',
        'interviewed' => 'Đã phỏng vấn',
        'accepted' => 'Đã tuyển dụng',
        'rejected' => 'Từ chối',
    ];

    /**
     * Chi nhánh người dùng quản lý tuyển dụng: không có module phạm vi riêng → dùng phạm vi nhân sự "user.scope_*" (DataScope)
     * như các màn nhân sự khác. null = mọi chi nhánh (Admin); Quản lý cơ sở chỉ chi nhánh mình (rà soát 19/10/2026).
     *
     * @return list<int>|null
     */
    private static function branchScope(): ?array
    {
        return DataScope::branchIds(Auth::user(), 'user');
    }

    /** Tin / CV trong phạm vi: theo chi nhánh; tin / CV chưa gắn chi nhánh (toàn hệ thống) chỉ người phạm vi "all" thấy ($withGlobal: chỉ xem). */
    private static function scoped($query, bool $withGlobal = false)
    {
        $ids = self::branchScope();

        return $ids === null ? $query : $query->where(fn ($q) => $q->whereIn('branch_id', $ids)
            ->when($withGlobal, fn ($q) => $q->orWhereNull('branch_id')));
    }

    /**
     * Màn hình quản lý tuyển dụng & hồ sơ ứng viên trong Web Admin
     */
    public function index(Request $request): InertiaResponse
    {
        $branchIds = self::branchScope();
        $branches = Branch::where('is_active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))->get();
        $tab = $request->get('tab', 'candidates'); // 'candidates', 'jobs'
        $status = $request->get('status', 'all');
        $branchId = $request->get('branch_id');

        // Danh sách tin tuyển dụng (tin toàn hệ thống vẫn hiện để xem; số CV chỉ đếm CV trong phạm vi).
        $jobs = self::scoped(JobPosting::with(['branch', 'creator']), withGlobal: true)
            ->withCount(['candidateCvs' => fn ($q) => self::scoped($q)])
            ->latest()
            ->get();

        // Danh sách CV ứng tuyển
        $cvsQuery = self::scoped(CandidateCv::with(['jobPosting', 'branch', 'reviewer']))->latest();

        if ($status !== 'all' && ! empty($status)) {
            $cvsQuery->where('status', $status);
        }

        if (! empty($branchId)) {
            $cvsQuery->where('branch_id', $branchId);
        }

        $candidates = $cvsQuery->paginate(15)->withQueryString();

        // Thống kê
        $totalJobs = self::scoped(JobPosting::where('is_active', true), withGlobal: true)->count();
        $totalCvs = self::scoped(CandidateCv::query())->count();
        $interviewedCount = self::scoped(CandidateCv::where('status', 'interviewed'))->count();
        $acceptedCount = self::scoped(CandidateCv::where('status', 'accepted'))->count();

        return Inertia::render('Recruitment/Index', [
            'tab' => $tab,
            'branches' => Ui::options($branches, 'name'),
            'cvStatusOptions' => Ui::options(self::CV_STATUS_OPTIONS),
            'stats' => [
                'totalJobs' => $totalJobs,
                'totalCvs' => $totalCvs,
                'interviewedCount' => $interviewedCount,
                'acceptedCount' => $acceptedCount,
            ],
            'jobs' => $jobs->map(fn (JobPosting $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'deadline' => $job->deadline?->format('d/m/Y'),
                'department' => $job->department,
                'employment_type' => $job->employment_type,
                'branch_name' => $job->branch?->name,
                'salary_range' => $job->salary_range,
                'candidate_cvs_count' => $job->candidate_cvs_count,
                'is_active' => (bool) $job->is_active,
            ])->all(),
            'candidates' => $candidates->through(fn (CandidateCv $can) => [
                'id' => $can->id,
                'full_name' => $can->full_name,
                'phone' => $can->phone,
                'email' => $can->email,
                'applying_position' => $can->applying_position,
                'branch_name' => $can->branch?->name,
                'cv_url' => $can->cv_file_path ? asset('storage/'.$can->cv_file_path) : null,
                'portfolio_url' => $can->portfolio_url,
                'status' => $can->status,
                'status_label' => $can->statusBadge()['label'],
                'notes' => $can->notes,
            ]),
        ]);
    }

    /**
     * Tạo tin tuyển dụng mới
     */
    public function storeJob(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department' => 'required|string|max:100',
            'branch_id' => 'nullable|exists:branches,id',
            'employment_type' => 'required|string|in:Full-time,Part-time,Thực tập',
            'salary_range' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'description' => 'required|string',
            'requirements' => 'nullable|string',
            'benefits' => 'nullable|string',
            'deadline' => 'nullable|date',
        ]);
        // Tin toàn hệ thống (không chọn chi nhánh) chỉ người phạm vi "all"; còn lại phải là chi nhánh của mình.
        if (! DataScope::coversBranch(Auth::user(), 'user', isset($validated['branch_id']) ? (int) $validated['branch_id'] : null)) {
            throw ValidationException::withMessages(['branch_id' => 'Chỉ đăng tin cho chi nhánh bạn quản lý.']);
        }

        JobPosting::create([
            'title' => $validated['title'],
            'department' => $validated['department'],
            'branch_id' => $validated['branch_id'] ?? null,
            'employment_type' => $validated['employment_type'],
            'salary_range' => $validated['salary_range'] ?? 'Thỏa thuận',
            'location' => $validated['location'] ?? 'Toàn hệ thống',
            'description' => $validated['description'],
            'requirements' => $validated['requirements'] ?? null,
            'benefits' => $validated['benefits'] ?? null,
            'deadline' => $validated['deadline'] ?? now()->addMonth(),
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('recruitment.index', ['tab' => 'jobs'])->with('success', 'Đã đăng tin tuyển dụng mới thành công!');
    }

    /**
     * Bật / Tắt trạng thái tin tuyển dụng
     */
    public function toggleJobStatus($id): RedirectResponse
    {
        $job = self::scoped(JobPosting::query())->findOrFail($id);
        $job->update(['is_active' => ! $job->is_active]);

        $statusText = $job->is_active ? 'mở lại' : 'đóng tạm thời';

        return redirect()->back()->with('success', "Đã {$statusText} tin tuyển dụng '{$job->title}'!");
    }

    /**
     * Cập nhật trạng thái và ghi chú đánh giá CV ứng viên
     */
    public function updateCvStatus(Request $request, $id): RedirectResponse
    {
        $cv = self::scoped(CandidateCv::query())->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:pending,reviewing,interviewed,accepted,rejected',
            'notes' => 'nullable|string|max:1000',
        ]);

        $cv->update([
            'status' => $validated['status'],
            'notes' => ($validated['notes'] ?? null) ? ($cv->notes ? $cv->notes."\n[".now()->format('d/m/Y H:i').']: '.$validated['notes'] : $validated['notes']) : $cv->notes,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', "Đã cập nhật trạng thái hồ sơ ứng viên {$cv->full_name}!");
    }

    /**
     * Portal Tuyển dụng Public cho Ứng viên ngoài xem và nộp hồ sơ
     */
    public function portal(Request $request): InertiaResponse
    {
        $jobs = JobPosting::with('branch')
            ->where('is_active', true)
            ->latest()
            ->get();

        $branches = Branch::where('is_active', true)->get();

        return Inertia::render('Recruitment/Portal', [
            'jobs' => $jobs->map(fn (JobPosting $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'department' => $job->department,
                'employment_type' => $job->employment_type,
                'branch_id' => $job->branch_id,
                'branch_name' => $job->branch?->name,
                'salary_range' => $job->salary_range,
                'description' => $job->description,
                'requirements' => $job->requirements,
                'deadline' => $job->deadline?->format('d/m/Y'),
            ])->all(),
            'branches' => Ui::options($branches, 'name'),
        ]);
    }

    /**
     * Xử lý nộp CV trực tuyến từ Public Portal
     */
    public function portalSubmit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:30',
            'job_posting_id' => 'nullable|exists:job_postings,id',
            'applying_position' => 'required|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'cv_file' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'portfolio_url' => 'nullable|url|max:255',
            'cover_letter' => 'nullable|string|max:3000',
        ]);

        $cvPath = null;
        if ($request->hasFile('cv_file')) {
            $cvPath = SafeUploadService::store($request->file('cv_file'), 'candidate_cvs', ['pdf', 'doc', 'docx'], 'cv_file');
        }

        CandidateCv::create([
            'job_posting_id' => $validated['job_posting_id'] ?? null,
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'applying_position' => $validated['applying_position'],
            'branch_id' => $validated['branch_id'] ?? null,
            'cv_file_path' => $cvPath,
            'portfolio_url' => $validated['portfolio_url'] ?? null,
            'cover_letter' => $validated['cover_letter'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Hồ sơ ứng tuyển của bạn đã được gửi thành công! Ban Nhân sự MENGLISH sẽ liên hệ với bạn trong thời gian sớm nhất.');
    }
}
