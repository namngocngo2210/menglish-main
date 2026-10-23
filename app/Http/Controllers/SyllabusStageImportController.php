<?php

namespace App\Http\Controllers;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Services\Syllabus\Import\SyllabusImportStore;
use App\Services\Syllabus\StageImport\StageImporter;
use App\Services\Syllabus\StageImport\StageImportException;
use App\Services\Syllabus\StageImport\StageImportPlan;
use App\Services\Syllabus\StageImport\StageImportPlanner;
use App\Services\Syllabus\StageImport\StageImportTemplate;
use App\Services\Syllabus\StageImport\StageSheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

/**
 * Import 1 chặng của giáo trình từ file .xlsx theo form Sheet 1 (docs/kho-syllabus.md §3.3):
 * ① Tải file (thêm chặng mới hoặc cập nhật chặng) → ② Xem trước (không ghi DB) → ③ Xác nhận.
 * Quyền: middleware `can:syllabus.library` của nhóm route. File lưu tạm theo token trong phiên (SyllabusImportStore).
 */
class SyllabusStageImportController extends Controller
{
    private const UNREADABLE = 'Không đọc được file Excel — hãy dùng file .xlsx theo file mẫu.';

    public function __construct(
        private readonly SyllabusImportStore $store,
        private readonly StageSheetReader $reader,
        private readonly StageImportPlanner $planner,
        private readonly StageImporter $importer,
    ) {}

    public function template()
    {
        return response()->download(app(StageImportTemplate::class)->build(), 'mau-syllabus-chang.xlsx')->deleteFileAfterSend();
    }

    public function preview(Request $request, int $curriculum)
    {
        $curriculum = SyllabusCurriculum::findOrFail($curriculum);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:xlsx'],
            'stage_id' => ['nullable', Rule::exists('syllabus_stages', 'id')->whereNull('deleted_at')->where('curriculum_id', $curriculum->id)],
        ], [
            'file.required' => 'Hãy chọn file .xlsx.',
            'file.max' => 'File tối đa 20MB.',
            'file.extensions' => 'Chỉ nhận file .xlsx theo file mẫu.',
            'stage_id.exists' => 'Chặng không thuộc giáo trình này.',
        ]);

        $token = $this->store->put($data['file'], $request->user(), [], ['curriculum_id' => $curriculum->id, 'stage_id' => $data['stage_id'] ?? null]);
        $meta = $this->store->get($token, $request->user());
        try {
            $this->reader->read($meta['path']);
        } catch (StageImportException $e) {
            $this->store->forget($token);

            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);
            $this->store->forget($token);

            return back()->withErrors(['file' => self::UNREADABLE]);
        }

        return redirect()->route('syllabus.stage-import.show', ['curriculum' => $curriculum->id, 'token' => $token]);
    }

    public function show(Request $request, int $curriculum)
    {
        $curriculum = SyllabusCurriculum::findOrFail($curriculum);
        $token = $this->token($request);
        $loaded = $this->loadPlan($token, $curriculum, $request);
        if ($loaded instanceof RedirectResponse) {
            return $loaded;
        }
        [$stage, $plan] = $loaded;
        $deleted = SyllabusLesson::whereKey($plan->deleteLessonIds)->orderBy('session_no')->get(['session_no', 'title']);

        return Inertia::render('Syllabus/StageImport', [
            'curriculum' => ['id' => $curriculum->id, 'title' => $curriculum->title, 'code' => $curriculum->code],
            'stage' => $stage ? ['id' => $stage->id, 'label' => $stage->label] : null,
            'token' => $token,
            'plan' => [
                'ok' => $plan->ok(),
                'stage_name' => $plan->stageName,
                'summary' => $plan->summary(),
                'errors' => $plan->errors,
                'warnings' => $plan->warnings,
                'lessons' => collect($plan->lessons)->map(fn (array $l) => [
                    'line' => $l['line'],
                    'unit_number' => $l['unit_number'],
                    'title' => $l['attributes']['title'],
                    'type' => SyllabusLesson::TYPES[$l['attributes']['lesson_type']] ?? '',
                    'action' => $l['lesson_id'] ? 'Sửa' : 'Thêm',
                ])->all(),
                'deletions' => $deleted->map(fn (SyllabusLesson $l) => "Buổi {$l->session_no}: {$l->title}")->all(),
            ],
        ]);
    }

    public function confirm(Request $request, int $curriculum)
    {
        $curriculum = SyllabusCurriculum::findOrFail($curriculum);
        $token = $this->token($request);
        $loaded = $this->loadPlan($token, $curriculum, $request);
        if ($loaded instanceof RedirectResponse) {
            return $loaded;
        }
        [, $plan] = $loaded;
        if (! $plan->ok()) {
            return redirect()->route('syllabus.stage-import.show', ['curriculum' => $curriculum->id, 'token' => $token])
                ->withErrors(['file' => 'File còn lỗi, hãy sửa file rồi tải lên lại.']);
        }

        $stage = $this->importer->import($plan, $curriculum, $request->user());
        $this->store->forget($token);
        $s = $plan->summary();

        return redirect()->route('syllabus.curriculums.show', ['id' => $curriculum->id, 'tab' => 'stages'])
            ->with('status', "Đã import {$stage->label}: {$s['create']} thêm, {$s['update']} sửa, {$s['delete']} xóa.");
    }

    /**
     * Đọc lại file của phiên và lập kế hoạch với dữ liệu hiện tại (chặng có thể đã đổi từ lúc tải lên).
     *
     * @return array{0: ?SyllabusStage, 1: StageImportPlan}|RedirectResponse
     */
    private function loadPlan(string $token, SyllabusCurriculum $curriculum, Request $request): array|RedirectResponse
    {
        $detail = redirect()->route('syllabus.curriculums.show', ['id' => $curriculum->id, 'tab' => 'stages']);
        $meta = $token === '' ? null : $this->store->get($token, $request->user());
        if ($meta === null || ($meta['context']['curriculum_id'] ?? null) !== $curriculum->id) {
            return $detail->with('error', 'Phiên import đã hết hạn, vui lòng tải file lên lại.');
        }

        $stage = ($stageId = $meta['context']['stage_id'] ?? null) ? SyllabusStage::where('curriculum_id', $curriculum->id)->find($stageId) : null;
        if ($stageId && ! $stage) {
            $this->store->forget($token);

            return $detail->with('error', 'Chặng cần cập nhật không còn tồn tại.');
        }

        try {
            return [$stage, $this->planner->plan($this->reader->read($meta['path']), $curriculum, $stage)];
        } catch (StageImportException $e) {
            $this->store->forget($token);

            return $detail->with('error', $e->getMessage());
        }
    }

    private function token(Request $request): string
    {
        $token = $request->input('token');

        return is_string($token) ? $token : '';
    }
}
