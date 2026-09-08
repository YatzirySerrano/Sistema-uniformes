<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('un usuario NO verificado se autentica correctamente (autenticación ≠ verificación de correo)', function () {
    $user = User::factory()->unverified()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticated();
});

test('regresión: el login funciona sobre http aunque llegue X-Forwarded-Proto https, sin TRUSTED_PROXIES', function () {
    // Sin `TRUSTED_PROXIES` en el entorno, un `X-Forwarded-Proto: https`
    // inyectado por cualquier proxy NO debe volver `Secure` la cookie de
    // sesión ni cambiar el esquema del redirect (esa era la regresión que
    // dejaba al usuario fuera tras un login con credenciales válidas).
    $user = User::factory()->create();

    $response = $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'otro-host.test',
    ])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $cookieSesion = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));
    $cookieXsrf = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');

    expect($cookieSesion)->not->toBeNull()
        ->and($cookieSesion->isSecure())->toBeFalse()
        ->and($cookieXsrf?->isSecure())->toBeFalse()
        ->and($response->headers->get('Location'))->toStartWith('http://');

    // La sesión persiste en la siguiente petición.
    $this->get('/dashboard')->assertOk();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
