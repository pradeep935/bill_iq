<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class CsrfTokenHeaderTest extends TestCase
{
    private function middleware(): VerifyCsrfToken
    {
        // The framework skips CSRF checks under PHPUnit; enforce them here like a real browser request.
        return new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        };
    }

    private function deleteRequest(array $headers): Request
    {
        $request = Request::create('/app/accounting/petty-cash/5', 'DELETE', server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());
        $request->setLaravelSession($session = $this->app['session']->driver());
        $session->start();
        return $request;
    }

    public function test_app_token_header_wins_over_xsrf_cookie_overwritten_by_another_app_on_same_host(): void
    {
        // An XSRF-TOKEN cookie written by a different Laravel app (different key) on localhost:8888.
        $foreign = (new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'))->encrypt('foreign-token', false);

        $request = $this->deleteRequest(['X-XSRF-TOKEN' => $foreign]);
        $this->expectsMismatch(fn () => $this->middleware()->handle($request, fn () => response('ok')));

        $request = $this->deleteRequest(['X-XSRF-TOKEN' => $foreign]);
        $request->headers->set('X-CSRF-TOKEN', $request->session()->token());
        $this->assertSame('ok', $this->middleware()->handle($request, fn () => response('ok'))->getContent());
    }

    public function test_inertia_shares_the_current_session_token_for_the_frontend(): void
    {
        $request = $this->deleteRequest([]);
        $shared = (new HandleInertiaRequests)->share($request);
        $this->assertSame($request->session()->token(), value($shared['csrf_token']));
    }

    private function expectsMismatch(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected CSRF token mismatch.');
        } catch (TokenMismatchException) {
            $this->addToAssertionCount(1);
        }
    }
}
