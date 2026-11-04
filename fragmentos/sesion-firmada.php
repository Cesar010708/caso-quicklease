<?php
/**
 * Sesión firmada entre dos sistemas que comparten dominio (versión genérica).
 *
 * El contexto: un módulo nuevo vive junto a un sistema existente que ya tiene su
 * propio inicio de sesión. En vez de pedir otro login, el sistema existente emite
 * al entrar un token firmado con un secreto compartido, y el módulo lo verifica sin
 * consultar la sesión del otro.
 *
 *   token = base64url(json{id, rol, exp}) . "." . base64url(HMAC-SHA256(contexto:cuerpo))
 *
 * Detalles que importan:
 *   - El CONTEXTO va dentro de lo firmado ("sesion:", "cotizacion:", ...). Así un
 *     pase firmado para una cosa no sirve para otra, aunque use el mismo secreto.
 *   - La comparación de firmas es en tiempo constante (hash_equals).
 *   - El token caduca (exp) y se rechaza cualquier cosa mal formada sin dar
 *     detalles del motivo a quien llama.
 *
 * Ejecutar el ejemplo:  php sesion-firmada.php
 */

declare(strict_types=1);

function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function desdeBase64url(string $texto): string|false
{
    return base64_decode(strtr($texto, '-_', '+/'), true);
}

function firmarPase(string $contexto, array $datos, string $secreto, int $vigenciaSegundos = 86400): string
{
    $cuerpo = base64url(json_encode($datos + ['exp' => time() + $vigenciaSegundos], JSON_THROW_ON_ERROR));
    $firma  = base64url(hash_hmac('sha256', $contexto . ':' . $cuerpo, $secreto, true));
    return $cuerpo . '.' . $firma;
}

/** @return array<string,mixed>|null  Los datos del pase, o null si no es válido para ese contexto. */
function verificarPase(string $contexto, string $token, string $secreto): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 2 || $secreto === '') {
        return null;
    }
    [$cuerpo, $firma] = $partes;
    $esperada = base64url(hash_hmac('sha256', $contexto . ':' . $cuerpo, $secreto, true));
    if (!hash_equals($esperada, $firma)) {
        return null;
    }
    $json = desdeBase64url($cuerpo);
    $datos = $json === false ? null : json_decode($json, true);
    if (!is_array($datos) || !isset($datos['exp']) || (int) $datos['exp'] < time()) {
        return null;
    }
    return $datos;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $secreto = bin2hex(random_bytes(32));   // en producción: variable de entorno, compartida por los dos sistemas
    $token = firmarPase('sesion', ['id' => 7, 'rol' => 'analista'], $secreto);

    $casos = [
        'pase válido'                          => verificarPase('sesion', $token, $secreto) !== null,
        'mismo pase en otro contexto'          => verificarPase('cotizacion', $token, $secreto) === null,
        'firma alterada'                       => verificarPase('sesion', $token . 'x', $secreto) === null,
        'otro secreto'                         => verificarPase('sesion', $token, bin2hex(random_bytes(32))) === null,
        'pase vencido'                         => verificarPase('sesion', firmarPase('sesion', ['id' => 7], $secreto, -60), $secreto) === null,
    ];
    foreach ($casos as $nombre => $ok) {
        printf("%s  %s\n", $ok ? 'OK   ' : 'FALLA', $nombre);
    }
    exit(in_array(false, $casos, true) ? 1 : 0);
}
