<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">event_note</span>
                Nhật ký sự vụ Học vụ
            </h1>
        </div>
    </x-slot>

    <div class="space-y-6">
        
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        {{-- Form ghi sự vụ mới --}}
        <form method="POST" action="{{ route('reports.journal.store') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-gray-900">Ghi nhận sự vụ mới</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input type="text" name="title" required placeholder="Tiêu đề sự vụ *" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('title') }}">
                <select name="severity" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                    <option value="normal">Bình thường</option>
                    <option value="important">Quan trọng</option>
                    <option value="urgent">Khẩn cấp</option>
                </select>
            </div>
            <textarea name="content" rows="2" placeholder="Mô tả chi tiết..." class="w-full text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">{{ old('content') }}</textarea>
            <div class="flex items-center justify-between">
                <input type="date" name="report_date" value="{{ now()->toDateString() }}" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">add</span> Ghi sự vụ
                </button>
            </div>
        </form>

        {{-- Danh sách sự vụ --}}
        <div class="space-y-3">
            @forelse ($journals as $j)
                @php
                    $sevClass = match($j->severity) {
                        'urgent' => 'bg-rose-100 text-rose-700',
                        'important' => 'bg-amber-100 text-amber-700',
                        default => 'bg-gray-100 text-gray-600',
                    };
                    $statusClass = match($j->status) {
                        'resolved' => 'bg-emerald-100 text-emerald-700',
                        'following' => 'bg-blue-100 text-blue-700',
                        default => 'bg-orange-100 text-orange-700',
                    };
                    $statusLabel = match($j->status) {
                        'resolved' => 'Đã xử lý', 'following' => 'Đang theo dõi', default => 'Mới',
                    };
                @endphp
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $sevClass }}">{{ $j->severity_label }}</span>
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $statusClass }}">{{ $statusLabel }}</span>
                                <span class="font-bold text-sm text-gray-900">{{ $j->title }}</span>
                            </div>
                            <div class="text-[11px] text-gray-400 mt-1">
                                {{ $j->report_date->format('d/m/Y') }}
                                @if ($isPriv) · <span class="font-semibold text-gray-600">{{ $j->user?->name }}</span> @endif
                            </div>
                            @if ($j->content)
                                <p class="text-sm text-gray-600 mt-2">{{ $j->content }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('reports.journal.status', $j->id) }}" class="shrink-0">
                            @csrf
                            <select name="status" onchange="this.form.submit()" class="text-[11px] rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container py-1">
                                <option value="open" @selected($j->status==='open')>Mới</option>
                                <option value="following" @selected($j->status==='following')>Đang theo dõi</option>
                                <option value="resolved" @selected($j->status==='resolved')>Đã xử lý</option>
                            </select>
                        </form>
                    </div>

                    {{-- Follow-ups --}}
                    @if ($j->followups->isNotEmpty())
                        <div class="pl-3 border-l-2 border-gray-100 space-y-1.5">
                            @foreach ($j->followups as $f)
                                <div class="text-xs text-gray-600">
                                    <span class="font-semibold text-gray-800">{{ $f->user?->name ?? 'N/A' }}:</span> {{ $f->content }}
                                    <span class="text-gray-400">· {{ $f->created_at->format('d/m H:i') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Thêm follow-up (tạo tác vụ) --}}
                    <form method="POST" action="{{ route('reports.journal.followup', $j->id) }}" class="flex items-center gap-2">
                        @csrf
                        <input type="text" name="content" required placeholder="Nhập nội dung tác vụ / follow-up..." class="flex-1 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                        <button type="submit" class="px-3 py-2 rounded-lg bg-orange-50 text-primary hover:bg-orange-100 text-xs font-bold transition flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">add_task</span> Tạo tác vụ
                        </button>
                    </form>
                </div>
            @empty
                <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">event_note</span>
                    <p class="mt-2 text-sm">Chưa có sự vụ nào được ghi nhận.</p>
                </div>
            @endforelse

            {{ $journals->links() }}
        </div>
    </div>
</x-app-layout>
