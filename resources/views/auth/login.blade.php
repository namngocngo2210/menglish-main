<x-guest-layout>
    <div class="mb-lg">
        <h1 class="font-h2 text-h2 text-on-surface">Đăng nhập</h1>
        <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Dùng email hoặc mã học viên do trung tâm cấp.</p>
    </div>

    {{-- Session Status --}}
    @if (session('status'))
        <x-ui.alert type="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Email Address --}}
        <div>
            <x-ui.input id="email" type="text" name="email" label="Email hoặc mã học viên" required autofocus autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" />
        </div>

        {{-- Password --}}
        <div class="mt-4">
            <x-ui.input id="password" type="password" name="password" :label="__('Password')" required autocomplete="current-password" />
        </div>

        {{-- Remember Me --}}
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-outline-variant text-primary-container shadow-sm focus:ring-primary-container/40" name="remember">
                <span class="ms-2 font-body-small text-body-small text-on-surface-variant">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="mt-lg flex flex-wrap items-center justify-end gap-md">
            @if (Route::has('password.request'))
                <a class="rounded-md font-body-small text-body-small text-on-surface-variant underline hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-ui.button type="submit">
                {{ __('Log in') }}
            </x-ui.button>
        </div>
    </form>
</x-guest-layout>
