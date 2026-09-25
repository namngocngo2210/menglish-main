{{-- Tương thích ngược: <x-pagination :paginator="$rows" /> => dùng <x-ui.pagination>. --}}
@props(['paginator', 'options' => [10, 20, 50, 100, 'all']])

<div class="border-t border-outline-variant bg-surface-container-low">
    <x-ui.pagination :paginator="$paginator" :options="$options" />
</div>
