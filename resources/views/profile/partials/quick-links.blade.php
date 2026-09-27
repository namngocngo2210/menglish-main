{{-- Lối tắt tới các màn hình công việc của vai trò --}}
@if (! empty($quickLinks))
<div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 shadow-sm space-y-4">
    <h2 class="text-sm font-black text-on-surface uppercase tracking-wider flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-[20px]">apps</span>
        {{ ($portal ?? null) === 'student' ? 'Cổng học viên' : 'Màn hình công việc của bạn' }}
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        @foreach ($quickLinks as $link)
            <a href="{{ $link['url'] }}" class="flex items-center gap-2 p-3 rounded-2xl bg-surface-container-low/70 border border-surface-container-highest hover:border-primary-container hover:bg-surface-container-lowest transition text-xs font-bold text-on-surface">
                <span class="material-symbols-outlined text-primary text-[20px]">{{ $link['icon'] }}</span>
                <span class="min-w-0">{{ $link['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
@endif
