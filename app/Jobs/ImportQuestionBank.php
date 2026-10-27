<?php

namespace App\Jobs;

use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\QuestionBankImporter;
use App\Support\Audit;
use InvalidArgumentException;

/** Import file ZIP ngân hàng câu hỏi (data/*.json + hinh/*) chạy nền; một dòng nhật ký tổng thay cho từng câu. */
class ImportQuestionBank extends BackgroundTaskJob
{
    private const ERROR_LINES = 20;

    protected function process(): array
    {
        try {
            $summary = activity()->withoutLogs(fn () => app(QuestionBankImporter::class)->importZip($this->storedFile()));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($this->task->user)
            ->withProperties([
                'file' => $this->task->payload['file_name'] ?? null,
                'created' => $summary->created,
                'updated' => $summary->updated,
                'kept' => $summary->kept,
                'skipped' => $summary->skipped,
                'errors' => count($summary->errors),
            ])
            ->log('Import ngân hàng câu hỏi: '.$summary->message());

        $errors = array_slice($summary->errors, 0, self::ERROR_LINES);
        if (count($summary->errors) > self::ERROR_LINES) {
            $errors[] = '… và '.(count($summary->errors) - self::ERROR_LINES).' lỗi khác.';
        }

        return ['ok' => true, 'message' => $summary->message(), 'report' => ['errors' => $errors]];
    }

    protected function link(): string
    {
        return route('question-bank.index');
    }
}
