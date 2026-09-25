{{-- Legacy Breeze button — cùng style với <x-ui.button variant="danger">. Ưu tiên dùng <x-ui.button> cho màn mới. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex shrink-0 items-center justify-center gap-xs whitespace-nowrap rounded-lg bg-error px-md py-sm font-body-medium text-body-medium text-white shadow-sm transition-colors hover:bg-on-error-container focus:outline-none focus-visible:ring-2 focus-visible:ring-error/40 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
