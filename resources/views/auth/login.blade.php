{{-- Markup and styling are intentionally minimal: presentation belongs to coder-frontend. --}}
<x-layouts.app title="Sign in">
    <h1>Sign in</h1>

    @if ($errors->any())
        <ul role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <label for="remember">
            <input id="remember" name="remember" type="checkbox"> Remember me
        </label>

        <button type="submit">Sign in</button>
    </form>

    <a href="{{ route('password.request') }}">Forgot your password?</a>
</x-layouts.app>
