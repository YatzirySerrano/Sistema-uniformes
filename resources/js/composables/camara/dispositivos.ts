/**
 * Utilidades puras (sin DOM, sin Vue) para elegir entre las cámaras de vídeo
 * del dispositivo. Se aíslan aquí para poder razonarlas y probarlas sin montar
 * un componente ni mockear `navigator.mediaDevices`.
 */

export type CamaraInfo = {
    deviceId: string;
    label: string;
};

/**
 * Convierte la salida de `navigator.mediaDevices.enumerateDevices()` en la
 * lista de cámaras de vídeo utilizables. Descarta entradas sin `deviceId`
 * (algunos navegadores las exponen antes de conceder permiso) y de-duplica.
 */
export function camarasDeVideo(
    dispositivos: readonly MediaDeviceInfo[],
): CamaraInfo[] {
    const vistos = new Set<string>();
    const camaras: CamaraInfo[] = [];

    for (const d of dispositivos) {
        if (d.kind !== 'videoinput' || !d.deviceId || vistos.has(d.deviceId)) {
            continue;
        }
        vistos.add(d.deviceId);
        camaras.push({
            deviceId: d.deviceId,
            label: d.label?.trim() || `Cámara ${camaras.length + 1}`,
        });
    }

    return camaras;
}

/**
 * Devuelve la SIGUIENTE cámara en orden circular respecto a `actualId`.
 * `null` cuando no tiene sentido cambiar (0 o 1 cámara). Si `actualId` no se
 * reconoce, empieza por la primera.
 */
export function siguienteCamara(
    camaras: readonly CamaraInfo[],
    actualId: string | null,
): CamaraInfo | null {
    if (camaras.length <= 1) {
        return null;
    }

    const indiceActual = camaras.findIndex((c) => c.deviceId === actualId);
    const siguiente = (indiceActual + 1) % camaras.length;

    return camaras[siguiente] ?? null;
}
