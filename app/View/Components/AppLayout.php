<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * Tiêu đề trang (<title> + topbar). Khai báo là prop để Blade không escape sẵn như thuộc tính thường
     * (thuộc tính :title="..." bị e() một lần rồi {{ }} escape thêm lần nữa → hiện "&amp;amp;").
     */
    public function __construct(public ?string $title = null) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
