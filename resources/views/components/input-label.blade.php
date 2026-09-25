@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-body-small text-body-small text-on-surface-variant']) }}>
    {{ $value ?? $slot }}
</label>
