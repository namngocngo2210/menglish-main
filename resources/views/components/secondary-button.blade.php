{{-- Legacy Breeze button — cùng style với <x-ui.button variant="secondary">. Ưu tiên dùng <x-ui.button> cho màn mới. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex shrink-0 items-center justify-center gap-xs whitespace-nowrap rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-medium text-body-medium text-on-surface shadow-sm transition-colors hover:bg-surface-container-low focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
