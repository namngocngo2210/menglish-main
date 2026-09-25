<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Khóa checklist CRM cũ → mốc mới (null = bỏ, không thuộc gate hoa hồng A6). */
    private const CHECKLIST_MAP = [
        'welcome_call' => null,
        'first_session_feedback' => 'session_1',
        'materials_check' => null,
        'week2_parent_update' => 'session_4_5',
        'month_end_review' => 'day_30',
    ];

    private const LABELS = [
        'welcome_call' => 'Gọi chào mừng, xác nhận lịch học buổi đầu',
        'materials_check' => 'Kiểm tra đã nhận đủ giáo trình / tài khoản học',
    ];

    /**
     * Chăm sóc tháng đầu chuyển từ 5 mục / ngày 3-7-14-30 sang 3 mốc gate hoa hồng A6 (Buổi 1, Buổi 4–5, Đủ 30 ngày).
     *
     * - crm_customers.care_checklist: first_session_feedback → session_1, week2_parent_update → session_4_5,
     *   month_end_review → day_30. Mục bỏ (welcome_call, materials_check) đã tick được ghi lại vào lịch sử khách
     *   (crm_customer_histories, type = care) để không mất dấu vết.
     * - work_tasks.care_milestone: 3 → 1, 14 → 4, 30 → 30; việc ngày 7 (kiểm tra giáo trình) giữ nguyên là việc
     *   thường (care_milestone = NULL, tiêu đề giữ nguyên).
     */
    public function up(): void
    {
        DB::table('crm_customers')->whereNotNull('care_checklist')->orderBy('id')->each(function ($customer) {
            $old = json_decode((string) $customer->care_checklist, true);
            if (! is_array($old)) {
                return;
            }
            $new = ['session_1' => null, 'session_4_5' => null, 'day_30' => null];
            $dropped = [];
            foreach ($old as $key => $value) {
                if (array_key_exists($key, $new)) { // đã là khóa mới
                    $new[$key] = $new[$key] ?: $value;

                    continue;
                }
                $target = self::CHECKLIST_MAP[$key] ?? null;
                if ($target) {
                    $new[$target] = $new[$target] ?: $value;
                } elseif (! empty($value)) {
                    $dropped[] = self::LABELS[$key] ?? $key;
                }
            }
            DB::table('crm_customers')->where('id', $customer->id)->update(['care_checklist' => json_encode($new, JSON_UNESCAPED_UNICODE)]);
            if ($dropped !== []) {
                DB::table('crm_customer_histories')->insert([
                    'customer_id' => $customer->id,
                    'user_id' => null,
                    'type' => 'care',
                    'content' => 'Checklist chăm sóc tháng đầu chuyển sang 3 mốc A6 (Buổi 1, Buổi 4–5, Đủ 30 ngày). Mục cũ đã hoàn thành (không còn trong checklist): '.implode('; ', $dropped).'.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        // Đổi mốc việc: làm lần lượt theo thứ tự không đụng unique (student_id, care_milestone) — giá trị mới 1/4 chưa từng dùng.
        DB::table('work_tasks')->where('care_milestone', 7)->update(['care_milestone' => null]);
        DB::table('work_tasks')->where('care_milestone', 3)->update(['care_milestone' => 1]);
        DB::table('work_tasks')->where('care_milestone', 14)->update(['care_milestone' => 4]);
    }

    public function down(): void
    {
        DB::table('work_tasks')->where('care_milestone', 4)->update(['care_milestone' => 14]);
        DB::table('work_tasks')->where('care_milestone', 1)->update(['care_milestone' => 3]);

        $reverse = ['session_1' => 'first_session_feedback', 'session_4_5' => 'week2_parent_update', 'day_30' => 'month_end_review'];
        DB::table('crm_customers')->whereNotNull('care_checklist')->orderBy('id')->each(function ($customer) use ($reverse) {
            $new = json_decode((string) $customer->care_checklist, true);
            if (! is_array($new)) {
                return;
            }
            $old = array_fill_keys(array_keys(self::CHECKLIST_MAP), null);
            foreach ($reverse as $key => $legacy) {
                $old[$legacy] = $new[$key] ?? null;
            }
            DB::table('crm_customers')->where('id', $customer->id)->update(['care_checklist' => json_encode($old, JSON_UNESCAPED_UNICODE)]);
        });
    }
};
