<?php

namespace App\Models;

use App\Services\Sla\Sla;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Báo cáo buổi học của giáo viên chính (chủ dự án 27/10/2026): nộp từ Trang lớp, mỗi buổi một bản, chỉ để xem (không duyệt,
 * không ảnh). Hạn nộp = giờ kết thúc buổi + SLA "gv.session_report"; nộp lần đầu sau hạn → "Nộp trễ".
 */
class TeacherSessionReport extends Model
{
    protected $fillable = ['class_id', 'class_session_id', 'teacher_id', 'content', 'class_situation', 'attention', 'proposal', 'submitted_at'];

    protected $casts = ['attention' => 'array', 'submitted_at' => 'datetime'];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** Hạn nộp báo cáo của buổi. */
    public static function deadline(ClassSession $session): Carbon
    {
        $ends = $session->end_time ? $session->date->copy()->setTimeFrom($session->end_time) : $session->date->copy()->endOfDay();

        return $ends->addHours(Sla::value('gv.session_report'));
    }

    public function isLate(): bool
    {
        return $this->session !== null && $this->submitted_at?->gt(self::deadline($this->session));
    }

    /**
     * GV chính của buổi (người nộp báo cáo; GVNN không nộp): GV của buổi, trừ khi đó là GVNN (lớp không có GV chính) →
     * GV chính của lớp.
     */
    public static function responsibleTeacherId(ClassSession $session, ?ClassModel $class = null): ?int
    {
        $class ??= $session->classModel;
        $foreign = array_filter([(int) $session->foreign_teacher_id, (int) $class?->foreign_teacher_id]);
        foreach ([(int) $session->teacher_id, (int) $class?->teacher_id] as $id) {
            if ($id && ! in_array($id, $foreign, true)) {
                return $id;
            }
        }

        return null;
    }
}
