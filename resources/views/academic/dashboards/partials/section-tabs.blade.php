{{-- Tab cấp mục "Báo cáo & sự vụ" (menu Lớp học): hai màn giám sát chung một chỗ. --}}
<x-ui.tabs class="mb-lg">
    <x-ui.tab icon="assessment" :href="route('academic.dashboards.reports')" :active="request()->routeIs('academic.dashboards.reports')">Báo cáo đào tạo</x-ui.tab>
    <x-ui.tab icon="report" :href="route('academic.dashboards.incidents')" :active="request()->routeIs('academic.dashboards.incidents')">Nhật ký sự vụ</x-ui.tab>
</x-ui.tabs>
