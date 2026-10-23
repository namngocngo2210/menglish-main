<?php

namespace App\Console\Commands;

use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\QuestionBankImporter;
use App\Support\Audit;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Import ngân hàng câu hỏi từ ZIP (data/*.json + hinh/*). Chạy lại an toàn: câu đã duyệt / ẩn / đã sửa giữ nguyên.
 * Một dòng nhật ký tổng (không người thực hiện) thay cho từng câu.
 */
class ImportQuestionBankCommand extends Command
{
    protected $signature = 'question-bank:import {zip : Đường dẫn file ZIP (data/*.json + hinh/*)}';

    protected $description = 'Import ngân hàng câu hỏi từ file ZIP';

    public function handle(QuestionBankImporter $importer): int
    {
        $zip = (string) $this->argument('zip');

        try {
            // Như import qua web: tắt nhật ký từng câu / ngữ liệu, ghi một dòng tổng.
            $summary = activity()->withoutLogs(fn () => $importer->importZip($zip));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->withProperties([
                'file' => basename($zip),
                'created' => $summary->created,
                'updated' => $summary->updated,
                'kept' => $summary->kept,
                'skipped' => $summary->skipped,
                'errors' => count($summary->errors),
            ])
            ->log('Import ngân hàng câu hỏi: '.$summary->message());

        $this->info($summary->message());
        foreach ($summary->skipReasons as $reason => $count) {
            $this->line("- Bỏ qua {$count} câu: {$reason}");
        }
        foreach ($summary->errors as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
