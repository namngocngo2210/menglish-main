<?php

namespace App\Services;

use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\PlacementTestSubmission;
use Illuminate\Support\Collection;

/**
 * Gắn bài test đầu vào (nộp qua link công khai) với khách CRM.
 *
 * Bài nộp không qua link riêng của lead chỉ tự gắn khi SĐT khớp lead chưa chốt; các bài còn lại (SĐT gõ khác,
 * khách tạo lúc SĐT chưa chuẩn hoá...) Học vụ gắn tay từ hồ sơ khách để kết quả test đi theo khách cả sau khi chốt.
 */
class PlacementSubmissionLinker
{
    /** Chỉ xét các bài chưa gắn khách gần đây để hồ sơ khách không phải quét toàn bảng. */
    private const CANDIDATE_SCAN_LIMIT = 500;

    /**
     * Bài chưa gắn khách có SĐT thí sinh trùng SĐT khách / phụ huynh, hoặc trùng họ tên.
     *
     * @return Collection<int, PlacementTestSubmission>
     */
    public function candidatesFor(CrmCustomer $customer, int $limit = 5): Collection
    {
        $phones = collect([$customer->phone, $customer->parent_phone])
            ->map(fn (?string $phone) => CrmCustomer::normalizePhone($phone))
            ->filter(fn (string $phone) => strlen($phone) >= 9)
            ->unique()->all();
        $name = mb_strtolower(trim((string) $customer->name));

        return PlacementTestSubmission::query()
            ->whereNull('customer_id')
            ->with('test')
            ->latest('id')
            ->limit(self::CANDIDATE_SCAN_LIMIT)
            ->get()
            ->filter(fn (PlacementTestSubmission $submission) => in_array(CrmCustomer::normalizePhone($submission->candidate_phone), $phones, true)
                || ($name !== '' && mb_strtolower(trim((string) $submission->candidate_name)) === $name))
            ->take($limit)
            ->values();
    }

    /** Gắn bài vào khách; bài đã chấm thì điểm tóm tắt ghi sang khách (khách chưa có điểm). */
    public function attach(PlacementTestSubmission $submission, CrmCustomer $customer, ?int $actorId, string $how): void
    {
        $submission->forceFill([
            'customer_id' => $customer->id,
            'student_id' => $submission->student_id ?? $customer->converted_student_id,
        ])->save();

        if (! $submission->isPending() && blank($customer->test_score) && $customer->stage !== CrmCustomer::STAGE_LOST) {
            $customer->update(['test_score' => $submission->scoreSummary()]);
        }

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $actorId ?? $customer->assigned_user_id,
            'type' => 'test',
            'content' => "Gắn bài test [{$submission->test?->code}] (bài #{$submission->id}, thí sinh {$submission->candidate_name} · {$submission->candidate_phone}) vào khách: {$how}.",
        ]);
    }
}
