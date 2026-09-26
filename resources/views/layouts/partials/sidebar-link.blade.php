{{-- Mục cấp 1 của sidebar (workspace / Tổng quan / Cài đặt). Biến: url, label, icon, active, id, badge (tuỳ chọn, số chờ xử lý) --}}
@php $badge = ($badge ?? 0) > 0 ? ($badge > 99 ? '99+' : (string) $badge) : null; @endphp
<a href="{{ $url }}"
   @if ($active) aria-current="page" @endif
   title="{{ $label }}{{ $badge ? ' ('.$badge.')' : '' }}"
   data-menu-item="{{ $id }}"
   class="relative flex items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium transition-colors duration-150 active:scale-95 md:justify-center md:px-0 desktop:justify-start desktop:px-md {{ $active ? 'bg-primary-container text-white shadow-lg shadow-primary-container/20' : 'hover:bg-white/10 hover:text-white' }}">
    <span class="material-symbols-outlined shrink-0 {{ $active ? 'fill' : '' }}" aria-hidden="true">{{ $icon }}</span>
    <span class="truncate md:hidden desktop:inline">{{ $label }}</span>
    @if ($badge)
        {{-- Sidebar đầy đủ: số bên phải; thu gọn (icon): chấm số ở góc icon --}}
        <span class="ml-auto min-w-[20px] rounded-full bg-error px-1.5 text-center font-code text-[11px] font-semibold leading-5 text-white md:absolute md:right-2 md:top-1 md:ml-0 md:min-w-[16px] md:px-1 md:leading-4 desktop:static desktop:ml-auto desktop:min-w-[20px] desktop:px-1.5 desktop:leading-5"
              data-approval-badge>{{ $badge }}<span class="sr-only"> việc chờ duyệt</span></span>
    @endif
</a>
