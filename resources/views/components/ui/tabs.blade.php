{{--
    <x-ui.tabs> + <x-ui.tab> — thanh tab điều hướng, tab active gạch chân màu cam.
    <x-ui.tab> props: href, active (bool), icon (tuỳ chọn), count (tuỳ chọn, số đếm nhỏ)
    Ví dụ:
      <x-ui.tabs>
          <x-ui.tab :href="route('crm.pipeline')" :active="request()->routeIs('crm.pipeline')">Theo giai đoạn</x-ui.tab>
          <x-ui.tab :href="route('crm.lost-deals')" :active="request()->routeIs('crm.lost-deals')" :count="$lostCount">Khách không chốt</x-ui.tab>
      </x-ui.tabs>
--}}
<nav {{ $attributes->merge(['class' => 'no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest']) }}>
    {{ $slot }}
</nav>
