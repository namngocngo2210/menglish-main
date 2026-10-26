<?php

namespace App\Jobs;

use App\Models\PracticeExamImport;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\PracticeExamImporter;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Import đề cố định chạy nền (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2): đọc file đã lưu của lần import, ghi đề,
 * lưu kết quả vào practice_exam_imports rồi xoá file. Chạy một lần (không thử lại: import lại = thay đề nháp).
 */
class ImportPracticeExams implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Giây; worker (docker-compose: queue) và DB_QUEUE_RETRY_AFTER đặt lớn hơn. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public PracticeExamImport $import) {}

    public function handle(PracticeExamImporter $importer): void
    {
        $import = $this->import;
        $import->update(['status' => PracticeExamImport::RUNNING, 'started_at' => now()]);
        $disk = Storage::disk('local');

        $files = [];
        foreach ($import->file_names as $i => $name) {
            $files[] = ['path' => $disk->path("{$import->directory()}/{$i}"), 'name' => $name];
        }

        try {
            $summary = activity()->withoutLogs(fn () => $importer->import($files));
        } catch (InvalidArgumentException $e) {
            $this->finish(PracticeExamImport::FAILED, $e->getMessage());

            return;
        } finally {
            $disk->deleteDirectory($import->directory());
        }

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($import->user_id ? $import->user : null)
            ->withProperties([
                'files' => $import->file_names,
                'exam_ids' => $summary->examIds,
                'errors' => count($summary->errors),
            ])
            ->log('Import đề cố định: '.$summary->message());

        $this->finish($summary->saved === [] && $summary->kept === [] ? PracticeExamImport::FAILED : PracticeExamImport::DONE, $summary->message(), [
            'saved' => $summary->saved,
            'kept' => $summary->kept,
            'errors' => $summary->errors,
            'warnings' => array_slice($summary->warnings, 0, 50),
        ]);
    }

    /** Lỗi ngoài dự kiến / quá thời gian: báo trên màn hình, chi tiết ở log. */
    public function failed(?Throwable $e): void
    {
        Storage::disk('local')->deleteDirectory($this->import->directory());
        $this->finish(PracticeExamImport::FAILED, 'Import bị lỗi hệ thống hoặc quá thời gian. Thử lại với ít file hơn, hoặc báo quản trị.');
    }

    private function finish(string $status, string $message, ?array $report = null): void
    {
        $this->import->update(['status' => $status, 'message' => $message, 'report' => $report, 'finished_at' => now()]);
    }
}
