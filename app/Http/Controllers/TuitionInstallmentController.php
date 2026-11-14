<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Support\TuitionBranchScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;

/**
 * TK-2026-0116: tạo khoản học phí đợt mới (đợt 2, 3…) cho học viên đã có trong lớp. Dùng chung StudentTuition với
 * Closing Wizard: tiền = số buổi × đơn giá, công nợ / phiếu thu vẫn do StudentTuition::recalculateDebt() lo.
 * Quyền riêng `tuition.create_installment` (mặc định chỉ Admin) + phạm vi chi nhánh của học phí.
 */
class TuitionInstallmentController extends Controller
{
    use RendersModals;

    public function create(Request $request, int $student): InertiaResponse
    {
        $model = $this->student($student);
        $scope = TuitionBranchScope::branchIds($request->user());
        $classes = ClassModel::query()
            ->when($scope !== null, fn ($q) => $q->whereIn('branch_id', $scope))
            ->where(fn ($q) => $q->where('id', $model->current_class_id)
                ->orWhereIn('id', StudentTuition::query()->where('student_id', $model->id)->whereNotNull('class_id')->select('class_id')))
            ->orderBy('name')->get();

        return $this->modalPage('Tuition/InstallmentForm', [
            'student' => ['id' => $model->id, 'name' => $model->name, 'code' => $model->code],
            'classes' => $classes->map(fn (ClassModel $c) => ['value' => $c->id, 'label' => trim("{$c->code} {$c->name}")])->values(),
            'classId' => $request->integer('class_id') ?: $model->current_class_id,
        ]);
    }

    public function store(Request $request, int $student): RedirectResponse
    {
        $model = $this->student($student);
        $data = $request->validate([
            'class_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'session_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $class = ClassModel::query()->find($data['class_id']);
        // Lớp ngoài phạm vi chi nhánh của người dùng (hoặc không tồn tại) → từ chối như truy cập trái phạm vi.
        abort_unless($class && TuitionBranchScope::coversBranch($request->user(), $class->branch_id ? (int) $class->branch_id : null), 403);

        $enrolled = (int) $model->current_class_id === (int) $class->id
            || StudentTuition::query()->where('student_id', $model->id)->where('class_id', $class->id)->exists();
        if (! $enrolled) {
            throw ValidationException::withMessages(['class_id' => 'Học viên chưa thuộc lớp này.']);
        }

        $tuition = DB::transaction(function () use ($data, $model, $class, $request) {
            // Khoá các khoản của học viên trong lớp để hai lần bấm đồng thời không tạo đợt trùng.
            $existing = StudentTuition::query()->where('student_id', $model->id)->where('class_id', $class->id)->lockForUpdate()->get();
            if ($existing->contains(fn (StudentTuition $t) => $t->start_date?->toDateString() === $data['start_date'])) {
                throw ValidationException::withMessages(['start_date' => 'Học viên đã có đợt học phí bắt đầu ngày này trong lớp.']);
            }
            // Khoản cũ chưa có ngày (đợt đầu) không tham gia kiểm tra chồng lấn.
            if ($existing->contains(fn (StudentTuition $t) => $t->start_date && $t->end_date
                && $t->start_date->toDateString() <= $data['end_date'] && $t->end_date->toDateString() >= $data['start_date'])) {
                throw ValidationException::withMessages(['start_date' => 'Khoảng thời gian trùng với một đợt học phí khác của học viên trong lớp.']);
            }

            $total = round((float) $data['unit_price'] * (int) $data['session_count'], 2);
            $tuition = StudentTuition::create([
                'student_id' => $model->id,
                'class_id' => $class->id,
                'branch_id' => $class->branch_id ?? $model->branch_id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'session_count' => (int) $data['session_count'],
                'total_amount' => $total,
                'discount_amount' => 0,
                'other_fees' => 0,
                'prepaid_amount' => 0,
                'final_amount' => $total,
                'paid_amount' => 0,
                'debt_amount' => $total,
                'due_date' => $data['start_date'],
                'status' => 'unpaid',
                'notes' => $data['notes'] ?? 'Đợt học phí mới tạo thủ công.',
            ]);

            activity('tuition_installment')->causedBy($request->user())->performedOn($tuition)->withProperties([
                'student_id' => $model->id, 'class_id' => $class->id,
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                'session_count' => (int) $data['session_count'], 'unit_price' => (float) $data['unit_price'], 'total' => $total,
            ])->log("Created tuition installment #{$tuition->id}");

            return $tuition;
        });

        return $this->modalSaved("Đã tạo đợt học phí mới cho {$model->name} (".number_format((float) $tuition->final_amount, 0, ',', '.').' đ).', route('tuition.students'));
    }

    /** Học viên trong phạm vi chi nhánh của người dùng, ngoài phạm vi → 404. */
    private function student(int $id): Student
    {
        return TuitionBranchScope::students(Student::query(), TuitionBranchScope::branchIds(Auth::user()))->findOrFail($id);
    }
}
