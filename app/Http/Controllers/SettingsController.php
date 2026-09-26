<?php

namespace App\Http\Controllers;

use App\Support\Navigation\SidebarMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Trang Cài đặt: gom các màn cấu hình / danh mục (URL cũ giữ nguyên, layout tự hiện menu con).
 * /settings chuyển tới mục đầu tiên user được xem, không có mục nào thì 403.
 */
class SettingsController extends Controller
{
    public function __invoke(Request $request, SidebarMenu $menu): RedirectResponse
    {
        $url = $menu->settingsUrlFor($request->user(), $request);
        abort_if($url === null, 403);

        return redirect()->to($url);
    }
}
