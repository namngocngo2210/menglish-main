<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Services\Syllabus\SyllabusOrders;
use App\Support\Ui;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Cổng GV — ORDER theo mốc syllabus (docs/kho-syllabus.md §9.3): danh sách mốc order của chặng lớp đang học (đến hạn / đã order),
 * bộ phận đích rõ ràng (vận hành cơ sở / Học thuật), order 1 lần / mốc.
 */
class TeacherOrderController extends Controller
{
    public function __construct(private readonly SyllabusOrders $orders) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $classes = $this->orders->classesOf($user);
        $assignments = SyllabusAssignment::with(['classModel.branch', 'stage'])->open()->whereIn('class_id', $classes->modelKeys())->get()->keyBy('class_id');
        $milestones = $assignments->map(fn (SyllabusAssignment $a) => $this->orders->milestones($a));
        // Mặc định: lớp có mốc đến hạn chưa order.
        $class = $request->filled('class') ? $classes->firstWhere('id', $request->integer('class'))
            : ($classes->first(fn (ClassModel $c) => ($milestones[$c->id] ?? collect())->contains(fn ($m) => $m['due'] && ! $m['ordered'])) ?? $classes->first());
        abort_if($request->filled('class') && ! $class, 404);

        return Inertia::render('Teacher/Orders', [
            'classes' => Ui::options($classes, fn (ClassModel $c) => $c->name.' ('.($milestones[$c->id] ?? collect())->filter(fn ($m) => $m['due'] && ! $m['ordered'])->count().' mốc đến hạn)'),
            'classId' => $class?->id,
            'stage' => $class ? ($assignments[$class->id]?->stage?->label ?? $assignments[$class->id]?->stage_name) : null,
            'milestones' => $class ? ($milestones[$class->id] ?? collect())->map(fn (array $m) => [
                'lesson_id' => $m['lesson']->id,
                'lesson' => 'Buổi '.$m['lesson']->session_no.': '.$m['lesson']->title,
                'kind' => $m['kind'],
                'label' => $m['label'],
                'due' => $m['due'],
                'ordered' => $m['ordered'],
                'target' => $m['target'],
                'use_date' => $m['use_date']?->toDateString(),
                'exam_date' => $m['exam_date']?->toDateString(),
                'due_date' => $m['due_date']?->toDateString(),
            ])->values() : [],
            'storeUrl' => $class ? route('teacher.orders.store', $class->id) : null,
        ]);
    }

    public function store(Request $request, int $class)
    {
        $user = $request->user();
        $classModel = $this->orders->classesOf($user)->firstWhere('id', $class);
        abort_unless($classModel, 404);
        $assignment = SyllabusAssignment::with(['classModel.branch', 'stage'])->open()->where('class_id', $classModel->id)->firstOrFail();
        $validated = $request->validate([
            'lesson_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $code = $this->orders->place($user, $assignment, (int) $validated['lesson_id'], $validated);

        return redirect()->back()->with('status', "Đã gửi order {$code}.");
    }
}
