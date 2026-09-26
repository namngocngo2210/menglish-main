<x-app-layout>
    <x-ui.page-header title="Đề xuất sửa giáo trình" :back="route('syllabus.documents')">
        <x-slot:breadcrumbs>
            <span>Quản lý giáo trình</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="font-semibold text-on-surface">Chi tiết đề xuất</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Gửi đề xuất</x-ui.button>
            <x-ui.button icon="rule" :href="route('syllabus.adjustment-requests')">Duyệt tiến độ</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    @php($canReview = auth()->user()->can('syllabus.approve_adjustment'))

    {{-- Mockup 01_Web_Admin/05: Thông tin chung + Nội dung thay đổi chi tiết | Trạng thái phê duyệt (phản hồi, lịch sử, Phê duyệt / Từ chối). --}}
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        {{-- Danh sách đề xuất --}}
        <div class="xl:col-span-3 min-w-0">
            <x-ui.data-table>
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Đề xuất</h2>
                        <x-ui.badge color="warning">{{ $pendingCount }} chờ duyệt</x-ui.badge>
                    </div>
                    <form method="GET">
                        <x-ui.select name="status" placeholder="Tất cả trạng thái" :options="\App\Models\SyllabusChangeProposal::STATUS_LABELS" onchange="this.form.submit()" />
                    </form>
                </x-slot:header>
                <table>
                    <tbody>
                        @forelse ($proposals as $p)
                            <tr class="{{ $selected?->id === $p->id ? 'bg-primary-fixed/30' : '' }}">
                                <td>
                                    <a href="{{ route('syllabus.versions', array_filter(['proposal' => $p->id, 'status' => $status, 'page' => request('page')])) }}" class="block">
                                        <p class="font-body-medium text-body-small font-semibold text-on-surface">{{ $p->curriculum?->title }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ $p->target_label }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ $p->proposer?->name }} · {{ $p->created_at->format('d/m/Y H:i') }}</p>
                                        <x-ui.badge :color="$p->status_color" class="mt-1">{{ $p->status_label }}</x-ui.badge>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td><x-ui.empty-state icon="inbox" title="Chưa có đề xuất nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$proposals" :options="[]" unit="đề xuất" /></x-slot:footer>
            </x-ui.data-table>
        </div>

        @if (! $selected)
            <div class="xl:col-span-9 bg-surface-container-lowest border border-outline-variant rounded-xl shadow-sm">
                <x-ui.empty-state icon="description" title="Chọn một đề xuất để xem chi tiết" />
            </div>
        @else
            {{-- Cột trái: thông tin + nội dung thay đổi --}}
            <div class="xl:col-span-6 min-w-0 space-y-6">
                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm">
                    <div class="flex items-center gap-sm mb-lg">
                        <span class="material-symbols-outlined text-primary">info</span>
                        <h2 class="font-h3 text-h3 text-on-surface">Thông tin chung</h2>
                        <span class="ml-auto font-caption text-caption text-on-surface-variant">#{{ $selected->id }}</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-md">
                        <x-ui.select label="Giáo trình" id="version_curriculum" disabled>
                            <option selected>{{ $selected->curriculum?->title }} ({{ $selected->curriculum?->version }})</option>
                        </x-ui.select>
                        <x-ui.select label="Buổi học/Unit cần sửa" id="version_target" disabled>
                            <option selected>{{ $selected->target_label }}</option>
                        </x-ui.select>
                        <div class="md:col-span-2">
                            <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Người đề xuất</p>
                            <div class="flex items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                                <x-ui.avatar :name="$selected->proposer?->name" />
                                <div>
                                    <p class="font-body-medium text-body-medium text-on-surface">{{ $selected->proposer?->name ?? '—' }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $selected->proposer?->roles->map(fn ($r) => \App\Helpers\AclHelper::roleLabel($r->name))->implode(', ') ?: '—' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm">
                    <div class="flex items-center gap-sm mb-lg">
                        <span class="material-symbols-outlined text-primary">edit_document</span>
                        <h2 class="font-h3 text-h3 text-on-surface">Nội dung thay đổi chi tiết</h2>
                        @if ($selected->proposal_type)<x-ui.badge class="ml-auto">{{ $selected->proposal_type }}</x-ui.badge>@endif
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-md">
                        <div>
                            <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Nội dung cũ</p>
                            <div class="min-h-[72px] whitespace-pre-line rounded-lg border border-error/20 bg-error/5 p-md font-body-small text-body-small text-on-surface">{{ $selected->old_content ?: '—' }}</div>
                        </div>
                        <div>
                            <p class="mb-xs font-label text-label text-primary uppercase">Nội dung mới đề xuất</p>
                            <div class="min-h-[72px] whitespace-pre-line rounded-lg border border-tertiary/20 bg-tertiary/5 p-md font-body-small text-body-small font-medium text-on-surface">{{ $selected->new_content }}</div>
                        </div>
                    </div>
                    <div class="mt-md">
                        <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Lý do thay đổi</p>
                        <div class="whitespace-pre-line rounded-lg border border-outline-variant p-md font-body-small text-body-small text-on-surface">{{ $selected->reason ?: '—' }}</div>
                    </div>
                    @if ($selected->attachment_path)
                        <a href="{{ route('syllabus.proposals.attachment', $selected->id) }}" class="mt-md inline-flex items-center gap-1.5 font-body-small text-body-small font-semibold text-primary hover:underline">
                            <span class="material-symbols-outlined text-[16px]">attach_file</span>{{ $selected->attachment_name }}
                        </a>
                    @endif
                </section>
            </div>

            {{-- Cột phải: trạng thái & phê duyệt --}}
            <div class="xl:col-span-3 min-w-0">
                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl shadow-sm flex flex-col">
                    <div class="p-lg space-y-lg">
                        <div class="flex items-center gap-sm">
                            <span class="material-symbols-outlined text-primary">verified_user</span>
                            <h2 class="font-h3 text-h3 text-on-surface">Trạng thái phê duyệt</h2>
                        </div>
                        <div>
                            <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Trạng thái hiện tại</p>
                            <x-ui.badge :color="$selected->status_color">{{ $selected->status_label }}</x-ui.badge>
                        </div>
                        <div>
                            <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Người phê duyệt</p>
                            <div class="flex items-center gap-sm">
                                @if ($selected->reviewer)
                                    <x-ui.avatar :name="$selected->reviewer->name" size="sm" />
                                    <div>
                                        <p class="font-body-medium text-body-medium text-on-surface">{{ $selected->reviewer->name }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">Ban Học thuật</p>
                                    </div>
                                @else
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-surface-container-high text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">person</span></span>
                                    <div>
                                        <p class="font-body-medium text-body-medium text-on-surface">Chưa phân công</p>
                                        <p class="font-caption text-caption text-on-surface-variant">Ban Học thuật</p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($canReview && $selected->status === 'pending')
                            <form id="proposal-review-form" method="POST" action="{{ route('syllabus.proposals.approve', $selected->id) }}">
                                @csrf
                                <x-ui.textarea name="review_note" label="Phản hồi từ người duyệt" rows="4" placeholder="Nhập lý do phê duyệt hoặc từ chối đề xuất này..." hint="Bắt buộc khi từ chối." />
                            </form>
                        @elseif ($selected->review_note)
                            <div>
                                <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Phản hồi từ người duyệt</p>
                                <div class="whitespace-pre-line rounded-lg border border-outline-variant bg-surface-container-low p-md font-body-small text-body-small text-on-surface">{{ $selected->review_note }}</div>
                            </div>
                        @endif

                        <div>
                            <p class="mb-sm font-label text-label text-on-surface-variant uppercase">Lịch sử xử lý</p>
                            <ol class="relative space-y-md border-l-2 border-outline-variant pl-md">
                                <li class="relative">
                                    <span class="absolute -left-[23px] top-1 h-3 w-3 rounded-full bg-primary-container ring-2 ring-white"></span>
                                    <p class="font-body-medium text-body-small font-semibold text-on-surface">Đã tạo đề xuất</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $selected->created_at->format('H:i, d/m/Y') }} - {{ $selected->proposer?->name }}</p>
                                </li>
                                <li class="relative">
                                    <span class="absolute -left-[23px] top-1 h-3 w-3 rounded-full ring-2 ring-white {{ $selected->status === 'pending' ? 'bg-outline-variant' : ($selected->status === 'approved' ? 'bg-tertiary' : 'bg-error') }}"></span>
                                    @if ($selected->status === 'pending')
                                        <p class="font-body-medium text-body-small text-on-surface-variant">Đang chờ xử lý</p>
                                        <p class="font-caption text-caption text-on-surface-variant">Hệ thống đang chờ phê duyệt...</p>
                                    @else
                                        <p class="font-body-medium text-body-small font-semibold text-on-surface">{{ $selected->status_label }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ $selected->reviewed_at?->format('H:i, d/m/Y') }} - {{ $selected->reviewer?->name }}</p>
                                    @endif
                                </li>
                            </ol>
                        </div>

                        @if ($selected->status === 'approved')
                            {{-- Chưa tự áp nội dung vào buổi học (chưa có quyết định BA) — Học thuật cập nhật tay ở màn Soạn syllabus. --}}
                            <x-ui.alert type="info">Nội dung được duyệt chưa tự áp vào giáo trình — Học thuật cập nhật buổi học ở màn Soạn syllabus.</x-ui.alert>
                            @if ($selected->lesson && auth()->user()->can('syllabus.manage'))
                                <x-ui.button variant="secondary" icon="edit" class="w-full" :href="route('syllabus.builder', ['curriculum' => $selected->curriculum_id, 'edit_lesson' => $selected->lesson_id]).'#editor'">Mở buổi để cập nhật</x-ui.button>
                            @endif
                        @endif
                    </div>

                    @if ($canReview && $selected->status === 'pending')
                        <div class="mt-auto grid grid-cols-2 gap-sm border-t border-outline-variant p-lg">
                            <x-ui.button type="submit" form="proposal-review-form" icon="check_circle">Phê duyệt</x-ui.button>
                            <x-ui.button type="submit" form="proposal-review-form" variant="danger" icon="close" formaction="{{ route('syllabus.proposals.reject', $selected->id) }}">Từ chối</x-ui.button>
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-app-layout>
