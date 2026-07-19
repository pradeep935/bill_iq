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
                        <input type="email" name="username" class="form-control" value="{{ old('username') }}" placeholder="admin@gmail.com" required autofocus>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
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
@endsection
