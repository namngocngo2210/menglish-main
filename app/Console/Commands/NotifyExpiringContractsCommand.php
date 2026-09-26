<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Cảnh báo hợp đồng nhân sự sắp hết hạn (A3 "Nền tảng", Phase 4).
 *
 * Chạy hằng ngày: với mỗi nhân sự đang hoạt động có hợp đồng kết thúc trong
 * User::CONTRACT_WARNING_DAYS ngày tới, gửi thông báo cá nhân cho Admin và
 * Quản lý cơ sở của chi nhánh nhân sự đó. Idempotent: mỗi người nhận chỉ nhận
 * 1 thông báo cho mỗi (nhân sự, ngày kết thúc hợp đồng); gia hạn hợp đồng
 * (đổi ngày kết thúc) sẽ cảnh báo lại cho kỳ mới.
 */
class NotifyExpiringContractsCommand extends Command
{
    protected $signature = 'hr:notify-expiring-contracts';

    protected $description = 'Thông báo cho Admin/Quản lý cơ sở các hợp đồng nhân sự hết hạn trong 30 ngày tới';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $until = $today->copy()->addDays(User::CONTRACT_WARNING_DAYS);

        $staff = User::query()
            ->with('branches:id')
            ->where('is_active', true)
            ->whereNull('locked_at')
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '>=', $today->toDateString())
            ->whereDate('contract_end_date', '<=', $until->toDateString())
            ->get();

        if ($staff->isEmpty()) {
            $this->info('Không có hợp đồng nào sắp hết hạn.');

            return self::SUCCESS;
        }

        $admins = User::role(\App\Support\Rbac::SUPER_ADMIN)->where('is_active', true)->whereNull('locked_at')->get();
        $managers = User::role('manager')->with('branches:id')->where('is_active', true)->whereNull('locked_at')->get();

        $created = 0;
        foreach ($staff as $member) {
            $endDate = $member->contract_end_date->toDateString();
            $daysLeft = (int) $today->diffInDays($member->contract_end_date->copy()->startOfDay());

            $recipients = $admins->merge(
                $managers->filter(fn (User $manager) => in_array((int) $member->branch_id, $manager->branchIds(), true))
            )->unique('id')->reject(fn (User $recipient) => $recipient->id === $member->id);

            foreach ($recipients as $recipient) {
                $exists = AdminNotification::query()
                    ->where('type', 'contract_expiring')
                    ->where('user_id', $recipient->id)
                    ->where('data->staff_id', $member->id)
                    ->where('data->contract_end_date', $endDate)
                    ->exists();

                if ($exists) {
                    continue;
                }

                AdminNotification::create([
                    'user_id' => $recipient->id,
                    'type' => 'contract_expiring',
                    'title' => "Hợp đồng của {$member->name} sắp hết hạn",
                    'message' => "Hợp đồng lao động của {$member->name} kết thúc ngày {$member->contract_end_date->format('d/m/Y')} (còn {$daysLeft} ngày). Vui lòng gia hạn hoặc xử lý.",
                    'data' => [
                        'staff_id' => $member->id,
                        'contract_end_date' => $endDate,
                        'link' => route('users.show', $member->id),
                    ],
                    'is_read' => false,
                ]);
                $created++;
            }
        }

        $this->info("Đã tạo {$created} thông báo hợp đồng sắp hết hạn.");

        return self::SUCCESS;
    }
}
