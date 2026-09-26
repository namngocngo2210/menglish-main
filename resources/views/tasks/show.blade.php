{{-- Chi tiết công việc: mở từ danh sách → modal xem nhanh 2xl (đẩy URL /tasks/{id}); mở thẳng link → trang đầy đủ.
     Đổi trạng thái ngay trong modal: lưu xong đóng modal + "tasks-changed" làm mới danh sách. --}}
@if ($asModal)
    <x-ui.modal-frame :title="$task->title" :description="'Công việc #'.$task->id" cancel="Đóng">
        @include('tasks.partials.task-detail')
        <x-slot:footer>
            <x-ui.button variant="secondary" icon="open_in_new" :href="route('tasks.show', $task->id)" hx-boost="false">Mở trang đầy đủ</x-ui.button>
            @if ($allowed)
                <x-ui.button type="submit" form="modal-task-status-form" icon="check">Cập nhật trạng thái</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout :title="$task->title">
    <x-ui.page-header :title="$task->title" :description="'Công việc #'.$task->id">
        <x-slot:breadcrumbs>
            <a href="{{ route('tasks.index') }}" class="hover:text-primary">Công việc</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Chi tiết</span>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <div class="mx-auto max-w-2xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
        @include('tasks.partials.task-detail')
    </div>
</x-app-layout>
@endif
