{{--
    <x-ui.tabs> + <x-ui.tab> — thanh tab điều hướng, tab active gạch chân màu cam.
    <x-ui.tab> props: href, active (bool), icon (tuỳ chọn), count (tuỳ chọn, số đếm nhỏ)
    Ví dụ:
      <x-ui.tabs>
          <x-ui.tab :href="route('crm.pipeline')" :active="request()->routeIs('crm.pipeline')">Theo giai đoạn</x-ui.tab>
          <x-ui.tab :href="route('crm.lost-deals')" :active="request()->routeIs('crm.lost-deals')" :count="$lostCount">Khách không chốt</x-ui.tab>
      </x-ui.tabs>
--}}
{{-- Màn hẹp: tab tràn thì cuộn ngang; mép phải mờ dần khi còn tab khuất, tab đang mở tự cuộn vào tầm nhìn. --}}
<nav x-data="{ more: false, check() { this.more = $el.scrollLeft + $el.clientWidth < $el.scrollWidth - 4 } }"
     x-init="$el.querySelector('[aria-current=page]')?.scrollIntoView({ block: 'nearest', inline: 'center' }); $nextTick(() => check())"
     x-on:scroll.passive="check()" x-on:resize.window.debounce="check()"
     x-bind:class="more && '[mask-image:linear-gradient(to_right,#000_80%,transparent)]'"
     {{ $attributes->merge(['class' => 'no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest']) }}>
    {{ $slot }}
</nav>
