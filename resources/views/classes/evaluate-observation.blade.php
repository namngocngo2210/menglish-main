<x-ui.feature-pending title="Đánh giá dự giờ học thuật" description="Chấm điểm và nhận xét buổi dự giờ của giáo viên.">
    <x-ui.button variant="secondary" icon="meeting_room" :href="route('classes.index')">Danh sách lớp</x-ui.button>
    @can('kpi.view')
        <x-ui.button variant="secondary" icon="analytics" :href="route('kpi.monthly')">Tổng hợp KPI tháng</x-ui.button>
    @endcan
</x-ui.feature-pending>
