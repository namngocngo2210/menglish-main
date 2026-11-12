<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Support\TuitionBranchScope;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Rollup thu học phí theo lớp (chỉ đọc): mỗi học viên một dòng, mỗi khoản học phí của lớp (đợt thu) một cụm cột.
 * Đợt thu thứ N = khoản học phí thứ N (theo id tăng dần) của học viên trong lớp này. Chỉ phiếu đã duyệt được tính.
 */
class TuitionClassRollupController extends Controller
{
    public function index(Request $request)
    {
        $branchIds = TuitionBranchScope::branchIds($request->user());
        $classes = ClassModel::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('name')->get(['id', 'name', 'code']);

        $classId = $request->integer('class_id') ?: null;
        $rows = [];
        $roundCount = 0;
        $total = 0.0;
        $totals = [];

        if ($classId) {
            // Lớp ngoài phạm vi chi nhánh → 403 (không chỉ ẩn khỏi danh sách chọn).
            abort_unless($classes->contains('id', $classId), 403);

            $tuitions = StudentTuition::query()->where('class_id', $classId)->orderBy('id')
                ->with(['receipts' => fn ($q) => $q->where('status', TuitionReceipt::STATUS_APPROVED)->orderBy('id')])
                ->get()->groupBy('student_id');
            $students = Student::query()->where(fn ($q) => $q->whereIn('id', $tuitions->keys())->orWhere('current_class_id', $classId))
                ->orderBy('name')->get(['id', 'name', 'code']);

            foreach ($students as $student) {
                $rounds = [];
                foreach ($tuitions->get($student->id, collect())->values() as $i => $tuition) {
                    $receipts = $tuition->receipts;
                    $amount = (float) $receipts->sum('amount');
                    $rounds[$i] = [
                        'amount' => $amount,
                        // Ghi chú (R-05): phiếu duyệt mới nhất (ngày thu, rồi id); không có phiếu thì ghi chú khoản học phí.
                        'note' => $receipts->sortBy([['payment_date', 'desc'], ['id', 'desc']])->first()?->notes ?: $tuition->notes,
                        'methods' => $receipts->pluck('payment_method')->unique()
                            ->map(fn ($m) => TuitionReceipt::METHOD_LABELS[$m] ?? $m)->implode(', '),
                        'invoices' => $receipts->pluck('invoice_number')->filter()->implode(', '),
                    ];
                    $totals[$i] = ($totals[$i] ?? 0) + $amount;
                    $total += $amount;
                }
                $roundCount = max($roundCount, count($rounds));
                $rows[] = ['id' => $student->id, 'name' => $student->name, 'code' => $student->code, 'rounds' => $rounds];
            }
        }

        return Inertia::render('Tuition/ClassRollup', [
            'classes' => $classes->map(fn ($c) => ['value' => $c->id, 'label' => trim("{$c->code} {$c->name}")])->values(),
            'classId' => $classId,
            'rows' => $rows,
            'roundCount' => $roundCount,
            'totals' => array_map(fn ($i) => $totals[$i] ?? 0, range(0, max($roundCount - 1, 0))),
            'total' => $total,
        ]);
    }
}
