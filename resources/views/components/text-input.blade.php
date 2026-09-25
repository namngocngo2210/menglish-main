@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-lg border-outline-variant bg-surface-container-lowest font-body-base text-body-base text-on-surface shadow-xs focus:border-primary-container focus:ring-primary-container/20 disabled:bg-surface-container-low']) }}>
