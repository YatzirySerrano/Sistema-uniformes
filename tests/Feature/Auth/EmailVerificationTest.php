<?php

use App\Enums\RolSistema;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

/**
 * Genera el enlace de verificación tal cual lo hace la notificación de
 * Laravel.
 */
function enlaceVerificacion(User $user, ?int $id = null, ?string $hash = null): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $id ?? $user->id, 'hash' => $hash ?? sha1($user->email)],
    );
}

test('la pantalla de verificación se muestra al usuario autenticado sin verificar', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

test('el enlace verifica el correo SIN necesidad de iniciar sesión', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    // Sin `actingAs`: nadie autenticado, como cuando la persona abre el
    // correo que le creó un administrador.
    $this->get(enlaceVerificacion($user))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Tu correo electrónico fue verificado correctamente. Ya puedes iniciar sesión.');

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('con sesión del MISMO usuario, verifica y va a su destino habitual', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(enlaceVerificacion($user))->assertRedirect('/dashboard');

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('con una sesión de administrador abierta, el enlace del usuario nuevo se verifica igual y NO cambia la sesión activa', function () {
    sembrarRolesPermisos();
    Event::fake();

    $admin = usuarioCon(RolSistema::Administrador->value);
    $nuevo = User::factory()->unverified()->create();

    $this->actingAs($admin)
        ->get(enlaceVerificacion($nuevo))
        ->assertRedirect(route('login')) // no es el mismo usuario → al login
        ->assertSessionHas('status');

    Event::assertDispatched(Verified::class);
    expect($nuevo->fresh()->hasVerifiedEmail())->toBeTrue()
        // La sesión sigue siendo la del administrador: no hay login automático.
        ->and(auth()->id())->toBe($admin->id);
});

test('una firma manipulada se rechaza y no verifica', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    $url = enlaceVerificacion($user);
    $manipulada = substr($url, 0, -3).'000';

    $this->get($manipulada)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Este enlace de verificación ya no es válido o ha expirado. Inicia sesión para solicitar uno nuevo.');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('un enlace expirado devuelve una respuesta controlada, no un 403 crudo', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    $expirado = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->get($expirado)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Este enlace de verificación ya no es válido o ha expirado. Inicia sesión para solicitar uno nuevo.');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('un hash de correo alterado se rechaza (defensa ante cambio de email tras enviar el enlace)', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    $this->get(enlaceVerificacion($user, hash: sha1('otro@correo.test')))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Este enlace de verificación ya no es válido. Inicia sesión para solicitar uno nuevo.');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('el id de un usuario inexistente no revela información y no verifica a nadie', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    // Firma válida sobre un id inexistente + el hash del correo real.
    $this->get(enlaceVerificacion($user, id: 999999))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Este enlace de verificación ya no es válido. Inicia sesión para solicitar uno nuevo.');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('un usuario que YA había verificado recibe un mensaje humano, sin error técnico', function () {
    Event::fake();
    $user = User::factory()->create(); // ya verificado

    $this->get(enlaceVerificacion($user))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Tu correo electrónico ya había sido verificado.');

    Event::assertNotDispatched(Verified::class);
});

test('abrir el enlace dos veces (verifica y luego «ya verificado») nunca produce 429', function () {
    $user = User::factory()->unverified()->create();

    $this->get(enlaceVerificacion($user))->assertRedirect(route('login'));
    $this->get(enlaceVerificacion($user))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Tu correo electrónico ya había sido verificado.');
});

test('el correo de verificación está completamente en español', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe('Verifica tu correo electrónico')
            ->and($mail->actionText)->toBe('Verificar correo electrónico')
            ->and($mail->introLines)->toContain('Haz clic en el botón para verificar tu dirección de correo electrónico.')
            ->and($mail->outroLines)->toContain('Si no creaste una cuenta, no es necesario que hagas nada.')
            ->and($mail->salutation)->toContain('Saludos,')
            ->and($mail->salutation)->toContain('Sistema Uniformes');

        expect($html)->not->toContain('Verify your email address')
            ->and($html)->not->toContain('Verify Email Address')
            ->and($html)->not->toContain('Regards')
            ->and($html)->not->toContain('Please click the button below')
            ->and($html)->not->toContain('If you did not create an account');

        return true;
    });
});
