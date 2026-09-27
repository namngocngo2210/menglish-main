<x-ui.feature-pending title="Checklist Học phí & Feedback theo lớp" description="Theo dõi nhắc học phí và feedback Big Test định kỳ theo từng lớp.">
    @can('tuition.view')
        <x-ui.button variant="secondary" icon="payments" :href="route('tuition.overdue')">Thu phí quá hạn & Nhắc phí</x-ui.button>
    @endcan
    @can('syllabus.view')
        <x-ui.button variant="secondary" icon="grading" :href="route('syllabus.big-tests.results')">Kết quả Big Test</x-ui.button>
    @endcan
</x-ui.feature-pending>
