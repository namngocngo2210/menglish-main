<x-ui.feature-pending title="QA Observation — Dự giờ vận hành" description="Lên lịch dự giờ và theo dõi kết quả kiểm định giảng dạy.">
    <x-ui.button variant="secondary" icon="meeting_room" :href="route('classes.index')">Danh sách lớp</x-ui.button>
    @can('work_task.view')
    <x-ui.button variant="secondary" icon="calendar_month" :href="route('tasks.classes-dashboard')">Dashboard lớp theo ngày</x-ui.button>
    @endcan
</x-ui.feature-pending>
