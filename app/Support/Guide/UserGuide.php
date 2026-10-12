<?php

namespace App\Support\Guide;

use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Illuminate\Http\Request;

/**
 * Trang "Hướng dẫn sử dụng": mỗi vai trò một bộ hướng dẫn (chức năng → ảnh chụp + các bước), nội dung ở
 * resources/guides/<id>.php, ảnh ở public/images/huong-dan/<id>/.
 *
 * Người dùng thấy hướng dẫn của vai trò mình trước. Nhân sự xem được hướng dẫn mọi vai trò (để hỗ trợ đồng nghiệp,
 * học viên); học viên / phụ huynh chỉ thấy hướng dẫn Học viên (không lộ quy trình nội bộ).
 */
final class UserGuide
{
    /** Bộ hướng dẫn → vai trò dùng nó, theo thứ tự hiển thị. */
    public const GUIDES = [
        'admin' => [Roles::ADMIN],
        'quan-ly' => [Roles::MANAGER],
        'hoc-thuat' => [Roles::ACADEMIC_LEAD],
        'hoc-vu' => [Roles::ACADEMIC_STAFF],
        'tu-van' => [Roles::SALES_CONSULTANT],
        'giao-vien' => [Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME],
        'tro-giang' => [Roles::ASSISTANT],
        'hoc-vien' => [Roles::STUDENT],
    ];

    /** Hướng dẫn chung cho mọi người (đăng nhập, hồ sơ, thông báo, điện thoại). */
    public const COMMON = 'chung';

    public function __construct(private readonly SidebarMenu $menu) {}

    /**
     * Bộ hướng dẫn user được xem: của mình trước (`own` = true), rồi các vai trò khác, cuối cùng là phần chung.
     * Nút "Mở màn hình" (`url`) chỉ có ở mục user mở được.
     *
     * @return list<array<string, mixed>>
     */
    public function for(User $user, ?Request $request = null): array
    {
        $roles = $user->getRoleNames()->all();
        $own = array_keys(array_filter(self::GUIDES, fn (array $guideRoles) => array_intersect($guideRoles, $roles) !== []));
        $visible = $user->isPortalStudentOnly() ? ['hoc-vien'] : array_keys(self::GUIDES);
        $ordered = [...array_values(array_intersect($own, $visible)), ...array_values(array_diff($visible, $own))];

        return array_map(fn (string $id) => $this->guide($id, in_array($id, $own, true), $user, $request), [...$ordered, self::COMMON]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function content(string $id): array
    {
        return require resource_path("guides/{$id}.php");
    }

    /**
     * @return array<string, mixed>
     */
    private function guide(string $id, bool $own, User $user, ?Request $request): array
    {
        $guide = self::content($id);

        return [
            'id' => $id,
            'label' => $guide['label'],
            'icon' => $guide['icon'],
            'summary' => $guide['summary'],
            'own' => $own,
            'common' => $id === self::COMMON,
            'sections' => array_map(fn (array $section) => [
                ...$section,
                'images' => array_map(fn (array $image) => [...$image, 'src' => asset('images/huong-dan/'.$image['src'])], $section['images'] ?? []),
                'url' => $this->openUrl($section['route'] ?? null, $user, $request),
            ], $guide['sections']),
        ];
    }

    /** Link mở màn hình của mục hướng dẫn, chỉ khi route không cần tham số và user có quyền mở. */
    private function openUrl(?string $route, User $user, ?Request $request): ?string
    {
        if (! $route || ! $this->menu->canSee($user, ['route' => $route], $request)) {
            return null;
        }

        try {
            return route($route);
        } catch (\Throwable) {
            return null;
        }
    }
}
