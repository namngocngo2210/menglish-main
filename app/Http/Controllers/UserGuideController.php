<?php

namespace App\Http\Controllers;

use App\Support\Guide\UserGuide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Trang "Hướng dẫn sử dụng" theo vai trò (mọi người dùng đã đăng nhập), xem App\Support\Guide\UserGuide. */
class UserGuideController extends Controller
{
    public function index(Request $request, UserGuide $guides): Response
    {
        $list = $guides->for($request->user(), $request);
        $ids = array_column($list, 'id');
        $selected = in_array($request->query('vai-tro'), $ids, true) ? $request->query('vai-tro') : $ids[0];

        return Inertia::render('Guide/Index', [
            'guides' => $list,
            'selected' => $selected,
        ]);
    }
}
