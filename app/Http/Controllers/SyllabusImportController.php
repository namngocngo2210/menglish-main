<?php

namespace App\Http\Controllers;

use App\Services\Syllabus\Import\SyllabusImporter;
use App\Services\Syllabus\Import\SyllabusImportException;
use App\Services\Syllabus\Import\SyllabusImportPlan;
use App\Services\Syllabus\Import\SyllabusImportStore;
use App\Services\Syllabus\Import\SyllabusImportTemplate;
use App\Services\Syllabus\Import\SyllabusPackageException;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Import syllabus từ file mẫu (.zip / .xlsx): ① Tải file → ② Xem trước (không ghi DB) → ③ Xác nhận (ghi DB).
 * Quyền: middleware `can:syllabus.library` (Kho syllabus) của nhóm route; người có quyền này cũng được nhập tài liệu (validator).
 */
class SyllabusImportController extends Controller
{
    private const UNREADABLE = 'Không đọc được file. Vui lòng dùng file mẫu (.zip/.xlsx).';

    public function __construct(
        private readonly SyllabusImportStore $store,
        private readonly SyllabusPackageReader $reader,
        private readonly SyllabusPackageValidator $validator,
        private readonly SyllabusImporter $importer,
    ) {}

    public function index(Request $request)
    {
        $canUpload = $request->user()->can('syllabus.library');
        $preview = null;

        if ($request->filled('token')) {
            $token = $this->token($request);
            $loaded = $this->loadPlan($token, $request);
            if ($loaded instanceof RedirectResponse) {
                return $loaded;
            }
            [$meta, $plan] = $loaded;

            // Chỉ gửi những gì cây xem trước hiển thị (không gửi nội dung chi tiết buổi, không gửi đường dẫn file tạm).
            $document = fn (array $d) => ['title' => $d['title'], 'extension' => $d['extension'], 'size' => $d['size']];
            $preview = [
                'token' => $token,
                'file_name' => $meta['file_name'],
                'curriculum' => $plan->curriculum === null ? null : [
                    'code' => $plan->curriculum['code'],
                    'title' => $plan->curriculum['title'],
                    'version' => $plan->curriculum['version'],
                ],
                'summary' => $plan->summary(),
                'errors' => $plan->errors,
                'warnings' => $plan->warnings,
                'stages' => array_map(fn (array $stage) => [
                    'position' => $stage['position'],
                    'name' => $stage['name'],
                    'units' => array_map(fn (array $unit) => [
                        'unit_number' => $unit['unit_number'],
                        'title' => $unit['title'],
                        'lessons' => array_map(fn (array $l) => ['session_no' => $l['session_no'], 'title' => $l['title']], $unit['lessons']),
                    ], $stage['units']),
                    'documents' => array_map($document, $stage['documents']),
                ], $plan->stages),
                'shared_documents' => array_map($document, $plan->sharedDocuments),
                'visibility' => $meta['visibility'],
            ];
        }

        return Inertia::render('Syllabus/Import', [
            'canUpload' => $canUpload,
            'preview' => $preview,
        ]);
    }

    public function template()
    {
        $path = app(SyllabusImportTemplate::class)->build();

        return response()->download($path, 'mau-import-syllabus.zip')->deleteFileAfterSend();
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'extensions:zip,xlsx'],
            'visible_to_teachers' => ['nullable', 'boolean'],
            'visible_to_assistants' => ['nullable', 'boolean'],
            'downloadable' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'Hãy chọn file .zip hoặc .xlsx.',
            'file.max' => 'File tối đa 100MB.',
            'file.extensions' => 'Chỉ nhận file .zip hoặc .xlsx.',
        ]);

        $visibility = [
            'visible_to_teachers' => $request->boolean('visible_to_teachers'),
            'visible_to_assistants' => $request->boolean('visible_to_assistants'),
            'downloadable' => $request->boolean('downloadable'),
        ];
        $token = $this->store->put($data['file'], $request->user(), $visibility);
        $meta = $this->store->get($token, $request->user());

        try {
            $this->reader->read($meta['path'], $meta['work_dir']);
        } catch (SyllabusPackageException $e) {
            $this->store->forget($token);

            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            $this->store->forget($token);

            return back()->withErrors(['file' => self::UNREADABLE]);
        }

        return redirect()->route('syllabus.import', ['token' => $token]);
    }

    public function confirm(Request $request)
    {
        $token = $this->token($request);
        $loaded = $this->loadPlan($token, $request);
        if ($loaded instanceof RedirectResponse) {
            return $loaded;
        }
        [$meta, $plan] = $loaded;

        $back = fn (string $message) => redirect()->route('syllabus.import', ['token' => $token])->withErrors(['file' => $message]);
        if (! $plan->ok()) {
            return $back('File còn lỗi, hãy sửa file rồi tải lên lại.');
        }

        try {
            $curriculum = $this->importer->import($plan, $request->user(), $meta['visibility']);
        } catch (SyllabusImportException $e) {
            return $back($e->getMessage());
        }

        $this->store->forget($token);
        $s = $plan->summary();

        return redirect()->route('syllabus.curriculums.show', $curriculum->id)->with(
            'status',
            "Đã import giáo trình {$plan->curriculum['code']}: {$s['stages']} chặng, {$s['lessons']} buổi, {$s['documents']} tài liệu. Gán cấp độ khoá học để lớp dùng được giáo trình."
        );
    }

    /**
     * Đọc lại gói từ phiên kiểm tra và validate lại (quyền upload tính tại thời điểm này).
     *
     * @return array{0: array<string, mixed>, 1: SyllabusImportPlan}|RedirectResponse redirect khi phiên hết hạn / gói hỏng
     */
    private function loadPlan(string $token, Request $request): array|RedirectResponse
    {
        $meta = $token === '' ? null : $this->store->get($token, $request->user());
        if ($meta === null) {
            return redirect()->route('syllabus.import')->withErrors(['file' => 'Phiên kiểm tra đã hết hạn, vui lòng tải file lên lại.']);
        }

        try {
            $plan = $this->validator->validate($this->reader->read($meta['path'], $meta['work_dir']), $request->user()->can('syllabus.library'));
        } catch (SyllabusPackageException $e) {
            $this->store->forget($token);

            return redirect()->route('syllabus.import')->withErrors(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            $this->store->forget($token);

            return redirect()->route('syllabus.import')->withErrors(['file' => self::UNREADABLE]);
        }

        return [$meta, $plan];
    }

    /** Token dạng chuỗi; `token[]=x` hoặc thiếu token coi như rỗng (phiên hết hạn). */
    private function token(Request $request): string
    {
        $token = $request->input('token');

        return is_string($token) ? $token : '';
    }
}
