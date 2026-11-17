<?php

namespace App\Support;

use App\Models\BigTestOrder;
use App\Models\Branch;
use App\Models\ClassLessonOverride;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Student;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\SystemCategory;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTaskSeries;
use Illuminate\Database\Eloquent\Model;

/**
 * Cấu hình & tiện ích dùng chung cho nhật ký thao tác (audit log).
 *
 * Nguyên tắc chống ghi trùng: mỗi request thay đổi dữ liệu chỉ có MỘT nguồn
 * ghi nhật ký. Model trong MODELS tự ghi trước/sau (trait AuditsChanges); các
 * thao tác không làm đổi model được audit thì controller gọi activity() thủ
 * công; nếu request không sinh dòng nhật ký nào, AuditOperationMiddleware ghi
 * một dòng chung. Controller muốn đặt mô tả dễ đọc cho dòng nhật ký của model
 * thì gọi Audit::describe('...') trước khi lưu, không gọi activity() lần nữa.
 */
class Audit
{
    /**
     * Model được ghi trước/sau: tên phân hệ (log_name), nhãn tiếng Việt và
     * danh sách trường được phép "Hoàn tác" (chỉ trường thông tin đơn giản,
     * không gồm trạng thái nghiệp vụ, tiền, liên kết).
     */
    public const MODELS = [
        WorkTaskSeries::class => ['log' => 'Công việc', 'label' => 'cấu hình phạt việc lặp', 'undo' => []],
        User::class => [
            'log' => 'Người dùng & Phân quyền',
            'label' => 'tài khoản',
            'undo' => ['name', 'phone', 'employee_code', 'department', 'branch_id', 'hometown', 'current_address',
                'emergency_contact', 'graduation_school', 'certificates', 'teaching_level', 'contract_type',
                'contract_start_date', 'contract_end_date'],
        ],
        Student::class => [
            'log' => 'Học viên & Lớp học',
            'label' => 'học viên',
            'undo' => ['name', 'phone', 'email', 'dob', 'gender', 'address', 'target', 'notes'],
        ],
        ClassModel::class => [
            'log' => 'Học viên & Lớp học',
            'label' => 'lớp học',
            'undo' => ['name', 'room', 'schedule_text', 'max_capacity', 'notes'],
        ],
        CrmCustomer::class => [
            'log' => 'CRM & Leads',
            'label' => 'khách hàng',
            'undo' => ['name', 'parent_name', 'email', 'dob', 'gender', 'address', 'school', 'course_interest', 'source'],
        ],
        // Kho syllabus (docs/kho-syllabus.md): tab Lịch sử của trang chi tiết giáo trình lọc theo properties.curriculum_id.
        SyllabusCurriculum::class => ['log' => 'Giáo trình & Syllabus', 'label' => 'giáo trình', 'undo' => []],
        SyllabusStage::class => ['log' => 'Giáo trình & Syllabus', 'label' => 'chặng', 'undo' => []],
        SyllabusUnit::class => ['log' => 'Giáo trình & Syllabus', 'label' => 'unit', 'undo' => []],
        SyllabusLesson::class => ['log' => 'Giáo trình & Syllabus', 'label' => 'buổi học', 'undo' => []],
        SyllabusDocument::class => ['log' => 'Giáo trình & Syllabus', 'label' => 'tài liệu giáo trình', 'undo' => []],
        ClassLessonOverride::class => ['log' => 'Giáo trình & Syllabus', 'label' => 'syllabus riêng của lớp', 'undo' => []],
        // Ngân hàng câu hỏi luyện tập: sửa câu từng câu ghi trước/sau; duyệt hàng loạt / import ghi một dòng tổng (QuestionBankController).
        QuestionBankQuestion::class => ['log' => 'Ngân hàng câu hỏi', 'label' => 'câu hỏi', 'undo' => []],
        TuitionReceipt::class => [
            'log' => 'Học phí & Thu chi',
            'label' => 'phiếu thu',
            'undo' => [],
        ],
        SystemCategory::class => [
            'log' => 'Cấu hình hệ thống',
            'label' => 'danh mục',
            'undo' => ['name', 'sort_order', 'is_active'],
        ],
        Branch::class => [
            'log' => 'Cấu hình hệ thống',
            'label' => 'chi nhánh',
            'undo' => ['name', 'address', 'phone'],
        ],
        Room::class => [
            'log' => 'Cấu hình hệ thống',
            'label' => 'phòng học',
            'undo' => ['capacity', 'description'],
        ],
        RoomType::class => [
            'log' => 'Cấu hình hệ thống',
            'label' => 'loại phòng',
            'undo' => ['name', 'is_active'],
        ],
    ];

    /** Trường không bao giờ ghi vào nhật ký. */
    public const NEVER_LOG = ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at', 'phone_normalized'];

    /** Chỉ đổi các trường này thì không ghi nhật ký (đăng nhập, ghi nhớ phiên...). */
    public const IGNORE_ONLY = ['last_login_at', 'remember_token', 'updated_at'];

    private const CONTAINER_KEY = 'audit.description';

    /**
     * Đặt mô tả cho các dòng nhật ký model sinh ra trong request hiện tại.
     */
    public static function describe(string $description): void
    {
        app()->instance(self::CONTAINER_KEY, $description);
    }

    public static function currentDescription(): ?string
    {
        return app()->bound(self::CONTAINER_KEY) ? app(self::CONTAINER_KEY) : null;
    }

    public static function clearDescription(): void
    {
        if (app()->bound(self::CONTAINER_KEY)) {
            app()->forgetInstance(self::CONTAINER_KEY);
        }
    }

    /**
     * Ghi một dòng nhật ký thủ công cho order (phân hệ Giáo trình & Syllabus) với dữ liệu trước/sau.
     * Dòng này nằm trong batch của request nên middleware không ghi thêm dòng chung.
     */
    public static function logOrder(?User $actor, Model $order, string $event, string $description, ?array $before, array $after): void
    {
        $activity = activity('Giáo trình & Syllabus')->performedOn($order)->event($event)
            ->withProperties(['before' => $before, 'after' => $after]);
        if ($actor) {
            $activity->causedBy($actor);
        }
        $activity->log($description);
    }

    /** Dữ liệu order để so sánh trước/sau: mã, lớp, các dòng hàng (số lượng, ghi chú), trạng thái. */
    public static function orderSnapshot(Model $order): array
    {
        $isTest = $order instanceof BigTestOrder;
        $class = $isTest ? $order->classModel : $order->classRoom;
        $title = $isTest ? trim($order->type_label.' '.$order->stage_name) : $order->title;
        $note = $isTest ? $order->note : $order->description;

        return [
            'code' => $order->code,
            'class' => $class?->name,
            'status' => $order->status,
            'items' => [['title' => $title, 'quantity' => $isTest ? 1 : $order->quantity, 'note' => $note]],
        ];
    }

    /**
     * Ghi một dòng nhật ký cho một lần lưu điểm danh (phân hệ Lớp học/Điểm danh). $changes: [student_id => [name, before, after]]
     * với before/after dạng ['status' => .., 'note' => ..] (before null = chưa điểm danh). Nằm trong batch của request nên middleware không ghi thêm dòng chung.
     */
    public static function logAttendance(?User $actor, ClassModel $class, ClassSession $session, array $changes): void
    {
        $labels = ['present' => 'Có mặt', 'late' => 'Đi muộn', 'absent' => 'Vắng không phép', 'excused' => 'Vắng có phép'];
        $before = $after = [];
        foreach ($changes as $change) {
            foreach (['status' => 'trạng thái', 'note' => 'ghi chú'] as $field => $label) {
                $old = $change['before'][$field] ?? null;
                $new = $change['after'][$field] ?? null;
                if ($old === $new) {
                    continue;
                }
                $key = $change['name'].' — '.$label;
                $before[$key] = $field === 'status' ? ($labels[$old] ?? $old) : $old;
                $after[$key] = $field === 'status' ? ($labels[$new] ?? $new) : $new;
            }
        }

        $activity = activity('Lớp học/Điểm danh')->performedOn($session)->event('updated')
            ->withProperties([
                'class_id' => $class->id, 'class' => $class->name, 'class_session_id' => $session->id,
                'session_date' => $session->date->toDateString(), 'before' => $before, 'after' => $after,
            ]);
        if ($actor) {
            $activity->causedBy($actor);
        }
        $activity->log("Điểm danh lớp {$class->name} buổi {$session->date->format('d/m/Y')}: ".count($changes).' học viên thay đổi');
    }

    public static function config(Model|string $model): ?array
    {
        $class = is_string($model) ? $model : $model::class;

        return self::MODELS[$class] ?? null;
    }

    public static function logNameFor(Model|string $model): string
    {
        return self::config($model)['log'] ?? 'Hệ thống chung';
    }

    public static function undoableAttributes(Model|string $model): array
    {
        return self::config($model)['undo'] ?? [];
    }

    public static function descriptionFor(Model $model, string $event): string
    {
        if ($custom = self::currentDescription()) {
            return $custom;
        }

        $label = self::config($model)['label'] ?? class_basename($model);
        $name = $model->getAttribute('name') ?? $model->getAttribute('code') ?? $model->getAttribute('title');
        $target = trim($label.' '.($name ? "\"{$name}\"" : '#'.$model->getKey()));

        return match ($event) {
            'created' => "Tạo mới {$target}",
            'updated' => "Cập nhật {$target}",
            'deleted' => "Xóa {$target}",
            'restored' => "Khôi phục {$target}",
            default => ucfirst($event)." {$target}",
        };
    }

    /**
     * Nhãn tiếng Việt cho event của nhật ký.
     */
    public static function eventLabel(?string $event): string
    {
        return match ($event) {
            'created' => 'Tạo mới',
            'updated' => 'Cập nhật',
            'deleted' => 'Xóa',
            'restored' => 'Khôi phục',
            null, '' => 'Thao tác',
            default => $event,
        };
    }
}
