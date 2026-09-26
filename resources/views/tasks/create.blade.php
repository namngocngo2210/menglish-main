{{-- Giao việc mới (mockup phan-cong-cong-viec/form_giao_vi_c) — trang riêng, cùng trường với modal ở Danh sách công việc. --}}
<x-app-layout title="Giao việc mới">
    <x-ui.page-header title="Giao việc mới" description="Tạo và phân công nhiệm vụ cho nhân sự; giáo viên / trợ giảng đề xuất việc cho Admin, Quản lý, Học vụ, Học thuật.">
        <x-slot:breadcrumbs>
            <a href="{{ route('tasks.index') }}" class="hover:text-primary">Công việc</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Giao việc mới</span>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <div class="mx-auto max-w-2xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
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
