<x-app-layout>
    <x-ui.page-header title="Đề xuất sửa giáo trình" :back="route('syllabus.documents')">
        <x-slot:breadcrumbs>
            <span>Quản lý giáo trình</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="font-semibold text-on-surface">Đề xuất sửa giáo trình</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Gửi đề xuất</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    @php($canReview = auth()->user()->can('syllabus.approve_adjustment'))
    @php($listQuery = array_filter(['status' => $status, 'page' => request('page')]))

    {{-- Danh sách đề xuất — bấm dòng → chi tiết trong modal (?proposal=; đóng modal thì bỏ query). --}}
    <x-ui.data-table min-width="760px">
        <x-slot:header>
            <div class="flex items-center gap-2">
                <h2 class="font-h3 text-h3 text-on-surface">Đề xuất</h2>
                <x-ui.badge color="warning">{{ $pendingCount }} chờ duyệt</x-ui.badge>
            </div>
            <form method="GET">
                <x-ui.select name="status" placeholder="Tất cả trạng thái" :options="\App\Models\SyllabusChangeProposal::STATUS_LABELS" :value="$status" onchange="this.form.submit()" aria-label="Lọc trạng thái" />
            </form>
        </x-slot:header>
        <table>
            <thead>
                <tr>
                    <th>Giáo trình</th>
                    <th>Buổi học / Unit</th>
                    <th>Người đề xuất</th>
                    <th>Gửi lúc</th>
                    <th>Trạng thái</th>
                    <th class="text-right"><span class="sr-only">Thao tác</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($proposals as $p)
                    @php($detailUrl = route('syllabus.versions', $listQuery + ['proposal' => $p->id]))
                    <tr data-href="{{ $detailUrl }}" @class(['cursor-pointer', 'bg-primary-fixed/30' => $selected?->id === $p->id])>
                        <td><a href="{{ $detailUrl }}" class="font-semibold text-on-surface hover:text-primary">{{ $p->curriculum?->title }}</a></td>
                        <td class="text-on-surface-variant">{{ $p->target_label }}</td>
                        <td>{{ $p->proposer?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap font-code text-body-small">{{ $p->created_at->format('d/m/Y H:i') }}</td>
                        <td class="whitespace-nowrap"><x-ui.badge :color="$p->status_color">{{ $p->status_label }}</x-ui.badge></td>
                        <td class="text-right"><x-ui.button variant="secondary" size="sm" icon="visibility" :href="$detailUrl">Xem</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="inbox" title="Chưa có đề xuất nào" description="Giáo viên gửi đề xuất từ Xin duyệt › Đề xuất sửa giáo trình." /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$proposals" :options="[]" unit="đề xuất" /></x-slot:footer>
    </x-ui.data-table>

    @if ($selected)
        {{-- Mockup 01_Web_Admin/05: Thông tin chung + Nội dung thay đổi | Trạng thái phê duyệt (phản hồi, lịch sử, Phê duyệt / Từ chối).
             Mở sẵn khi URL có ?proposal=; đóng → bỏ proposal khỏi thanh địa chỉ. --}}
        <x-ui.modal name="proposal-detail" :title="'Chi tiết đề xuất #'.$selected->id.' · '.$selected->curriculum?->title" max-width="4xl" show
                    :dismiss-url="route('syllabus.versions', $listQuery)">
            <div class="grid grid-cols-1 gap-lg lg:grid-cols-3">
                {{-- Thông tin + nội dung thay đổi --}}
                <div class="min-w-0 space-y-lg lg:col-span-2">
                    <section class="space-y-md">
                        <div class="flex items-center gap-sm">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">info</span>
                            <h3 class="font-h3 text-h3 text-on-surface">Thông tin chung</h3>
                        </div>
                        <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md md:grid-cols-2">
                            <div>
                                <dt class="font-label text-label uppercase text-on-surface-variant">Giáo trình</dt>
                                <dd class="mt-xs text-on-surface">{{ $selected->curriculum?->title }} ({{ $selected->curriculum?->version }})</dd>
                            </div>
                            <div>
                                <dt class="font-label text-label uppercase text-on-surface-variant">Buổi học/Unit cần sửa</dt>
                                <dd class="mt-xs text-on-surface">{{ $selected->target_label }}</dd>
                            </div>
                            <div class="md:col-span-2">
                                <dt class="font-label text-label uppercase text-on-surface-variant">Người đề xuất</dt>
                                <dd class="mt-xs flex items-center gap-sm">
                                    <x-ui.avatar :name="$selected->proposer?->name" size="sm" />
                                    <span>
                                        <span class="block font-body-medium text-body-medium text-on-surface">{{ $selected->proposer?->name ?? '—' }}</span>
                                        <span class="block font-caption text-caption text-on-surface-variant">{{ $selected->proposer?->roles->map(fn ($r) => \App\Helpers\AclHelper::roleLabel($r->name))->implode(', ') ?: '—' }}</span>
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="space-y-md">
                        <div class="flex items-center gap-sm">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">edit_document</span>
                            <h3 class="font-h3 text-h3 text-on-surface">Nội dung thay đổi chi tiết</h3>
                            @if ($selected->proposal_type)<x-ui.badge class="ml-auto">{{ $selected->proposal_type }}</x-ui.badge>@endif
                        </div>
                        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                            <div>
                                <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Nội dung cũ</p>
                                <div class="min-h-[72px] whitespace-pre-line rounded-lg border border-error/20 bg-error/5 p-md font-body-small text-body-small text-on-surface">{{ $selected->old_content ?: '—' }}</div>
                            </div>
                            <div>
                                <p class="mb-xs font-label text-label uppercase text-primary">Nội dung mới đề xuất</p>
                                <div class="min-h-[72px] whitespace-pre-line rounded-lg border border-tertiary/20 bg-tertiary/5 p-md font-body-small text-body-small font-medium text-on-surface">{{ $selected->new_content }}</div>
                            </div>
                        </div>
                        <div>
                            <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Lý do thay đổi</p>
                            <div class="whitespace-pre-line rounded-lg border border-outline-variant p-md font-body-small text-body-small text-on-surface">{{ $selected->reason ?: '—' }}</div>
                        </div>
                        @if ($selected->attachment_path)
                            <a href="{{ route('syllabus.proposals.attachment', $selected->id) }}" class="inline-flex items-center gap-1.5 font-body-small text-body-small font-semibold text-primary hover:underline">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">attach_file</span>{{ $selected->attachment_name }}
                            </a>
                        @endif
                    </section>
                </div>

                {{-- Trạng thái & phê duyệt --}}
                <section class="min-w-0 space-y-lg rounded-lg border border-outline-variant bg-surface-container-low p-md">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">verified_user</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Trạng thái phê duyệt</h3>
                    </div>
                    <div>
                        <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Trạng thái hiện tại</p>
                        <x-ui.badge :color="$selected->status_color">{{ $selected->status_label }}</x-ui.badge>
                    </div>
                    <div>
                        <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Người phê duyệt</p>
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
                            <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Phản hồi từ người duyệt</p>
                            <div class="whitespace-pre-line rounded-lg border border-outline-variant bg-surface-container-lowest p-md font-body-small text-body-small text-on-surface">{{ $selected->review_note }}</div>
                        </div>
                    @endif

                    <div>
                        <p class="mb-sm font-label text-label uppercase text-on-surface-variant">Lịch sử xử lý</p>
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
                </section>
            </div>

            @if ($canReview && $selected->status === 'pending')
                <x-slot:footer>
                    <x-ui.button type="submit" form="proposal-review-form" variant="danger-text" icon="close" formaction="{{ route('syllabus.proposals.reject', $selected->id) }}">Từ chối</x-ui.button>
                    <x-ui.button type="submit" form="proposal-review-form" icon="check_circle">Phê duyệt</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</x-app-layout>
