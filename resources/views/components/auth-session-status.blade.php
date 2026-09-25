@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-body-medium text-body-medium text-tertiary']) }}>
        {{ $status }}
    </div>
@endif
