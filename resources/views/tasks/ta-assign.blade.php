{{-- Tạo lượt giao việc cho Trợ giảng (mockup phan-cong-cong-viec/t_o_l_t_giao_vi_c_cho_tr_gi_ng) — 3 ca: Trước / Trong / Sau giờ học.
     Luật riêng so với "Giao việc mới" (nhiều đầu việc 1 lần, chỉ work_task.assign, TA trong phạm vi quản lý, giờ hạn theo buổi học,
     báo Admin khi gửi sau giờ chốt) nên giữ route riêng; mở từ danh sách / form Giao việc → modal 4xl, mở thẳng URL → trang riêng. --}}
@if ($asModal)
    <x-ui.modal-frame title="Tạo lượt giao việc cho Trợ giảng" description="Phân công nhiệm vụ chi tiết theo ngày và ca học." size="4xl">
        @include('tasks.partials.assign-mode-switch', ['current' => 'assistant'])
        @include('tasks.partials.ta-assign-form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-ta-assign-form" icon="send">Gửi nhiệm vụ</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout title="Tạo lượt giao việc cho Trợ giảng">
    <x-ui.page-header title="Tạo lượt giao việc cho Trợ giảng" description="Phân công nhiệm vụ chi tiết theo ngày và ca học.">
        <x-slot:breadcrumbs>
            <a href="{{ route('tasks.index') }}" class="hover:text-primary">Công việc</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Giao việc trợ giảng</span>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    @include('tasks.partials.ta-assign-form')
</x-app-layout>
@endif
