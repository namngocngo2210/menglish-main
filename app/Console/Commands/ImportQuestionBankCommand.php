<?php

namespace App\Console\Commands;

use App\Services\QuestionBank\QuestionBankImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Import ngân hàng câu hỏi từ ZIP (data/*.json + hinh/*). Chạy lại an toàn: câu đã duyệt / ẩn / đã sửa giữ nguyên.
 */
class ImportQuestionBankCommand extends Command
{
    protected $signature = 'question-bank:import {zip : Đường dẫn file ZIP (data/*.json + hinh/*)}';

    protected $description = 'Import ngân hàng câu hỏi từ file ZIP';

    public function handle(QuestionBankImporter $importer): int
    {
        try {
            $summary = $importer->importZip((string) $this->argument('zip'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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
