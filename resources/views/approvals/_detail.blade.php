{{-- Thân chi tiết mục chờ duyệt + form Duyệt / Từ chối (dùng chung modal ↔ trang). Biến: source, item, canApprove, canReject --}}
<div class="space-y-md">
    @if ($item->flag)
        <x-ui.badge color="error">{{ $item->flag }}</x-ui.badge>
    @endif
    <dl class="grid grid-cols-1 gap-x-lg gap-y-sm sm:grid-cols-3">
        @if ($item->amount !== null)
            <dt class="font-body-small text-body-small text-on-surface-variant">Số tiền</dt>
            <dd class="sm:col-span-2"><x-ui.money :value="$item->amount" align="left" /></dd>
        @endif
        @foreach ($item->meta as $label => $value)
            <dt class="font-body-small text-body-small text-on-surface-variant">{{ $label }}</dt>
            <dd class="whitespace-pre-line break-words text-on-surface sm:col-span-2">{{ $value }}</dd>
        @endforeach
        @if ($item->createdAt)
            <dt class="font-body-small text-body-small text-on-surface-variant">Gửi lúc</dt>
            <dd class="text-on-surface sm:col-span-2">{{ $item->createdAt->format('H:i d/m/Y') }} ({{ $item->createdAt->diffForHumans() }})</dd>
        @endif
    </dl>

    @if ($item->modalUrl)
        <a href="{{ $item->modalUrl }}" class="inline-flex items-center gap-xs font-body-medium text-body-small text-primary hover:underline">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">visibility</span> Xem chi tiết đầy đủ
        </a>
    @endif

    @unless ($canApprove || $canReject)
        <x-ui.alert type="info">Mục này cần nhập thêm thông tin khi duyệt — vui lòng xử lý ở màn gốc.</x-ui.alert>
    @endunless

    @if ($canApprove)
        <form id="approval-approve-form" method="POST" action="{{ route('approvals.bulk') }}">
            @csrf
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="single" value="1">
            <input type="hidden" name="items[]" value="{{ $item->ref() }}">
        </form>
    @endif
    @if ($canReject)
        <form id="approval-reject-form" method="POST" action="{{ route('approvals.bulk') }}" x-show="rejecting" x-cloak>
            @csrf
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="single" value="1">
            <input type="hidden" name="items[]" value="{{ $item->ref() }}">
            <x-ui.textarea name="reason" id="approval-reject-reason" label="Lý do từ chối" required rows="3" maxlength="1000" />
        </form>
    @endif
</div>
