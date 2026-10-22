<?php

namespace App\Http\Controllers;

use App\Services\Syllabus\Import\SyllabusImportStore;
use App\Services\Syllabus\Import\SyllabusImportTemplate;
use App\Services\Syllabus\Import\SyllabusPackageException;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Import syllabus từ file mẫu (.zip / .xlsx): ① Tải file → ② Xem trước (không ghi DB) → ③ Xác nhận (Task 7).
 * Quyền: middleware `can:syllabus.manage` của nhóm route; gói có tài liệu cần thêm `syllabus.upload` (validator).
 */
class SyllabusImportController extends Controller
{
    public function __construct(
        private readonly SyllabusImportStore $store,
        private readonly SyllabusPackageReader $reader,
        private readonly SyllabusPackageValidator $validator,
    ) {}

    public function index(Request $request)
    {
        $canUpload = $request->user()->can('syllabus.upload');
        $preview = null;

        if ($request->filled('token')) {
            $token = (string) $request->input('token');
            $meta = $this->store->get($token, $request->user());
            if ($meta === null) {
                return redirect()->route('syllabus.import')->withErrors(['file' => 'Phiên kiểm tra đã hết hạn, vui lòng tải file lên lại.']);
            }

            try {
                $plan = $this->validator->validate($this->reader->read($meta['path'], $meta['work_dir']), $canUpload);
            } catch (SyllabusPackageException $e) {
                $this->store->forget($token);

                return redirect()->route('syllabus.import')->withErrors(['file' => $e->getMessage()]);
            }

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
        }

        return redirect()->route('syllabus.import', ['token' => $token]);
    }
}
