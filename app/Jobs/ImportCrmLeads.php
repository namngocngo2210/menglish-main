<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Crm\CrmLeadImporter;

/** Nhập khách CRM từ Excel (bản xem trước đã kiểm tra) chạy nền; các dòng bị bỏ qua hiện ở danh sách khách. */
class ImportCrmLeads extends BackgroundTaskJob
{
    protected function process(): array
    {
        $preview = $this->task->payload['preview'];
        $user = User::find($this->task->user_id);
        if (! $user) {
            return ['ok' => false, 'message' => 'Người nhập không còn tồn tại.'];
        }

        ['created' => $created, 'skipped' => $skipped] = app(CrmLeadImporter::class)->import($preview, $user);

        return [
            'ok' => true,
            'message' => "Đã nhập {$created} khách hàng mới vào {$preview['branch_name']}.".($skipped ? ' Bỏ qua '.count($skipped).' dòng lỗi.' : ''),
            'report' => ['errors' => $skipped],
        ];
    }

    protected function link(): string
    {
        return route('crm.customers.index');
    }
}
