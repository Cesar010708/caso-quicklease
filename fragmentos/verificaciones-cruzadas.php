<?php
/**
 * Verificaciones cruzadas entre documentos de un expediente (versión genérica).
 *
 * Un dato confirmado por un tercero vale más que el mismo dato repetido en
 * documentos que controla el solicitante. Por eso las reglas de mayor peso cotejan
 * un documento contra otro: el ingreso del recibo contra el abono del banco, el
 * domicilio de la identificación contra el del comprobante, etcétera.
 *
 * Principio: ninguna regla aprueba ni rechaza. Cada una emite hallazgos con
 * severidad; los de severidad alta o media mandan el expediente a revisión manual
 * y la decisión la toma una persona. Cuando algo cuadra también se deja constancia
 * (severidad informativa): a quien revisa le sirve saber qué se comprobó.
 *
 * Ejecutar el ejemplo:  php verificaciones-cruzadas.php
 */

declare(strict_types=1);

/** @return array{regla:string, severidad:string, detalle:string} */
function hallazgo(string $regla, string $severidad, string $detalle): array
{
    return ['regla' => $regla, 'severidad' => $severidad, 'detalle' => $detalle];
}

/** Mayúsculas, sin acentos ni signos, espacios simples. */
function normalizar(string $texto): string
{
    $texto = mb_strtoupper(trim($texto));
    $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    $texto = preg_replace('/[^A-ZÑ0-9 ]+/u', ' ', $texto) ?? '';
    return preg_replace('/\s+/', ' ', trim($texto)) ?? '';
}

/** Proporción de palabras de $a que aparecen en $b (0 a 1). Tolera orden distinto y abreviaturas. */
function coincidencia(string $a, string $b): float
{
    $pa = array_filter(explode(' ', normalizar($a)), fn ($p) => mb_strlen($p) > 2);
    $pb = array_flip(explode(' ', normalizar($b)));
    if ($pa === []) {
        return 0.0;
    }
    $comunes = count(array_filter($pa, fn ($p) => isset($pb[$p])));
    return $comunes / count($pa);
}

/** Ingreso neto del recibo de nómina contra el abono de nómina del estado de cuenta. */
function ingresoContraBanco(?float $netoRecibo, ?float $abonoBanco): array
{
    if ($netoRecibo === null || $abonoBanco === null || $netoRecibo <= 0) {
        return hallazgo('ingreso_vs_banco', 'media', 'No se pudo comparar: falta el ingreso del recibo o el abono del banco.');
    }
    $diferencia = abs($netoRecibo - $abonoBanco) / $netoRecibo;
    $pct = round($diferencia * 100);
    return match (true) {
        $diferencia > 0.25 => hallazgo('ingreso_vs_banco', 'alta', "El abono del banco difiere {$pct}% del ingreso del recibo."),
        $diferencia > 0.05 => hallazgo('ingreso_vs_banco', 'baja', "Diferencia de {$pct}% entre recibo y banco (descuentos o redondeos)."),
        default            => hallazgo('ingreso_vs_banco', 'informativa', 'El abono del banco coincide con el ingreso del recibo.'),
    };
}

/** El titular tiene que ser la misma persona en todos los documentos. */
function mismoTitular(array $nombresPorDocumento): array
{
    $base = reset($nombresPorDocumento);
    foreach ($nombresPorDocumento as $documento => $nombre) {
        if (coincidencia((string) $base, (string) $nombre) < 0.67) {
            return hallazgo('titular', 'alta', "El nombre en «{$documento}» no corresponde al de la identificación.");
        }
    }
    return hallazgo('titular', 'informativa', 'El titular coincide en todos los documentos.');
}

/** El comprobante de domicilio no debe tener más de 3 meses. */
function vigenciaComprobante(DateTimeImmutable $finDelPeriodo, DateTimeImmutable $hoy): array
{
    if ($finDelPeriodo > $hoy) {
        return hallazgo('vigencia_comprobante', 'media', 'El periodo del comprobante está en el futuro: revisar la lectura.');
    }
    $meses = $finDelPeriodo->diff($hoy)->m + 12 * $finDelPeriodo->diff($hoy)->y;
    return $meses >= 3
        ? hallazgo('vigencia_comprobante', 'media', "El comprobante tiene {$meses} meses de antigüedad.")
        : hallazgo('vigencia_comprobante', 'informativa', 'El comprobante está vigente.');
}

/** ¿Requiere revisión manual? Nunca "rechazado": eso lo decide una persona. */
function requiereRevision(array $hallazgos): bool
{
    return (bool) array_filter($hallazgos, fn ($h) => in_array($h['severidad'], ['alta', 'media'], true));
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    // Expediente ficticio.
    $hallazgos = [
        ingresoContraBanco(18500.00, 12100.00),
        mismoTitular([
            'Identificación'      => 'Ana Lucía Torres Méndez',
            'Recibo de nómina'    => 'TORRES MENDEZ ANA LUCIA',
            'Estado de cuenta'    => 'Ana L. Torres Méndez',
        ]),
        vigenciaComprobante(new DateTimeImmutable('2026-09-30'), new DateTimeImmutable('2026-11-01')),
    ];
    foreach ($hallazgos as $h) {
        printf("%-12s %-22s %s\n", strtoupper($h['severidad']), $h['regla'], $h['detalle']);
    }
    echo requiereRevision($hallazgos) ? "→ A revisión manual\n" : "→ Sin observaciones\n";
}
