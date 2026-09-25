@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-200 bg-white text-gray-900 focus:border-primary-container focus:ring-primary-container rounded-xl text-sm shadow-xs']) }}>
