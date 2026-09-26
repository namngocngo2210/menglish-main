<x-app-layout>
    <x-ui.page-header title="Nhật ký sự vụ Học vụ" icon="event_note" />

    <div class="space-y-6">
        
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        {{-- Form ghi sự vụ mới --}}
        <form method="POST" action="{{ route('reports.journal.store') }}" class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-on-surface">Ghi nhận sự vụ mới</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <x-ui.input name="title" required placeholder="Tiêu đề sự vụ *" aria-label="Tiêu đề sự vụ" />
                </div>
                <x-ui.select name="severity" :options="['normal' => 'Bình thường', 'important' => 'Quan trọng', 'urgent' => 'Khẩn cấp']" aria-label="Mức độ" />
            </div>
            <x-ui.textarea name="content" rows="2" placeholder="Mô tả chi tiết..." aria-label="Mô tả chi tiết" />
            <div class="flex items-center justify-between">
                <x-ui.date name="report_date" :value="now()->toDateString()" aria-label="Ngày sự vụ" />
                <x-ui.button type="submit" icon="add">Ghi sự vụ</x-ui.button>
            </div>
        </form>

        {{-- Danh sách sự vụ --}}
        <div class="space-y-3">
            @forelse ($journals as $j)
                @php
                    $sevColor = match($j->severity) {
                        'urgent' => 'error',
                        'important' => 'warning',
                        default => 'neutral',
                    };
                    $statusColor = match($j->status) {
                        'resolved' => 'success',
                        'following' => 'secondary',
                        default => 'primary',
                    };
                    $statusLabel = match($j->status) {
                        'resolved' => 'Đã xử lý', 'following' => 'Đang theo dõi', default => 'Mới',
                    };
                @endphp
                <div class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <x-ui.badge :color="$sevColor" :pill="true" :dot="false">{{ $j->severity_label }}</x-ui.badge>
                                <x-ui.badge :color="$statusColor" :pill="true" :dot="false">{{ $statusLabel }}</x-ui.badge>
                                <span class="font-bold text-sm text-on-surface">{{ $j->title }}</span>
                            </div>
                            <div class="text-[11px] text-on-surface-variant/70 mt-1">
                                {{ $j->report_date->format('d/m/Y') }}
                                @if ($isPriv) · <span class="font-semibold text-on-surface-variant">{{ $j->user?->name }}</span> @endif
                            </div>
                            @if ($j->content)
                                <p class="text-sm text-on-surface-variant mt-2">{{ $j->content }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('reports.journal.status', $j->id) }}" class="shrink-0">
                            @csrf
                            <x-ui.select name="status" id="journal-status-{{ $j->id }}" onchange="this.form.submit()" aria-label="Trạng thái sự vụ" class="text-[11px]">
                                <option value="open" @selected($j->status==='open')>Mới</option>
                                <option value="following" @selected($j->status==='following')>Đang theo dõi</option>
                                <option value="resolved" @selected($j->status==='resolved')>Đã xử lý</option>
                            </x-ui.select>
                        </form>
                    </div>

                    {{-- Follow-ups --}}
                    @if ($j->followups->isNotEmpty())
                        <div class="pl-3 border-l-2 border-surface-container-highest space-y-1.5">
                            @foreach ($j->followups as $f)
                                <div class="text-xs text-on-surface-variant">
                                    <span class="font-semibold text-on-surface">{{ $f->user?->name ?? 'N/A' }}:</span> {{ $f->content }}
                                    <span class="text-on-surface-variant/70">· {{ $f->created_at->format('d/m H:i') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Thêm follow-up (tạo tác vụ) --}}
                    <form method="POST" action="{{ route('reports.journal.followup', $j->id) }}" class="flex items-center gap-2">
                        @csrf
                        <input type="text" name="content" required placeholder="Nhập nội dung tác vụ / follow-up..." aria-label="Nội dung tác vụ" class="flex-1 text-xs rounded-lg border-outline-variant bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/60 focus:border-primary-container focus:ring-primary-container/20">
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="add_task">Tạo tác vụ</x-ui.button>
                    </form>
                </div>
            @empty
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm">
                    <x-ui.empty-state icon="event_note" title="Chưa có sự vụ nào được ghi nhận." />
                </div>
            @endforelse

            <x-ui.pagination :paginator="$journals" :options="[]" />
        </div>
    </div>
</x-app-layout>
