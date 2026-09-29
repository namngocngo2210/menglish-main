<?php

use App\Models\CrmCustomer;
use App\Models\PlacementTestSubmission;
use App\Services\PlacementSubmissionLinker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bài test nộp qua link công khai trước đây không gắn được khách khi khách chưa có phone_normalized
 * (tạo ngoài form CRM) hoặc SĐT gõ khác định dạng → hồ sơ khách (kể cả sau khi chốt) báo "Chưa có kết quả test".
 * Gắn lại các bài đó cho khách có đúng SĐT, chỉ khi khách chưa có bài test nào và chỉ một khách khớp.
 */
return new class extends Migration
{
    public function up(): void
    {
        $linker = app(PlacementSubmissionLinker::class);

        PlacementTestSubmission::query()
            ->whereNull('customer_id')
            ->with('test')
            ->orderBy('id')
            ->get()
            ->each(function (PlacementTestSubmission $submission) use ($linker): void {
                $phone = CrmCustomer::normalizePhone($submission->candidate_phone);
                if (strlen($phone) < 9) {
                    return;
                }
                $leads = CrmCustomer::query()
                    ->where('stage', '!=', CrmCustomer::STAGE_LOST)
                    ->where('phone_normalized', $phone)
                    ->limit(2)
                    ->get();
                if ($leads->count() !== 1) {
                    return;
                }
                $lead = $leads->first();
                if (DB::table('placement_test_submissions')->where('customer_id', $lead->id)->exists()) {
                    return;
                }
                $linker->attach($submission, $lead, null, 'hệ thống gắn lại theo SĐT thí sinh');
            });
    }

    public function down(): void
    {
        // Không hoàn tác: gắn bài test với khách không làm mất dữ liệu.
    }
};
