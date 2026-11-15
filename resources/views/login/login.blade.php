@extends('layout_login')

@section('content')
<div class="login-bg">
    <div class="login-shell">
        <div class="login-topline">
            <div class="login-brand">
                <div class="login-logo">₹</div>
                <div>
                    <strong>Bill IQ</strong>
                    <span>Billing + Accounting</span>
                </div>
            </div>
            <div class="login-cycle">FY 2026-27</div>
        </div>

        <div class="login-panel">
            <section class="login-insight">
                <span class="panel-kicker">Retail Command Center</span>
                <h2>Run billing, stock, GST and accounts from one workspace.</h2>
                <p>Designed for owners, cashiers and accountants who need fast billing, accurate inventory and clean financial posting.</p>

                <div class="login-metric-grid">
                    <div><span>Active Modules</span><strong>8</strong></div>
                    <div><span>GST Workflow</span><strong>Ready</strong></div>
                    <div><span>Stock View</span><strong>Live</strong></div>
                </div>

                <div class="login-flow">
                    <div><span>01</span><strong>Bill</strong><small>POS and invoices</small></div>
                    <div><span>02</span><strong>Post</strong><small>Stock and GST</small></div>
                    <div><span>03</span><strong>Close</strong><small>Ledgers and reports</small></div>
                </div>
            </section>

            <section class="login-card" id="login">
                <form action="{{ url('/login') }}" class="form-horizontal fix-w login-form-wrap" autocomplete="off" method="POST">
                    {{ csrf_field() }}

                    <div class="form-kicker">Secure Workspace</div>
                    <h1>Login to Bill IQ</h1>
                    <p class="form-subtitle">A unified business control room for owners, cashiers and accountants.</p>

                    @if(Session::has('failure'))
                        <div class="alert alert-danger">
                            {!! Session::get('failure') !!}
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="username" class="form-control" value="{{ old('username') }}" placeholder="Enter your email" required autofocus>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-field">
                            <input type="password" name="password" id="login-password" class="form-control" placeholder="Enter password" required autocomplete="current-password">
                            <button type="button" class="password-toggle" id="login-password-toggle" aria-label="Show password" aria-pressed="false" title="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="login-options">
                        <label class="remember-row">
                            <input type="checkbox" name="remember" value="1">
                            <span>Remember me</span>
                        </label>
                        <span>Main Branch</span>
                    </div>

                    <div class="text-center mt-4">
                        <button type="submit" class="full">Open Dashboard</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
<script>
    (function () {
        var input = document.getElementById('login-password');
        var btn = document.getElementById('login-password-toggle');
        if (!input || !btn) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('title', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    })();
</script>
@endsection
