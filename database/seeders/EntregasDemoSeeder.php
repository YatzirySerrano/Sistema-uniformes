<?php

namespace Database\Seeders;

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CorregirEntrega;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\User;
use App\Servicios\ResolverAlmacenOperativo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class EntregasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Empresa::query()->where('codigo', 'EMP-A')->with('sucursales')->first();
        if ($empresa === null) {
            return;
        }

        $encargado = User::query()->where('email', 'encargado.a@example.test')->first()
            ?? User::query()->where('email', 'admin.ab@example.test')->first();
        Auth::login($encargado);

        $crear = app(CrearEntregaUniforme::class);
        $confirmar = app(ConfirmarAcuseRecepcion::class);
        $devolver = app(RegistrarDevolucion::class);
        $corregir = app(CorregirEntrega::class);

        $sucursal = $empresa->sucursales->first();

        if ($sucursal === null) {
            return;
        }

        // La empresa puede tener varios almacenes (uno propio + el compartido);
        // para el demo se opera contra su almacén propio (el más antiguo).
        $almacenPropio = Almacen::query()->paraEmpresa($empresa->id)->where('activo', true)->orderBy('id')->first();

        try {
            $almacen = app(ResolverAlmacenOperativo::class)->paraEmpresa($empresa, $almacenPropio?->id);
        } catch (\Throwable) {
            return;
        }

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('sucursal_id', $sucursal->id)
            ->where('activo', true)
            ->limit(6)
            ->get();

        if ($colaboradores->count() < 4) {
            return;
        }

        $itemsDisponibles = fn () => SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->where('almacen_id', $almacen->id)
            ->where('cantidad', '>=', 3)
            ->inRandomOrder()
            ->limit(2)
            ->get()
            ->map(fn ($s): array => ['activo_id' => $s->activo_id, 'talla_id' => $s->talla_id, 'cantidad' => 2])
            ->all();

        // 1) Entrega pendiente de firma
        $crear->ejecutar($colaboradores[0]->id, $almacen->id, $encargado->id, now()->subDays(2)->toDateString(), $itemsDisponibles(), [], [], 'Dotación inicial');

        // 2) Entrega firmada (con acuse y PDF)
        $firmada = $crear->ejecutar($colaboradores[1]->id, $almacen->id, $encargado->id, now()->subDays(5)->toDateString(), $itemsDisponibles(), [], []);
        $firma = $this->firmaDemo();
        if ($firma !== null) {
            $confirmar->ejecutar($firmada, $firma, $firma, true, $encargado->id, '127.0.0.1', 'SeederDemo/1.0');
        }

        // 3) Entrega reciente pendiente
        $crear->ejecutar($colaboradores[2]->id, $almacen->id, $encargado->id, now()->toDateString(), $itemsDisponibles(), [], []);

        // 4) Entrega firmada + devolución
        $conDevolucion = $crear->ejecutar($colaboradores[3]->id, $almacen->id, $encargado->id, now()->subDays(10)->toDateString(), $itemsDisponibles(), [], []);
        if ($firma !== null) {
            $confirmar->ejecutar($conDevolucion, $firma, $firma, true, $encargado->id, '127.0.0.1', 'SeederDemo/1.0');
        }
        $primerItem = $conDevolucion->detalles->first();
        $devolver->ejecutar(
            $conDevolucion->id, $almacen->id, now()->subDays(1)->toDateString(),
            [['detalle_entrega_id' => $primerItem->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
            [],
            $encargado->id, 'Cambio de talla',
        );

        // 5) Entrega firmada + corrección administrativa
        if ($colaboradores->count() >= 5 && $firma !== null) {
            $aCorregir = $crear->ejecutar($colaboradores[4]->id, $almacen->id, $encargado->id, now()->subDays(15)->toDateString(), $itemsDisponibles(), [], []);
            $confirmar->ejecutar($aCorregir, $firma, $firma, true, $encargado->id, '127.0.0.1', 'SeederDemo/1.0');
            $nuevos = $aCorregir->detalles->map(fn ($d): array => [
                'activo_id' => $d->activo_id, 'talla_id' => $d->talla_id, 'cantidad' => max(1, $d->cantidad - 1),
            ])->all();
            $corregir->ejecutar($aCorregir->fresh('detalles'), $nuevos, 'Se registró un activo de más por error de captura.', $encargado->id);
        }

        Auth::logout();
    }

    /**
     * Genera una firma PNG de demostración con GD. Devuelve base64 o null si GD
     * no está disponible.
     */
    private function firmaDemo(): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $img = imagecreatetruecolor(360, 140);
        if ($img === false) {
            return null;
        }
        $blanco = imagecolorallocate($img, 255, 255, 255);
        $tinta = imagecolorallocate($img, 20, 30, 80);
        if ($blanco === false || $tinta === false) {
            imagedestroy($img);

            return null;
        }
        imagefill($img, 0, 0, $blanco);

        $y = 70;
        for ($x = 10; $x < 350; $x += 4) {
            $y2 = 70 + (int) (40 * sin($x / 18));
            imageline($img, $x, $y, $x + 4, $y2, $tinta);
            $y = $y2;
        }
        imagesetthickness($img, 2);
        imageline($img, 20, 110, 330, 110, $tinta);

        ob_start();
        imagepng($img);
        $binario = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($binario);
    }
}
