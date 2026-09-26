{{-- Giao việc mới (mockup phan-cong-cong-viec/form_giao_vi_c): mở từ Danh sách công việc → modal 2xl (htmx);
     mở thẳng URL → trang riêng. "Giao cho: Trợ giảng" chuyển sang form giao việc theo ca (tasks.ta-assign). --}}
@php
    $title = auth()->user()->can('work_task.create') ? 'Giao việc mới' : 'Đề xuất việc cho Admin / Học vụ';
@endphp
@if ($asModal)
    <x-ui.modal-frame :title="$title" description="Tạo và phân công nhiệm vụ cho nhân sự; giáo viên / trợ giảng đề xuất việc cho Admin, Quản lý, Học vụ, Học thuật." size="2xl">
        @if ($canTaAssign)
            @include('tasks.partials.assign-mode-switch', ['current' => 'staff'])
        @endif
        <form id="modal-task-form" action="{{ route('tasks.store') }}" method="POST">
            @csrf
            @include('tasks.partials.task-form-fields')
        </form>
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-task-form" icon="send">Lưu và Giao việc</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout title="Giao việc mới">
    <x-ui.page-header title="Giao việc mới" description="Tạo và phân công nhiệm vụ cho nhân sự; giáo viên / trợ giảng đề xuất việc cho Admin, Quản lý, Học vụ, Học thuật.">
        <x-slot:breadcrumbs>
            <a href="{{ route('tasks.index') }}" class="hover:text-primary">Công việc</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Giao việc mới</span>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <div class="mx-auto max-w-2xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
        @if ($canTaAssign)
            @include('tasks.partials.assign-mode-switch', ['current' => 'staff'])
        @endif
        <form action="{{ route('tasks.store') }}" method="POST" class="space-y-lg">
            @csrf
            @include('tasks.partials.task-form-fields')
            <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                <x-ui.button variant="secondary" :href="route('tasks.index')">Hủy</x-ui.button>
                <x-ui.button type="submit" icon="send">Lưu và Giao việc</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
@endif
