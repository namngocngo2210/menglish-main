@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'space-y-1 font-caption text-caption text-error']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
