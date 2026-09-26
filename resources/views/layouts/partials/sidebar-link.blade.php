{{-- Mục cấp 1 của sidebar (workspace / Tổng quan / Cài đặt). Biến: url, label, icon, active, id --}}
<a href="{{ $url }}"
   @if ($active) aria-current="page" @endif
   title="{{ $label }}"
   data-menu-item="{{ $id }}"
   class="flex items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium transition-colors duration-150 active:scale-95 md:justify-center md:px-0 desktop:justify-start desktop:px-md {{ $active ? 'bg-primary-container text-white shadow-lg shadow-primary-container/20' : 'hover:bg-white/10 hover:text-white' }}">
    <span class="material-symbols-outlined shrink-0 {{ $active ? 'fill' : '' }}" aria-hidden="true">{{ $icon }}</span>
    <span class="truncate md:hidden desktop:inline">{{ $label }}</span>
</a>
