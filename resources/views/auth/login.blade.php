<x-guest-layout>
    {{-- Session Status --}}
    @if (session('status'))
        <x-ui.alert type="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Email Address --}}
        <div>
            <x-ui.input id="email" type="email" name="email" :label="__('Email')" required autofocus autocomplete="username" />
        </div>

        {{-- Password --}}
        <div class="mt-4">
            <x-ui.input id="password" type="password" name="password" :label="__('Password')" required autocomplete="current-password" />
        </div>

        {{-- Remember Me --}}
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-outline-variant text-primary-container shadow-sm focus:ring-primary-container/40" name="remember">
                <span class="ms-2 text-sm text-on-surface-variant">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-on-surface-variant hover:text-on-surface rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-container/40" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-ui.button type="submit" class="ms-3">
                {{ __('Log in') }}
            </x-ui.button>
        </div>
    </form>
</x-guest-layout>
