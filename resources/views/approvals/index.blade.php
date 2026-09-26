{{--
    "Việc cần duyệt" (IX-5, docs/frontend-interaction-redesign.md §4.3): gom mục chờ duyệt từ các module
    (App\Support\Approvals\ApprovalInboxService). Chip lọc theo nhóm, mỗi nguồn tối đa ApprovalController::PER_SOURCE mục
    (còn lại: "Xem tất cả" sang màn gốc). Bấm dòng → modal chi tiết. Chọn nhiều → Duyệt hàng loạt / Từ chối (lý do).
    Xử lý xong server phát "approvals-changed" → #approval-list tự tải lại (giữ bộ lọc), bỏ chọn.
--}}
<x-app-layout title="Việc cần duyệt">
    <x-ui.page-header title="Việc cần duyệt" description="Mọi yêu cầu đang chờ bạn duyệt, gom từ Học phí, Đào tạo và Công việc. Màn duyệt cũ vẫn dùng được như trước." />

    <div x-data="{
            selected: [],
            can(action) {
                return this.selected.length > 0 && this.selected.every((ref) => this.$root.querySelector(`input[data-ref='${ref}']`)?.dataset[action] === '1');
            },
            toggleAll(refs, on) {
                this.selected = on ? [...new Set([...this.selected, ...refs])] : this.selected.filter((ref) => !refs.includes(ref));
            },
         }"
         x-on:approvals-changed.window="selected = []">

        {{-- Kết quả xử lý hàng loạt (htmx swap vào đây; request thường: lấy từ flash) --}}
        <div id="approval-results" aria-live="polite">
            @include('approvals._results', ['results' => session('approval_results', []), 'error' => $errors->first() ?: null])
        </div>

        <div id="approval-list" hx-get="{{ route('approvals.index', request()->query()) }}" hx-trigger="approvals-changed from:body" hx-select="#approval-list" hx-swap="outerHTML" hx-disinherit="*">
            {{-- Chip lọc theo nhóm --}}
            <nav class="no-scrollbar mb-lg flex items-center gap-sm overflow-x-auto" aria-label="Lọc theo nhóm">
                @php $chip = fn (bool $on) => 'inline-flex shrink-0 items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-small transition-colors '.($on ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface hover:border-primary-container hover:text-primary'); @endphp
                <a href="{{ route('approvals.index') }}" class="{{ $chip($group === null) }}" @if ($group === null) aria-current="page" @endif data-approval-group="all">
                    Tất cả <span class="font-code">{{ $total }}</span>
                </a>
                @foreach ($groups as $slug => $g)
                    <a href="{{ route('approvals.index', ['group' => $slug]) }}" class="{{ $chip($group === $slug) }}" @if ($group === $slug) aria-current="page" @endif data-approval-group="{{ $slug }}">
                        {{ $g['label'] }} <span class="font-code">{{ $g['count'] }}</span>
                    </a>
                @endforeach
            </nav>

            @php $hasAny = collect($sections)->contains(fn ($s) => $s['items']->isNotEmpty()); @endphp
            @if (! $hasAny)
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                    <x-ui.empty-state icon="task_alt" title="Không còn việc chờ duyệt" description="Bạn đã xử lý hết các yêu cầu. Số chờ duyệt được cập nhật mỗi phút." />
                </div>
            @endif

            <div class="space-y-lg">
                @foreach ($sections as $section)
                    @continue($section['items']->isEmpty())
                    @php
                        $source = $section['source'];
                        $bulk = $section['approve'] || $section['reject'];
                        $refs = $section['items']->map->ref()->values()->all();
                    @endphp
                    <section class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm" data-approval-source="{{ $source->key() }}">
                        <header class="flex flex-wrap items-center gap-sm border-b border-surface-container px-md py-sm">
                            @if ($bulk)
                                <input type="checkbox" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container/30"
                                       aria-label="Chọn tất cả {{ $source->label() }}"
                                       :checked="@js($refs).every((ref) => selected.includes(ref))"
                                       @change="toggleAll(@js($refs), $event.target.checked)">
                            @endif
                            <h2 class="font-h3 text-h3 text-on-surface">{{ $source->label() }}</h2>
                            <x-ui.badge color="warning" :dot="false" pill>{{ $section['count'] }}</x-ui.badge>
                            <span class="font-caption text-caption text-on-surface-variant">{{ $source->group() }}</span>
                            @unless ($bulk)
                                <span class="font-caption text-caption text-on-surface-variant">· duyệt tại màn gốc</span>
                            @endunless
                            <a href="{{ $source->indexUrl() }}" class="ml-auto inline-flex items-center gap-xs font-body-medium text-body-small text-primary hover:underline">
                                Xem tất cả <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_forward</span>
                            </a>
                        </header>

                        <ul class="divide-y divide-surface-container">
                            @foreach ($section['items'] as $item)
                                <li class="flex items-center gap-sm px-md py-sm hover:bg-surface-container-low" data-approval-item="{{ $item->ref() }}">
                                    @if ($bulk)
                                        <input type="checkbox" value="{{ $item->ref() }}" x-model="selected" data-ref="{{ $item->ref() }}"
                                               data-approve="{{ $section['approve'] ? '1' : '0' }}" data-reject="{{ $section['reject'] ? '1' : '0' }}"
                                               class="h-4 w-4 shrink-0 rounded border-outline-variant text-primary-container focus:ring-primary-container/30"
                                               aria-label="Chọn {{ $item->title }}">
                                    @endif
                                    <a href="{{ route('approvals.show', [$item->source, $item->id]) }}"
                                       hx-get="{{ route('approvals.show', [$item->source, $item->id]) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" data-modal-size="xl"
                                       class="flex min-w-0 flex-1 items-center gap-md rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-body-medium text-body-medium text-on-surface">{{ $item->title }}</span>
                                            @if ($item->subtitle)
                                                <span class="block truncate font-body-small text-body-small text-on-surface-variant">{{ $item->subtitle }}</span>
                                            @endif
                                        </span>
                                        @if ($item->flag)
                                            <x-ui.badge color="error" class="hidden sm:inline-flex">{{ $item->flag }}</x-ui.badge>
                                        @endif
                                        @if ($item->amount !== null)
                                            <span class="hidden shrink-0 sm:block"><x-ui.money :value="$item->amount" /></span>
                                        @endif
                                        @if ($item->createdAt)
                                            <time datetime="{{ $item->createdAt->toIso8601String() }}" title="{{ $item->createdAt->format('H:i d/m/Y') }}" class="hidden w-24 shrink-0 text-right font-caption text-caption text-on-surface-variant md:block">{{ $item->createdAt->diffForHumans() }}</time>
                                        @endif
                                        <span class="material-symbols-outlined shrink-0 text-[20px] text-on-surface-variant" aria-hidden="true">chevron_right</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>

                        @if ($section['count'] > $section['items']->count())
                            <div class="border-t border-surface-container px-md py-sm text-center">
                                <a href="{{ $source->indexUrl() }}" class="font-body-medium text-body-small text-primary hover:underline">Xem tất cả {{ $section['count'] }} mục ở màn gốc</a>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>

        {{-- Thanh hành động khi đã chọn --}}
        <div x-show="selected.length" x-cloak x-transition.opacity
             class="sticky bottom-md z-20 mt-lg flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm shadow-level-3">
            <span class="font-body-medium text-body-medium text-on-surface">Đã chọn <strong x-text="selected.length"></strong></span>
            <x-ui.button variant="ghost" size="sm" @click="selected = []">Bỏ chọn</x-ui.button>
            <div class="ml-auto flex flex-wrap gap-sm">
                <x-ui.button variant="danger-text" icon="block" x-bind:disabled="!can('reject')" @click="$dispatch('open-modal', 'approval-bulk-reject')"
                             x-bind:title="can('reject') ? '' : 'Có mục phải xử lý ở màn gốc'">Từ chối</x-ui.button>
                <x-ui.button icon="done_all" x-bind:disabled="!can('approve')" @click="$dispatch('open-modal', 'approval-bulk-approve')"
                             x-bind:title="can('approve') ? '' : 'Có mục phải duyệt ở màn gốc'">Duyệt hàng loạt</x-ui.button>
            </div>
        </div>

        {{-- Xác nhận duyệt hàng loạt --}}
        <x-ui.modal name="approval-bulk-approve" title="Duyệt các mục đã chọn?" max-width="md">
            <p>Duyệt <strong x-text="selected.length"></strong> mục. Mỗi mục được xử lý riêng theo đúng quy tắc của màn gốc; mục không hợp lệ sẽ được báo lại, không ảnh hưởng mục khác.</p>
            <form id="approval-bulk-approve-form" method="POST" action="{{ route('approvals.bulk') }}"
                  hx-post="{{ route('approvals.bulk') }}" hx-target="#approval-results" hx-swap="innerHTML">
                @csrf
                <input type="hidden" name="action" value="approve">
                <template x-for="ref in selected" :key="ref"><input type="hidden" name="items[]" :value="ref"></template>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'approval-bulk-approve')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="approval-bulk-approve-form" icon="done_all">Duyệt</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- Từ chối hàng loạt (bắt buộc lý do) --}}
        <x-ui.modal name="approval-bulk-reject" title="Từ chối các mục đã chọn?" max-width="md">
            <form id="approval-bulk-reject-form" method="POST" action="{{ route('approvals.bulk') }}" class="space-y-md"
                  hx-post="{{ route('approvals.bulk') }}" hx-target="#approval-results" hx-swap="innerHTML">
                @csrf
                <input type="hidden" name="action" value="reject">
                <template x-for="ref in selected" :key="ref"><input type="hidden" name="items[]" :value="ref"></template>
                <p>Từ chối <strong x-text="selected.length"></strong> mục. Lý do được gửi kèm cho người đề nghị.</p>
                <x-ui.textarea name="reason" id="approval-bulk-reason" label="Lý do từ chối" required rows="3" maxlength="1000" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'approval-bulk-reject')">Hủy</x-ui.button>
                <x-ui.button variant="danger" type="submit" form="approval-bulk-reject-form" icon="block">Từ chối</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
