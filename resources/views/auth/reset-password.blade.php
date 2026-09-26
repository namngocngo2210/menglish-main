<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        {{-- Password Reset Token --}}
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        {{-- Email Address --}}
        <div>
            <x-ui.input id="email" type="email" name="email" :label="__('Email')" :value="$request->email" required autofocus autocomplete="username" />
        </div>

        {{-- Password --}}
        <div class="mt-4">
            <x-ui.input id="password" type="password" name="password" :label="__('Password')" required autocomplete="new-password" />
        </div>

        {{-- Confirm Password --}}
        <div class="mt-4">
            <x-ui.input id="password_confirmation" type="password" name="password_confirmation" :label="__('Confirm Password')" required autocomplete="new-password" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-ui.button type="submit">
                {{ __('Reset Password') }}
            </x-ui.button>
        </div>
    </form>
</x-guest-layout>
