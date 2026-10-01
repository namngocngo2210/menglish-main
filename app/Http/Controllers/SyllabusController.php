<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
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
use App\Services\AdjustmentSlaService;
use App\Services\DocumentCodeGenerator;
use App\Services\SafeUploadService;
use App\Services\ScheduleExtensionService;
use App\Services\SyllabusProgressionService;
use App\Services\ZaloZnsService;
use App\Support\Ui;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

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
        $documents = SyllabusDocument::with(['curriculum', 'uploader', 'stage'])
            ->visibleTo($user)
            ->when($request->filled('curriculum_id'), fn ($q) => $q->where('curriculum_id', $request->integer('curriculum_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return Inertia::render('Syllabus/Documents', [
            'documents' => $documents->through(fn (SyllabusDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'icon' => $doc->icon,
                'curriculum' => $doc->curriculum?->title,
                'extension' => $doc->extension,
                'size_human' => $doc->size_human,
                'stage_label' => $doc->stage_label,
                'audience_labels' => $doc->audience_labels,
                'downloadable' => (bool) $doc->downloadable,
            ]),
            'curriculums' => $curriculums->map(fn (SyllabusCurriculum $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'code' => $c->code,
                'version' => $c->version,
                'stages' => $c->stages->map(fn (SyllabusStage $st) => ['id' => $st->id, 'label' => $st->label])->values()->all(),
            ])->values()->all(),
            'canUpload' => $user->can('syllabus.upload'),
        ]);
    }

    public function storeDocument(Request $request)
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id,deleted_at,NULL'],
            'title' => ['required', 'string', 'max:255'],
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->whereNull('deleted_at')->where('curriculum_id', $request->input('curriculum_id'))],
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
        // Xóa mềm — giữ file để có thể khôi phục.
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

        return Inertia::render('Syllabus/Builder', [
            'curriculums' => Ui::options($curriculums, fn ($c) => $c->title.' ('.$c->code.' · '.$c->version.')'),
            'curriculum' => $curriculum ? [
                'id' => $curriculum->id,
                'title' => $curriculum->title,
                'code' => $curriculum->code,
                'version' => $curriculum->version,
                'course_id' => $curriculum->course_id,
                'description' => $curriculum->description,
                'level_ids' => $curriculum->levels->pluck('id')->all(),
            ] : null,
            'stages' => $stages->map(fn (SyllabusStage $stage) => [
                'id' => $stage->id,
                'position' => $stage->position,
                'label' => $stage->label,
                'description' => $stage->description,
                'overview_link' => $stage->overview_link,
                'big_test_title' => $stage->big_test_title,
                'big_test_note' => $stage->big_test_note,
                'open_classes' => $openClassesByStage->get($stage->id, collect())->map(fn ($a) => $a->classModel?->name)->filter()->implode(', '),
                'units' => $stage->units->map(fn (SyllabusUnit $u) => [
                    'id' => $u->id,
                    'unit_number' => $u->unit_number,
                    'title' => $u->title,
                    'objectives' => $u->objectives,
                    'lessons' => $u->lessons->map(fn (SyllabusLesson $l) => $this->lessonData($l))->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'totals' => [
                'units' => $units->count(),
                'lessons' => $lessons->count(),
                'next_position' => (int) $stages->max('position') + 1,
                'next_unit_number' => (int) ($units->max('unit_number') ?? 0) + 1,
                'next_session_no' => (int) ($lessons->max('session_no') ?? 0) + 1,
            ],
            'unitOptions' => Ui::options($units, fn ($u) => 'Unit '.$u->unit_number.': '.$u->title),
            'editor' => $editor ? $this->editorData($editor) : null,
            'courses' => Ui::options($courses, 'name'),
            'levels' => $levels->map(fn (CourseLevel $level) => [
                'id' => $level->id,
                'code' => $level->code,
                'name' => $level->name,
                'other' => $curriculum && $level->syllabus_curriculum_id && $level->syllabus_curriculum_id !== $curriculum->id,
            ])->values()->all(),
            'newCode' => 'CUR-'.strtoupper(Str::random(4)),
            'canManage' => $request->user()->can('syllabus.manage'),
        ]);
    }

    /** Nội dung 1 buổi học cho màn soạn / màn GV xem. */
    private function lessonData(SyllabusLesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'session_no' => $lesson->session_no,
            'title' => $lesson->title,
            'unit_id' => $lesson->unit_id,
            'objectives' => $lesson->objectives,
            'content' => $lesson->content,
            'homework_guide' => $lesson->homework_guide,
            'vocabulary_focus' => $lesson->vocabulary_focus,
            'grammar_focus' => $lesson->grammar_focus,
        ];
    }

    /**
     * Ô soạn thảo đang mở của màn soạn syllabus (chặng / unit / buổi; thêm hoặc sửa).
     *
     * @param  array{type: string, model: mixed, parent: mixed}  $editor
     */
    private function editorData(array $editor): array
    {
        $model = $editor['model'];

        return match ($editor['type']) {
            'stage' => ['type' => 'stage', 'model' => $model ? [
                'id' => $model->id,
                'label' => $model->label,
                'name' => $model->name,
                'overview_link' => $model->overview_link,
                'description' => $model->description,
                'big_test_title' => $model->big_test_title,
                'big_test_note' => $model->big_test_note,
            ] : null],
            'unit' => ['type' => 'unit', 'model' => $model ? [
                'id' => $model->id,
                'unit_number' => $model->unit_number,
                'title' => $model->title,
                'objectives' => $model->objectives,
            ] : null, 'parent' => ($stage = $model?->stage ?? $editor['parent']) ? ['id' => $stage->id, 'label' => $stage->label] : null],
            default => ['type' => 'lesson', 'model' => $model ? $this->lessonData($model) : null,
                'parent' => ($unit = $model?->unit ?? $editor['parent']) ? ['id' => $unit->id, 'unit_number' => $unit->unit_number, 'title' => $unit->title] : null],
        };
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

        // Xóa mềm dây chuyền (khóa ngoại cascade chỉ chạy khi xóa cứng); giữ file tài liệu để có thể khôi phục.
        DB::transaction(function () use ($curriculum) {
            $curriculum->levels()->update(['syllabus_curriculum_id' => null]);
            $curriculum->documents()->delete();
            $curriculum->lessons()->get()->each->delete();
            $curriculum->units()->delete();
            $curriculum->stages()->delete();
            $curriculum->delete();
        });

        return redirect()->route('syllabus.builder')->with('status', "Đã xóa giáo trình {$curriculum->title}.");
    }

    private function curriculumRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'course_id' => ['nullable', 'exists:courses,id,deleted_at,NULL'],
            'version' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'level_ids' => ['nullable', 'array'],
            'level_ids.*' => ['integer', 'exists:course_levels,id,deleted_at,NULL'],
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
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id,deleted_at,NULL'],
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

        DB::transaction(function () use ($stage) {
            SyllabusDocument::where('stage_id', $stage->id)->update(['stage_id' => null]);
            $stage->delete();
        });
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
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id,deleted_at,NULL'],
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->whereNull('deleted_at')->where('curriculum_id', $request->input('curriculum_id'))],
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
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->whereNull('deleted_at')->where('curriculum_id', $unit->curriculum_id)],
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
        DB::transaction(function () use ($unit) {
            $unit->lessons()->get()->each->delete();
            $unit->delete();
        });

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
            'unit_id' => ['required', 'exists:syllabus_units,id,deleted_at,NULL'],
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
            'unit_id' => ['nullable', Rule::exists('syllabus_units', 'id')->whereNull('deleted_at')->where('curriculum_id', $lesson->curriculum_id)],
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
        // Phạm vi lớp theo quyền (Quản lý cơ sở chỉ thấy lớp chi nhánh mình); lớp đã xóa không còn hiện.
        $visibleClassIds = ClassModel::visibleTo($request->user())->select('id');
        $assignments = SyllabusAssignment::with(['teacher', 'curriculum', 'classModel.branch', 'stage', 'closer', 'opener', 'closingBigTest'])
            ->whereIn('class_id', $visibleClassIds)
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
        $classes = ClassModel::visibleTo($request->user())->where('status', '!=', 'cancelled')->orderBy('name')->get();
        $levelCurriculum = CourseLevel::whereNotNull('syllabus_curriculum_id')->pluck('syllabus_curriculum_id', 'code');
        $openByClass = SyllabusAssignment::open()->whereIn('class_id', $visibleClassIds)->with('stage')->get()->keyBy('class_id');

        $user = $request->user();

        return Inertia::render('Syllabus/Assignments', [
            'assignments' => $assignments->through(fn (SyllabusAssignment $as) => [
                'id' => $as->id,
                'class_name' => $as->classModel?->name,
                'class_place' => collect([$as->classModel?->branch?->name, $as->classModel?->room])->filter()->implode(' - '),
                'curriculum' => $as->curriculum?->title,
                'teacher' => $as->teacher?->name,
                'teacher_code' => $as->teacher?->employee_code,
                'user_id' => (string) $as->user_id,
                'stage_label' => $as->stage?->label ?? $as->stage_name,
                'assigned_chapters' => $as->assigned_chapters,
                'extra_sessions' => (int) $as->extra_sessions,
                'start_date' => ($as->opened_at ?? $as->created_at)?->toDateString(),
                'closed_at' => $as->closed_at?->toDateString(),
                'close_reason' => $as->close_reason,
                'deadline' => $as->deadline?->toDateString(),
                'is_open' => $as->isOpen(),
                'curriculum_completed' => (bool) $as->curriculum_completed_at,
                'closing_big_test' => $as->closingBigTest?->code,
            ]),
            'teachers' => Ui::options($teachers, fn (User $t) => $t->name.($t->employee_code ? ' (ID: '.$t->employee_code.')' : '')),
            'curriculums' => $curriculums->map(fn (SyllabusCurriculum $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'code' => $c->code,
                'stages' => $c->stages->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])->values(),
            ])->values(),
            'classes' => $classes->map(fn (ClassModel $c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values(),
            'classCurriculum' => $classes->mapWithKeys(fn ($c) => [$c->id => $levelCurriculum[$c->level] ?? null]),
            'classOpenStage' => $openByClass->map(fn ($a) => $a->stage_name),
            'canManage' => $user->can('syllabus.manage'),
            'canOverride' => $user->can('syllabus.approve_adjustment'),
            'canViewLog' => $user->can('activity_log.view'),
            'hasTickets' => Route::has('tickets.create'),
        ]);
    }

    /**
     * Mở chặng cho lớp. Mặc định: giáo trình theo Trình độ của lớp, chặng = chặng đầu tiên lớp chưa học xong,
     * GV = GV chính của lớp. Lớp đang mở chặng khác thì từ chối, trừ khi Học thuật chọn "chuyển chặng" kèm lý do.
     */
    public function storeAssignment(Request $request, SyllabusProgressionService $progression)
    {
        $validated = $request->validate([
            'class_id' => ['required', 'exists:classes,id'],
            'curriculum_id' => ['nullable', 'exists:syllabus_curriculums,id,deleted_at,NULL'],
            'stage_id' => ['nullable', 'exists:syllabus_stages,id,deleted_at,NULL'],
            'user_id' => ['nullable', 'exists:users,id'],
            'assigned_chapters' => ['nullable', 'string', 'max:255'],
            'stage_name' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date', 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'replace_current' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $class = ClassModel::visibleTo($request->user())->findOrFail($validated['class_id']);

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
        $assignment = SyllabusAssignment::with('classModel')->whereIn('class_id', ClassModel::visibleTo($request->user())->select('id'))->findOrFail($id);
        if (! $assignment->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Chặng đã đóng, không chỉnh sửa được.']);
        }
        // Lỗi của hộp thoại sửa báo bằng thông báo chung: trường trùng tên với form "Mở chặng" nên lỗi theo trường sẽ hiện nhầm chỗ.
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'exists:users,id'],
            'start_date' => ['nullable', 'date', 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'deadline' => ['nullable', 'date'],
        ], [], ['user_id' => 'giáo viên phụ trách', 'start_date' => 'ngày bắt đầu', 'deadline' => 'dự kiến hoàn thành']);
        if ($validator->fails()) {
            return redirect()->back()->with('error', 'Chưa lưu chỉnh sửa chặng: '.implode(' ', $validator->errors()->all()));
        }
        $validated = $validator->validated();

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
        $assignment = SyllabusAssignment::whereIn('class_id', ClassModel::visibleTo($request->user())->select('id'))->findOrFail($id);
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

        // Chi tiết mở trong modal khi URL chọn 1 đề xuất (?proposal=); không chọn → chỉ danh sách.
        $selected = null;
        if ($request->filled('proposal')) {
            $selected = SyllabusChangeProposal::with(['curriculum', 'unit', 'lesson', 'proposer.roles', 'reviewer'])->findOrFail($request->integer('proposal'));
            abort_unless($selected->isVisibleTo($user), 404);
        }

        $pendingCount = SyllabusChangeProposal::visibleTo($user)->where('status', 'pending')->count();

        $listQuery = array_filter(['status' => $status, 'page' => $request->query('page')]);

        return Inertia::render('Syllabus/Versions', [
            'proposals' => $proposals->through(fn (SyllabusChangeProposal $p) => [
                'id' => $p->id,
                'curriculum' => $p->curriculum?->title,
                'target_label' => $p->target_label,
                'proposer' => $p->proposer?->name,
                'created_at' => $p->created_at->format('d/m/Y H:i'),
                'status_label' => $p->status_label,
                'status_color' => $p->status_color,
                'detail_url' => route('syllabus.versions', $listQuery + ['proposal' => $p->id]),
            ]),
            'selected' => $selected ? [
                'id' => $selected->id,
                'curriculum' => $selected->curriculum?->title,
                'curriculum_version' => $selected->curriculum?->version,
                'target_label' => $selected->target_label,
                'proposer' => $selected->proposer?->name,
                'proposer_roles' => $selected->proposer?->roles->map(fn ($r) => AclHelper::roleLabel($r->name))->implode(', '),
                'proposal_type' => $selected->proposal_type,
                'old_content' => $selected->old_content,
                'new_content' => $selected->new_content,
                'reason' => $selected->reason,
                'attachment_name' => $selected->attachment_path ? $selected->attachment_name : null,
                'status' => $selected->status,
                'status_label' => $selected->status_label,
                'status_color' => $selected->status_color,
                'reviewer' => $selected->reviewer?->name,
                'review_note' => $selected->review_note,
                'created_at' => $selected->created_at->format('H:i, d/m/Y'),
                'reviewed_at' => $selected->reviewed_at?->format('H:i, d/m/Y'),
                'edit_lesson_url' => $selected->lesson ? route('syllabus.builder', ['curriculum' => $selected->curriculum_id, 'edit_lesson' => $selected->lesson_id]).'#editor' : null,
            ] : null,
            'pendingCount' => $pendingCount,
            'statusOptions' => Ui::options(SyllabusChangeProposal::STATUS_LABELS),
            'listUrl' => route('syllabus.versions', $listQuery),
            'canReview' => $user->can('syllabus.approve_adjustment'),
            'canManage' => $user->can('syllabus.manage'),
        ]);
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

        return Inertia::render('Syllabus/TeacherPropose', [
            'proposals' => $proposals->through(fn (SyllabusChangeProposal $p) => [
                'id' => $p->id,
                'curriculum' => $p->curriculum?->title,
                'target' => $p->lesson ? 'Buổi '.$p->lesson->session_no : ($p->unit ? 'Unit '.$p->unit->unit_number : 'Chung'),
                'new_content' => $p->new_content,
                'created_at' => $p->created_at->format('d/m/Y'),
                'status' => $p->status,
                'status_label' => $p->status_label,
                'status_color' => $p->status_color,
                'review_note' => $p->review_note,
            ]),
            'curriculums' => $curriculums->map(fn (SyllabusCurriculum $c) => [
                'id' => $c->id,
                'label' => $c->title.' ('.$c->version.')',
                'lessons' => $c->lessons->map(fn ($l) => ['value' => $l->id, 'label' => 'Buổi '.$l->session_no.': '.$l->title.($l->unit ? ' (Unit '.$l->unit->unit_number.')' : '')])->values(),
            ])->values(),
            'statusOptions' => Ui::options(SyllabusChangeProposal::STATUS_LABELS),
            'canPropose' => $request->user()->can('syllabus.propose_adjustment'),
        ]);
    }

    public function storeProposal(Request $request)
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id,deleted_at,NULL'],
            'unit_id' => ['nullable', Rule::exists('syllabus_units', 'id')->whereNull('deleted_at')->where('curriculum_id', $request->input('curriculum_id'))],
            'lesson_id' => ['nullable', Rule::exists('syllabus_lessons', 'id')->whereNull('deleted_at')->where('curriculum_id', $request->input('curriculum_id'))],
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
        // Chỉ mở tài liệu (modal) khi URL chọn; không tự mở tài liệu đầu danh sách.
        $selected = $request->filled('document') ? $visible->firstWhere('id', $request->integer('document')) : null;
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
        $overviewCurriculum = $assignment?->curriculum ?? ($selected ?? $documents->first())?->curriculum;
        $overviewStages = $stages->isNotEmpty() ? $stages : ($overviewCurriculum ? $overviewCurriculum->stages()->with('units.lessons')->get() : collect());

        $stageUnits = $assignment?->stage?->units()->with('lessons')->get() ?? collect();
        $stageState = fn (SyllabusStage $s) => $assignment && $s->id === $assignment->stage_id ? 'open' : ($closedStageIds->contains($s->id) ? 'done' : 'todo');
        $listQuery = array_filter(['class' => $class?->id, 'q' => $search]);
        $current = $position['current'] ?? null;

        return Inertia::render('Syllabus/TeacherView', [
            'documents' => $documents->map(fn (SyllabusDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'icon' => $doc->icon,
                'place' => collect([$doc->curriculum?->title, $doc->stage_label])->filter()->implode(' · '),
                'extension' => $doc->extension,
                'size_human' => $doc->size_human,
                'can_download' => $doc->canDownload($user),
                'viewed' => $viewedIds->contains($doc->id),
                'url' => route('syllabus.teacher-view', $listQuery + ['document' => $doc->id]),
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'title' => $selected->title,
                'icon' => $selected->icon,
                'kind' => $selected->kind,
                'extension' => $selected->extension,
                'meta' => ($selected->stage_label ?: 'Chưa gắn chặng').' • '.$selected->curriculum?->title.' • '.$selected->size_human,
                'can_download' => $selected->canDownload($user),
                'viewed' => $viewedIds->contains($selected->id),
            ] : null,
            'listUrl' => route('syllabus.teacher-view', $listQuery),
            'search' => $search,
            'initialTab' => in_array($request->query('tab'), ['docs', 'overview', 'lessons'], true) ? $request->query('tab') : ($class && ! $documents->count() ? 'lessons' : 'docs'),
            'classes' => Ui::options($classes, 'name'),
            'classId' => $class?->id,
            'userEmail' => $user->email,
            'overviewCurriculum' => $overviewCurriculum?->title,
            'overviewStages' => $overviewStages->map(fn (SyllabusStage $s) => [
                'id' => $s->id,
                'label' => $s->label,
                'state' => $stageState($s),
                'summary' => $s->description ?: $s->units->count().' unit · '.$s->units->sum(fn ($u) => $u->lessons->count()).' buổi',
                'overview_link' => $s->overview_link,
            ])->values(),
            'stages' => $stages->map(fn (SyllabusStage $s) => ['id' => $s->id, 'label' => $s->label, 'state' => $stageState($s)])->values(),
            'assignment' => $assignment ? [
                'stage_label' => $assignment->stage?->label ?? $assignment->stage_name,
                'curriculum' => $assignment->curriculum?->title,
                'opened_at' => ($assignment->opened_at ?? $assignment->created_at)?->format('d/m/Y'),
                'extra_sessions' => (int) $assignment->extra_sessions,
                'big_test_title' => $assignment->stage?->big_test_title ?: 'Big Test cuối chặng',
                'has_stage' => (bool) $assignment->stage,
            ] : null,
            'position' => $position ? [
                'taught' => $position['taught'],
                'lessons' => $position['lessons']->count(),
                'over' => $position['over'],
                'current' => $current ? ['id' => $current->id, 'session_no' => $current->session_no, 'unit_number' => $current->unit?->unit_number, 'title' => $current->title] : null,
            ] : null,
            'stageUnits' => $stageUnits->map(fn (SyllabusUnit $u) => [
                'id' => $u->id,
                'unit_number' => $u->unit_number,
                'title' => $u->title,
                'lessons' => $u->lessons->map(fn (SyllabusLesson $l) => $this->lessonData($l))->values(),
            ])->values(),
        ]);
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

        return Inertia::render('Syllabus/TeacherAdjust', [
            'requests' => $requests->through(fn (SyllabusAdjustmentRequest $req) => [
                'id' => $req->id,
                'created_at' => $req->created_at->format('d/m/Y'),
                'class_stage_label' => $req->class_stage_label,
                'reason' => $req->reason,
                'applied_note' => $req->applied_note,
                'extra_sessions' => (int) $req->extra_sessions,
                'status' => $req->status,
                'status_label' => $req->status_label,
                'rejection_reason' => $req->rejection_reason,
            ]),
            'classes' => $classes->map(fn (ClassModel $cl) => [
                'value' => $cl->id,
                'label' => $cl->name.' - '.($openAssignments[$cl->id]?->stage?->label ?? $openAssignments[$cl->id]?->stage_name).' ('.$cl->code.')',
            ])->values(),
            'slaDays' => SyllabusAdjustmentRequest::SLA_DAYS,
            'canReview' => $user->can('syllabus.approve_adjustment'),
        ]);
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
        // Chi tiết mở trong modal khi URL chọn 1 yêu cầu (?request=); không chọn → chỉ danh sách.
        $selected = $request->filled('request')
            ? SyllabusAdjustmentRequest::with(['classModel', 'teacher', 'approver', 'assignment'])->findOrFail($request->integer('request'))
            : null;
        abort_if($selected && ! $canReview && (int) $selected->user_id !== (int) $user->id, 404);

        $listQuery = array_filter(['status' => $status, 'page' => $request->query('page')]);
        $reviewedBy = fn (SyllabusAdjustmentRequest $r) => trim($r->approver?->name.' '.($r->reviewed_at ? 'lúc '.$r->reviewed_at->format('H:i d/m/Y') : ''));

        return Inertia::render('Syllabus/AdjustmentRequests', [
            'requests' => $requests->through(fn (SyllabusAdjustmentRequest $req) => [
                'id' => $req->id,
                'teacher' => $req->teacher?->name,
                'class_stage_label' => $req->class_stage_label,
                'extra_sessions' => (int) $req->extra_sessions,
                'reason' => $req->reason,
                'created_at' => $req->created_at->format('d/m/Y'),
                'status' => $req->status,
                'status_label' => $req->status_label,
                'sla_overdue' => $req->isSlaOverdue(),
                'detail_url' => route('syllabus.adjustment-requests', $listQuery + ['request' => $req->id]),
            ]),
            'selected' => $selected ? [
                'id' => $selected->id,
                'teacher' => $selected->teacher?->name,
                'teacher_code' => $selected->teacher?->employee_code,
                'class_stage_label' => $selected->class_stage_label,
                'created_at' => $selected->created_at->format('d/m/Y'),
                'extra_sessions' => $selected->extra_sessions,
                'class_end_date' => $selected->classModel?->end_date?->format('d/m/Y'),
                'reason' => $selected->reason,
                'request_type' => $selected->request_type,
                'status' => $selected->status,
                'status_label' => $selected->status_label,
                'sla_overdue' => $selected->isSlaOverdue(),
                'reviewed_by' => $reviewedBy($selected),
                'applied_note' => $selected->applied_note,
                'rejection_reason' => $selected->rejection_reason,
            ] : null,
            'status' => $status,
            'statusOptions' => Ui::options(['all' => 'Tất cả'] + SyllabusAdjustmentRequest::STATUS_LABELS),
            'listTitle' => ['pending' => 'Danh sách chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối'][$status] ?? 'Tất cả yêu cầu',
            'listUrl' => route('syllabus.adjustment-requests', $listQuery),
            'maxExtraSessions' => SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS,
            'canReview' => $canReview,
        ]);
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
        // Giãn tiến độ luôn gắn chặng đang mở (Q4); lớp chưa mở chặng thì không nhận yêu cầu.
        $openAssignmentId = SyllabusAssignment::open()->where('class_id', $class->id)->value('id');
        if (! $openAssignmentId) {
            throw ValidationException::withMessages(['class_id' => "Lớp {$class->name} không có chặng học nào đang mở — không thể xin điều chỉnh tiến độ."]);
        }

        $adjustment = SyllabusAdjustmentRequest::create([
            'class_id' => $class->id,
            // Gắn chặng đang mở để biết giãn tiến độ cho chặng nào.
            'syllabus_assignment_id' => $openAssignmentId,
            'user_id' => $user->id,
            'request_type' => $validated['request_type'],
            'reason' => $validated['reason'],
            'extra_sessions' => $validated['extra_sessions'] ?? null,
            'status' => 'pending',
        ]);
        // Báo người duyệt (Học thuật / Admin) có yêu cầu mới, hạn duyệt 3 ngày.
        app(AdjustmentSlaService::class)->notifyCreated($adjustment->load('classModel', 'teacher'));

        // Giáo viên quay lại màn gửi yêu cầu của mình; người duyệt về màn duyệt.
        return redirect()->route($user->can('syllabus.approve_adjustment') ? 'syllabus.adjustment-requests' : 'syllabus.teacher-adjust')
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
        if ($count > 0 && ! $req->classModel) {
            throw ValidationException::withMessages(['extra_sessions' => 'Lớp của yêu cầu đã bị xóa, chỉ có thể từ chối hoặc duyệt với 0 buổi.']);
        }
        // Buổi giãn cộng vào đúng chặng của yêu cầu; chặng đó đã đóng thì không cộng sang chặng khác.
        $targetAssignment = $req->syllabus_assignment_id
            ? SyllabusAssignment::find($req->syllabus_assignment_id)
            : SyllabusAssignment::open()->where('class_id', $req->class_id)->first();
        if ($count > 0 && $targetAssignment && ! $targetAssignment->isOpen()) {
            throw ValidationException::withMessages(['extra_sessions' => "{$targetAssignment->stage_name} đã đóng. Nhập 0 buổi để chỉ ghi nhận, hoặc từ chối yêu cầu."]);
        }

        $appliedNote = 'Không thay đổi lịch học (yêu cầu không kèm số buổi cần thêm).';
        DB::transaction(function () use ($req, $count, $extension, $targetAssignment, &$appliedNote) {
            if ($count > 0) {
                $created = $extension->extend($req->classModel, $count, "Giãn tiến độ theo yêu cầu #{$req->id}");
                $appliedNote = 'Đã thêm '.count($created).' buổi: '
                    .collect($created)->map(fn ($s) => $s->date->format('d/m/Y'))->implode(', ')
                    .'. Ngày kết thúc lớp: '.$req->classModel->fresh()->end_date?->format('d/m/Y').'.';

                // Chặng đang mở được cộng thêm số buổi giãn (buổi thêm dùng để dạy chậm lại / ôn trước Big Test).
                $assignment = $targetAssignment;
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
                'extra_sessions' => $count, // số buổi thực duyệt (0 = chỉ ghi nhận)
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

        $isAcademic = $user->can('syllabus.approve_adjustment');
        $canRecordAny = $user->can('attendance_student.record_any');

        return Inertia::render('Syllabus/TeachingStages', [
            'assignments' => $assignments->map(function (SyllabusAssignment $as) use ($plans, $user, $isAcademic, $canRecordAny) {
                $class = $as->classModel;
                $plan = $plans[$as->id];
                $order = $plan['order'];
                $isMainTeacher = (int) $class?->teacher_id === (int) $user->id || (int) $class?->foreign_teacher_id === (int) $user->id;
                $isAssistant = (int) $class?->assistant_id === (int) $user->id && ! $isMainTeacher;

                return [
                    'id' => $as->id,
                    'class_id' => $as->class_id,
                    'stage_label' => $as->stage?->label ?? $as->stage_name,
                    'class_label' => $class?->name.($class?->code ? ' - '.$class->code : ''),
                    'start_date' => ($as->opened_at ?? $as->created_at)?->format('d/m/Y'),
                    'is_assistant' => $isAssistant,
                    'role_label' => $isAssistant ? 'Trợ giảng (Chỉ xem)' : ($isMainTeacher ? 'Giáo viên chính' : 'Học thuật / Quản lý'),
                    'exam' => $plan['exam'],
                    'date' => $plan['date']?->format('d/m/Y'),
                    'big_test_code' => $plan['bigTest']?->code,
                    'expected_date' => $as->expected_big_test_date?->toDateString(),
                    'can_set_date' => ($isMainTeacher || $isAcademic) && ! $plan['bigTest'],
                    'can_order' => $isMainTeacher || $canRecordAny,
                    'order_status' => $order?->status,
                    'order_rejection' => $order?->rejection_reason,
                ];
            })->values(),
            'today' => now()->toDateString(),
            'month' => 'Tháng '.now()->month.', '.now()->year,
        ]);
    }

    /**
     * Kế hoạch Big Test cuối chặng của từng chặng đang mở: ngày thi (đợt Big Test của chặng → ngày dự kiến GV đặt →
     * ngày thi trong order), order đề mới nhất và trạng thái đề (đã duyệt / chờ duyệt / chưa order).
     *
     * @return Collection<int, array{date: ?CarbonInterface, order: ?BigTestOrder, bigTest: ?BigTest, exam: string}>
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
            ->whereHas('classModel') // lớp đã xóa không còn order chờ xử lý
            ->when(in_array($orderStatus, array_keys(BigTestOrder::STATUS_LABELS), true), fn ($q) => $q->where('status', $orderStatus))
            ->when($orderSearch !== '', fn ($q) => $q->whereHas('classModel', fn ($c) => $c->where('name', 'like', "%{$orderSearch}%")->orWhere('code', 'like', "%{$orderSearch}%")))
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest()
            ->paginate($request->perPage(10), ['*'], 'orders_page')
            ->withQueryString();
        // Chỉ mở chi tiết (modal) khi URL chọn order; không tự chọn order đầu danh sách.
        $selectedOrder = $request->filled('order')
            ? BigTestOrder::with(['classModel.course', 'classModel.branch', 'teacher', 'reviewer', 'stage'])->visibleTo($user)->findOrFail($request->integer('order'))
            : null;
        $pendingOrders = BigTestOrder::visibleTo($user)->where('status', 'pending')->count();

        // "Gắn chặng" cho đợt thi: chặng của các giáo trình lớp đã/đang học (hoặc giáo trình theo trình độ).
        $stageOptions = $this->stageOptionsForClasses($bigTests->getCollection()->pluck('class_id')->filter()->unique())
            ->map(fn (Collection $stages) => $stages->pluck('label', 'id'));

        $canReview = $user->can('big_test.approve');
        $listQuery = array_filter(['order_search' => $orderSearch, 'orders_page' => $request->query('orders_page')]) + ['order_status' => (string) $orderStatus];

        return Inertia::render('Syllabus/BigTestDistribution', [
            'classes' => Ui::options($classes, 'name'),
            'defaultScheduledAt' => now()->addDays(7)->format('Y-m-d\TH:i'),
            'orders' => $orders->through(fn (BigTestOrder $order) => [
                'id' => $order->id,
                'class_label' => $order->classModel?->name.($order->classModel?->code ? ' - '.$order->classModel->code : ''),
                'ordered_ago' => $order->created_at->diffForHumans(),
                'stage_type' => $order->stage_label.' · '.$order->type_label,
                'teacher' => $order->teacher?->name,
                'exam_date' => $order->exam_date?->format('d/m/Y'),
                'due_date' => $order->due_date?->format('d/m/Y'),
                'overdue' => $order->isOverdue(),
                'sla_warning' => $order->isSlaWarning(),
                'status_label' => $order->status_label,
                'status_color' => $order->status_color,
                'detail_url' => route('syllabus.big-tests.distribution', ['order' => $order->id] + $listQuery),
            ]),
            'orderStatus' => (string) $orderStatus,
            'orderSearch' => $orderSearch,
            'orderStatusOptions' => Ui::options(BigTestOrder::STATUS_LABELS),
            'pendingOrders' => $pendingOrders,
            'bigTests' => $bigTests->through(fn (BigTest $bt) => [
                'id' => $bt->id,
                'code' => $bt->code,
                'title' => $bt->title,
                'content_url' => $bt->content_url && $bt->contentLinkVisibleTo($user) ? $bt->content_url : null,
                'speaking_url' => $bt->speakingLinkVisibleTo($user) ? $bt->speaking_url : null,
                'class_id' => $bt->class_id,
                'class_name' => $bt->classModel?->name,
                'stage_label' => $bt->stage?->label,
                'stage_id' => (string) ($bt->syllabus_stage_id ?? ''),
                'scheduled_at' => $bt->scheduled_at?->format('d/m/Y H:i'),
                'room' => $bt->room,
                'proctor' => $bt->proctor?->name,
                'passcode' => $bt->passcodeVisibleTo($user) ? $bt->passcode : '••••••',
                'is_distributed' => (bool) $bt->is_distributed,
                'paper_warning' => $this->paperWarning($bt),
                'stage_options' => Ui::options($stageOptions[$bt->class_id] ?? []),
            ]),
            'selectedOrder' => $selectedOrder ? $this->orderDetail($selectedOrder, $canReview) : null,
            'listUrl' => route('syllabus.big-tests.distribution', $listQuery),
            'leadDays' => BigTestOrder::LEAD_DAYS,
            'canReview' => $canReview,
            'canManage' => $user->can('syllabus.manage'),
        ]);
    }

    /**
     * Cảnh báo duyệt đề: đợt thi trong 7 ngày tới chưa phân phối đề ('warn'); còn dưới 3 ngày mà vẫn chưa phân phối ('overdue').
     *
     * @return array{level: string, label: string}|null
     */
    private function paperWarning(BigTest $bt): ?array
    {
        if ($bt->is_distributed || ! $bt->scheduled_at || ! $bt->scheduled_at->isFuture()) {
            return null;
        }
        $sla = app(\App\Services\BigTestSlaService::class);
        $days = $sla->daysUntil($bt);
        if ($days > \App\Services\BigTestSlaService::PAPER_WARN_DAYS) {
            return null;
        }

        return $sla->paperOverdue($bt)
            ? ['level' => 'overdue', 'label' => 'Quá hạn duyệt đề (trước '.\App\Services\BigTestSlaService::PAPER_APPROVE_BEFORE_DAYS.' ngày) — còn '.$days.' ngày']
            : ['level' => 'warn', 'label' => "Cần duyệt đề trước ngày thi ".\App\Services\BigTestSlaService::PAPER_APPROVE_BEFORE_DAYS." ngày — còn {$days} ngày"];
    }

    /** Dữ liệu hộp thoại chi tiết order đề (màn Duyệt & phân phối đề Big Test). */
    private function orderDetail(BigTestOrder $order, bool $canReview): array
    {
        $oc = $order->classModel;
        $reviewing = $canReview && ! in_array($order->status, ['approved', 'rejected'], true);
        $classTests = $reviewing && $order->test_type === 'big'
            ? BigTest::where('class_id', $order->class_id)->whereNull('results_completed_at')->latest('scheduled_at')->get()
            : collect();

        return [
            'id' => $order->id,
            'code' => $order->code,
            'class_code' => $oc?->code,
            'class_label' => $oc?->name.($oc?->code ? ' - '.$oc->code : ''),
            'summary' => (collect([$oc?->course?->name, $oc?->branch?->name])->filter()->implode(' · ') ?: '—').'. Đề '.$order->type_label.' cho '.$order->stage_label.'.',
            'status' => $order->status,
            'status_label' => $order->status_label,
            'status_color' => $order->status_color,
            'exam_date' => $order->exam_date?->format('d/m/Y'),
            'teacher' => $order->teacher?->name,
            'due_date' => $order->due_date?->format('d/m/Y'),
            'overdue' => $order->isOverdue(),
            'sla_warning' => $order->isSlaWarning(),
            'note' => $order->note,
            'reviewer' => $order->reviewer?->name,
            'reviewed_at' => $order->reviewed_at?->format('H:i d/m/Y'),
            'rejection_reason' => $order->rejection_reason,
            'test_link' => $canReview ? $order->test_link : null,
            'speaking_link' => $order->speaking_link,
            'big_test' => $order->bigTest ? [
                'code' => $order->bigTest->code,
                'scheduled_at' => $order->bigTest->scheduled_at?->format('H:i d/m/Y'),
                'room' => $order->bigTest->room,
            ] : null,
            'reviewing' => $reviewing,
            'is_big' => $order->test_type === 'big',
            'default_scheduled_at' => $order->exam_date?->copy()->setTime(8, 0)->format('Y-m-d\TH:i'),
            'default_room' => $oc?->room,
            'class_tests' => Ui::options($classTests, fn (BigTest $t) => $t->code.' · '.$t->title.($t->scheduled_at ? ' · '.$t->scheduled_at->format('d/m/Y H:i') : '')),
        ];
    }

    /** Các chặng có thể gắn cho đợt thi của lớp: giáo trình của các lượt chặng của lớp + giáo trình theo trình độ lớp. */
    private function stageOptionsForClass(int $classId): Collection
    {
        return $this->stageOptionsForClasses(collect([$classId]))->get($classId);
    }

    /**
     * stageOptionsForClass() cho nhiều lớp bằng 4 truy vấn gộp (trang phân phối Big Test hiển thị nhiều lớp).
     *
     * @return Collection<int, Collection> theo id lớp
     */
    private function stageOptionsForClasses(Collection $classIds): Collection
    {
        $classIds = $classIds->map(fn ($id) => (int) $id)->unique()->values();
        if ($classIds->isEmpty()) {
            return collect();
        }

        $classes = ClassModel::whereIn('id', $classIds)->get(['id', 'level'])->keyBy('id');
        $assigned = SyllabusAssignment::whereIn('class_id', $classIds)->get(['class_id', 'curriculum_id'])->groupBy('class_id');
        $levelCurriculum = CourseLevel::whereIn('code', $classes->pluck('level')->filter()->unique()->values())
            ->get(['code', 'syllabus_curriculum_id'])
            ->groupBy('code')->map(fn ($levels) => $levels->first()->syllabus_curriculum_id);

        $curriculumIdsByClass = $classIds->mapWithKeys(fn (int $classId) => [$classId => $assigned->get($classId, collect())->pluck('curriculum_id')
            ->push(($class = $classes->get($classId)) ? $levelCurriculum->get($class->level) : null)
            ->filter()->unique()->values()]);

        $stages = SyllabusStage::whereIn('curriculum_id', $curriculumIdsByClass->flatten()->unique()->values())
            ->orderBy('curriculum_id')->orderBy('position')->get();

        return $curriculumIdsByClass->map(fn ($curriculumIds) => $stages
            ->filter(fn (SyllabusStage $stage) => $curriculumIds->contains($stage->curriculum_id))
            ->values());
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

    /**
     * Duyệt & phân phối order đề (Học thuật). Kèm link đề (bắt buộc) và link phần Speaking (GV chỉ xem phần này).
     * Order Big Test: gắn vào đợt thi có sẵn của lớp (big_test_id) hoặc — khi không chọn — hệ thống tự tạo đợt
     * Big Test từ ngày giờ thi + phòng thi nhập ở form duyệt, gắn chặng đang mở của lớp (Big Test cuối chặng) và
     * phân phối luôn để GV nhập kết quả.
     */
    public function approveBigTestOrder(Request $request, int $id)
    {
        $order = BigTestOrder::with('classModel')->findOrFail($id);
        $this->ensurePending($order->status);
        $isBig = $order->test_type === 'big';
        $validated = $request->validate([
            'test_link' => ['required', 'url', 'max:500'],
            'speaking_link' => ['nullable', 'url', 'max:500'],
            'big_test_id' => ['nullable', Rule::exists('big_tests', 'id')->where('class_id', $order->class_id)],
            'scheduled_at' => [Rule::requiredIf($isBig && ! $request->filled('big_test_id')), 'nullable', 'date'],
            'room' => [Rule::requiredIf($isBig && ! $request->filled('big_test_id')), 'nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
        ], [
            'test_link.required' => 'Vui lòng nhập link đề trước khi phê duyệt.',
            'big_test_id.exists' => 'Đợt Big Test không thuộc lớp của order.',
            'scheduled_at.required' => 'Vui lòng chọn ngày giờ thi để tạo đợt Big Test (hoặc gắn đợt thi có sẵn).',
            'room.required' => 'Vui lòng nhập phòng thi để tạo đợt Big Test (hoặc gắn đợt thi có sẵn).',
        ]);

        $created = null;
        DB::transaction(function () use ($order, $validated, $isBig, &$created) {
            $bigTestId = $validated['big_test_id'] ?? null;
            if (! $bigTestId && $isBig) {
                $created = $this->createBigTestForOrder($order, $validated);
                $bigTestId = $created->id;
            }

            $order->update([
                'status' => 'approved',
                'test_link' => $validated['test_link'],
                'speaking_link' => $validated['speaking_link'] ?? null,
                'big_test_id' => $bigTestId,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);
            if ($order->big_test_id) {
                BigTest::whereKey($order->big_test_id)->whereNull('content_url')->update(['content_url' => $order->test_link]);
                if ($order->speaking_link) {
                    BigTest::whereKey($order->big_test_id)->update(['speaking_url' => $order->speaking_link]);
                }
                // Đợt thi chưa gắn chặng → gắn chặng của order (Big Test cuối chặng).
                if ($order->syllabus_stage_id) {
                    BigTest::whereKey($order->big_test_id)->whereNull('syllabus_stage_id')->update(['syllabus_stage_id' => $order->syllabus_stage_id]);
                }
                // "Duyệt & phân phối đề" cho đợt thi đã gắn: đợt thi được phân phối luôn (GV nhập được kết quả).
                BigTest::whereKey($order->big_test_id)->where('is_distributed', false)->update([
                    'status' => 'distributed',
                    'is_distributed' => true,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'distributed_at' => now(),
                ]);
                app(\App\Services\BigTestSlaService::class)->closeApprovalTasks(BigTest::find($order->big_test_id));
            }
        });

        $createdNote = $created ? " Đã tạo đợt thi {$created->code} lúc ".$created->scheduled_at->format('H:i d/m/Y')." tại {$created->room}." : '';
        $this->notifyUser($order->teacher_id, 'Order đề đã được duyệt', "Đề {$order->type_label} chặng \"{$order->stage_name}\" lớp {$order->classModel?->name} đã được phân phối.".$createdNote, route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'approved']));

        return redirect()->route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'approved'])
            ->with('status', "Đã duyệt và phân phối đề cho order {$order->code}.".$createdNote);
    }

    /** Tạo đợt Big Test cho order vừa duyệt: gắn chặng đang mở của lớp (không có thì chặng của order), phân phối ở bước duyệt. */
    private function createBigTestForOrder(BigTestOrder $order, array $validated): BigTest
    {
        $open = SyllabusAssignment::open()->with('stage')->where('class_id', $order->class_id)->first();
        $stageId = $open?->stage_id ?? $order->syllabus_stage_id;
        $stageLabel = $open ? ($open->stage?->label ?? $open->stage_name) : $order->stage_label;
        $title = trim((string) ($validated['title'] ?? ''))
            ?: trim(($open?->stage?->big_test_title ?: 'Big Test '.$stageLabel).' · '.$order->classModel?->name, ' ·');

        return BigTest::create([
            'code' => app(DocumentCodeGenerator::class)->bigTestCode(),
            'title' => mb_substr($title, 0, 255),
            'class_id' => $order->class_id,
            'syllabus_stage_id' => $stageId,
            'test_type' => 'stage_end',
            'scheduled_at' => $validated['scheduled_at'],
            'room' => $validated['room'],
            'proctor_id' => $order->classModel?->teacher_id ?? $order->teacher_id,
            'passcode' => 'MEN'.random_int(1000, 9999),
            'content_url' => $validated['test_link'],
            'speaking_url' => $validated['speaking_link'] ?? null,
            'is_distributed' => false,
            'status' => 'draft',
        ]);
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
            'class_id' => ['required', Rule::in(ClassModel::visibleTo($request->user())->pluck('id')->all())],
            'test_type' => 'required|string|max:50',
            'scheduled_at' => 'required|date',
            'room' => 'required|string',
            'syllabus_stage_id' => 'nullable|exists:syllabus_stages,id,deleted_at,NULL',
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
        // Đề đã phân phối → tự đóng việc duyệt đề của Học thuật.
        app(\App\Services\BigTestSlaService::class)->closeApprovalTasks($test);

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

        return Inertia::render('Syllabus/BigTestSchedules', [
            'upcoming' => $upcoming->map(fn ($row) => [
                'id' => $row['assignment']->id,
                'code' => $row['assignment']->code,
                'class_name' => $row['assignment']->classModel?->name,
                'stage_label' => $row['assignment']->stage?->label ?? $row['assignment']->stage_name,
                'date' => $row['date']->format('d/m/Y'),
                'exam' => $row['exam'],
                'days_left' => $row['days_left'],
            ])->values(),
            'urgent' => $upcoming->filter(fn ($row) => $row['days_left'] <= 2)->count(),
            'bigTests' => $bigTests->through(fn (BigTest $bt) => [
                'id' => $bt->id,
                'code' => $bt->code,
                'class_name' => $bt->classModel?->name,
                'title' => $bt->title,
                'scheduled_at' => $bt->scheduled_at?->format('d/m/Y H:i'),
                'place' => $bt->room.' · '.($bt->classModel?->branch?->name ?? '—'),
                'proctor' => $bt->proctor?->name,
                'passcode' => $bt->passcodeVisibleTo($user) ? $bt->passcode : '••••••',
                'paper_warning' => $this->paperWarning($bt),
            ]),
            'canManage' => $user->can('syllabus.manage'),
        ]);
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
                        'bg_color' => 'bg-accent-container',
                        'text_color' => 'text-accent',
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

        $isApprover = $user->can('big_test.approve');
        $canGradeRole = $user->can('syllabus.update') && ! $isApprover;
        $taken = $results->where('is_absent', false)->whereNotNull('overall_score');
        $resultsByStudent = $results->keyBy('student_id');
        $daysLeft = $test?->resultsDaysLeft();

        return Inertia::render('Syllabus/BigTestResults', [
            'test' => $test ? [
                'id' => $test->id,
                'code' => $test->code,
                'room' => $test->room,
                'class_name' => $test->classModel?->name,
                'class_teacher' => $test->classModel?->teacher?->name,
                'is_distributed' => (bool) $test->is_distributed,
                'results_due' => $test->resultsDueAt()?->format('d/m/Y'),
                'results_overdue' => $test->resultsDaysLeft() < 0 && ! $test->results_completed_at,
                'days_left' => $daysLeft,
                'stage_badge' => $test->stage ? 'Big Test cuối '.$test->stage->label.($test->results_completed_at ? ' · đã hoàn tất' : '') : null,
                'stage_label' => $test->stage ? 'Big Test - '.$test->stage->label : 'Big Test - '.($test->test_type === 'final' ? 'Cuối khóa' : 'Giữa kỳ'),
            ] : null,
            'allTests' => $allTests->map(fn (BigTest $t) => ['value' => $t->id, 'label' => '['.$t->code.'] '.$t->title.' · '.$t->classModel?->name])->values(),
            'stats' => [
                'total' => $results->count(),
                'taken' => $taken->count(),
                'absent' => $results->where('is_absent', true)->count(),
                'avg' => $taken->count() > 0 ? round($taken->avg('overall_score'), 1) : 0,
                'highest' => $taken->count() > 0 ? $taken->max('overall_score') : 0,
            ],
            'rows' => $students->values()->map(function (Student $student) use ($resultsByStudent, $missingParentPhone) {
                $res = $resultsByStudent->get($student->id);

                return [
                    'student_id' => $student->id,
                    'name' => $student->name,
                    'code' => $student->code ?? 'HV-'.$student->id,
                    'result' => $res ? [
                        'id' => $res->id,
                        'status' => $res->status,
                        'status_label' => $res->status_label,
                        'locked' => $res->isLocked(),
                        'is_absent' => (bool) $res->is_absent,
                        'listening_score' => $res->listening_score,
                        'reading_score' => $res->reading_score,
                        'writing_score' => $res->writing_score,
                        'speaking_score' => $res->speaking_score,
                        'overall_score' => $res->overall_score,
                        'progress_note' => $res->progress_note,
                        'video_url' => $res->video_url,
                        'parent_notified' => (bool) $res->parent_notified,
                        'notified_at' => $res->notified_at?->format('d/m H:i'),
                        'missing_phone' => in_array((int) $res->student_id, $missingParentPhone, true),
                    ] : null,
                ];
            }),
            'selectedResult' => $test && $selectedResult ? [
                'id' => $selectedResult->id,
                'student' => $selectedResult->student?->name,
                'student_code' => $selectedResult->student?->code ?? 'HV-'.$selectedResult->student_id,
                'is_absent' => (bool) $selectedResult->is_absent,
                'listening_score' => $selectedResult->listening_score,
                'reading_score' => $selectedResult->reading_score,
                'writing_score' => $selectedResult->writing_score,
                'speaking_score' => $selectedResult->speaking_score,
                'overall_score' => $selectedResult->overall_score,
                'video_url' => $selectedResult->video_url,
                'progress_note' => $selectedResult->progress_note,
                'status' => $selectedResult->status,
                'status_label' => $selectedResult->status_label,
                'grader' => $selectedResult->grader?->name,
                'approver' => $selectedResult->approver?->name,
                'parent_notified' => (bool) $selectedResult->parent_notified,
                'notified_at' => $selectedResult->notified_at?->format('H:i d/m/Y'),
            ] : null,
            'userName' => $user->name,
            'isApprover' => $isApprover,
            'canGradeRole' => $canGradeRole,
            'canGrade' => $test && $test->is_distributed && $canGradeRole,
            'backUrl' => $user->can('syllabus.manage') || $isApprover ? route('syllabus.big-tests.distribution') : null,
            'resultDeadlineDays' => BigTest::RESULT_DEADLINE_DAYS,
            'missingPhoneLabel' => self::MISSING_PARENT_PHONE,
        ]);
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
            // Dòng để trống toàn bộ được bỏ qua; gửi duyệt thì đã nhập một kỹ năng phải nhập đủ 4. Vắng thi đánh dấu riêng.
            $others = collect($skills)->reject(fn ($s) => $s === $skill)->map(fn ($s) => "results.*.{$s}")->implode(',');
            $rules["results.*.{$skill}"] = $isDraft
                ? ['nullable', 'numeric', 'between:0,10']
                : ['nullable', "required_with:{$others}", 'numeric', 'between:0,10'];
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

        $pendingDrafts = BigTestResult::where('big_test_id', $test->id)->where('status', 'draft')->whereNotIn('student_id', $rows->pluck('student_id'))->get();
        if ($rows->isEmpty() && ($isDraft || $pendingDrafts->isEmpty())) {
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

        $status = $isDraft ? 'draft' : 'pending_review';
        // "Lưu nháp" không kéo các dòng đã gửi duyệt về nháp (vẫn nằm trong hàng chờ duyệt của Học thuật).
        $awaitingReview = BigTestResult::where('big_test_id', $test->id)->where('status', 'pending_review')->pluck('student_id')->map(fn ($id) => (int) $id);
        $promoted = 0;
        DB::transaction(function () use ($rows, $test, $skills, $isAbsent, $status, $isDraft, $pendingDrafts, $awaitingReview, &$promoted) {
            foreach ($rows as $row) {
                $rowStatus = $isDraft && $awaitingReview->contains((int) $row['student_id']) ? 'pending_review' : $status;
                $absent = $isAbsent($row);
                // Vắng thi: không lưu điểm (null) thay vì 0 để không kéo điểm trung bình.
                $scores = collect($skills)->mapWithKeys(fn ($s) => [$s => $absent || ! isset($row[$s]) || $row[$s] === '' ? null : $row[$s]]);
                $complete = $scores->filter(fn ($v) => $v !== null)->count() === count($skills);
                BigTestResult::updateOrCreate(
                    ['big_test_id' => $test->id, 'student_id' => $row['student_id']],
                    $scores->all() + [
                        'is_absent' => $absent,
                        'progress_note' => $row['progress_note'] ?? null,
                        'video_url' => $row['video_url'] ?? null,
                        'overall_score' => $absent || ! $complete ? null : round($scores->avg(), 1),
                        'status' => $rowStatus,
                        'graded_by' => Auth::id(),
                        'approved_by' => null,
                        'approved_at' => null,
                    ]
                );
            }

            // Gửi duyệt: các bản nháp còn lại của đợt thi đã đủ điểm (hoặc vắng thi) cũng được gửi luôn.
            if (! $isDraft) {
                foreach ($pendingDrafts as $draft) {
                    $complete = $draft->is_absent || collect($skills)->every(fn ($s) => $draft->$s !== null);
                    if ($complete) {
                        $draft->update(['status' => 'pending_review', 'graded_by' => $draft->graded_by ?? Auth::id()]);
                        $promoted++;
                    }
                }
            }
        });

        $count = $rows->count() + $promoted;
        $leftDrafts = BigTestResult::where('big_test_id', $test->id)->where('status', 'draft')->count();

        return redirect()->back()->with('status', $isDraft
            ? "Đã lưu nháp kết quả {$rows->count()} học viên (chưa gửi Học thuật duyệt)."
            : "Đã gửi duyệt kết quả {$count} học viên; kết quả đang chờ Học thuật duyệt."
                .($leftDrafts > 0 ? " Còn {$leftDrafts} bản nháp chưa đủ điểm." : ''));
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
