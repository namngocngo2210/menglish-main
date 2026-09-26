{{--
    Chi tiết 1 mục chờ duyệt (modal từ "Việc cần duyệt"; mở thẳng URL → trang đầy đủ).
    Duyệt / Từ chối gửi tới approvals.bulk với 1 mục (single=1) → 204 + toast + approvals-changed.
    Biến: source (ApprovableSource), item (ApprovalItem), canApprove, canReject, asModal
--}}
@php
    $bulkUrl = route('approvals.bulk');
@endphp
@if ($asModal)
    <x-ui.modal-frame :title="$item->title" :description="$source->label().' · '.$source->group()" cancel="Đóng" x-data="{ rejecting: false }">
        @include('approvals._detail')
        <x-slot:footer>
            <x-ui.button variant="secondary" icon="open_in_new" :href="$item->url" hx-boost="false" class="mr-auto">Mở màn gốc</x-ui.button>
            @if ($canReject)
                <x-ui.button variant="danger-text" icon="block" x-show="!rejecting" @click="rejecting = true; $nextTick(() => document.getElementById('approval-reject-reason')?.focus())">Từ chối</x-ui.button>
                <x-ui.button variant="danger" type="submit" form="approval-reject-form" icon="block" x-show="rejecting" x-cloak>Xác nhận từ chối</x-ui.button>
            @endif
            @if ($canApprove)
                <x-ui.button type="submit" form="approval-approve-form" icon="check" x-show="!rejecting">Duyệt</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout :title="$item->title">
        <x-ui.page-header :title="$item->title" :description="$source->label().' · '.$source->group()">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="arrow_back" :href="route('approvals.index')">Việc cần duyệt</x-ui.button>
                <x-ui.button icon="open_in_new" :href="$item->url">Mở màn gốc</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
        <div class="max-w-3xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm" x-data="{ rejecting: false }">
            @include('approvals._detail')
            @if ($canApprove || $canReject)
                <div class="mt-lg flex flex-wrap justify-end gap-sm border-t border-surface-container pt-md">
                    @if ($canReject)
                        <x-ui.button variant="danger-text" icon="block" x-show="!rejecting" @click="rejecting = true">Từ chối</x-ui.button>
                        <x-ui.button variant="danger" type="submit" form="approval-reject-form" icon="block" x-show="rejecting" x-cloak>Xác nhận từ chối</x-ui.button>
                    @endif
                    @if ($canApprove)
                        <x-ui.button type="submit" form="approval-approve-form" icon="check" x-show="!rejecting">Duyệt</x-ui.button>
                    @endif
                </div>
            @endif
        </div>
    </x-app-layout>
@endif
