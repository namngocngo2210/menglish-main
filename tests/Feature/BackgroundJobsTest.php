<?php

namespace Tests\Feature;

use App\Jobs\BackgroundTaskJob;
use App\Jobs\SendHtmlMail;
use App\Models\AdminNotification;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Khung việc chạy nền (background_tasks) và email thông báo qua queue riêng. */
class BackgroundJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_mail_is_queued_on_notifications_queue_after_commit(): void
    {
        Queue::fake();

        SendHtmlMail::dispatch('ke-toan@example.com', 'Phiếu thu mới', '<p>Nội dung</p>');

        Queue::assertPushedOn(SendHtmlMail::QUEUE, SendHtmlMail::class, fn (SendHtmlMail $job) => $job->to === 'ke-toan@example.com' && $job->afterCommit === true);
    }

    public function test_task_runs_as_its_user_and_reports_result(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);

        $job = new class($task) extends BackgroundTaskJob
        {
            public ?int $ranAs = null;

            protected function process(): array
            {
                $this->ranAs = Auth::id();

                return ['ok' => true, 'message' => 'Đã nhập 3 dòng.', 'report' => ['created' => 3]];
            }

            protected function link(): string
            {
                return '/done';
            }
        };
        $job->handle();

        $this->assertSame($user->id, $job->ranAs);
        $this->assertNull(Auth::user(), 'Không giữ đăng nhập của người chạy sau khi xong');
        $task->refresh();
        $this->assertSame(BackgroundTask::DONE, $task->status);
        $this->assertSame(['created' => 3], $task->report);
        $this->assertNotNull($task->finished_at);
        $notice = AdminNotification::where('user_id', $user->id)->where('type', BackgroundTaskJob::NOTIFICATION_TYPE)->firstOrFail();
        $this->assertSame('/done', $notice->data['link']);
        $this->assertStringStartsWith('Xong:', $notice->title);
    }

    public function test_business_error_and_crash_mark_task_failed(): void
    {
        $user = User::factory()->create();

        $rejected = $this->task($user);
        (new class($rejected) extends BackgroundTaskJob
        {
            protected function process(): array
            {
                return ['ok' => false, 'message' => 'File thiếu cột Mã.'];
            }

            protected function link(): string
            {
                return '/x';
            }
        })->handle();
        $this->assertSame(BackgroundTask::FAILED, $rejected->refresh()->status);
        $this->assertSame('File thiếu cột Mã.', $rejected->message);

        $crashed = $this->task($user);
        $job = new class($crashed) extends BackgroundTaskJob
        {
            protected function process(): array
            {
                throw new RuntimeException('boom');
            }

            protected function link(): string
            {
                return '/x';
            }
        };
        try {
            $job->handle();
            $this->fail('Lỗi phải được ném ra để queue gọi failed()');
        } catch (RuntimeException) {
            $job->failed(new RuntimeException('boom'));
        }
        $this->assertSame(BackgroundTask::FAILED, $crashed->refresh()->status);
        $this->assertSame(2, AdminNotification::where('user_id', $user->id)->where('title', 'like', 'Không thành công:%')->count());
    }

    public function test_pending_and_stalled_flags(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $task->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $row = BackgroundTask::recentFor(BackgroundTask::CRM_IMPORT, $user->id)[0];
        $this->assertTrue($row['pending']);
        $this->assertTrue($row['stalled']);
        $this->assertTrue(BackgroundTask::pendingExists(BackgroundTask::CRM_IMPORT));
    }

    private function task(User $user): BackgroundTask
    {
        return BackgroundTask::query()->create([
            'type' => BackgroundTask::CRM_IMPORT,
            'user_id' => $user->id,
            'status' => BackgroundTask::QUEUED,
            'title' => 'Nhập khách: test.xlsx',
        ]);
    }
}
