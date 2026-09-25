<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CandidateCv;
use App\Models\JobPosting;
use App\Services\SafeUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RecruitmentController extends Controller
{
    /**
     * Màn hình quản lý tuyển dụng & hồ sơ ứng viên trong Web Admin
     */
    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->get();
        $tab = $request->get('tab', 'candidates'); // 'candidates', 'jobs'
        $status = $request->get('status', 'all');
        $branchId = $request->get('branch_id');

        // Danh sách tin tuyển dụng
        $jobs = JobPosting::with(['branch', 'creator'])
            ->withCount('candidateCvs')
            ->latest()
            ->get();

        // Danh sách CV ứng tuyển
        $cvsQuery = CandidateCv::with(['jobPosting', 'branch', 'reviewer'])->latest();

        if ($status !== 'all' && !empty($status)) {
            $cvsQuery->where('status', $status);
        }

        if (!empty($branchId)) {
            $cvsQuery->where('branch_id', $branchId);
        }

        $candidates = $cvsQuery->paginate(15)->withQueryString();

        // Thống kê
        $totalJobs = JobPosting::where('is_active', true)->count();
        $totalCvs = CandidateCv::count();
        $interviewedCount = CandidateCv::where('status', 'interviewed')->count();
        $acceptedCount = CandidateCv::where('status', 'accepted')->count();

        return view('recruitment.index', compact(
            'branches',
            'tab',
            'status',
            'branchId',
            'jobs',
            'candidates',
            'totalJobs',
            'totalCvs',
            'interviewedCount',
            'acceptedCount'
        ));
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
        $job = JobPosting::findOrFail($id);
        $job->update(['is_active' => !$job->is_active]);

        $statusText = $job->is_active ? 'mở lại' : 'đóng tạm thời';
        return redirect()->back()->with('success', "Đã {$statusText} tin tuyển dụng '{$job->title}'!");
    }

    /**
     * Cập nhật trạng thái và ghi chú đánh giá CV ứng viên
     */
    public function updateCvStatus(Request $request, $id): RedirectResponse
    {
        $cv = CandidateCv::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:pending,reviewing,interviewed,accepted,rejected',
            'notes' => 'nullable|string|max:1000',
        ]);

        $cv->update([
            'status' => $validated['status'],
            'notes' => $validated['notes'] ? ($cv->notes ? $cv->notes . "\n[" . now()->format('d/m/Y H:i') . ']: ' . $validated['notes'] : $validated['notes']) : $cv->notes,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', "Đã cập nhật trạng thái hồ sơ ứng viên {$cv->full_name}!");
    }

    /**
     * Portal Tuyển dụng Public cho Ứng viên ngoài xem và nộp hồ sơ
     */
    public function portal(Request $request): View
    {
        $jobs = JobPosting::with('branch')
            ->where('is_active', true)
            ->latest()
            ->get();

        $branches = Branch::where('is_active', true)->get();

        return view('portal.recruitment', compact('jobs', 'branches'));
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
