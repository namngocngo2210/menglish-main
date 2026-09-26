<?php

namespace App\Providers;

use App\Services\Crm\Approvals\EnrollmentConfirmationApprovalSource;
use App\Services\Students\Approvals\EnrollmentApprovalSource;
use App\Services\Syllabus\Approvals\AdjustmentApprovalSource;
use App\Services\Syllabus\Approvals\BigTestOrderApprovalSource;
use App\Services\Syllabus\Approvals\ProposalApprovalSource;
use App\Services\Tuition\Approvals\InvoiceCancellationApprovalSource;
use App\Services\Tuition\Approvals\ReceiptApprovalSource;
use App\Services\Tuition\Approvals\RefundApprovalSource;
use App\Services\WorkTasks\Approvals\ClassReportApprovalSource;
use App\Services\WorkTasks\Approvals\WorkTaskApprovalSource;
use App\Support\Approvals\ApprovableSource;
use App\Support\Approvals\ApprovalInboxService;
use Illuminate\Support\ServiceProvider;

/**
 * Hộp "Việc cần duyệt" (IX-5): đăng ký các nguồn duyệt (tag `approval.sources`) và xoá cache số đếm khi model của
 * nguồn thay đổi. Thêm nguồn mới: viết adapter ApprovableSource cạnh module rồi thêm class vào SOURCES.
 */
class ApprovalServiceProvider extends ServiceProvider
{
    public const TAG = 'approval.sources';

    /** @var list<class-string<ApprovableSource>> */
    public const SOURCES = [
        ReceiptApprovalSource::class,
        InvoiceCancellationApprovalSource::class,
        RefundApprovalSource::class,
        EnrollmentApprovalSource::class,
        EnrollmentConfirmationApprovalSource::class,
        ProposalApprovalSource::class,
        AdjustmentApprovalSource::class,
        BigTestOrderApprovalSource::class,
        WorkTaskApprovalSource::class,
        ClassReportApprovalSource::class,
    ];

    public function register(): void
    {
        $this->app->tag(self::SOURCES, self::TAG);
        $this->app->singleton(ApprovalInboxService::class, fn ($app) => new ApprovalInboxService($app->tagged(self::TAG)));
    }

    public function boot(): void
    {
        $models = collect($this->app->make(ApprovalInboxService::class)->sources())
            ->flatMap(fn ($source) => $source->watchedModels())
            ->unique();

        foreach ($models as $model) {
            $invalidate = fn () => $this->app->make(ApprovalInboxService::class)->invalidate();
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }
}
