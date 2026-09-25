{{--
    <x-ui.feature-pending> — trang cho chức năng CHƯA triển khai: hiện rõ trạng thái thay vì dữ liệu mẫu.
    Props: title, description (tuỳ chọn). Slot: liên kết tới màn thật liên quan (tuỳ chọn).
--}}
@props(['title', 'description' => null])

<x-app-layout :title="$title">
    <div class="mx-auto max-w-3xl">
        <x-ui.page-header :title="$title" :description="$description" />
        <div class="rounded-xl border border-surface-container-highest bg-surface">
            <x-ui.empty-state icon="construction" title="Chức năng chưa triển khai"
                description="Màn hình này chưa được kết nối dữ liệu nên chưa có gì để hiển thị. Vui lòng dùng các màn liên quan bên dưới.">
                {{ $slot }}
            </x-ui.empty-state>
        </div>
    </div>
</x-app-layout>
