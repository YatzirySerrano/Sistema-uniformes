<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Personalización visual GLOBAL: custom properties calculadas en
        el servidor para que el primer pintado ya use los colores
        configurados, sin parpadeo. `resources/js/lib/temaVisual.ts` toma el
        relevo en el cliente tras cada guardado / navegación. --}}
        @php($temaVisual = \App\Models\ConfiguracionSistema::actual())
        <style>
            :root {
                @foreach ($temaVisual->variablesClaro() as $variable => $valor)
                    {{ $variable }}: {{ $valor }};
                @endforeach
            }

            .dark {
                @foreach ($temaVisual->variablesOscuro() as $variable => $valor)
                    {{ $variable }}: {{ $valor }};
                @endforeach
            }
        </style>

        {{-- Favicon a partir del logo real de la empresa, recortado a fondo
        transparente (`public/images/logo-seretia-transparente.png`),
        centrado sobre lienzo cuadrado sin recortar ni deformar. `apple-touch-icon.png`
        se queda con fondo sólido a propósito (iOS rellena de negro las zonas
        transparentes de un ícono de pantalla de inicio). El `?v=` fuerza a
        los navegadores a descartar la caché del ícono anterior servido
        desde estas mismas rutas. --}}
        <link rel="icon" href="/favicon.ico?v=3" sizes="any">
        <link rel="icon" href="/favicon.png?v=3" type="image/png" sizes="512x512">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">

        {{-- Instalación como app (PWA): manifest dinámico (routes/web.php,
        toma nombre y color de marca de la configuración del sistema) +
        color de la barra del navegador mientras la app está instalada. --}}
        <link rel="manifest" href="{{ route('manifest') }}">
        <meta name="theme-color" content="{{ $temaVisual->color_principal }}">

        {{-- Aplicación privada autenticada, nunca un sitio de marketing:
        noindex/nofollow fijo aquí (fuera de x-inertia::head) para que
        ningún <Head> de página pueda quitarlo. Ver App\Http\Middleware\
        AgregaEncabezadoRobots para el header HTTP equivalente. --}}
        <meta name="robots" content="noindex, nofollow, noarchive">

        {{-- Metadata general (título/descripción/Open Graph/Twitter Card):
        valores por defecto renderizados en el servidor con `data-inertia`
        para que el <Head> de cada página los pueda reemplazar sin duplicar
        (nunca los toca `<meta name="robots">` de arriba). Ver
        https://inertiajs.com/docs/v3/the-basics/title-and-meta. --}}
        <meta data-inertia="description" name="description" content="{{ config('app.name') }}: sistema privado de control y gestión de uniformes y activos de la empresa.">
        <link data-inertia="canonical" rel="canonical" href="{{ config('app.url') }}">
        <meta data-inertia="og:type" property="og:type" content="website">
        <meta data-inertia="og:site_name" property="og:site_name" content="{{ config('app.name') }}">
        <meta data-inertia="og:title" property="og:title" content="{{ config('app.name') }}">
        <meta data-inertia="og:description" property="og:description" content="Sistema privado de control y gestión de uniformes y activos de la empresa.">
        <meta data-inertia="og:url" property="og:url" content="{{ config('app.url') }}">
        <meta data-inertia="og:locale" property="og:locale" content="es_MX">
        <meta data-inertia="twitter:card" name="twitter:card" content="summary">
        <meta data-inertia="twitter:title" name="twitter:title" content="{{ config('app.name') }}">
        <meta data-inertia="twitter:description" name="twitter:description" content="Sistema privado de control y gestión de uniformes y activos de la empresa.">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Sistema de Uniformes') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
