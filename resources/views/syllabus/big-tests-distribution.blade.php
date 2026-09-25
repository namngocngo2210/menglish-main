<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">dynamic_form</span>
                    Phân phối Kỳ thi Big Test (Giữa kỳ / Cuối kỳ)
                </h1>
                <p class="text-xs text-gray-500">Phân phối bộ đề thi đồng loạt, cấp mã bảo mật thi và chỉ định phòng thi / giám thị</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="document.getElementById('newBigTestModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Tạo Đợt Big Test mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    <!-- Create Big Test Modal -->
    <div id="newBigTestModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <h3 class="font-bold text-sm text-gray-900">Phân Phối Đợt Thi Big Test Mới</h3>
                <button type="button" onclick="document.getElementById('newBigTestModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('syllabus.big-tests.store') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Tên đợt thi <span class="text-rose-500">*</span></label>
                    <input type="text" name="title" placeholder="Final Big Test #09 (Cuối Khóa)" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-bold" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Lớp thi áp dụng <span class="text-rose-500">*</span></label>
                        <select name="class_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-semibold text-primary">
                            @foreach ($classes as $cl)
                                <option value="{{ $cl->id }}">{{ $cl->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Loại kỳ thi</label>
                        <select name="test_type" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                            <option value="midterm">Giữa kỳ (Mid-term)</option>
                            <option value="final">Cuối khóa (Final)</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Thời gian thi <span class="text-rose-500">*</span></label>
                        <input type="datetime-local" name="scheduled_at" value="{{ date('Y-m-d\TH:i', strtotime('+3 days')) }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Phòng thi <span class="text-rose-500">*</span></label>
                        <input type="text" name="room" value="Phòng Lab 201" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('newBigTestModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg border text-xs text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-1.5 bg-primary-container text-white text-xs font-bold rounded-lg shadow-sm">Lưu bản nháp</button>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        <!-- Big Tests Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Mã kỳ thi</th>
                        <th class="py-3 px-4">Tên kỳ thi Big Test</th>
                        <th class="py-3 px-4">Lớp thi</th>
                        <th class="py-3 px-4">Thời gian &amp; Địa điểm</th>
                        <th class="py-3 px-4">Giám thị</th>
                        <th class="py-3 px-4">Mật mã thi</th>
                        <th class="py-3 px-4 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($bigTests as $bt)
                        <tr class="hover:bg-orange-50/20 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-gray-900">{{ $bt->code }}</td>
                            <td class="py-3.5 px-4 font-bold text-gray-900">{{ $bt->title }}</td>
                            <td class="py-3.5 px-4 font-semibold text-primary">{{ $bt->classModel?->name }}</td>
                            <td class="py-3.5 px-4">
                                <div>{{ $bt->scheduled_at ? $bt->scheduled_at->format('d/m/Y H:i') : '—' }}</div>
                                <div class="text-[10px] text-gray-400">{{ $bt->room }}</div>
                            </td>
                            <td class="py-3.5 px-4">{{ $bt->proctor?->name ?? 'Admin' }}</td>
                            <td class="py-3.5 px-4 font-mono font-bold text-emerald-600">{{ $bt->passcodeVisibleTo(auth()->user()) ? $bt->passcode : '••••••' }}</td>
                            <td class="py-3.5 px-4 text-right">
                                <div class="flex justify-end items-center gap-2">
                                    @if(!$bt->is_distributed)
                                        <form method="POST" action="{{ route('syllabus.big-tests.approve', $bt->id) }}">@csrf
                                            <button class="text-emerald-700 font-semibold hover:underline">Duyệt & phân phối</button>
                                        </form>
                                    @else
                                        <span class="text-[10px] text-emerald-700 font-bold">Đã phân phối</span>
                                    @endif
                                    <a href="{{ route('syllabus.big-tests.results', $bt->id) }}" class="text-primary hover:underline font-semibold">Bảng điểm</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-8 text-gray-400 text-xs">Chưa có kỳ thi Big Test nào được tạo.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
