<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\SystemCategory;
use App\Models\TuitionReceipt;
use App\Models\User;
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
            'undo' => ['name', 'parent_name', 'email', 'dob', 'gender', 'address', 'course_interest', 'source'],
        ],
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
