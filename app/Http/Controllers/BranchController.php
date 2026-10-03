<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BranchController extends Controller
{
    /** Cơ sở & chi nhánh: thẻ số liệu, bộ lọc, bảng; Thêm / Sửa mở modal trong trang. */
    public function index(Request $request): InertiaResponse
    {
        $query = Branch::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === '1');
        }

        $branches = $query->orderBy('id')->get();

        // Số liệu từng chi nhánh: mỗi bảng 1 truy vấn đếm theo chi nhánh (thay vì 4 truy vấn cho mỗi chi nhánh).
        $countBy = fn (string $model) => $model::query()->whereIn('branch_id', $branches->pluck('id'))
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as aggregate')->pluck('aggregate', 'branch_id');
        $users = $countBy(User::class);
        $classes = $countBy(ClassModel::class);
        $students = $countBy(Student::class);
        $customers = $countBy(CrmCustomer::class);
        foreach ($branches as $branch) {
            $branch->users_count = (int) ($users[$branch->id] ?? 0);
            $branch->classes_count = (int) ($classes[$branch->id] ?? 0);
            $branch->students_count = (int) ($students[$branch->id] ?? 0);
            $branch->customers_count = (int) ($customers[$branch->id] ?? 0);
        }

        $totalBranches = Branch::count();
        $activeBranches = Branch::where('is_active', true)->count();
        $totalStudents = Student::count();
        $totalClasses = ClassModel::where('status', 'active')->count();

        return Inertia::render('Branches/Index', [
            'branches' => $branches->map(fn (Branch $branch) => [
                ...$branch->only(['id', 'code', 'name', 'address', 'phone']),
                'is_active' => (bool) $branch->is_active,
                'checkin' => [
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                    'radius' => $branch->checkin_radius ?: Branch::DEFAULT_CHECKIN_RADIUS,
                    'work_start' => $branch->workStart(),
                    'work_end' => $branch->workEnd(),
                    'grace' => (int) $branch->late_grace_minutes,
                    'configured' => $branch->hasCheckinLocation(),
                ],
                'users_count' => $branch->users_count,
                'classes_count' => $branch->classes_count,
                'students_count' => $branch->students_count,
            ])->values()->all(),
            'stats' => [
                'total' => $totalBranches,
                'active' => $activeBranches,
                'students' => $totalStudents,
                'classes' => $totalClasses,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:branches,code',
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['is_active'] = $request->has('is_active') ? (bool) $request->input('is_active') : true;

        Audit::describe("Thêm mới cơ sở chi nhánh: {$validated['name']} ({$validated['code']})");
        $branch = Branch::create($validated);

        return redirect()->route('branches.index')
            ->with('status', "Đã thêm cơ sở chi nhánh [{$branch->name}] thành công!");
    }

    public function update(Request $request, $id)
    {
        $branch = Branch::findOrFail($id);

        $validated = $request->validate([
            'code' => "required|string|max:50|unique:branches,code,{$branch->id}",
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['is_active'] = $request->has('is_active') ? (bool) $request->input('is_active') : false;

        Audit::describe("Cập nhật thông tin cơ sở chi nhánh: {$validated['name']} ({$validated['code']})");
        $branch->update($validated);

        return redirect()->route('branches.index')
            ->with('status', "Đã cập nhật cơ sở chi nhánh [{$branch->name}] thành công!");
    }

    /**
     * Cài đặt chấm công của cơ sở: toạ độ, bán kính được chấm (mét), giờ làm việc và số phút cho phép đến muộn.
     * Nhân sự chỉ chấm công trên điện thoại được khi GPS nằm trong bán kính của cơ sở mình làm việc.
     */
    public function updateAttendance(Request $request, $id)
    {
        $branch = Branch::findOrFail($id);

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'checkin_radius' => 'required|integer|min:20|max:5000',
            'work_start_time' => 'required|date_format:H:i',
            'work_end_time' => 'required|date_format:H:i|after:work_start_time',
            'late_grace_minutes' => 'required|integer|min:0|max:240',
        ], [
            'latitude.required' => 'Nhập vĩ độ của cơ sở (hoặc bấm "Lấy vị trí hiện tại" khi đang đứng ở cơ sở).',
            'longitude.required' => 'Nhập kinh độ của cơ sở.',
            'checkin_radius.min' => 'Bán kính tối thiểu 20 m (GPS điện thoại thường lệch 5–20 m).',
            'work_end_time.after' => 'Giờ ra phải sau giờ vào.',
        ], [
            'latitude' => 'vĩ độ',
            'longitude' => 'kinh độ',
            'checkin_radius' => 'bán kính',
            'work_start_time' => 'giờ vào',
            'work_end_time' => 'giờ ra',
            'late_grace_minutes' => 'số phút cho phép muộn',
        ]);

        Audit::describe("Cập nhật cài đặt chấm công cơ sở: {$branch->name}");
        $branch->update($validated);

        return redirect()->route('branches.index')
            ->with('status', "Đã lưu cài đặt chấm công của [{$branch->name}].");
    }

    public function destroy($id)
    {
        $branch = Branch::findOrFail($id);

        $hasClasses = ClassModel::where('branch_id', $branch->id)->exists();
        $hasStudents = Student::where('branch_id', $branch->id)->exists();

        if ($hasClasses || $hasStudents) {
            return redirect()->route('branches.index')
                ->with('error', "Không thể xóa chi nhánh [{$branch->name}] vì đang có lớp học hoặc học viên liên kết. Bạn có thể chuyển trạng thái sang Tạm dừng hoạt động.");
        }

        $branchName = $branch->name;
        Audit::describe("Xóa cơ sở chi nhánh: {$branchName}");
        $branch->delete();

        return redirect()->route('branches.index')
            ->with('status', "Đã xóa chi nhánh {$branchName} thành công!");
    }

    public function toggleStatus($id)
    {
        $branch = Branch::findOrFail($id);
        $branch->is_active = ! $branch->is_active;
        $branch->save();

        $statusText = $branch->is_active ? 'Đang hoạt động' : 'Tạm dừng';

        return redirect()->route('branches.index')
            ->with('status', "Đã chuyển trạng thái chi nhánh [{$branch->name}] sang {$statusText}!");
    }
}
