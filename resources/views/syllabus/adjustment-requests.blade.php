<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">rule</span>
                        Duyệt yêu cầu xin điều chỉnh tiến độ
                    </h1>
                    <p class="text-xs text-gray-500">Duyệt giãn tiến độ sẽ thêm buổi học vào cuối lịch của lớp; từ chối bắt buộc nhập lý do.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="speed" :href="route('syllabus.teacher-adjust')">Gửi yêu cầu mới</x-ui.button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 8])

    @php($canReview = auth()->user()->can('syllabus.approve_adjustment'))

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-7 min-w-0">
            <x-ui.data-table>
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">pending_actions</span>
                        <span class="text-sm font-bold text-gray-900">Danh sách yêu cầu</span>
                        <x-ui.badge color="warning">{{ $requests->total() }} yêu cầu</x-ui.badge>
                    </div>
                </x-slot:header>
                <table>
                    <thead><tr><th>Giáo viên / Lớp</th><th>Yêu cầu</th><th class="text-right">Trạng thái</th></tr></thead>
                    <tbody>
                        @forelse ($requests as $req)
                            <tr class="{{ $selected?->id === $req->id ? 'bg-orange-50/60' : '' }}">
                                <td>
                                    <a href="{{ route('syllabus.adjustment-requests', array_filter(['request' => $req->id, 'page' => request('page')])) }}" class="block">
                                        <p class="font-bold text-gray-900 text-xs">{{ $req->teacher?->name ?? '—' }}</p>
                                        <p class="text-[11px] text-gray-500">{{ $req->classModel?->name }} · {{ $req->created_at->format('d/m/Y') }}</p>
                                    </a>
                                </td>
                                <td class="text-xs">
                                    <p class="font-semibold text-gray-800">{{ $req->request_type }}</p>
                                    @if ($req->extra_sessions)
                                        <p class="text-[11px] text-primary font-semibold">+{{ $req->extra_sessions }} buổi</p>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <x-ui.badge :color="['pending' => 'warning', 'approved' => 'success', 'rejected' => 'error'][$req->status] ?? 'neutral'">{{ $req->status_label }}</x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-ui.empty-state icon="inbox" title="Chưa có yêu cầu điều chỉnh tiến độ nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$requests" unit="yêu cầu" /></x-slot:footer>
            </x-ui.data-table>
        </div>

        <div class="lg:col-span-5 min-w-0">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center gap-2 bg-gray-50/50">
                    <span class="material-symbols-outlined text-primary text-[20px]">assignment</span>
                    <span class="text-sm font-bold text-gray-900">Chi tiết yêu cầu &amp; Xử lý</span>
                </div>

                @if (! $selected)
                    <x-ui.empty-state icon="inbox" title="Không có yêu cầu để xử lý" />
                @else
                    <div class="p-5 space-y-4 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-gray-900">{{ $selected->teacher?->name ?? '—' }}</p>
                                <p class="text-[11px] text-gray-400">Gửi {{ $selected->created_at->format('H:i d/m/Y') }}</p>
                            </div>
                            <x-ui.badge :color="['pending' => 'warning', 'approved' => 'success', 'rejected' => 'error'][$selected->status] ?? 'neutral'">{{ $selected->status_label }}</x-ui.badge>
                        </div>

                        <dl class="bg-gray-50 p-3.5 rounded-xl border border-gray-100 grid grid-cols-2 gap-3">
                            <div>
                                <dt class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Lớp</dt>
                                <dd class="font-bold text-gray-800">{{ $selected->classModel?->name }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Kết thúc lớp hiện tại</dt>
                                <dd class="font-mono text-gray-700">{{ $selected->classModel?->end_date?->format('d/m/Y') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Yêu cầu</dt>
                                <dd class="font-bold text-primary">{{ $selected->request_type }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Số buổi cần thêm</dt>
                                <dd class="font-bold text-gray-800">{{ $selected->extra_sessions ?: 0 }}</dd>
                            </div>
                        </dl>

                        <div>
                            <p class="font-bold text-gray-800 mb-1">Lý do từ giáo viên</p>
                            <div class="bg-orange-50/30 border border-orange-200/60 rounded-xl p-3 text-gray-700 whitespace-pre-line">{{ $selected->reason }}</div>
                        </div>

                        @if ($selected->status === 'approved')
                            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-emerald-800">
                                <p class="font-bold">Đã duyệt bởi {{ $selected->approver?->name }} {{ $selected->reviewed_at ? 'lúc '.$selected->reviewed_at->format('H:i d/m/Y') : '' }}</p>
                                <p class="mt-1">{{ $selected->applied_note }}</p>
                            </div>
                        @elseif ($selected->status === 'rejected')
                            <div class="bg-rose-50 border border-rose-200 rounded-xl p-3 text-rose-800">
                                <p class="font-bold">Đã từ chối bởi {{ $selected->approver?->name }} {{ $selected->reviewed_at ? 'lúc '.$selected->reviewed_at->format('H:i d/m/Y') : '' }}</p>
                                <p class="mt-1">Lý do: {{ $selected->rejection_reason ?: '—' }}</p>
                            </div>
                        @elseif ($canReview)
                            <form method="POST" action="{{ route('syllabus.adjustment-requests.approve', $selected->id) }}" class="space-y-2 border-t border-gray-100 pt-4">
                                @csrf
                                <x-ui.input type="number" name="extra_sessions" label="Số buổi thêm vào lịch khi duyệt" min="0" :max="\App\Models\SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS" :value="$selected->extra_sessions ?? 0"
                                            hint="Buổi mới nối tiếp sau buổi cuối của lớp, theo TKB, bỏ qua ngày nghỉ lễ; 0 = chỉ ghi nhận, không đổi lịch." />
                                <x-ui.button type="submit" icon="check_circle" class="w-full">Phê duyệt</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('syllabus.adjustment-requests.reject', $selected->id) }}" class="space-y-2 border-t border-gray-100 pt-4">
                                @csrf
                                <x-ui.textarea name="rejection_reason" label="Lý do từ chối" required rows="3" placeholder="Nhập lý do chi tiết để phản hồi lại giáo viên..." />
                                <x-ui.button type="submit" variant="danger" icon="cancel" class="w-full">Từ chối</x-ui.button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
