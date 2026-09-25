<?php

namespace App\Providers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Silence PHP 8.5 deprecation notices (e.g. BelongsTo null offsets and PDO MySQL constants)
        error_reporting(error_reporting() & ~E_DEPRECATED);

        // Đăng ký Gate::before của lớp override cá nhân TRƯỚC Spatie. Spatie
        // đăng ký before của nó qua callAfterResolving(Gate) trong boot();
        // các callback afterResolving chạy theo thứ tự đăng ký, nên đăng ký ở
        // register() (chạy trước mọi boot) đảm bảo before của ta được thêm &
        // chạy ĐẦU TIÊN -> mới có thể THU HỒI (deny) quyền mà role đã cấp
        // (before của Spatie chỉ trả true/null nên không thể deny).
        $this->app->afterResolving(\Illuminate\Contracts\Auth\Access\Gate::class, function ($gate) {
            $this->registerPermissionOverrideGate($gate);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRequestMacros();
        $this->registerDynamicMailConfig();
    }

    /**
     * Nạp cấu hình máy chủ gửi thư SMTP động từ cơ sở dữ liệu (system_settings)
     */
    protected function registerDynamicMailConfig(): void
    {
        try {
            if (Schema::hasTable('system_settings')) {
                SystemSetting::applyDynamicMailConfig();
            }
        } catch (\Throwable $e) {
            // Ignore during early bootstrap or migrations
        }
    }

    /**
     * Helper macro cho phân trang tuỳ chọn số dòng (10, 20, 50, 100, all)
     */
    protected function registerRequestMacros(): void
    {
        Request::macro('perPage', function ($default = 15) {
            $val = $this->input('per_page');
            if ($val === 'all' || (int) $val >= 9999) {
                return 9999;
            }
            if (in_array((int) $val, [10, 20, 50, 100], true)) {
                return (int) $val;
            }

            return (int) $default;
        });
    }

    /**
     * Lớp "phân quyền chi tiết cá nhân" đè lên kết quả kiểm tra quyền theo
     * role mặc định của Spatie.
     *
     * PHẢI xử lý override trong Gate::before (KHÔNG dùng Gate::after) vì
     * Gate::after chỉ được lấy kết quả khi ability đang là null
     * ($result = $result ?? $afterResult), nên KHÔNG thể đảo allow -> deny.
     * Điều này khiến override "chỉ xem" (deny) bị bỏ qua khi role vẫn cấp
     * quyền. Trả về giá trị non-null trong Gate::before thì mang tính quyết
     * định (short-circuit), cho phép cả MỞ RỘNG lẫn THU HẸP quyền theo role.
     *
     * Chỉ xử lý override ở phạm vi "all" tại đây (Gate check theo tên ability
     * không mang ngữ cảnh scope). Override theo scope cụ thể (branch/class)
     * vẫn được kiểm tra qua User::hasModuleAction() trong từng module.
     */
    protected function registerPermissionOverrideGate($gate): void
    {
        $gate->before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            // Admin luôn có toàn quyền.
            if ($user->hasRole('admin')) {
                return true;
            }

            // Chỉ can thiệp với ability dạng "module.action".
            if (! str_contains($ability, '.')) {
                return null;
            }

            [$module, $action] = explode('.', $ability, 2);

            // Nạp toàn bộ override 1 lần / request rồi cache trên user để
            // tránh truy vấn lặp lại cho mỗi lần @can.
            if (! $user->relationLoaded('permissionOverrides')) {
                $user->setRelation('permissionOverrides', $user->permissionOverrides()->get());
            }

            $override = $user->getRelation('permissionOverrides')->first(
                fn (UserPermissionOverride $o) => $o->module === $module
                    && $o->action === $action
                    && $o->scope_type === UserPermissionOverride::SCOPE_ALL
            );

            // Có override phạm vi "all" => quyết định luôn (allow hoặc deny).
            // Không có => trả null để fallback về quyền theo role (Spatie).
            return $override !== null ? (bool) $override->allow : null;
        });
    }
}
