@extends('layouts.trader')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>

    <form method="post" action="{{ route('login') }}">
        @csrf

        <div class="card">
            <label for="email" style="display:block;font-size:14px;color:var(--ink-soft);margin-bottom:6px">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   autocomplete="username" inputmode="email"
                   style="width:100%">

            @error('email')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div class="card">
            <label for="password" style="display:block;font-size:14px;color:var(--ink-soft);margin-bottom:6px">Password</label>
            <input id="password" name="password" type="password" required
                   autocomplete="current-password" style="width:100%">

            @error('password')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <label style="display:flex;align-items:center;gap:10px;min-height:44px;margin-top:12px;font-size:15px">
            <input type="checkbox" name="remember" value="1" style="min-height:auto;width:20px;height:20px">
            Keep me signed in
        </label>

        <div class="actions">
            <button type="submit">Sign in</button>
        </div>
    </form>
@endsection