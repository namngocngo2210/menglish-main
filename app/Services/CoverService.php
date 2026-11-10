<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\ClassSession;
use App\Models\CoverRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoverService
{
    public function create(ClassSession $session, User $user, ?string $reason = null): CoverRequest
    {
        return DB::transaction(function () use ($session, $user, $reason) {
            // Lock the parent even when no request exists yet to serialize creation.
            $session = ClassSession::query()->lockForUpdate()->findOrFail($session->id);
            $ownsSession = in_array($user->id, [$session->teacher_id, $session->foreign_teacher_id], true);
            abort_unless($user->can('cover.approve') || ($ownsSession && $user->can('cover.request')), 403);
            $this->ensureFuture($session);
            if ($session->status !== 'scheduled') {
                throw ValidationException::withMessages(['class_session_id' => 'Chỉ có thể tìm giáo viên dạy thay cho buổi đã lên lịch.']);
            }
            if ($session->coverRequests()->whereIn('status', ['open', 'approved'])->exists()) {
                throw ValidationException::withMessages(['class_session_id' => 'Buổi học đã có yêu cầu dạy thay đang hoạt động.']);
            }

            $cover = $session->coverRequests()->create([
                'requested_by' => $user->id,
                'original_teacher_id' => $session->teacher_id,
                'reason' => $reason,
                'status' => 'open',
            ]);
            // Class name stays hidden until approval, so the broadcast only names the slot.
            $this->notify(User::permission('class.teach')->pluck('id')->diff([$user->id, $session->teacher_id, $session->foreign_teacher_id]),
                'cover_opened', 'Cần giáo viên dạy thay '.$this->slot($session), 'Bạn có thể đăng ký dạy thay buổi này.', route('cover.board'));

            return $cover;
        });
    }

    public function register(CoverRequest $request, User $teacher): CoverRequest
    {
        abort_unless($teacher->can('class.teach'), 403);

        return DB::transaction(function () use ($request, $teacher) {
            $request = CoverRequest::query()->lockForUpdate()->findOrFail($request->id);
            $session = $request->classSession()->lockForUpdate()->firstOrFail();
            $this->ensureOpen($request);
            $this->ensureFuture($session);
            if (in_array($teacher->id, [$session->teacher_id, $session->foreign_teacher_id, $request->original_teacher_id], true)) {
                throw ValidationException::withMessages(['teacher_id' => 'Giáo viên của buổi học không thể đăng ký dạy thay.']);
            }
            if ($request->registrations()->where('teacher_id', $teacher->id)->exists()) {
                throw ValidationException::withMessages(['teacher_id' => 'Bạn đã đăng ký dạy thay cho buổi học này.']);
            }
            $request->registrations()->create(['teacher_id' => $teacher->id]);
            $this->notify(User::permission('cover.approve')->pluck('id')->diff([$teacher->id]),
                'cover_registered', $teacher->name.' đăng ký dạy thay '.$this->slot($session), 'Vào Dạy thay để duyệt.', route('cover.board'));

            return $request;
        });
    }

    public function unregister(CoverRequest $request, User $teacher): CoverRequest
    {
        abort_unless($teacher->can('class.teach'), 403);

        return DB::transaction(function () use ($request, $teacher) {
            $request = CoverRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->ensureOpen($request);
            $this->ensureFuture($request->classSession()->lockForUpdate()->firstOrFail());
            $request->registrations()->where('teacher_id', $teacher->id)->delete();

            return $request;
        });
    }

    public function approve(CoverRequest $request, User $approver, int $registrationTeacherId): CoverRequest
    {
        abort_unless($approver->can('cover.approve'), 403);

        return DB::transaction(function () use ($request, $approver, $registrationTeacherId) {
            $request = CoverRequest::query()->lockForUpdate()->findOrFail($request->id);
            $session = $request->classSession()->lockForUpdate()->firstOrFail();
            $this->ensureActive($request);
            $this->ensureFuture($session);
            if (! $request->registrations()->where('teacher_id', $registrationTeacherId)->exists()) {
                throw ValidationException::withMessages(['teacher_id' => 'Giáo viên được chọn phải đăng ký dạy thay trước.']);
            }
            if ($request->status === 'approved') {
                $session->update(['teacher_id' => $request->original_teacher_id]);
            } else {
                $request->original_teacher_id = $session->teacher_id;
            }
            $session->update(['teacher_id' => $registrationTeacherId]);
            $request->fill([
                'status' => 'approved',
                'approved_user_id' => $registrationTeacherId,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();
            $this->notify([$registrationTeacherId], 'cover_approved', 'Bạn được duyệt dạy thay '.$this->slot($session), 'Bạn đã được xếp dạy buổi này.', route('teacher.home'));
            $this->notify([$request->requested_by, $request->original_teacher_id], 'cover_approved', 'Đã có giáo viên dạy thay '.$this->slot($session), 'Yêu cầu dạy thay đã được duyệt.', route('cover.board'));

            return $request;
        });
    }

    public function reopen(CoverRequest $request, User $approver): CoverRequest
    {
        abort_unless($approver->can('cover.approve'), 403);

        return DB::transaction(function () use ($request) {
            $request = CoverRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Chỉ có thể mở lại yêu cầu đã duyệt.']);
            }
            $session = $request->classSession()->lockForUpdate()->firstOrFail();
            $session->update(['teacher_id' => $request->original_teacher_id]);
            $this->notify([$request->approved_user_id], 'cover_reopened', 'Dạy thay '.$this->slot($session).' đã được mở lại', 'Bạn không còn dạy thay buổi này.', route('cover.board'));
            $request->update(['status' => 'open', 'approved_user_id' => null, 'approved_by' => null, 'approved_at' => null]);

            return $request;
        });
    }

    public function cancel(CoverRequest $request, User $user): CoverRequest
    {
        return DB::transaction(function () use ($request, $user) {
            $request = CoverRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless((int) $request->requested_by === (int) $user->id || $user->can('cover.approve'), 403);
            $this->ensureActive($request);
            if ($request->status === 'approved') {
                $session = $request->classSession()->lockForUpdate()->firstOrFail();
                $session->update(['teacher_id' => $request->original_teacher_id]);
                $this->notify([$request->approved_user_id], 'cover_cancelled', 'Dạy thay '.$this->slot($session).' đã bị hủy', 'Bạn không còn dạy thay buổi này.', route('cover.board'));
            }
            $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $request;
        });
    }

    private function slot(ClassSession $session): string
    {
        return $session->date->format('d/m/Y').' '.$session->start_time->format('H:i');
    }

    /** @param iterable<int|null> $userIds */
    private function notify(iterable $userIds, string $type, string $title, string $message, string $link): void
    {
        foreach (collect($userIds)->filter()->unique() as $userId) {
            AdminNotification::create(['user_id' => $userId, 'type' => $type, 'title' => $title, 'message' => $message,
                'data' => ['link' => $link], 'is_read' => false]);
        }
    }

    private function ensureFuture(ClassSession $session): void
    {
        if (! $session->startsAt()->isFuture()) {
            throw ValidationException::withMessages(['class_session_id' => 'Buổi học đã bắt đầu, không thể đăng ký hoặc duyệt dạy thay.']);
        }
    }

    private function ensureOpen(CoverRequest $request): void
    {
        if ($request->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'Yêu cầu dạy thay đã đóng đăng ký.']);
        }
    }

    private function ensureActive(CoverRequest $request): void
    {
        if (! in_array($request->status, ['open', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'Yêu cầu dạy thay đã bị hủy.']);
        }
    }
}
