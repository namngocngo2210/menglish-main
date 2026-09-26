<x-guest-layout>
    <div class="mb-4 text-sm text-on-surface-variant">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </div>

    {{-- Session Status --}}
    @if (session('status'))
        <x-ui.alert type="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        {{-- Email Address --}}
        <div>
            <x-ui.input id="email" type="email" name="email" :label="__('Email')" required autofocus />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-ui.button type="submit">
                {{ __('Email Password Reset Link') }}
            </x-ui.button>
        </div>
    </form>
</x-guest-layout>
