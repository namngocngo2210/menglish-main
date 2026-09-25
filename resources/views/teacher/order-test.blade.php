<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('teacher.home') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">quiz</span>
                    Chặng đang dạy &amp; Yêu cầu đề test — {{ $class->name }}
                </h1>
                <p class="text-xs text-gray-500">{{ $class->course?->name ?? $class->program }} · Gửi yêu cầu cấp đề Mini/Big Test tới Ban Học thuật</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Chặng đang dạy -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">flag</span>
                    Chặng được giao cho tôi
                </h2>
                <div class="space-y-2">
                    @forelse ($assignments as $asg)
                        <div class="border border-gray-200 rounded-xl p-3.5 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-bold text-gray-900">{{ $asg->stage_name ?? 'Chặng #'.$asg->id }}</div>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    {{ $asg->assigned_chapters }} · Hạn {{ $asg->deadline?->format('d/m/Y') ?? '—' }}
                                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold {{ $asg->progress_percent >= 100 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                        {{ (int) $asg->progress_percent }}%
                                    </span>
                                </div>
                            </div>
                            <button type="button"
                                    onclick="fillStage('{{ $asg->stage_name ?? 'Chặng #'.$asg->id }}')"
                                    class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-[11px] font-bold shrink-0">
                                Order test
                            </button>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 py-4 text-center">Chưa có chặng nào được giao cho tôi ở lớp này.</p>
                    @endforelse
                </div>
            </div>

            <!-- Form yêu cầu đề -->
            <form action="{{ route('teacher.order-test.submit', $class->id) }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
                @csrf
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">send</span>
                    Gửi yêu cầu đề test
                </h2>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Chặng / nội dung cần đề <span class="text-rose-500">*</span></label>
                    <input type="text" name="stage_name" id="stageNameInput" required
                           placeholder="VD: Chặng 2: Present Simple & Vocabulary"
                           class="w-full text-xs rounded-xl border border-gray-200 px-3 py-2 font-semibold" />
                    @error('stage_name') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Loại đề <span class="text-rose-500">*</span></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2 border border-gray-200 rounded-xl px-3 py-2 cursor-pointer hover:bg-orange-50/30">
                            <input type="radio" name="test_type" value="mini" checked class="text-primary focus:ring-primary-container" />
                            <span class="text-xs font-semibold">Mini Test (cuối chặng)</span>
                        </label>
                        <label class="flex items-center gap-2 border border-gray-200 rounded-xl px-3 py-2 cursor-pointer hover:bg-orange-50/30">
                            <input type="radio" name="test_type" value="big" class="text-primary focus:ring-primary-container" />
                            <span class="text-xs font-semibold">Big Test (giữa/cuối khóa)</span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Ngày thi dự kiến</label>
                    <input type="date" name="exam_date" value="{{ old('exam_date') }}" min="{{ now()->toDateString() }}"
                           class="w-full text-xs rounded-xl border border-gray-200 px-3 py-2" />
                    <p class="text-[11px] text-gray-500 mt-1">Hạn xử lý của Học thuật = ngày thi − {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày (để trống: trong {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày).</p>
                    @error('exam_date') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Ghi chú cho học vụ</label>
                    <textarea name="note" rows="3" placeholder="VD: đề trọng tâm Listening Part 1-2, độ khó vừa phải..."
                              class="w-full text-xs rounded-xl border border-gray-200 px-3 py-2"></textarea>
                </div>
                <button type="submit" class="w-full px-4 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition">
                    Gửi yêu cầu tới Ban Học thuật
                </button>
            </form>
        </div>

        <!-- Lịch sử yêu cầu -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">history</span>
                    Yêu cầu đã gửi cho lớp này
                </h2>
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Thời gian</th>
                        <th class="py-3 px-4">Loại đề</th>
                        <th class="py-3 px-4">Chặng</th>
                        <th class="py-3 px-4">Ngày thi / Hạn xử lý</th>
                        <th class="py-3 px-4">Ghi chú</th>
                        <th class="py-3 px-4">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($requests as $req)
                        <tr>
                            <td class="py-3 px-4 font-mono text-gray-500">{{ $req->created_at->format('d/m/Y H:i') }}</td>
                            <td class="py-3 px-4 font-bold">{{ $req->type_label }}</td>
                            <td class="py-3 px-4 font-semibold text-gray-900">{{ $req->stage_name }}</td>
                            <td class="py-3 px-4 text-gray-600">
                                <div>Thi: {{ $req->exam_date?->format('d/m/Y') ?? '—' }}</div>
                                <div class="text-[11px] text-gray-400">Hạn: {{ $req->due_date?->format('d/m/Y') ?? '—' }}</div>
                            </td>
                            <td class="py-3 px-4 text-gray-500">{{ $req->note ?: '—' }}</td>
                            <td class="py-3 px-4 space-y-1">
                                <x-ui.badge :color="$req->status_color">{{ $req->status_label }}</x-ui.badge>
                                @if ($req->status === 'approved' && $req->test_link)
                                    <a href="{{ $req->test_link }}" target="_blank" rel="noopener" class="block text-[11px] text-primary font-semibold hover:underline">Mở link đề</a>
                                @elseif ($req->status === 'rejected' && $req->rejection_reason)
                                    <p class="text-[11px] text-rose-600">Lý do: {{ $req->rejection_reason }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-8 text-gray-400 text-xs">Chưa gửi yêu cầu đề test nào cho lớp này.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @push('scripts')
    <script>
        function fillStage(name) {
            const input = document.getElementById('stageNameInput');
            input.value = name;
            input.focus();
        }
    </script>
    @endpush
</x-app-layout>
