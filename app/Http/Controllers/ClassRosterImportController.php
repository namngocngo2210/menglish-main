<?php

namespace App\Http\Controllers;

use App\Exports\ClassRosterTemplateExport;
use App\Models\Branch;
use App\Services\ClassRosterImportService;
use App\Support\DataScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Nhập danh sách lớp học viên từ hệ thống cũ — 3 bước: Tải file → Xem trước (lớp + học viên, lỗi / cảnh báo) → Kết quả.
 * Cần quyền tạo lớp (route) và xếp lớp học viên.
 */
class ClassRosterImportController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeImport($request);
        $preview = $request->filled('token') ? $request->session()->get('class_roster_import.'.$request->input('token')) : null;
        $result = $request->session()->get('class_roster_import_result');

        return Inertia::render('Classes/Import', [
            'branches' => $this->branches($request)->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->values(),
            'defaults' => ['sessions_from' => today()->toDateString(), 'sessions_to' => today()->addMonths(3)->toDateString()],
            'preview' => $preview ? [
                'token' => $request->input('token'),
                'file_name' => $preview['file_name'],
                'sessions_from' => Carbon::parse($preview['sessions_from'])->format('d/m/Y'),
                'sessions_to' => Carbon::parse($preview['sessions_to'])->format('d/m/Y'),
                'classes' => collect($preview['classes'])->map(fn (array $c) => [
                    'line' => $c['line'],
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'branch' => $c['branch_name'],
                    'exists' => (bool) $c['class_id'],
                    'teacher' => $c['teacher_label'],
                    'teacher_matched' => (bool) $c['teacher_id'],
                    'schedule' => collect($c['slots'])->map(fn ($s) => "{$s['day']} {$s['start']}–{$s['end']}".($s['room'] ? " · {$s['room']}" : ''))->values(),
                    'student_count' => $c['student_count'],
                    'errors' => $c['errors'],
                    'warnings' => $c['warnings'],
                ])->values(),
                'students' => collect($preview['students'])->map(fn (array $s) => [
                    'line' => $s['line'],
                    'action' => $s['action'],
                    'code' => $s['code'],
                    'name' => $s['name'],
                    'class_code' => $s['class_code'],
                    'phone' => $s['phone'],
                    'parent' => trim(($s['parent_name'] ?? '').' '.($s['parent_phone'] ?? '')) ?: null,
                    'errors' => $s['errors'],
                    'warnings' => $s['warnings'],
                ])->values(),
            ] : null,
            'result' => $result,
            'classHeadings' => implode(', ', ClassRosterImportService::CLASS_HEADINGS),
            'studentHeadings' => implode(', ', ClassRosterImportService::STUDENT_HEADINGS),
        ]);
    }

    public function preview(Request $request, ClassRosterImportService $importer)
    {
        $this->authorizeImport($request);
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'sessions_from' => 'required|date',
            'sessions_to' => 'required|date|after_or_equal:sessions_from',
            'excel_file' => 'required|file|max:20480|mimes:xlsx,xls,csv,txt',
        ], [
            'excel_file.required' => 'Vui lòng chọn file Excel danh sách lớp.',
            'excel_file.mimes' => 'Chỉ hỗ trợ file .xlsx, .xls hoặc .csv.',
            'sessions_to.after_or_equal' => 'Ngày kết thúc sinh buổi học phải sau ngày bắt đầu.',
        ], ['sessions_from' => 'ngày bắt đầu sinh buổi học', 'sessions_to' => 'ngày kết thúc sinh buổi học']);

        try {
            $parsed = $importer->parse($request->file('excel_file'), isset($validated['branch_id']) ? (int) $validated['branch_id'] : null, $request->user());
        } catch (\Throwable $e) {
            Log::warning('Không đọc được file nhập danh sách lớp: '.$e->getMessage());

            return redirect()->route('classes.import')->withErrors(['excel_file' => 'Không đọc được file. Vui lòng dùng file mẫu (.xlsx).']);
        }

        if ($parsed['missing']) {
            return redirect()->route('classes.import')->withErrors(['excel_file' => $parsed['missing']]);
        }
        if ($parsed['students'] === []) {
            return redirect()->route('classes.import')->withErrors(['excel_file' => 'File không có học viên nào.']);
        }

        $token = (string) Str::uuid();
        $request->session()->put('class_roster_import.'.$token, $parsed + [
            'file_name' => $request->file('excel_file')->getClientOriginalName(),
            'sessions_from' => $validated['sessions_from'],
            'sessions_to' => $validated['sessions_to'],
        ]);

        return redirect()->route('classes.import', ['token' => $token]);
    }

    public function confirm(Request $request, ClassRosterImportService $importer)
    {
        $this->authorizeImport($request);
        $validated = $request->validate(['token' => 'required|string']);
        $preview = $request->session()->pull('class_roster_import.'.$validated['token']);
        if (! $preview) {
            return redirect()->route('classes.import')->withErrors(['excel_file' => 'Phiên xem trước đã hết hạn, vui lòng tải file lên lại.']);
        }

        // Kiểm lại phạm vi cơ sở lúc ghi (quyền có thể đổi giữa hai bước).
        foreach ([...$preview['classes'], ...$preview['students']] as $row) {
            abort_if($row['branch_id'] && ! DataScope::coversBranch($request->user(), 'class', (int) $row['branch_id']), 403, 'Bạn chỉ được nhập lớp cho cơ sở mình quản lý.');
        }

        $result = $importer->import($preview, $request->user(), Carbon::parse($preview['sessions_from']), Carbon::parse($preview['sessions_to']));
        $result['file_name'] = $preview['file_name'];
        $result['skipped'] = collect($preview['students'])->filter(fn ($s) => $s['errors'] !== [] || $s['action'] === 'skip')->count();

        return redirect()->route('classes.import')
            ->with('class_roster_import_result', $result)
            ->with('status', "Đã tạo {$result['classes']} lớp, {$result['students']} học viên và xếp {$result['enrollments']} lượt vào lớp.");
    }

    public function template()
    {
        return Excel::download(new ClassRosterTemplateExport, 'mau-nhap-danh-sach-lop.xlsx');
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->user()->can('student.assign_class'), 403, 'Bạn không có quyền xếp lớp học viên.');
    }

    private function branches(Request $request)
    {
        return Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Branch $b) => DataScope::coversBranch($request->user(), 'class', $b->id));
    }
}
