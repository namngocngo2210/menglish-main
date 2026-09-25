<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusDocumentView;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\DocumentCodeGenerator;
use App\Services\SafeUploadService;
use App\Services\ScheduleExtensionService;
use App\Services\SyllabusProgressionService;
use App\Services\ZaloZnsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SyllabusController extends Controller
{
    /** Trạng thái khi không gửi được kết quả Big Test: hồ sơ HV và khách CRM đều không có SĐT phụ huynh. */
    public const MISSING_PARENT_PHONE = 'Thiếu SĐT phụ huynh';

    // ─────────────────────────────────────────────
    // 1. Kho tài liệu giáo trình (file thật, phân quyền xem)
    // ─────────────────────────────────────────────

    public function documents(Request $request)
    {
        $user = $request->user();
        $curriculums = SyllabusCurriculum::with(['course', 'stages'])->orderBy('title')->get();
        $courses = Course::orderBy('name')->get();
        $documents = SyllabusDocument::with(['curriculum', 'uploader', 'stage'])
            ->visibleTo($user)
            ->when($request->filled('curriculum_id'), fn ($q) => $q->where('curriculum_id', $request->integer('curriculum_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('syllabus.documents', compact('curriculums', 'courses', 'documents'));
    }

    public function storeDocument(Request $request)
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id'],
            'title' => ['required', 'string', 'max:255'],
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
            'stage_name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:'.SyllabusDocument::MAX_KB],
            'visible_to_teachers' => ['nullable', 'boolean'],
            'visible_to_assistants' => ['nullable', 'boolean'],
            'downloadable' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'Vui lòng chọn file tài liệu.',
            'file.max' => 'Dung lượng file tối đa 100 MB.',
            'stage_id.exists' => 'Chặng không thuộc giáo trình đã chọn.',
        ]);
        $stage = ! empty($validated['stage_id']) ? SyllabusStage::find($validated['stage_id']) : null;

        $file = $request->file('file');
        $size = (int) $file->getSize();
        $mime = $file->getMimeType();
        $path = SafeUploadService::store($file, SyllabusDocument::DIRECTORY, SyllabusDocument::ALLOWED_EXTENSIONS, 'file', SyllabusDocument::DISK);

        $doc = SyllabusDocument::create([
            'curriculum_id' => $validated['curriculum_id'],
            'title' => $validated['title'],
            'stage_id' => $stage?->id,
            'stage_name' => $stage?->label ?? ($validated['stage_name'] ?? null),
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'visible_to_teachers' => $request->boolean('visible_to_teachers'),
            'visible_to_assistants' => $request->boolean('visible_to_assistants'),
            'downloadable' => $request->boolean('downloadable'),
            'uploaded_by' => Auth::id(),
        ]);

        return redirect()->route('syllabus.documents')
            ->with('status', "Đã tải lên tài liệu {$doc->title} ({$doc->size_human}).");
    }

    public function destroyDocument(int $id)
    {
        $doc = SyllabusDocument::findOrFail($id);
        Storage::disk(SyllabusDocument::DISK)->delete($doc->file_path);
        $doc->delete();

        return redirect()->back()->with('status', "Đã xóa tài liệu {$doc->title}.");
    }

    /** Giáo viên / trợ giảng "Đánh dấu đã xem" tài liệu được chia sẻ. */
    public function markDocumentViewed(Request $request, int $id)
    {
        $doc = SyllabusDocument::findOrFail($id);
        abort_unless($doc->isVisibleTo($request->user()), 404);

        $doc->views()->updateOrCreate(['user_id' => $request->user()->id], ['viewed_at' => now()]);

        return redirect()->route('syllabus.teacher-view', array_filter(['document' => $doc->id, 'class' => $request->input('class')]))
            ->with('status', "Đã đánh dấu đã xem tài liệu {$doc->title}.");
    }

    /**
     * Xem/tải file tài liệu. Người không có quyền tải chỉ được xem trực tuyến (inline).
     */
    public function documentFile(Request $request, int $id)
    {
        $doc = SyllabusDocument::findOrFail($id);
        $user = $request->user();
        abort_unless($doc->isVisibleTo($user), 404);

        $disk = Storage::disk(SyllabusDocument::DISK);
        abort_unless($disk->exists($doc->file_path), 404, 'File tài liệu không còn trên máy chủ.');

        $name = pathinfo($doc->original_name, PATHINFO_FILENAME).'.'.$doc->extension;
        $wantsDownload = $request->boolean('download');
        abort_if($wantsDownload && ! $doc->canDownload($user), 403, 'Tài liệu này chỉ được xem trực tuyến.');

        $headers = ['X-Content-Type-Options' => 'nosniff'];
        if ($wantsDownload) {
            return $disk->download($doc->file_path, $name, $headers);
        }

        // Định dạng không xem được trên trình duyệt (Word/PowerPoint/Excel) chỉ tải về khi được phép.
        abort_unless($doc->inline_viewable || $doc->canDownload($user), 403, 'Định dạng này không xem trực tuyến được và bạn không có quyền tải về.');

        return $disk->response($doc->file_path, $name, $headers + [
            'Content-Disposition' => ($doc->inline_viewable ? 'inline' : 'attachment').'; filename="'.addslashes($name).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ─────────────────────────────────────────────
    // 2. Soạn syllabus: Giáo trình → Chặng → Unit → Buổi (Q4)
    // ─────────────────────────────────────────────

    /**
     * Màn soạn syllabus. Ô soạn thảo (chặng / unit / buổi) mở theo query:
     * new_stage=1, edit_stage={id}, new_unit={stage_id}, edit_unit={id}, new_lesson={unit_id}, edit_lesson={id}.
     */
    public function builder(Request $request)
    {
        $curriculums = SyllabusCurriculum::with('course')->orderBy('title')->get();
        $query = SyllabusCurriculum::with(['stages.units.lessons', 'levels']);
        $curriculum = $request->filled('curriculum')
            ? $query->findOrFail($request->integer('curriculum'))
            : $query->orderBy('title')->first();
        $stages = $curriculum ? $curriculum->stages : collect();
        $units = $stages->flatMap->units;
        $lessons = $units->flatMap->lessons;

        $editor = null;
        if ($curriculum && $request->user()->can('syllabus.manage')) {
            $editor = match (true) {
                $request->boolean('new_stage') => ['type' => 'stage', 'model' => null, 'parent' => $curriculum],
                $request->filled('edit_stage') => ['type' => 'stage', 'model' => $stages->firstWhere('id', $request->integer('edit_stage')), 'parent' => $curriculum],
                $request->filled('new_unit') => ['type' => 'unit', 'model' => null, 'parent' => $stages->firstWhere('id', $request->integer('new_unit'))],
                $request->filled('edit_unit') => ['type' => 'unit', 'model' => $units->firstWhere('id', $request->integer('edit_unit')), 'parent' => null],
                $request->filled('new_lesson') => ['type' => 'lesson', 'model' => null, 'parent' => $units->firstWhere('id', $request->integer('new_lesson'))],
                $request->filled('edit_lesson') => ['type' => 'lesson', 'model' => $lessons->firstWhere('id', $request->integer('edit_lesson')), 'parent' => null],
                default => null,
            };
            // Id không thuộc giáo trình đang mở → bỏ qua ô soạn thảo.
            if ($editor && ! $editor['model'] && ! $editor['parent']) {
                $editor = null;
            }
        }

        $courses = Course::orderBy('name')->get();
        $levels = CourseLevel::orderBy('name')->get(['id', 'code', 'name', 'syllabus_curriculum_id']);
        $openClassesByStage = $curriculum
            ? SyllabusAssignment::open()->where('curriculum_id', $curriculum->id)->with('classModel:id,name')->get()->groupBy('stage_id')
            : collect();

        return view('syllabus.builder', compact(
            'curriculums', 'curriculum', 'stages', 'units', 'lessons', 'editor', 'courses', 'levels', 'openClassesByStage'
        ));
    }

    public function storeCurriculum(Request $request)
    {
        $validated = $request->validate($this->curriculumRules() + [
            'code' => ['required', 'string', 'max:50', 'unique:syllabus_curriculums,code'],
            'stage_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Model tự tạo "Chặng 1" (tên lấy từ ô Chặng học nếu có).
        $curriculum = SyllabusCurriculum::create(collect($validated)->except('level_ids')->all());
        $this->syncCurriculumLevels($curriculum, $validated['level_ids'] ?? null);

        return redirect()->route('syllabus.builder', ['curriculum' => $curriculum->id])
            ->with('status', "Đã tạo giáo trình {$curriculum->title} (kèm Chặng 1).");
    }

    public function updateCurriculum(Request $request, int $id)
    {
        $curriculum = SyllabusCurriculum::findOrFail($id);
        $validated = $request->validate($this->curriculumRules() + [
            'code' => ['required', 'string', 'max:50', Rule::unique('syllabus_curriculums', 'code')->ignore($curriculum->id)],
        ]);

        $curriculum->update(collect($validated)->except('level_ids')->all());
        // Form có khối "Trình độ áp dụng" (levels_submitted) → bỏ chọn hết nghĩa là gỡ giáo trình khỏi mọi trình độ.
        $this->syncCurriculumLevels($curriculum, $request->has('levels_submitted') ? ($validated['level_ids'] ?? []) : ($validated['level_ids'] ?? null));

        return redirect()->route('syllabus.builder', ['curriculum' => $curriculum->id])
            ->with('status', "Đã lưu thông tin giáo trình {$curriculum->title}.");
    }

    public function destroyCurriculum(int $id)
    {
        $curriculum = SyllabusCurriculum::withCount(['assignments' => fn ($q) => $q->open()])->findOrFail($id);
        if ($curriculum->assignments_count > 0) {
            return redirect()->back()->with('error', "Giáo trình {$curriculum->title} đang được lớp học, không thể xóa.");
        }

        foreach ($curriculum->documents()->get() as $doc) {
            Storage::disk(SyllabusDocument::DISK)->delete($doc->file_path);
        }
        $curriculum->delete();

        return redirect()->route('syllabus.builder')->with('status', "Đã xóa giáo trình {$curriculum->title}.");
    }

    private function curriculumRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'version' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'level_ids' => ['nullable', 'array'],
            'level_ids.*' => ['integer', 'exists:course_levels,id'],
        ];
    }

    /** Gắn giáo trình cho Trình độ (course_levels.syllabus_curriculum_id). null = không đổi. */
    private function syncCurriculumLevels(SyllabusCurriculum $curriculum, ?array $levelIds): void
    {
        if ($levelIds === null) {
            return;
        }
        CourseLevel::where('syllabus_curriculum_id', $curriculum->id)->whereNotIn('id', $levelIds)->update(['syllabus_curriculum_id' => null]);
        CourseLevel::whereIn('id', $levelIds)->update(['syllabus_curriculum_id' => $curriculum->id]);
    }

    // ---- Chặng ----

    private function stageRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'overview_link' => ['nullable', 'url', 'max:500'],
            'big_test_title' => ['nullable', 'string', 'max:255'],
            'big_test_note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function storeStage(Request $request)
    {
        $validated = $request->validate($this->stageRules() + [
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id'],
        ]);
        $position = (int) SyllabusStage::where('curriculum_id', $validated['curriculum_id'])->max('position') + 1;
        $stage = SyllabusStage::create($validated + ['position' => $position]);

        return redirect()->route('syllabus.builder', ['curriculum' => $stage->curriculum_id])
            ->with('status', "Đã thêm {$stage->label}.");
    }

    public function updateStage(Request $request, int $id)
    {
        $stage = SyllabusStage::findOrFail($id);
        $stage->update($request->validate($this->stageRules()));

        return redirect()->route('syllabus.builder', ['curriculum' => $stage->curriculum_id])
            ->with('status', "Đã lưu thông tin {$stage->label}.");
    }

    public function destroyStage(int $id)
    {
        $stage = SyllabusStage::withCount(['units', 'assignments', 'bigTests'])->findOrFail($id);
        $error = match (true) {
            SyllabusStage::where('curriculum_id', $stage->curriculum_id)->count() <= 1 => 'Giáo trình phải có ít nhất 1 chặng.',
            $stage->units_count > 0 => "{$stage->label} còn {$stage->units_count} unit — hãy xóa hoặc chuyển unit sang chặng khác trước.",
            $stage->assignments_count > 0 || $stage->big_tests_count > 0 => "{$stage->label} đã được giao cho lớp / gắn Big Test, không thể xóa.",
            default => null,
        };
        if ($error) {
            return redirect()->back()->with('error', $error);
        }

        $stage->delete();
        $this->renumberStages($stage->curriculum_id);

        return redirect()->route('syllabus.builder', ['curriculum' => $stage->curriculum_id])
            ->with('status', "Đã xóa {$stage->label}.");
    }

    /** Đổi thứ tự chặng (lên / xuống 1 bậc). Thứ tự quyết định chặng nào tự mở sau Big Test. */
    public function moveStage(Request $request, int $id)
    {
        $stage = SyllabusStage::findOrFail($id);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($stage, $direction) {
            $ordered = $this->renumberStages($stage->curriculum_id);
            $index = $ordered->search(fn ($s) => $s->id === $stage->id);
            $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! $ordered->has($swapIndex)) {
                return;
            }
            $other = $ordered[$swapIndex];
            $current = $ordered[$index];
            [$a, $b] = [$current->position, $other->position];
            $current->update(['position' => $b]);
            $other->update(['position' => $a]);
        });

        return redirect()->route('syllabus.builder', ['curriculum' => $stage->curriculum_id])
            ->with('status', 'Đã đổi thứ tự chặng.');
    }

    /** Đánh số lại position 1..n theo thứ tự hiện tại. */
    private function renumberStages(int $curriculumId): Collection
    {
        $stages = SyllabusStage::where('curriculum_id', $curriculumId)->orderBy('position')->orderBy('id')->get()->values();
        foreach ($stages as $i => $stage) {
            if ($stage->position !== $i + 1) {
                $stage->update(['position' => $i + 1]);
            }
        }

        return $stages;
    }

    // ---- Unit ----

    public function storeUnit(Request $request)
    {
        $validated = $request->validate($this->unitRules() + [
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id'],
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
        ], ['stage_id.exists' => 'Chặng không thuộc giáo trình đã chọn.']);
        $this->ensureUniqueUnitNumber((int) $validated['curriculum_id'], (int) $validated['unit_number']);
        // Không chọn chặng → đưa vào chặng cuối của giáo trình.
        $validated['stage_id'] ??= SyllabusStage::where('curriculum_id', $validated['curriculum_id'])->orderByDesc('position')->orderByDesc('id')->value('id');

        $unit = SyllabusUnit::create($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã lưu Unit {$unit->unit_number}: {$unit->title}.");
    }

    public function updateUnit(Request $request, int $id)
    {
        $unit = SyllabusUnit::findOrFail($id);
        $validated = $request->validate($this->unitRules() + [
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->where('curriculum_id', $unit->curriculum_id)],
        ], ['stage_id.exists' => 'Chặng không thuộc giáo trình này.']);
        $this->ensureUniqueUnitNumber($unit->curriculum_id, (int) $validated['unit_number'], $unit->id);
        if (empty($validated['stage_id'])) {
            unset($validated['stage_id']);
        }

        $unit->update($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã cập nhật Unit {$unit->unit_number}: {$unit->title}.");
    }

    public function destroyUnit(int $id)
    {
        $unit = SyllabusUnit::withCount('lessons')->findOrFail($id);
        $unit->delete();

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã xóa Unit {$unit->title}".($unit->lessons_count ? " cùng {$unit->lessons_count} buổi." : '.'));
    }

    private function unitRules(): array
    {
        return [
            'unit_number' => ['required', 'integer', 'min:1', 'max:500'],
            'title' => ['required', 'string', 'max:255'],
            'objectives' => ['nullable', 'string'],
            'vocabulary_focus' => ['nullable', 'string'],
            'grammar_focus' => ['nullable', 'string'],
            'homework_guide' => ['nullable', 'string'],
        ];
    }

    private function ensureUniqueUnitNumber(int $curriculumId, int $number, ?int $ignoreId = null): void
    {
        $exists = SyllabusUnit::where('curriculum_id', $curriculumId)
            ->where('unit_number', $number)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['unit_number' => "Unit {$number} đã có trong giáo trình này."]);
        }
    }

    // ---- Buổi ----

    private function lessonRules(): array
    {
        return [
            'session_no' => ['required', 'integer', 'min:1', 'max:1000'],
            'title' => ['required', 'string', 'max:255'],
            'objectives' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'vocabulary_focus' => ['nullable', 'string'],
            'grammar_focus' => ['nullable', 'string'],
            'homework_guide' => ['nullable', 'string'],
        ];
    }

    private function ensureUniqueSessionNo(int $curriculumId, int $number, ?int $ignoreId = null): void
    {
        $exists = SyllabusLesson::where('curriculum_id', $curriculumId)
            ->where('session_no', $number)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['session_no' => "Buổi {$number} đã có trong giáo trình này (số buổi đánh liên tục trong cả giáo trình)."]);
        }
    }

    public function storeLesson(Request $request)
    {
        $validated = $request->validate($this->lessonRules() + [
            'unit_id' => ['required', 'exists:syllabus_units,id'],
        ]);
        $unit = SyllabusUnit::findOrFail($validated['unit_id']);
        $this->ensureUniqueSessionNo($unit->curriculum_id, (int) $validated['session_no']);

        $lesson = SyllabusLesson::create($validated + ['curriculum_id' => $unit->curriculum_id]);

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã lưu Buổi {$lesson->session_no}: {$lesson->title} (Unit {$unit->unit_number}).");
    }

    public function updateLesson(Request $request, int $id)
    {
        $lesson = SyllabusLesson::findOrFail($id);
        $validated = $request->validate($this->lessonRules() + [
            'unit_id' => ['nullable', Rule::exists('syllabus_units', 'id')->where('curriculum_id', $lesson->curriculum_id)],
        ], ['unit_id.exists' => 'Unit không thuộc giáo trình này.']);
        $this->ensureUniqueSessionNo($lesson->curriculum_id, (int) $validated['session_no'], $lesson->id);
        if (empty($validated['unit_id'])) {
            unset($validated['unit_id']);
        }

        $lesson->update($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $lesson->curriculum_id])
            ->with('status', "Đã cập nhật Buổi {$lesson->session_no}: {$lesson->title}.");
    }

    public function destroyLesson(int $id)
    {
        $lesson = SyllabusLesson::findOrFail($id);
        $lesson->delete();

        return redirect()->route('syllabus.builder', ['curriculum' => $lesson->curriculum_id])
            ->with('status', "Đã xóa Buổi {$lesson->session_no}: {$lesson->title}.");
    }

    // ─────────────────────────────────────────────
    // 3. Chặng của lớp: mỗi lớp chỉ 1 chặng đang mở; đóng khi Big Test duyệt & gửi → tự mở chặng kế
    // ─────────────────────────────────────────────

    public function assignments(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $assignments = SyllabusAssignment::with(['teacher', 'curriculum', 'classModel.branch', 'stage', 'closer', 'opener', 'closingBigTest'])
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when(in_array($status, array_keys(SyllabusAssignment::STATUS_LABELS), true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('stage_name', 'like', "%{$search}%")
                ->orWhereHas('teacher', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                ->orWhereHas('classModel', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                ->orWhereHas('curriculum', fn ($c) => $c->where('title', 'like', "%{$search}%"))))
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate($request->perPage(20))
            ->withQueryString();
        $teachers = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['teacher', 'teacher_fulltime', 'teacher_parttime', 'academic_lead']))->orderBy('name')->get();
        $curriculums = SyllabusCurriculum::with('stages')->orderBy('title')->get();
        $classes = ClassModel::where('status', '!=', 'cancelled')->orderBy('name')->get();
        $levelCurriculum = CourseLevel::whereNotNull('syllabus_curriculum_id')->pluck('syllabus_curriculum_id', 'code');
        $openByClass = SyllabusAssignment::open()->whereNotNull('class_id')->with('stage')->get()->keyBy('class_id');

        return view('syllabus.assignments', compact('assignments', 'teachers', 'curriculums', 'classes', 'levelCurriculum', 'openByClass', 'status'));
    }

    /**
     * Mở chặng cho lớp. Mặc định: giáo trình theo Trình độ của lớp, chặng = chặng đầu tiên lớp chưa học xong,
     * GV = GV chính của lớp. Lớp đang mở chặng khác thì từ chối, trừ khi Học thuật chọn "chuyển chặng" kèm lý do.
     */
    public function storeAssignment(Request $request, SyllabusProgressionService $progression)
    {
        $validated = $request->validate([
            'class_id' => ['required', 'exists:classes,id'],
            'curriculum_id' => ['nullable', 'exists:syllabus_curriculums,id'],
            'stage_id' => ['nullable', 'exists:syllabus_stages,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'assigned_chapters' => ['nullable', 'string', 'max:255'],
            'stage_name' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date', 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'replace_current' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $class = ClassModel::findOrFail($validated['class_id']);

        $stage = ! empty($validated['stage_id']) ? SyllabusStage::findOrFail($validated['stage_id']) : null;
        // Giáo trình theo Trình độ của lớp: classes.level là mã trình độ (form tạo lớp); lớp tạo từ nơi khác
        // (CRM / dữ liệu cũ) lưu tên hiển thị → lấy trình độ của khóa học.
        $curriculumId = $validated['curriculum_id'] ?? $stage?->curriculum_id
            ?? CourseLevel::where('code', $class->level)->value('syllabus_curriculum_id')
            ?? $class->course?->level?->syllabus_curriculum_id;
        if (! $curriculumId) {
            throw ValidationException::withMessages(['curriculum_id' => 'Chọn giáo trình (trình độ của lớp chưa gắn giáo trình).']);
        }
        if ($stage && (int) $stage->curriculum_id !== (int) $curriculumId) {
            throw ValidationException::withMessages(['stage_id' => 'Chặng không thuộc giáo trình đã chọn.']);
        }
        $stage ??= $progression->nextStageFor($class, SyllabusCurriculum::findOrFail($curriculumId));
        if (! $stage) {
            throw ValidationException::withMessages(['stage_id' => 'Lớp đã học hết các chặng của giáo trình này.']);
        }

        $attributes = array_filter([
            'assigned_chapters' => $validated['assigned_chapters'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            // "Ngày bắt đầu" (mockup): tiến độ buổi của chặng tính từ ngày này; mặc định hôm nay.
            'opened_at' => ! empty($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : null,
        ]);
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        if ($request->boolean('replace_current') && $progression->openAssignment($class)) {
            abort_unless($request->user()->can('syllabus.approve_adjustment'), 403, 'Chỉ Học thuật được chuyển chặng đang mở.');
            if (! $reason) {
                throw ValidationException::withMessages(['reason' => 'Vui lòng nhập lý do chuyển chặng.']);
            }
            $assignment = $progression->switchTo($class, $stage, $validated['user_id'] ?? null, $request->user(), $reason, $attributes);
        } else {
            $assignment = $progression->open($class, $stage, $validated['user_id'] ?? null, $request->user(), $reason, $attributes);
        }

        AdminNotification::create([
            'user_id' => $assignment->user_id,
            'type' => 'syllabus_stage',
            'title' => "Lớp {$class->name} mở {$assignment->stage_name}",
            'message' => 'Nội dung chặng đã mở trong màn Xem giáo trình.',
            'data' => ['link' => route('syllabus.teacher-view', ['class' => $class->id])],
            'is_read' => false,
        ]);

        return redirect()->route('syllabus.assignments')
            ->with('status', "Đã mở {$assignment->stage_name} cho lớp {$class->name}.");
    }

    /**
     * Chỉnh sửa chặng đang hiệu lực (mockup "Chỉnh sửa"): đổi GV phụ trách / ngày bắt đầu / dự kiến hoàn thành.
     * Không đổi chặng ở đây — đổi chặng đi qua "Chuyển chặng" (Học thuật, bắt buộc lý do).
     */
    public function updateAssignment(Request $request, int $id)
    {
        $assignment = SyllabusAssignment::with('classModel')->findOrFail($id);
        if (! $assignment->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Chặng đã đóng, không chỉnh sửa được.']);
        }
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'start_date' => ['nullable', 'date', 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'deadline' => ['nullable', 'date'],
        ]);

        $previousTeacher = $assignment->user_id;
        $assignment->update([
            'user_id' => $validated['user_id'],
            'deadline' => $validated['deadline'] ?? null,
            'opened_at' => ! empty($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : $assignment->opened_at,
        ]);
        if ((int) $previousTeacher !== (int) $assignment->user_id) {
            $this->notifyUser($assignment->user_id, "Bạn được giao {$assignment->stage_name}", 'Lớp '.$assignment->classModel?->name.': nội dung chặng đã mở trong màn Xem giáo trình.', route('syllabus.teacher-view', ['class' => $assignment->class_id]));
        }

        return redirect()->route('syllabus.assignments')
            ->with('status', "Đã cập nhật {$assignment->stage_name} của lớp {$assignment->classModel?->name}.");
    }

    /** Học thuật đóng tay chặng đang mở (bắt buộc lý do); mặc định tự mở chặng kế tiếp. */
    public function closeAssignment(Request $request, int $id, SyllabusProgressionService $progression)
    {
        $assignment = SyllabusAssignment::findOrFail($id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']], [
            'reason.required' => 'Vui lòng nhập lý do đóng chặng.',
        ]);

        $outcome = $progression->close($assignment, $request->user(), 'Học thuật đóng tay: '.$validated['reason'], null, $request->boolean('open_next'));

        return redirect()->back()->with('status', trim($progression->describe($outcome)));
    }

    // ─────────────────────────────────────────────
    // 4. Đề xuất sửa giáo trình (GV gửi → Học thuật duyệt)
    // ─────────────────────────────────────────────

    /**
     * Màn "Duyệt đề xuất sửa giáo trình" / chi tiết đề xuất. Người duyệt thấy mọi đề xuất,
     * giáo viên chỉ thấy đề xuất của mình (không có nút duyệt).
     */
    public function versions(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status');
        $proposals = SyllabusChangeProposal::with(['curriculum', 'unit', 'lesson', 'proposer'])
            ->visibleTo($user)
            ->when(in_array($status, array_keys(SyllabusChangeProposal::STATUS_LABELS), true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        $selected = null;
        if ($request->filled('proposal')) {
            $selected = SyllabusChangeProposal::with(['curriculum', 'unit', 'lesson', 'proposer.roles', 'reviewer'])->findOrFail($request->integer('proposal'));
            abort_unless($selected->isVisibleTo($user), 404);
        } else {
            $selected = $proposals->first()?->load(['reviewer', 'proposer.roles']);
        }

        $pendingCount = SyllabusChangeProposal::visibleTo($user)->where('status', 'pending')->count();

        return view('syllabus.versions', compact('proposals', 'selected', 'pendingCount', 'status'));
    }

    public function teacherPropose(Request $request)
    {
        $curriculums = SyllabusCurriculum::with(['lessons.unit'])->orderBy('title')->get();
        $status = $request->query('status');
        $proposals = SyllabusChangeProposal::with(['curriculum', 'unit', 'lesson'])
            ->where('user_id', $request->user()->id)
            ->when(in_array($status, array_keys(SyllabusChangeProposal::STATUS_LABELS), true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate($request->perPage(10))
            ->withQueryString();

        return view('syllabus.teacher-propose', compact('curriculums', 'proposals', 'status'));
    }

    public function storeProposal(Request $request)
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id'],
            'unit_id' => ['nullable', Rule::exists('syllabus_units', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
            'lesson_id' => ['nullable', Rule::exists('syllabus_lessons', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
            'proposal_type' => ['nullable', 'string', 'max:255'],
            'old_content' => ['nullable', 'string', 'max:5000'],
            'new_content' => ['required', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:20480'],
        ], [
            'unit_id.exists' => 'Unit không thuộc giáo trình đã chọn.',
            'lesson_id.exists' => 'Buổi học không thuộc giáo trình đã chọn.',
            'new_content.required' => 'Vui lòng nhập mô tả thay đổi đề xuất.',
        ]);
        // Chọn buổi học → gắn luôn Unit của buổi đó.
        if (! empty($validated['lesson_id'])) {
            $validated['unit_id'] = SyllabusLesson::whereKey($validated['lesson_id'])->value('unit_id');
        }

        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = SafeUploadService::store(
                $file,
                SyllabusChangeProposal::ATTACHMENT_DIRECTORY,
                [...SafeUploadService::DOCUMENTS, ...SafeUploadService::IMAGES, 'mp3', 'wav', 'm4a'],
                'attachment',
                SyllabusChangeProposal::ATTACHMENT_DISK
            );
            $attachmentName = mb_substr($file->getClientOriginalName(), 0, 255);
        }

        $proposal = SyllabusChangeProposal::create(collect($validated)->except('attachment')->all() + [
            'user_id' => Auth::id(),
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'status' => 'pending',
        ]);

        AdminNotification::create([
            'type' => 'syllabus_proposal',
            'title' => 'Đề xuất sửa giáo trình mới',
            'message' => Auth::user()->name.' đề xuất sửa giáo trình '.$proposal->curriculum?->title.' — '.$proposal->target_label.'.',
            'data' => ['link' => route('syllabus.versions', ['proposal' => $proposal->id])],
            'is_read' => false,
        ]);

        return redirect()->route('syllabus.teacher-propose')
            ->with('status', 'Đã gửi đề xuất sửa giáo trình tới Ban Học thuật.');
    }

    public function approveProposal(Request $request, int $id)
    {
        $proposal = SyllabusChangeProposal::findOrFail($id);
        $validated = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);
        $this->ensurePending($proposal->status);

        $proposal->update([
            'status' => 'approved',
            'reviewer_id' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);
        $this->notifyUser($proposal->user_id, 'Đề xuất sửa giáo trình đã được duyệt', 'Đề xuất sửa '.$proposal->curriculum?->title.' đã được Học thuật phê duyệt.', route('syllabus.versions', ['proposal' => $proposal->id]));

        return redirect()->route('syllabus.versions', ['proposal' => $proposal->id])
            ->with('status', 'Đã phê duyệt đề xuất sửa giáo trình.');
    }

    public function rejectProposal(Request $request, int $id)
    {
        $proposal = SyllabusChangeProposal::findOrFail($id);
        $validated = $request->validate(['review_note' => ['required', 'string', 'max:2000']], [
            'review_note.required' => 'Vui lòng nhập lý do từ chối.',
        ]);
        $this->ensurePending($proposal->status);

        $proposal->update([
            'status' => 'rejected',
            'reviewer_id' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'],
        ]);
        $this->notifyUser($proposal->user_id, 'Đề xuất sửa giáo trình bị từ chối', 'Lý do: '.$validated['review_note'], route('syllabus.versions', ['proposal' => $proposal->id]));

        return redirect()->route('syllabus.versions', ['proposal' => $proposal->id])
            ->with('status', 'Đã từ chối đề xuất sửa giáo trình.');
    }

    public function proposalAttachment(Request $request, int $id)
    {
        $proposal = SyllabusChangeProposal::findOrFail($id);
        abort_unless($proposal->isVisibleTo($request->user()) && $proposal->attachment_path, 404);
        $disk = Storage::disk(SyllabusChangeProposal::ATTACHMENT_DISK);
        abort_unless($disk->exists($proposal->attachment_path), 404);

        $name = pathinfo((string) $proposal->attachment_name, PATHINFO_FILENAME).'.'.pathinfo($proposal->attachment_path, PATHINFO_EXTENSION);

        return $disk->download($proposal->attachment_path, $name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Màn GV xem giáo trình: tài liệu được chia sẻ + chặng đang mở của lớp mình
     * (các chặng của giáo trình, Unit / Buổi của chặng hiện tại, buổi đang dạy).
     */
    public function teacherView(Request $request, SyllabusProgressionService $progression)
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $visible = SyllabusDocument::with(['curriculum.course', 'stage'])->visibleTo($user)->latest()->get();
        $documents = $search === '' ? $visible : $visible->filter(fn ($d) => str_contains(mb_strtolower($d->title.' '.$d->original_name), mb_strtolower($search)))->values();
        $selected = $request->filled('document')
            ? $visible->firstWhere('id', $request->integer('document'))
            : $documents->first();
        abort_if($request->filled('document') && ! $selected, 404);
        $viewedIds = SyllabusDocumentView::where('user_id', $user->id)->pluck('document_id');

        // Lớp người dùng được xem và đang có chặng mở.
        $classes = ClassModel::visibleTo($user)
            ->whereIn('id', SyllabusAssignment::open()->whereNotNull('class_id')->select('class_id'))
            ->orderBy('name')
            ->get();
        $class = $request->filled('class') ? $classes->firstWhere('id', $request->integer('class')) : $classes->first();
        abort_if($request->filled('class') && ! $class, 404);

        $assignment = $class ? $progression->openAssignment($class) : null;
        $position = $assignment ? $progression->position($assignment) : null;
        $stages = $assignment?->curriculum ? $assignment->curriculum->stages()->with('units.lessons')->get() : collect();
        $closedStageIds = $class
            ? SyllabusAssignment::where('class_id', $class->id)->where('status', SyllabusAssignment::STATUS_CLOSED)->pluck('stage_id')->filter()
            : collect();

        // Tab "Tổng quan syllabus": các chặng của giáo trình lớp đang học, hoặc của tài liệu đang xem.
        $overviewCurriculum = $assignment?->curriculum ?? $selected?->curriculum;
        $overviewStages = $stages->isNotEmpty() ? $stages : ($overviewCurriculum ? $overviewCurriculum->stages()->with('units.lessons')->get() : collect());

        return view('syllabus.teacher-view', compact(
            'documents', 'selected', 'classes', 'class', 'assignment', 'position', 'stages', 'closedStageIds',
            'search', 'viewedIds', 'overviewCurriculum', 'overviewStages'
        ));
    }

    // ─────────────────────────────────────────────
    // 5. Điều chỉnh (giãn) tiến độ
    // ─────────────────────────────────────────────

    public function teacherAdjust(Request $request)
    {
        $user = $request->user();
        // Mockup: chỉ chọn được lớp đang có chặng mở (giãn tiến độ cho chặng đang học).
        $openAssignments = SyllabusAssignment::open()->with('stage')
            ->whereIn('class_id', ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->select('id'))
            ->get()->keyBy('class_id');
        $classes = ClassModel::whereIn('id', $openAssignments->keys())->orderBy('name')->get();
        $requests = SyllabusAdjustmentRequest::with(['classModel', 'teacher', 'assignment'])
            ->when(! $user->can('syllabus.approve_adjustment'), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('syllabus.teacher-adjust', compact('classes', 'requests', 'openAssignments'));
    }

    public function adjustmentRequests(Request $request)
    {
        $user = $request->user();
        $canReview = $user->can('syllabus.approve_adjustment');
        // Mockup "Danh sách chờ duyệt": người duyệt mặc định lọc yêu cầu chờ duyệt; "all" = tất cả.
        $status = $request->query('status', $canReview ? 'pending' : 'all');
        $scoped = SyllabusAdjustmentRequest::query()->when(! $canReview, fn ($q) => $q->where('user_id', $user->id));
        $pendingCount = (clone $scoped)->where('status', 'pending')->count();
        $requests = (clone $scoped)->with(['classModel', 'teacher', 'approver', 'assignment'])
            ->when(array_key_exists($status, SyllabusAdjustmentRequest::STATUS_LABELS), fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();
        $selected = $request->filled('request')
            ? SyllabusAdjustmentRequest::with(['classModel', 'teacher', 'approver', 'assignment'])->findOrFail($request->integer('request'))
            : $requests->first();
        abort_if($selected && ! $canReview && (int) $selected->user_id !== (int) $user->id, 404);

        return view('syllabus.adjustment-requests', compact('requests', 'selected', 'status', 'pendingCount'));
    }

    public function storeAdjustmentRequest(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'request_type' => 'nullable|string|max:255',
            'reason' => 'required|string|max:2000',
            'extra_sessions' => 'nullable|integer|min:0|max:'.SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS,
        ]);
        $extra = (int) ($validated['extra_sessions'] ?? 0);
        // Mockup không có ô "loại điều chỉnh": mặc định là giãn tiến độ N buổi.
        $validated['request_type'] = trim((string) ($validated['request_type'] ?? '')) ?: ($extra > 0 ? "Xin giãn tiến độ thêm {$extra} buổi" : 'Xin điều chỉnh tiến độ');

        $user = $request->user();
        $class = ClassModel::findOrFail($validated['class_id']);
        abort_unless(ClassModel::visibleTo($user)->whereKey($class->id)->exists(), 403, 'Bạn không phụ trách lớp này.');

        SyllabusAdjustmentRequest::create([
            'class_id' => $class->id,
            // Gắn chặng đang mở để biết giãn tiến độ cho chặng nào.
            'syllabus_assignment_id' => SyllabusAssignment::open()->where('class_id', $class->id)->value('id'),
            'user_id' => $user->id,
            'request_type' => $validated['request_type'],
            'reason' => $validated['reason'],
            'extra_sessions' => $validated['extra_sessions'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()->route('syllabus.adjustment-requests')
            ->with('status', 'Đã gửi yêu cầu điều chỉnh tiến độ giáo trình!');
    }

    /**
     * Duyệt giãn tiến độ: nếu yêu cầu có số buổi cần thêm, thêm đúng N buổi nối tiếp theo TKB
     * của lớp (bỏ ngày nghỉ, chặn trùng lịch) và lùi ngày kết thúc lớp. Không thêm được thì không duyệt.
     */
    public function approveAdjustmentRequest(Request $request, $id, ScheduleExtensionService $extension)
    {
        $req = SyllabusAdjustmentRequest::with('classModel')->findOrFail($id);
        $this->ensurePending($req->status);
        $validated = $request->validate([
            'extra_sessions' => 'nullable|integer|min:0|max:'.SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS,
        ]);
        $count = (int) ($validated['extra_sessions'] ?? $req->extra_sessions ?? 0);

        $appliedNote = 'Không thay đổi lịch học (yêu cầu không kèm số buổi cần thêm).';
        DB::transaction(function () use ($req, $count, $extension, &$appliedNote) {
            if ($count > 0) {
                $created = $extension->extend($req->classModel, $count, "Giãn tiến độ theo yêu cầu #{$req->id}");
                $appliedNote = 'Đã thêm '.count($created).' buổi: '
                    .collect($created)->map(fn ($s) => $s->date->format('d/m/Y'))->implode(', ')
                    .'. Ngày kết thúc lớp: '.$req->classModel->fresh()->end_date?->format('d/m/Y').'.';

                // Chặng đang mở được cộng thêm số buổi giãn (buổi thêm dùng để dạy chậm lại / ôn trước Big Test).
                $assignment = SyllabusAssignment::open()->where('class_id', $req->class_id)->first();
                if ($assignment) {
                    $assignment->increment('extra_sessions', count($created));
                    $req->syllabus_assignment_id ??= $assignment->id;
                    $appliedNote .= " {$assignment->stage_name}: +".count($created).' buổi giãn tiến độ.';
                }
            }

            $req->update([
                'status' => 'approved',
                'syllabus_assignment_id' => $req->syllabus_assignment_id,
                'approver_id' => Auth::id(),
                'extra_sessions' => $count ?: $req->extra_sessions,
                'reviewed_at' => now(),
                'rejection_reason' => null,
                'applied_note' => $appliedNote,
            ]);
        });

        $this->notifyUser($req->user_id, 'Yêu cầu giãn tiến độ đã được duyệt', "Lớp {$req->classModel?->name}: {$appliedNote}", route('syllabus.adjustment-requests', ['request' => $req->id]));

        return redirect()->route('syllabus.adjustment-requests', ['request' => $req->id])
            ->with('status', "Đã phê duyệt yêu cầu điều chỉnh tiến độ cho lớp {$req->classModel?->name}. {$appliedNote}");
    }

    public function rejectAdjustmentRequest(Request $request, $id)
    {
        $req = SyllabusAdjustmentRequest::with('classModel')->findOrFail($id);
        $this->ensurePending($req->status);
        $validated = $request->validate(['rejection_reason' => 'required|string|max:2000'], [
            'rejection_reason.required' => 'Vui lòng nhập lý do từ chối.',
        ]);

        $req->update([
            'status' => 'rejected',
            'approver_id' => Auth::id(),
            'reviewed_at' => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);
        $this->notifyUser($req->user_id, 'Yêu cầu giãn tiến độ bị từ chối', "Lớp {$req->classModel?->name} — Lý do: {$validated['rejection_reason']}", route('syllabus.adjustment-requests', ['request' => $req->id]));

        return redirect()->route('syllabus.adjustment-requests', ['request' => $req->id])
            ->with('status', "Đã từ chối yêu cầu điều chỉnh tiến độ cho lớp {$req->classModel?->name}!");
    }

    private function ensurePending(string $status): void
    {
        if ($status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Yêu cầu này đã được xử lý trước đó.']);
        }
    }

    private function notifyUser(?int $userId, string $title, string $message, ?string $link = null): void
    {
        if (! $userId) {
            return;
        }

        AdminNotification::create([
            'user_id' => $userId,
            'type' => 'syllabus_review',
            'title' => $title,
            'message' => $message,
            'data' => $link ? ['link' => $link] : null,
            'is_read' => false,
        ]);
    }

    // ─────────────────────────────────────────────
    // 6. Big Test: order đề, duyệt & phân phối, nhắc lịch, kết quả
    // ─────────────────────────────────────────────

    /**
     * Cổng GV — "Chặng đang dạy & Order Test": mỗi lớp mình dạy đang mở chặng là một thẻ (chặng, ngày bắt đầu,
     * vai trò, trạng thái order đề Big Test của chặng).
     */
    public function teachingStages(Request $request)
    {
        $user = $request->user();
        $assignments = SyllabusAssignment::open()
            ->with(['stage', 'classModel.course'])
            ->whereIn('class_id', ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->select('id'))
            ->get()
            ->sortBy(fn ($a) => $a->classModel?->name)
            ->values();
        $plans = $this->stageExamPlans($assignments);

        return view('syllabus.teaching-stages', compact('assignments', 'plans'));
    }

    /**
     * Kế hoạch Big Test cuối chặng của từng chặng đang mở: ngày thi (đợt Big Test của chặng → ngày dự kiến GV đặt →
     * ngày thi trong order), order đề mới nhất và trạng thái đề (đã duyệt / chờ duyệt / chưa order).
     *
     * @return Collection<int, array{date: ?\Carbon\CarbonInterface, order: ?BigTestOrder, bigTest: ?BigTest, exam: string}>
     */
    private function stageExamPlans(Collection $assignments): Collection
    {
        $classIds = $assignments->pluck('class_id')->filter()->unique();
        $orders = BigTestOrder::where('test_type', 'big')->whereIn('class_id', $classIds)->latest('id')->get()->groupBy('class_id');
        $tests = BigTest::whereIn('class_id', $classIds)->whereNotNull('syllabus_stage_id')->latest('scheduled_at')->get()->groupBy('class_id');

        return $assignments->mapWithKeys(function (SyllabusAssignment $as) use ($orders, $tests) {
            $label = $as->stage?->label ?? $as->stage_name;
            $order = ($orders[$as->class_id] ?? collect())->first(fn ($o) => $as->stage_id && $o->syllabus_stage_id
                ? (int) $o->syllabus_stage_id === (int) $as->stage_id
                : $o->stage_name === $label);
            $bigTest = ($tests[$as->class_id] ?? collect())->first(fn ($t) => (int) $t->syllabus_stage_id === (int) $as->stage_id);
            $approved = ($bigTest && $bigTest->is_distributed) || $order?->status === 'approved';

            return [$as->id => [
                'date' => $bigTest?->scheduled_at ?? $as->expected_big_test_date ?? $order?->exam_date,
                'order' => $order,
                'bigTest' => $bigTest,
                'exam' => $approved ? 'approved' : ($order?->status === 'pending' ? 'pending' : 'none'),
            ]];
        });
    }

    /**
     * GV chính (hoặc GVNN) đặt / sửa ngày dự kiến Big Test cuối chặng đang mở; trợ giảng chỉ xem.
     * Học thuật (syllabus.approve_adjustment) cũng sửa được.
     */
    public function updateExpectedBigTestDate(Request $request, int $id)
    {
        $assignment = SyllabusAssignment::with('classModel')->findOrFail($id);
        $user = $request->user();
        $class = $assignment->classModel;
        $isMainTeacher = $class && in_array((int) $user->id, [(int) $class->teacher_id, (int) $class->foreign_teacher_id], true);
        abort_unless($isMainTeacher || $user->can('syllabus.approve_adjustment'), 403, 'Chỉ giáo viên chính của lớp được đặt lịch dự kiến Big Test.');
        if (! $assignment->isOpen()) {
            throw ValidationException::withMessages(['expected_big_test_date' => 'Chặng đã đóng.']);
        }
        $validated = $request->validate([
            'expected_big_test_date' => ['required', 'date', 'after_or_equal:today'],
        ], ['expected_big_test_date.required' => 'Vui lòng chọn ngày dự kiến Big Test.']);

        $assignment->update(['expected_big_test_date' => $validated['expected_big_test_date']]);

        return redirect()->back()->with('status', "Đã lưu ngày dự kiến Big Test {$assignment->stage_name} lớp {$class?->name}: ".$assignment->expected_big_test_date->format('d/m/Y').'.');
    }

    public function bigTestDistribution(Request $request)
    {
        $user = $request->user();
        $classes = ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->orderBy('name')->get();
        $bigTests = BigTest::with(['classModel', 'proctor', 'stage'])->visibleTo($user)->latest()->paginate($request->perPage(15), ['*'], 'tests_page')->withQueryString();

        $orderStatus = $request->query('order_status', 'pending');
        $orderSearch = trim((string) $request->query('order_search', ''));
        $orders = BigTestOrder::with(['classModel', 'teacher', 'reviewer', 'stage'])
            ->visibleTo($user)
            ->when(in_array($orderStatus, array_keys(BigTestOrder::STATUS_LABELS), true), fn ($q) => $q->where('status', $orderStatus))
            ->when($orderSearch !== '', fn ($q) => $q->whereHas('classModel', fn ($c) => $c->where('name', 'like', "%{$orderSearch}%")->orWhere('code', 'like', "%{$orderSearch}%")))
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest()
            ->paginate($request->perPage(10), ['*'], 'orders_page')
            ->withQueryString();
        $selectedOrder = $request->filled('order')
            ? BigTestOrder::with(['classModel.course', 'classModel.branch', 'teacher', 'reviewer', 'stage'])->visibleTo($user)->findOrFail($request->integer('order'))
            : $orders->first()?->load(['classModel.course', 'classModel.branch']);
        $pendingOrders = BigTestOrder::visibleTo($user)->where('status', 'pending')->count();

        // "Gắn chặng" cho đợt thi: chặng của các giáo trình lớp đã/đang học (hoặc giáo trình theo trình độ).
        $stageOptions = $bigTests->getCollection()->pluck('class_id')->filter()->unique()
            ->mapWithKeys(fn ($classId) => [$classId => $this->stageOptionsForClass((int) $classId)->pluck('label', 'id')]);

        return view('syllabus.big-tests-distribution', compact('classes', 'bigTests', 'orders', 'selectedOrder', 'orderStatus', 'orderSearch', 'pendingOrders', 'stageOptions'));
    }

    /** Các chặng có thể gắn cho đợt thi của lớp: giáo trình của các lượt chặng của lớp + giáo trình theo trình độ lớp. */
    private function stageOptionsForClass(int $classId): Collection
    {
        $class = ClassModel::find($classId);
        $curriculumIds = SyllabusAssignment::where('class_id', $classId)->pluck('curriculum_id')
            ->push($class ? CourseLevel::where('code', $class->level)->value('syllabus_curriculum_id') : null)
            ->filter()->unique();

        return SyllabusStage::whereIn('curriculum_id', $curriculumIds)->orderBy('curriculum_id')->orderBy('position')->get();
    }

    /**
     * Gắn lại chặng cho một đợt Big Test đã tạo (đợt thi cũ trước Q4 chưa gắn chặng, hoặc gắn nhầm).
     * Nếu đợt thi đã duyệt & gửi đủ và chặng là chặng đang mở của lớp → đóng chặng, mở chặng kế (như luồng thường).
     */
    public function assignBigTestStage(Request $request, int $id, SyllabusProgressionService $progression)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        $validated = $request->validate(['syllabus_stage_id' => ['nullable', 'integer']]);
        $stageId = $validated['syllabus_stage_id'] ?? null;
        if ($stageId && ! $this->stageOptionsForClass((int) $test->class_id)->contains('id', (int) $stageId)) {
            throw ValidationException::withMessages(['syllabus_stage_id' => 'Chặng không thuộc giáo trình của lớp thi.']);
        }

        $test->update(['syllabus_stage_id' => $stageId]);
        $label = $stageId ? SyllabusStage::find($stageId)?->label : null;
        $note = $stageId ? $progression->describe($progression->syncBigTest($test->fresh(), $request->user())) : '';

        return redirect()->back()->with('status', $label
            ? "Đã gắn đợt thi {$test->code} vào {$label}.".$note
            : "Đã bỏ gắn chặng của đợt thi {$test->code}.");
    }

    public function approveBigTestOrder(Request $request, int $id)
    {
        $order = BigTestOrder::with('classModel')->findOrFail($id);
        $this->ensurePending($order->status);
        $validated = $request->validate([
            'test_link' => ['required', 'url', 'max:500'],
            'big_test_id' => ['nullable', Rule::exists('big_tests', 'id')->where('class_id', $order->class_id)],
        ], [
            'test_link.required' => 'Vui lòng nhập link đề trước khi phê duyệt.',
            'big_test_id.exists' => 'Đợt Big Test không thuộc lớp của order.',
        ]);

        $order->update([
            'status' => 'approved',
            'test_link' => $validated['test_link'],
            'big_test_id' => $validated['big_test_id'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);
        if ($order->big_test_id) {
            BigTest::whereKey($order->big_test_id)->whereNull('content_url')->update(['content_url' => $order->test_link]);
            // Đợt thi chưa gắn chặng → gắn chặng của order (Big Test cuối chặng).
            if ($order->syllabus_stage_id) {
                BigTest::whereKey($order->big_test_id)->whereNull('syllabus_stage_id')->update(['syllabus_stage_id' => $order->syllabus_stage_id]);
            }
            // "Duyệt & phân phối đề" cho đợt thi đã gắn: đợt thi được phân phối luôn (trước đây vẫn ở nháp nên GV
            // không nhập được kết quả dù order đã duyệt).
            BigTest::whereKey($order->big_test_id)->where('is_distributed', false)->update([
                'status' => 'distributed',
                'is_distributed' => true,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'distributed_at' => now(),
            ]);
        }
        $this->notifyUser($order->teacher_id, 'Order đề đã được duyệt', "Đề {$order->type_label} chặng \"{$order->stage_name}\" lớp {$order->classModel?->name} đã được phân phối.", route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'approved']));

        return redirect()->route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'approved'])
            ->with('status', "Đã duyệt và phân phối đề cho order {$order->code}.");
    }

    public function rejectBigTestOrder(Request $request, int $id)
    {
        $order = BigTestOrder::with('classModel')->findOrFail($id);
        $this->ensurePending($order->status);
        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']], [
            'rejection_reason.required' => 'Vui lòng nhập lý do từ chối.',
        ]);

        $order->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);
        $this->notifyUser($order->teacher_id, 'Order đề bị từ chối', "Order {$order->code} lớp {$order->classModel?->name} — Lý do: {$validated['rejection_reason']}", route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'rejected']));

        return redirect()->route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'rejected'])
            ->with('status', "Đã từ chối order {$order->code}.");
    }

    public function storeBigTest(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'class_id' => 'required|exists:classes,id',
            'test_type' => 'required|string',
            'scheduled_at' => 'required|date',
            'room' => 'required|string',
            'syllabus_stage_id' => 'nullable|exists:syllabus_stages,id',
        ]);
        // Big Test cuối chặng: mặc định gắn chặng đang mở của lớp.
        $validated['syllabus_stage_id'] ??= SyllabusAssignment::open()->where('class_id', $validated['class_id'])->value('stage_id');

        $code = app(DocumentCodeGenerator::class)->bigTestCode();

        $bt = BigTest::create($validated + [
            'code' => $code,
            'proctor_id' => Auth::id(),
            'passcode' => 'MEN'.rand(1000, 9999),
            'is_distributed' => false,
            'status' => 'draft',
        ]);

        return redirect()->route('syllabus.big-tests.distribution')
            ->with('status', "Đã tạo bản nháp Big Test {$bt->title} ({$bt->code}); cần duyệt trước khi phân phối.");
    }

    public function approveAndDistributeBigTest(int $id)
    {
        $test = BigTest::findOrFail($id);
        $test->update([
            'status' => 'distributed',
            'is_distributed' => true,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'distributed_at' => now(),
        ]);

        return redirect()->back()->with('status', "Đã duyệt và phân phối đề {$test->code}.");
    }

    public function bigTestSchedules(Request $request)
    {
        $user = $request->user();
        $bigTests = BigTest::with(['classModel.branch', 'proctor'])->visibleTo($user)->latest()->paginate($request->perPage(20))->withQueryString();

        // Mockup "Nhắc lịch Big Test": chặng đang mở sắp đến hạn thi Big Test (trong 7 ngày) mà đề chưa được duyệt.
        $openAssignments = SyllabusAssignment::open()->with(['stage', 'classModel'])
            ->whereIn('class_id', ClassModel::visibleTo($user)->select('id'))
            ->get();
        $plans = $this->stageExamPlans($openAssignments);
        $upcoming = $openAssignments
            ->map(fn ($as) => ['assignment' => $as] + $plans[$as->id])
            ->filter(fn ($row) => $row['date'] && $row['exam'] !== 'approved'
                && $row['date']->copy()->startOfDay()->betweenIncluded(today(), today()->addDays(7)))
            ->map(fn ($row) => $row + ['days_left' => (int) today()->diffInDays($row['date']->copy()->startOfDay())])
            ->sortBy('days_left')
            ->values();

        return view('syllabus.big-test-schedules', compact('bigTests', 'upcoming'));
    }

    /**
     * Gửi nhắc lịch thi Big Test tới toàn bộ học viên của lớp qua Cổng PH/HS
     * (inbox thông báo) và ghi log AdminNotification cho học vụ.
     */
    public function sendBigTestReminder(Request $request, int $id)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        abort_unless($test->classModel, 422, 'Đợt thi chưa gắn lớp học.');
        abort_unless($test->scheduled_at, 422, 'Đợt thi chưa có lịch giờ thi.');

        $students = $test->classModel->students()
            ->whereIn('status', ['studying', 'deferred'])
            ->get();

        $when = $test->scheduled_at->format('H:i d/m/Y');
        $sent = 0;
        foreach ($students as $student) {
            AcademicRecord::firstOrCreate(
                [
                    'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao',
                    'record_code' => 'BIGTEST-REMIND-'.$test->id.'-'.$student->id,
                ],
                [
                    'module' => 'student_portal',
                    'title' => 'Nhắc lịch Big Test: '.$test->title,
                    'status' => 'active',
                    'data' => [
                        'type' => 'big_test_reminder',
                        'title' => 'Nhắc lịch thi: '.$test->title,
                        'content' => 'Học viên '.$student->name.' có bài thi "'.$test->title.'" lúc '.$when.' tại phòng '.$test->room.'. Vui lòng có mặt sớm 15 phút.',
                        'unread' => true,
                        'icon' => 'alarm',
                        'bg_color' => 'bg-purple-100',
                        'text_color' => 'text-purple-600',
                        'student_id' => $student->id,
                        'created_at' => now()->toDateTimeString(),
                    ],
                    'user_id' => $student->user_id,
                ]
            );
            $sent++;
        }

        AdminNotification::create([
            'title' => 'Đã gửi nhắc lịch Big Test: '.$test->title,
            'message' => "Đợt thi [{$test->code}] {$test->title} lúc {$when} — đã gửi nhắc tới {$sent} học viên của lớp {$test->classModel->name}.",
            'type' => 'info',
            'is_read' => false,
        ]);

        return redirect()->back()
            ->with('status', "Đã gửi nhắc lịch {$test->title} tới {$sent} học viên của lớp {$test->classModel->name}.");
    }

    public function bigTestResults(Request $request, $id = null)
    {
        $user = $request->user();
        $testId = $id ?: $request->query('test_id');
        $allTests = BigTest::with('classModel')->visibleTo($user)->latest()->get();

        if ($testId) {
            $test = BigTest::with(['classModel.teacher', 'stage'])->find($testId);
            abort_unless($test && $test->isAccessibleBy($user), 404);
        } else {
            $test = $allTests->first()?->load(['classModel.teacher', 'stage']);
        }

        $results = $test ? BigTestResult::with(['student', 'grader', 'approver'])->where('big_test_id', $test->id)->get() : collect();
        // Mockup "Duyệt kết quả Big Test & gửi phụ huynh": khung xét duyệt từng học viên (?result=).
        $selectedResult = $request->filled('result') ? $results->firstWhere('id', $request->integer('result')) : null;
        abort_if($request->filled('result') && ! $selectedResult, 404);
        // Danh sách lớp thật (gồm học viên liên kết lớp khác) + học viên đã có kết quả.
        $students = $test?->classModel
            ? $test->classModel->rosterStudents()->concat(Student::whereIn('id', $results->pluck('student_id'))
                ->whereNotIn('id', $test->classModel->roster()->pluck('id'))->orderBy('name')->get())
            : collect();

        // Học viên chưa gửi được kết quả vì không có SĐT phụ huynh (hồ sơ HV → khách CRM).
        $missingParentPhone = $results->filter(fn ($r) => ! $r->parent_notified && ! $r->is_absent && $r->student && ! $r->student->parentContactPhone())
            ->pluck('student_id')->map(fn ($id) => (int) $id)->all();

        return view('syllabus.big-tests-results', compact('test', 'allTests', 'results', 'students', 'selectedResult', 'missingParentPhone'));
    }

    /**
     * "Duyệt & Gửi phụ huynh" một học viên: duyệt kết quả đang chờ duyệt rồi gửi Zalo ZNS cho phụ huynh
     * (vắng thi: chỉ duyệt, không gửi). Sau đó kiểm tra đóng chặng (Q4).
     */
    public function approveAndSendResult(int $resultId, SyllabusProgressionService $progression)
    {
        $res = BigTestResult::with(['student', 'bigTest.classModel'])->findOrFail($resultId);
        $test = $res->bigTest;
        abort_unless($test && $test->is_distributed, 422, 'Đề thi chưa được duyệt và phân phối.');
        $studentName = $res->student?->name ?? 'Học viên';

        if ($res->parent_notified || $res->status === 'sent') {
            return redirect()->back()->with('error', "Kết quả của em {$studentName} đã được gửi phụ huynh trước đó.");
        }
        if (! in_array($res->status, ['pending_review', 'approved'], true)) {
            return redirect()->back()->with('error', "Kết quả của em {$studentName} chưa được giáo viên nhập / gửi duyệt.");
        }
        if ($res->status === 'pending_review') {
            $res->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);
        }

        if ($res->is_absent) {
            return redirect()->back()->with('status', "Đã duyệt kết quả em {$studentName} (vắng thi — không gửi phụ huynh)."
                .$progression->describe($progression->syncBigTest($test->fresh(), Auth::user())));
        }

        return match ($this->deliverZaloResult($res, $test)) {
            'sent' => redirect()->back()->with('status', "Đã duyệt và gửi kết quả em {$studentName} cho phụ huynh qua Zalo ZNS."
                .$progression->describe($progression->syncBigTest($test->fresh(), Auth::user()))),
            'skipped' => redirect()->back()->with('warning', "Đã duyệt kết quả em {$studentName}, nhưng chưa gửi được: ".self::MISSING_PARENT_PHONE.' (nhập SĐT phụ huynh ở hồ sơ học viên).'),
            default => redirect()->back()->with('warning', "Đã duyệt kết quả em {$studentName}, nhưng gửi Zalo ZNS thất bại — vui lòng bấm Gửi PH lại."),
        };
    }

    /**
     * GV nhập kết quả Big Test. "Lưu nháp" (action=draft): kết quả ở trạng thái Nháp, sửa tiếp được, chưa vào hàng chờ
     * duyệt của Học thuật, cho phép nhập dở (thiếu kỹ năng). "Gửi duyệt" (mặc định): bắt buộc đủ 4 kỹ năng (hoặc Vắng thi),
     * chuyển sang Chờ duyệt — kèm các bản nháp còn lại đã đủ điểm của đợt thi.
     */
    public function storeBigTestResults(Request $request, int $id)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        abort_unless($test->isAccessibleBy($request->user()), 404);
        abort_unless($test->is_distributed, 422, 'Đề thi chưa được duyệt và phân phối.');
        $isDraft = $request->input('action') === 'draft';

        $skills = ['listening_score', 'reading_score', 'writing_score', 'speaking_score'];
        $rules = [
            'action' => ['nullable', 'in:draft,submit'],
            'results' => ['required', 'array'],
            'results.*.student_id' => ['required', 'exists:students,id'],
            'results.*.progress_note' => ['nullable', 'string', 'max:2000'],
            'results.*.video_url' => ['nullable', 'url', 'max:500'],
            'results.*.is_absent' => ['nullable', 'boolean'],
        ];
        foreach ($skills as $skill) {
            // Dòng để trống toàn bộ được bỏ qua; đã nhập một kỹ năng thì phải nhập đủ 4. Vắng thi đánh dấu riêng.
            $others = collect($skills)->reject(fn ($s) => $s === $skill)->map(fn ($s) => "results.*.{$s}")->implode(',');
            $rules["results.*.{$skill}"] = ['nullable', "required_with:{$others}", 'numeric', 'between:0,10'];
        }
        $validated = $request->validate($rules);

        $isAbsent = fn (array $row) => filter_var($row['is_absent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasScore = fn (array $row) => collect($skills)->contains(fn ($s) => isset($row[$s]) && $row[$s] !== '');
        $rows = collect($validated['results'])->filter(fn (array $row) => $isAbsent($row) || $hasScore($row));

        if ($rows->contains(fn (array $row) => $isAbsent($row) && $hasScore($row))) {
            throw ValidationException::withMessages(['results' => 'Học viên đã đánh dấu "Vắng thi" thì không nhập điểm.']);
        }

        $classStudentIds = $test->classModel
            ? $test->classModel->roster()->pluck('id')->merge(BigTestResult::where('big_test_id', $test->id)->pluck('student_id'))->map(fn ($id) => (int) $id)
            : collect();
        abort_if(
            $rows->contains(fn (array $row) => ! $classStudentIds->contains((int) $row['student_id'])),
            422,
            'Học viên không thuộc lớp thi.'
        );

        if ($rows->isEmpty()) {
            return redirect()->back()->with('error', 'Chưa nhập điểm (hoặc đánh dấu vắng thi) cho học viên nào.');
        }

        $locked = BigTestResult::with('student:id,name')
            ->where('big_test_id', $test->id)
            ->whereIn('student_id', $rows->pluck('student_id'))
            ->whereIn('status', BigTestResult::LOCKED_STATUSES)
            ->get();
        if ($locked->isNotEmpty()) {
            return redirect()->back()->withInput()->with('error', 'Không thể sửa điểm đã được duyệt/đã gửi phụ huynh: '
                .$locked->map(fn ($r) => $r->student?->name ?? '#'.$r->student_id)->implode(', ').'.');
        }

        DB::transaction(function () use ($rows, $test, $skills, $isAbsent) {
            foreach ($rows as $row) {
                $absent = $isAbsent($row);
                // Vắng thi: không lưu điểm (null) thay vì 0 để không kéo điểm trung bình.
                $scores = collect($skills)->mapWithKeys(fn ($s) => [$s => $absent ? null : $row[$s]]);
                BigTestResult::updateOrCreate(
                    ['big_test_id' => $test->id, 'student_id' => $row['student_id']],
                    $scores->all() + [
                        'is_absent' => $absent,
                        'progress_note' => $row['progress_note'] ?? null,
                        'video_url' => $row['video_url'] ?? null,
                        'overall_score' => $absent ? null : round($scores->avg(), 1),
                        'status' => 'pending_review',
                        'graded_by' => Auth::id(),
                        'approved_by' => null,
                        'approved_at' => null,
                    ]
                );
            }
        });

        return redirect()->back()->with('status', "Đã lưu kết quả {$rows->count()} học viên; kết quả đang chờ Học thuật duyệt.");
    }

    public function approveBigTestResults(int $id, SyllabusProgressionService $progression)
    {
        $test = BigTest::findOrFail($id);
        BigTestResult::where('big_test_id', $test->id)
            ->where('status', 'pending_review')
            ->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        // Trường hợp mọi kết quả đã gửi / vắng thi: duyệt xong là đủ điều kiện đóng chặng.
        $stageNote = $progression->describe($progression->syncBigTest($test, Auth::user()));

        return redirect()->back()->with('status', 'Đã duyệt kết quả Big Test; có thể gửi cho phụ huynh.'.$stageNote);
    }

    public function sendZaloResults($id, SyllabusProgressionService $progression)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        abort_unless($test->is_distributed, 422, 'Đề thi chưa được duyệt và phân phối.');
        $results = BigTestResult::with('student')
            ->where('big_test_id', $test->id)
            ->where('status', 'approved')
            ->where('parent_notified', false)
            ->where('is_absent', false)
            ->get();

        if ($results->isEmpty()) {
            return redirect()->back()->with('error', 'Không có kết quả đã duyệt nào chưa gửi phụ huynh.');
        }

        $sent = 0;
        $failed = [];
        $skipped = [];
        foreach ($results as $res) {
            $name = $res->student?->name ?? 'HV #'.$res->student_id;
            $outcome = $this->deliverZaloResult($res, $test);
            match ($outcome) {
                'sent' => $sent++,
                'skipped' => $skipped[] = $name,
                default => $failed[] = $name,
            };
        }

        $message = "Kỳ thi [{$test->title}]: đã gửi Zalo ZNS {$sent} phụ huynh";
        // Q4: Big Test của chặng đã duyệt và gửi đủ → đóng chặng, tự mở chặng kế tiếp.
        $stageNote = $sent > 0 ? $progression->describe($progression->syncBigTest($test->fresh(), Auth::user())) : '';
        if ($failed === [] && $skipped === []) {
            return redirect()->back()->with('status', $message.'.'.$stageNote);
        }
        if ($failed !== []) {
            $message .= '; gửi lỗi '.count($failed).' ('.implode(', ', $failed).')';
        }
        if ($skipped !== []) {
            $message .= '; bỏ qua '.count($skipped).' — '.self::MISSING_PARENT_PHONE.' ('.implode(', ', $skipped).')';
        }

        return redirect()->back()->with('warning', $message.'.'.$stageNote);
    }

    public function sendSingleZaloResult($resultId, SyllabusProgressionService $progression)
    {
        $res = BigTestResult::with(['student', 'bigTest.classModel'])->findOrFail($resultId);
        $studentName = $res->student?->name ?? 'Học viên';

        if ($res->parent_notified || $res->status === 'sent') {
            return redirect()->back()->with('error', "Kết quả của em {$studentName} đã được gửi phụ huynh trước đó.");
        }
        abort_unless($res->status === 'approved', 422, 'Kết quả chưa được Học thuật duyệt.');
        if ($res->is_absent) {
            return redirect()->back()->with('error', "Học viên {$studentName} vắng thi — không có điểm để gửi phụ huynh.");
        }

        return match ($this->deliverZaloResult($res, $res->bigTest)) {
            'sent' => redirect()->back()->with('status', "Đã gửi thông báo điểm qua Zalo ZNS đến Phụ huynh em {$studentName} thành công!"
                .$progression->describe($progression->syncBigTest($res->bigTest, Auth::user()))),
            'skipped' => redirect()->back()->with('error', "Không gửi được cho em {$studentName}: ".self::MISSING_PARENT_PHONE.' (nhập SĐT phụ huynh ở hồ sơ học viên).'),
            default => redirect()->back()->with('error', "Gửi Zalo ZNS cho phụ huynh em {$studentName} thất bại, vui lòng thử lại."),
        };
    }

    /**
     * Gửi 1 kết quả qua Zalo ZNS; chỉ đánh dấu đã gửi khi nhà cung cấp trả về thành công.
     *
     * @return 'sent'|'failed'|'skipped'
     */
    private function deliverZaloResult(BigTestResult $res, ?BigTest $test): string
    {
        $student = $res->student;
        $phone = $student ? (string) $student->parentContactPhone() : '';
        if ($phone === '') {
            return 'skipped';
        }

        $response = ZaloZnsService::sendBigTestResult(
            phone: $phone,
            studentName: $student->name ?? 'Học viên',
            className: $test?->classModel?->name ?? 'Lớp MEnglish',
            testTitle: $test?->title ?? 'Big Test',
            listening: $res->listening_score ?? '-',
            reading: $res->reading_score ?? '-',
            writing: $res->writing_score ?? '-',
            speaking: $res->speaking_score ?? '-',
            overall: $res->overall_score ?? '-',
            progressNote: $res->progress_note ?? ''
        );

        if (($response['success'] ?? false) !== true) {
            return 'failed';
        }

        $res->update([
            'status' => 'sent',
            'parent_notified' => true,
            'notified_at' => now(),
        ]);

        return 'sent';
    }
}
