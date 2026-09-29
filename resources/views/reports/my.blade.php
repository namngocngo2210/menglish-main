<x-app-layout>
    @php
        $typeLabels = \App\Models\StaffReport::TYPE_LABELS;
        $label = $typeLabels[$type] ?? 'Báo cáo';
    @endphp
    <x-ui.page-header :title="$label . ' của tôi'" icon="assignment">
        <x-slot:meta>Nộp và theo dõi {{ mb_strtolower($label) }} theo vai trò của bạn</x-slot:meta>
        <x-slot:actions>
            <x-ui.button icon="send" x-on:click="$dispatch('open-modal', 'new-report')">Nộp {{ mb_strtolower($label) }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif


        <div class="space-y-3">
            <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider">Lịch sử đã nộp</h2>
            @forelse ($reports as $r)
                <div class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm text-on-surface">{{ $r->title }}</span>
                        <span class="text-[11px] text-on-surface-variant/70">{{ $r->report_date->format('d/m/Y') }}</span>
                    </div>
                    <p class="text-sm text-on-surface-variant mt-2 whitespace-pre-line">{{ $r->content }}</p>
                </div>
            @empty
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm">
                    <x-ui.empty-state icon="description" :title="'Bạn chưa nộp '.mb_strtolower($label).' nào.'" />
                </div>
            @endforelse
            <x-ui.pagination :paginator="$reports" :options="[]" />
        </div>
    </div>

    <x-ui.modal name="new-report" :title="'Nộp '.mb_strtolower($label).' mới'" max-width="xl" :show="old('_modal') === 'new-report'">
        <form id="new-report-form" method="POST" action="{{ route('reports.my.store') }}" class="space-y-md">
            @csrf
            <input type="hidden" name="_modal" value="new-report">
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-ui.input name="title" label="Tiêu đề" required />
                </div>
                <x-ui.date name="report_date" label="Ngày báo cáo" :value="now()->toDateString()" />
            </div>
            <x-ui.textarea name="content" label="Nội dung" rows="6" required placeholder="Kết quả thực hiện, tồn đọng, kế hoạch..." />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-report')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="new-report-form" icon="send">Nộp báo cáo</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-app-layout>
