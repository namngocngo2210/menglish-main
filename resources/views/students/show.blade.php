{{-- Chi tiết hồ sơ học sinh (mockup epic-6/chi-tiet-ho-so-hoc-sinh-desktop). Nội dung: students/partials/profile. --}}
<x-app-layout title="Chi tiết hồ sơ học sinh">
    <x-ui.page-header title="Chi tiết hồ sơ học sinh" :description="$student->name.' · '.$student->code">
        <x-slot:breadcrumbs>
            <a href="{{ route('dashboard') }}" class="hover:text-primary">Trang chủ</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <a href="{{ route('students.index') }}" class="hover:text-primary">Hồ sơ học sinh</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span class="font-semibold text-primary">Chi tiết</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="admin_panel_settings" :href="route('students.scoped', $student->id)">Xem theo phân quyền</x-ui.button>
            <x-ui.button variant="secondary" icon="arrow_back" :href="route('students.index')">Danh sách học sinh</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    @include('students.partials.profile')
</x-app-layout>
