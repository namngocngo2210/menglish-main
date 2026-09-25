<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-rose-600">gavel</span>
                    Biên bản Vi phạm &amp; Kỷ luật Nhân sự
                </h1>
                <p class="text-xs text-gray-500">Ghi nhận vi phạm quy chế đào tạo, trừ thưởng KPI và chế tài bảng lương</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="document.getElementById('newPenaltyModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_alert</span>
                    <span>Lập Biên bản Vi phạm mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    <!-- Create Penalty Modal -->
    <div id="newPenaltyModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <h3 class="font-bold text-sm text-gray-900">Lập Biên Bản Vi Phạm Mới</h3>
                <button type="button" onclick="document.getElementById('newPenaltyModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('penalties.store') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nhân sự vi phạm <span class="text-rose-500">*</span></label>
                    <select name="user_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-bold">
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Lỗi vi phạm <span class="text-rose-500">*</span></label>
                    <select name="violation_type" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                        <option value="Đến muộn > 15 phút không báo trước">Đến muộn > 15 phút không báo trước</option>
                        <option value="Chậm nộp nhận xét buổi học (> 24h)">Chậm nộp nhận xét buổi học (> 24h)</option>
                        <option value="Không nộp giáo án / bài tập đúng hạn">Không nộp giáo án / bài tập đúng hạn</option>
                        <option value="Nghỉ dạy không phép">Nghỉ dạy không phép</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Ngày vi phạm</label>
                        <input type="date" name="violation_date" value="{{ date('Y-m-d') }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Mức phạt (VNĐ)</label>
                        <input type="number" name="amount" value="200000" required class="w-full text-xs font-mono font-bold text-rose-600 rounded-xl border border-gray-200 p-2" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('newPenaltyModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg border text-xs text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-1.5 bg-rose-600 text-white text-xs font-bold rounded-lg shadow-sm">Lập biên bản</button>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        <!-- Penalty Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Mã biên bản</th>
                        <th class="py-3 px-4">Nhân sự vi phạm</th>
                        <th class="py-3 px-4">Lỗi vi phạm</th>
                        <th class="py-3 px-4">Ngày vi phạm</th>
                        <th class="py-3 px-4 text-right">Số tiền phạt</th>
                        <th class="py-3 px-4">Người lập</th>
                        <th class="py-3 px-4">Trạng thái</th>
                        <th class="py-3 px-4 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($penalties as $pen)
                        <tr class="hover:bg-rose-50/20 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-gray-900">{{ $pen->code }}</td>
                            <td class="py-3.5 px-4 font-bold text-gray-900">{{ $pen->user?->name }}</td>
                            <td class="py-3.5 px-4 font-medium text-rose-700">{{ $pen->violation_type }}</td>
                            <td class="py-3.5 px-4 font-mono text-gray-500">{{ $pen->violation_date->format('d/m/Y') }}</td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-rose-600 text-sm">
                                -{{ number_format($pen->amount) }}đ
                            </td>
                            <td class="py-3.5 px-4">{{ $pen->reporter?->name ?? 'Hệ thống' }}</td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $pen->status_badge }}">
                                    {{ $pen->status_label }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                @can('violation.confirm_error')
                                    @if (in_array($pen->status, ['pending', 'confirmed']))
                                        <form action="{{ route('penalties.confirm', $pen->id) }}" method="POST" class="inline">
                                            @csrf
                                            <input type="hidden" name="decision" value="error" />
                                            <button type="submit" class="px-2.5 py-1 bg-rose-600 text-white rounded-lg text-[11px] font-bold hover:bg-rose-700 transition" title="Xác nhận lỗi (chưa quyết phạt)">
                                                Xác nhận lỗi
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.confirm_fine')
                                    @if (in_array($pen->status, ['pending', 'confirmed']))
                                        <form action="{{ route('penalties.confirm', $pen->id) }}" method="POST" class="inline">
                                            @csrf
                                            <input type="hidden" name="decision" value="fine" />
                                            <button type="submit" class="px-2.5 py-1 bg-orange-600 text-white rounded-lg text-[11px] font-bold hover:bg-orange-700 transition" title="Quyết định phạt tiền — sẽ trừ bảng lương">
                                                Quyết phạt
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.mark_paid')
                                    @if ($pen->status === 'fined')
                                        <form action="{{ route('penalties.mark-paid', $pen->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1 bg-emerald-600 text-white rounded-lg text-[11px] font-bold hover:bg-emerald-700 transition" title="Nhân sự đã nộp phạt trực tiếp">
                                                Đã nộp phạt
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.mark_resolved')
                                    @if (in_array($pen->status, ['pending', 'confirmed', 'fined']))
                                        <form action="{{ route('penalties.resolve', $pen->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1 bg-sky-600 text-white rounded-lg text-[11px] font-bold hover:bg-sky-700 transition" title="Đóng vụ, miễn phạt tiền">
                                                Đóng vụ
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.cancel')
                                    @if (in_array($pen->status, ['pending', 'confirmed']))
                                        <form action="{{ route('penalties.cancel', $pen->id) }}" method="POST" class="inline" data-confirm="Hủy biên bản {{ $pen->code }}?">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1 bg-gray-200 text-gray-700 rounded-lg text-[11px] font-bold hover:bg-gray-300 transition">
                                                Hủy
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @if (! in_array($pen->status, ['pending', 'confirmed', 'fined']))
                                    <span class="text-gray-400 font-mono text-[11px]">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-400 text-xs">Không có biên bản vi phạm nào.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
