<x-app-layout>
    @php
        $typeLabels = \App\Models\StaffReport::TYPE_LABELS;
        $label = $typeLabels[$type] ?? 'Báo cáo';
    @endphp
    <x-ui.page-header :title="$label . ' của tôi'" icon="assignment">
        <x-slot:meta>Nộp và theo dõi {{ mb_strtolower($label) }} theo vai trò của bạn</x-slot:meta>
    </x-ui.page-header>

    <div class="space-y-6">
        
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('reports.my.store') }}" class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-on-surface">Nộp {{ mb_strtolower($label) }} mới</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <x-ui.input name="title" required placeholder="Tiêu đề *" aria-label="Tiêu đề" />
                </div>
                <x-ui.date name="report_date" :value="now()->toDateString()" aria-label="Ngày báo cáo" />
            </div>
            <x-ui.textarea name="content" rows="5" required placeholder="Nội dung: kết quả thực hiện, tồn đọng, kế hoạch..." aria-label="Nội dung" />
            <div class="flex justify-end">
                <x-ui.button type="submit" icon="send">Nộp báo cáo</x-ui.button>
            </div>
        </form>

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
</x-app-layout>
