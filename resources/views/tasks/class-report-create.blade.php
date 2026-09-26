{{-- Nộp báo cáo trực lớp (mockup phan-cong-cong-viec/n_p_b_o_c_o_tr_c_l_p_ta), luật A6 Q8:
     ảnh không bắt buộc; có ≥ 1 ảnh → đầu việc "Trực lớp" tự hoàn thành; không ảnh → chờ GV chính
     của lớp xác nhận (lớp chưa có GV chính → người giao việc).
     Mở từ Cổng TA → modal 2xl (htmx); mở thẳng URL → trang riêng. --}}
@if ($asModal)
    <x-ui.modal-frame title="Nộp báo cáo trực lớp" description="Nội dung bài giảng, nhật ký lớp và học sinh cần bổ trợ." size="2xl">
        @include('tasks.partials.class-report-form')
        @if ($classes->isNotEmpty())
            <x-slot:footer>
                <x-ui.button type="submit" form="modal-class-report-form" icon="send">Nộp báo cáo</x-ui.button>
            </x-slot:footer>
        @endif
    </x-ui.modal-frame>
@else
<x-app-layout title="Nộp báo cáo trực lớp">
    @include('tasks.partials.class-report-form')
</x-app-layout>
@endif
