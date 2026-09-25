<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\DocumentCodeGenerator;
use App\Services\SafeUploadService;
use App\Services\ScheduleExtensionService;
use App\Services\ZaloZnsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SyllabusController extends Controller
{
    // ─────────────────────────────────────────────
    // 1. Kho tài liệu giáo trình (file thật, phân quyền xem)
    // ─────────────────────────────────────────────

    public function documents(Request $request)
    {
        $user = $request->user();
        $curriculums = SyllabusCurriculum::with('course')->orderBy('title')->get();
        $courses = Course::orderBy('name')->get();
        $documents = SyllabusDocument::with(['curriculum', 'uploader'])
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
            'stage_name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:'.SyllabusDocument::MAX_KB],
            'visible_to_teachers' => ['nullable', 'boolean'],
            'visible_to_assistants' => ['nullable', 'boolean'],
            'downloadable' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'Vui lòng chọn file tài liệu.',
            'file.max' => 'Dung lượng file tối đa 100 MB.',
        ]);

        $file = $request->file('file');
        $size = (int) $file->getSize();
        $mime = $file->getMimeType();
        $path = SafeUploadService::store($file, SyllabusDocument::DIRECTORY, SyllabusDocument::ALLOWED_EXTENSIONS, 'file', SyllabusDocument::DISK);

        $doc = SyllabusDocument::create([
            'curriculum_id' => $validated['curriculum_id'],
            'title' => $validated['title'],
            'stage_name' => $validated['stage_name'] ?? null,
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
    // 2. Soạn syllabus: chọn giáo trình, thông tin chặng, CRUD bài học
    // ─────────────────────────────────────────────

    public function builder(Request $request)
    {
        $curriculums = SyllabusCurriculum::with('course')->orderBy('title')->get();
        $curriculum = $request->filled('curriculum')
            ? SyllabusCurriculum::with('units')->findOrFail($request->integer('curriculum'))
            : SyllabusCurriculum::with('units')->orderBy('title')->first();
        $units = $curriculum ? $curriculum->units : collect();
        $editUnit = $request->filled('edit_unit') && $curriculum
            ? $units->firstWhere('id', $request->integer('edit_unit'))
            : null;
        $courses = Course::orderBy('name')->get();

        return view('syllabus.builder', compact('curriculums', 'curriculum', 'units', 'editUnit', 'courses'));
    }

    public function storeCurriculum(Request $request)
    {
        $validated = $request->validate($this->curriculumRules() + [
            'code' => ['required', 'string', 'max:50', 'unique:syllabus_curriculums,code'],
        ]);

        $curriculum = SyllabusCurriculum::create($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $curriculum->id])
            ->with('status', "Đã tạo giáo trình {$curriculum->title}.");
    }

    public function updateCurriculum(Request $request, int $id)
    {
        $curriculum = SyllabusCurriculum::findOrFail($id);
        $validated = $request->validate($this->curriculumRules() + [
            'code' => ['required', 'string', 'max:50', Rule::unique('syllabus_curriculums', 'code')->ignore($curriculum->id)],
        ]);

        $curriculum->update($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $curriculum->id])
            ->with('status', "Đã lưu thông tin chặng / giáo trình {$curriculum->title}.");
    }

    public function destroyCurriculum(int $id)
    {
        $curriculum = SyllabusCurriculum::withCount(['assignments' => fn ($q) => $q->where('status', 'in_progress')])->findOrFail($id);
        if ($curriculum->assignments_count > 0) {
            return redirect()->back()->with('error', "Giáo trình {$curriculum->title} đang được giao cho lớp, không thể xóa.");
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
            'stage_name' => ['nullable', 'string', 'max:255'],
            'unlock_policy' => ['nullable', Rule::in(array_keys(SyllabusCurriculum::UNLOCK_POLICIES))],
            'overview_link' => ['nullable', 'url', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function storeUnit(Request $request)
    {
        $validated = $request->validate($this->unitRules() + [
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id'],
        ]);
        $this->ensureUniqueUnitNumber((int) $validated['curriculum_id'], (int) $validated['unit_number']);

        $unit = SyllabusUnit::create($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã lưu bài {$unit->title}.");
    }

    public function updateUnit(Request $request, int $id)
    {
        $unit = SyllabusUnit::findOrFail($id);
        $validated = $request->validate($this->unitRules());
        $this->ensureUniqueUnitNumber($unit->curriculum_id, (int) $validated['unit_number'], $unit->id);

        $unit->update($validated);

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã cập nhật bài {$unit->title}.");
    }

    public function destroyUnit(int $id)
    {
        $unit = SyllabusUnit::findOrFail($id);
        $unit->delete();

        return redirect()->route('syllabus.builder', ['curriculum' => $unit->curriculum_id])
            ->with('status', "Đã xóa bài {$unit->title}.");
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
            throw ValidationException::withMessages(['unit_number' => "Buổi số {$number} đã có trong giáo trình này."]);
        }
    }

    // ─────────────────────────────────────────────
    // 3. Giao chặng cho lớp: mỗi lớp chỉ 1 chặng đang áp dụng
    // ─────────────────────────────────────────────

    public function assignments(Request $request)
    {
        $assignments = SyllabusAssignment::with(['teacher', 'curriculum', 'classModel'])
            ->latest()
            ->paginate($request->perPage(20))
            ->withQueryString();
        $teachers = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['teacher', 'teacher_fulltime', 'teacher_parttime', 'academic_lead']))->orderBy('name')->get();
        $curriculums = SyllabusCurriculum::orderBy('title')->get();
        $classes = ClassModel::where('status', '!=', 'cancelled')->orderBy('name')->get();

        return view('syllabus.assignments', compact('assignments', 'teachers', 'curriculums', 'classes'));
    }

    public function storeAssignment(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'curriculum_id' => 'required|exists:syllabus_curriculums,id',
            'class_id' => 'required|exists:classes,id',
            'assigned_chapters' => 'required|string',
            'stage_name' => 'required|string|max:255',
            'deadline' => 'required|date',
        ]);

        // R19: mỗi lớp chỉ có 1 chặng đang áp dụng; phải hoàn thành chặng hiện tại trước khi giao chặng mới.
        $current = SyllabusAssignment::where('class_id', $validated['class_id'])->where('status', 'in_progress')->first();
        if ($current) {
            throw ValidationException::withMessages([
                'class_id' => "Lớp đang áp dụng chặng \"{$current->stage_name}\". Hãy đánh dấu hoàn thành chặng hiện tại trước khi giao chặng mới.",
            ]);
        }

        SyllabusAssignment::create($validated + ['progress_percent' => 0, 'status' => 'in_progress']);

        return redirect()->route('syllabus.assignments')
            ->with('status', 'Đã giao chặng cho lớp và giáo viên thành công!');
    }

    public function completeAssignment(int $id)
    {
        $assignment = SyllabusAssignment::findOrFail($id);
        abort_unless($assignment->status === 'in_progress', 422, 'Chặng này không ở trạng thái đang áp dụng.');
        $assignment->update(['status' => 'completed', 'progress_percent' => 100]);

        return redirect()->back()->with('status', "Đã đóng chặng {$assignment->stage_name}; có thể giao chặng tiếp theo cho lớp.");
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
        $proposals = SyllabusChangeProposal::with(['curriculum', 'unit', 'proposer'])
            ->visibleTo($user)
            ->when(in_array($status, array_keys(SyllabusChangeProposal::STATUS_LABELS), true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        $selected = null;
        if ($request->filled('proposal')) {
            $selected = SyllabusChangeProposal::with(['curriculum', 'unit', 'proposer', 'reviewer'])->findOrFail($request->integer('proposal'));
            abort_unless($selected->isVisibleTo($user), 404);
        } else {
            $selected = $proposals->first()?->load('reviewer');
        }

        $pendingCount = SyllabusChangeProposal::visibleTo($user)->where('status', 'pending')->count();

        return view('syllabus.versions', compact('proposals', 'selected', 'pendingCount', 'status'));
    }

    public function teacherPropose(Request $request)
    {
        $curriculums = SyllabusCurriculum::with('units')->orderBy('title')->get();
        $proposals = SyllabusChangeProposal::with(['curriculum', 'unit'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->perPage(10))
            ->withQueryString();

        return view('syllabus.teacher-propose', compact('curriculums', 'proposals'));
    }

    public function storeProposal(Request $request)
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'exists:syllabus_curriculums,id'],
            'unit_id' => ['nullable', Rule::exists('syllabus_units', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
            'proposal_type' => ['nullable', 'string', 'max:255'],
            'old_content' => ['nullable', 'string', 'max:5000'],
            'new_content' => ['required', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:20480'],
        ], [
            'unit_id.exists' => 'Bài học không thuộc giáo trình đã chọn.',
            'new_content.required' => 'Vui lòng nhập nội dung đề xuất sửa.',
        ]);

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
            'message' => Auth::user()->name.' đề xuất sửa giáo trình '.$proposal->curriculum?->title.($proposal->unit ? ' — '.$proposal->unit->title : '').'.',
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

    public function teacherView(Request $request)
    {
        $user = $request->user();
        $documents = SyllabusDocument::with('curriculum.course')->visibleTo($user)->latest()->get();
        $selected = $request->filled('document')
            ? $documents->firstWhere('id', $request->integer('document'))
            : $documents->first();
        abort_if($request->filled('document') && ! $selected, 404);

        $curriculumIds = SyllabusAssignment::where('user_id', $user->id)->pluck('curriculum_id');
        $curriculum = SyllabusCurriculum::with(['course', 'units'])
            ->when(! SyllabusDocument::userManages($user), fn ($query) => $query->whereIn('id', $curriculumIds))
            ->when($selected, fn ($query) => $query->orderByRaw('id = ? desc', [$selected->curriculum_id]))
            ->latest()
            ->first();
        $units = $curriculum ? $curriculum->units : collect();

        return view('syllabus.teacher-view', compact('documents', 'selected', 'curriculum', 'units'));
    }

    // ─────────────────────────────────────────────
    // 5. Điều chỉnh (giãn) tiến độ
    // ─────────────────────────────────────────────

    public function teacherAdjust(Request $request)
    {
        $user = $request->user();
        $classes = ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->orderBy('name')->get();
        $requests = SyllabusAdjustmentRequest::with(['classModel', 'teacher'])
            ->when(! $user->can('syllabus.approve_adjustment'), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('syllabus.teacher-adjust', compact('classes', 'requests'));
    }

    public function adjustmentRequests(Request $request)
    {
        $user = $request->user();
        $requests = SyllabusAdjustmentRequest::with(['classModel', 'teacher', 'approver'])
            ->when(! $user->can('syllabus.approve_adjustment'), fn ($q) => $q->where('user_id', $user->id))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();
        $selected = $request->filled('request')
            ? SyllabusAdjustmentRequest::with(['classModel', 'teacher', 'approver'])->findOrFail($request->integer('request'))
            : $requests->first();
        abort_if($selected && ! $user->can('syllabus.approve_adjustment') && (int) $selected->user_id !== (int) $user->id, 404);
        $classes = ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->orderBy('name')->get();

        return view('syllabus.adjustment-requests', compact('requests', 'selected', 'classes'));
    }

    public function storeAdjustmentRequest(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'request_type' => 'required|string|max:255',
            'reason' => 'required|string|max:2000',
            'extra_sessions' => 'nullable|integer|min:0|max:'.SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS,
        ]);

        $user = $request->user();
        $class = ClassModel::findOrFail($validated['class_id']);
        abort_unless(ClassModel::visibleTo($user)->whereKey($class->id)->exists(), 403, 'Bạn không phụ trách lớp này.');

        SyllabusAdjustmentRequest::create([
            'class_id' => $class->id,
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
            }

            $req->update([
                'status' => 'approved',
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

    public function bigTestDistribution(Request $request)
    {
        $user = $request->user();
        $classes = ClassModel::visibleTo($user)->where('status', '!=', 'cancelled')->orderBy('name')->get();
        $bigTests = BigTest::with(['classModel', 'proctor'])->visibleTo($user)->latest()->paginate($request->perPage(15), ['*'], 'tests_page')->withQueryString();

        $orderStatus = $request->query('order_status', 'pending');
        $orders = BigTestOrder::with(['classModel', 'teacher', 'reviewer'])
            ->visibleTo($user)
            ->when(in_array($orderStatus, array_keys(BigTestOrder::STATUS_LABELS), true), fn ($q) => $q->where('status', $orderStatus))
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest()
            ->paginate($request->perPage(10), ['*'], 'orders_page')
            ->withQueryString();
        $selectedOrder = $request->filled('order')
            ? BigTestOrder::with(['classModel.course', 'teacher', 'reviewer'])->visibleTo($user)->findOrFail($request->integer('order'))
            : $orders->first()?->load('classModel.course');
        $pendingOrders = BigTestOrder::visibleTo($user)->where('status', 'pending')->count();

        return view('syllabus.big-tests-distribution', compact('classes', 'bigTests', 'orders', 'selectedOrder', 'orderStatus', 'pendingOrders'));
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
        ]);

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

        // Đợt thi trong 7 ngày tới (mockup "Nhắc lịch Big Test"): ngày thi, trạng thái đề, số ngày còn lại.
        $upcoming = BigTest::with('classModel')
            ->visibleTo($user)
            ->whereBetween('scheduled_at', [now(), now()->addDays(7)->endOfDay()])
            ->orderBy('scheduled_at')
            ->get();

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
            $test = BigTest::with('classModel')->find($testId);
            abort_unless($test && $test->isAccessibleBy($user), 404);
        } else {
            $test = $allTests->first();
        }

        $results = $test ? BigTestResult::with('student')->where('big_test_id', $test->id)->get() : collect();
        // Danh sách lớp thật (gồm học viên liên kết lớp khác) + học viên đã có kết quả.
        $students = $test?->classModel
            ? $test->classModel->rosterStudents()->concat(Student::whereIn('id', $results->pluck('student_id'))
                ->whereNotIn('id', $test->classModel->roster()->pluck('id'))->orderBy('name')->get())
            : collect();

        return view('syllabus.big-tests-results', compact('test', 'allTests', 'results', 'students'));
    }

    public function storeBigTestResults(Request $request, int $id)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        abort_unless($test->isAccessibleBy($request->user()), 404);
        abort_unless($test->is_distributed, 422, 'Đề thi chưa được duyệt và phân phối.');

        $skills = ['listening_score', 'reading_score', 'writing_score', 'speaking_score'];
        $rules = [
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

    public function approveBigTestResults(int $id)
    {
        $test = BigTest::findOrFail($id);
        BigTestResult::where('big_test_id', $test->id)
            ->where('status', 'pending_review')
            ->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return redirect()->back()->with('status', 'Đã duyệt kết quả Big Test; có thể gửi cho phụ huynh.');
    }

    public function sendZaloResults($id)
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
        if ($failed === [] && $skipped === []) {
            return redirect()->back()->with('status', $message.'.');
        }
        if ($failed !== []) {
            $message .= '; gửi lỗi '.count($failed).' ('.implode(', ', $failed).')';
        }
        if ($skipped !== []) {
            $message .= '; bỏ qua '.count($skipped).' do thiếu số điện thoại ('.implode(', ', $skipped).')';
        }

        return redirect()->back()->with('warning', $message.'.');
    }

    public function sendSingleZaloResult($resultId)
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
            'sent' => redirect()->back()->with('status', "Đã gửi thông báo điểm qua Zalo ZNS đến Phụ huynh em {$studentName} thành công!"),
            'skipped' => redirect()->back()->with('error', "Không gửi được: học viên {$studentName} chưa có số điện thoại liên hệ."),
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
        // Student chưa có cột SĐT phụ huynh riêng → dùng SĐT liên hệ của học viên; không có thì bỏ qua.
        $phone = trim((string) $student?->phone);
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
