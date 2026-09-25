<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">checklist_rtl</span>
                        Duyệt đề xuất sửa giáo trình
                    </h1>
                    <p class="text-xs text-gray-500">Danh sách và chi tiết đề xuất sửa giáo trình từ giáo viên; Học thuật phê duyệt hoặc từ chối kèm lý do.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Gửi đề xuất</x-ui.button>
                <x-ui.button icon="rule" :href="route('syllabus.adjustment-requests')">Duyệt tiến độ</x-ui.button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 6])

    @php($canReview = auth()->user()->can('syllabus.approve_adjustment'))

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Danh sách đề xuất --}}
        <div class="lg:col-span-5 min-w-0">
            <x-ui.data-table>
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">pending_actions</span>
                        <h2 class="text-sm font-bold text-gray-900">Đề xuất</h2>
                        <x-ui.badge color="warning">{{ $pendingCount }} chờ duyệt</x-ui.badge>
                    </div>
                    <form method="GET">
                        <x-ui.select name="status" placeholder="Tất cả trạng thái" :options="\App\Models\SyllabusChangeProposal::STATUS_LABELS" onchange="this.form.submit()" />
                    </form>
                </x-slot:header>
                <table>
                    <thead><tr><th>Đề xuất</th><th class="text-right">Trạng thái</th></tr></thead>
                    <tbody>
                        @forelse ($proposals as $p)
                            <tr class="{{ $selected?->id === $p->id ? 'bg-orange-50/60' : '' }}">
                                <td>
                                    <a href="{{ route('syllabus.versions', array_filter(['proposal' => $p->id, 'status' => $status, 'page' => request('page')])) }}" class="block">
                                        <p class="font-bold text-gray-900 text-xs">{{ $p->curriculum?->title }}</p>
                                        <p class="text-[11px] text-gray-500">{{ $p->unit ? 'Unit '.$p->unit->unit_number.': '.$p->unit->title : 'Chung toàn giáo trình' }}</p>
                                        <p class="text-[11px] text-gray-400">{{ $p->proposer?->name }} · {{ $p->created_at->format('d/m/Y H:i') }}</p>
                                    </a>
                                </td>
                                <td class="text-right whitespace-nowrap"><x-ui.badge :color="$p->status_color">{{ $p->status_label }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="2"><x-ui.empty-state icon="inbox" title="Chưa có đề xuất nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$proposals" :options="[]" unit="đề xuất" /></x-slot:footer>
            </x-ui.data-table>
        </div>

        {{-- Chi tiết đề xuất --}}
        <div class="lg:col-span-7 min-w-0">
            @if (! $selected)
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm">
                    <x-ui.empty-state icon="description" title="Chọn một đề xuất để xem chi tiết" />
                </div>
            @else
                <div class="space-y-6">
                    <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-2 mb-5 pb-3 border-b border-gray-100">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary">info</span>
                                <h2 class="text-sm font-bold text-gray-900">Chi tiết đề xuất #{{ $selected->id }}</h2>
                            </div>
                            <x-ui.badge :color="$selected->status_color">{{ $selected->status_label }}</x-ui.badge>
                        </div>

                        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                            <div>
                                <dt class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Giáo trình</dt>
                                <dd class="font-bold text-gray-900 mt-0.5">{{ $selected->curriculum?->title }} ({{ $selected->curriculum?->version }})</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Buổi học / Unit cần sửa</dt>
                                <dd class="font-bold text-gray-900 mt-0.5">{{ $selected->unit ? 'Unit '.$selected->unit->unit_number.': '.$selected->unit->title : 'Chung toàn giáo trình' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Người đề xuất</dt>
                                <dd class="font-bold text-gray-900 mt-0.5">{{ $selected->proposer?->name }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Loại đề xuất</dt>
                                <dd class="text-gray-800 mt-0.5">{{ $selected->proposal_type ?: '—' }}</dd>
                            </div>
                        </dl>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5">
                            <div>
                                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Nội dung cũ</p>
                                <div class="bg-rose-50/50 border border-rose-200/70 rounded-xl p-3 text-xs text-gray-700 whitespace-pre-line min-h-[60px]">{{ $selected->old_content ?: '—' }}</div>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold text-primary uppercase tracking-wider mb-1">Nội dung mới đề xuất</p>
                                <div class="bg-emerald-50/50 border border-emerald-200/70 rounded-xl p-3 text-xs text-gray-800 font-medium whitespace-pre-line min-h-[60px]">{{ $selected->new_content }}</div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <p class="text-[11px] font-bold text-gray-600 uppercase tracking-wider mb-1">Lý do thay đổi</p>
                            <div class="bg-white border border-gray-200 rounded-xl px-3.5 py-2.5 text-xs text-gray-700 whitespace-pre-line">{{ $selected->reason ?: '—' }}</div>
                        </div>
                        @if ($selected->attachment_path)
                            <a href="{{ route('syllabus.proposals.attachment', $selected->id) }}" class="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline">
                                <span class="material-symbols-outlined text-[16px]">attach_file</span>{{ $selected->attachment_name }}
                            </a>
                        @endif
                    </section>

                    <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-4">
                        <div class="flex items-center gap-2 pb-3 border-b border-gray-100">
                            <span class="material-symbols-outlined text-primary">verified_user</span>
                            <h2 class="text-sm font-bold text-gray-900">Trạng thái phê duyệt</h2>
                        </div>
                        <ol class="relative pl-5 space-y-3 before:absolute before:left-2 before:top-2 before:bottom-2 before:w-0.5 before:bg-gray-200 text-xs">
                            <li class="relative">
                                <span class="absolute -left-[17px] top-1 w-2.5 h-2.5 rounded-full bg-primary-container ring-2 ring-white"></span>
                                <p class="font-bold text-gray-900">Đã tạo đề xuất</p>
                                <p class="text-[10px] text-gray-400">{{ $selected->created_at->format('H:i, d/m/Y') }} — {{ $selected->proposer?->name }}</p>
                            </li>
                            <li class="relative">
                                <span class="absolute -left-[17px] top-1 w-2.5 h-2.5 rounded-full {{ $selected->status === 'pending' ? 'bg-gray-300' : ($selected->status === 'approved' ? 'bg-emerald-500' : 'bg-rose-500') }} ring-2 ring-white"></span>
                                @if ($selected->status === 'pending')
                                    <p class="font-medium text-gray-600">Đang chờ Học thuật xử lý</p>
                                @else
                                    <p class="font-bold text-gray-900">{{ $selected->status_label }}</p>
                                    <p class="text-[10px] text-gray-400">{{ $selected->reviewed_at?->format('H:i, d/m/Y') }} — {{ $selected->reviewer?->name }}</p>
                                    @if ($selected->review_note)
                                        <p class="mt-1 text-gray-700">Phản hồi: {{ $selected->review_note }}</p>
                                    @endif
                                @endif
                            </li>
                        </ol>

                        @if ($canReview && $selected->status === 'pending')
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-gray-100">
                                <form method="POST" action="{{ route('syllabus.proposals.approve', $selected->id) }}" class="space-y-2">
                                    @csrf
                                    <x-ui.textarea name="review_note" label="Ghi chú phê duyệt (tùy chọn)" rows="2" />
                                    <x-ui.button type="submit" icon="check_circle" class="w-full">Phê duyệt</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('syllabus.proposals.reject', $selected->id) }}" class="space-y-2">
                                    @csrf
                                    <x-ui.textarea name="review_note" label="Lý do từ chối" rows="2" required />
                                    <x-ui.button type="submit" variant="danger" icon="close" class="w-full">Từ chối</x-ui.button>
                                </form>
                            </div>
                        @endif
                    </section>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
