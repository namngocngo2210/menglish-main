@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-200 bg-white text-gray-900 focus:border-[#F5691A] focus:ring-[#F5691A] rounded-xl text-sm shadow-xs']) }}>
