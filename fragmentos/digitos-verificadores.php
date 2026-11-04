<?php
/**
 * Dígitos verificadores de identificadores mexicanos: CURP, RFC y CLABE.
 *
 * Por qué hace falta en un flujo con IA de visión: cuando el modelo no alcanza a
 * leer un identificador borroso puede devolver una cadena con el formato correcto
 * que no es de nadie. El esquema JSON no lo detecta (la cadena cumple el patrón) y
 * la confianza tampoco (la asigna el propio modelo). La aritmética sí: un valor
 * inventado falla el dígito verificador en la gran mayoría de los casos.
 *
 * Regla de uso: un dato que no pasa su comprobación no se le muestra al usuario
 * para que lo "confirme"; se descarta y se registra el motivo para quien revisa.
 *
 * Ejecutar las comprobaciones:  php digitos-verificadores.php
 */

declare(strict_types=1);

// ─────────────────────────────── CURP ───────────────────────────────

/** La posición de cada carácter en esta cadena es su valor. La Ñ va tras la N. */
const ALFABETO_CURP = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';

const PATRON_CURP = '/^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM]'
    . '(AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)'
    . '[B-DF-HJ-NP-TV-Z]{3}[0-9A-Z]\d$/';

/** Dígito que le corresponde a un CURP por sus primeros 17 caracteres (pesos 18…2, módulo 10). */
function digitoCurp(string $curp): ?string
{
    if (mb_strlen($curp) !== 18) {
        return null;
    }
    $suma = 0;
    for ($i = 0; $i < 17; $i++) {
        $valor = mb_strpos(ALFABETO_CURP, mb_substr($curp, $i, 1));
        if ($valor === false) {
            return null;
        }
        $suma += $valor * (18 - $i);
    }
    return (string) ((10 - $suma % 10) % 10);
}

function curpValido(string $curp): bool
{
    $curp = strtoupper(trim($curp));
    return (bool) preg_match(PATRON_CURP, $curp) && digitoCurp($curp) === substr($curp, -1);
}

// ─────────────────────────────── RFC ───────────────────────────────

/** Valor de cada carácter en el algoritmo del SAT. No es el alfabeto del CURP: aquí `&` va tras la N. */
function valorRfc(string $c): ?int
{
    static $tabla = null;
    if ($tabla === null) {
        $tabla = array_flip(str_split('0123456789ABCDEFGHIJKLMN&OPQRSTUVWXYZ'));
        $tabla[' '] = 37;
        $tabla['Ñ'] = 38;
    }
    return $tabla[$c] ?? null;
}

/** Dígito del RFC (pesos 13…2, módulo 11). El de persona moral (12) se completa con un espacio. */
function digitoRfc(string $rfc): ?string
{
    $largo = mb_strlen($rfc);
    if ($largo === 12) {
        $rfc = ' ' . $rfc;
    } elseif ($largo !== 13) {
        return null;
    }
    $suma = 0;
    for ($i = 0; $i < 12; $i++) {
        $valor = valorRfc(mb_substr($rfc, $i, 1));
        if ($valor === null) {
            return null;
        }
        $suma += $valor * (13 - $i);
    }
    $residuo = $suma % 11;
    return match ($residuo) {
        0       => '0',
        1       => 'A',
        default => (string) (11 - $residuo),
    };
}

/** RFC genéricos del SAT: existen en facturas, pero no identifican a nadie. */
const RFC_GENERICOS = ['XAXX010101000', 'XEXX010101000'];

function rfcValido(string $rfc): bool
{
    $rfc = strtoupper(preg_replace('/[\s-]+/u', '', $rfc) ?? '');
    if (in_array($rfc, RFC_GENERICOS, true) || !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u', $rfc)) {
        return false;
    }
    // La fecha embebida tiene que existir.
    $inicio = mb_strlen($rfc) === 13 ? 4 : 3;
    $aa = (int) mb_substr($rfc, $inicio, 2);
    $anio = $aa <= (int) date('y') ? 2000 + $aa : 1900 + $aa;
    if (!checkdate((int) mb_substr($rfc, $inicio + 2, 2), (int) mb_substr($rfc, $inicio + 4, 2), $anio)) {
        return false;
    }
    return digitoRfc($rfc) === mb_substr($rfc, -1);
}

// ─────────────────────────────── CLABE ───────────────────────────────

/** Pesos 3-7-1; cada producto se toma módulo 10 ANTES de sumar (el paso que suele olvidarse). */
function digitoClabe(string $clabe): ?string
{
    if (!preg_match('/^\d{18}$/', $clabe)) {
        return null;
    }
    $pesos = [3, 7, 1];
    $suma = 0;
    for ($i = 0; $i < 17; $i++) {
        $suma += ((int) $clabe[$i] * $pesos[$i % 3]) % 10;
    }
    return (string) ((10 - $suma % 10) % 10);
}

function clabeValida(string $clabe): bool
{
    $clabe = preg_replace('/\D+/', '', $clabe) ?? '';
    return strlen($clabe) === 18 && substr($clabe, 0, 3) !== '000' && digitoClabe($clabe) === $clabe[17];
}

// ───────────────────── Aplicarlo a lo que devolvió el modelo ─────────────────────

/**
 * Deja pasar el valor solo si se puede comprobar. Si no, lo descarta y devuelve el
 * hallazgo que verá quien revisa el expediente (con el valor descartado y el motivo).
 *
 * @return array{valor: ?string, hallazgo: ?string}
 */
function depurarIdentificador(string $campo, string $valor): array
{
    $valido = match ($campo) {
        'curp'  => curpValido($valor),
        'rfc'   => rfcValido($valor),
        'clabe' => clabeValida($valor),
        default => true,
    };
    if ($valido || $valor === '') {
        return ['valor' => $valor === '' ? null : $valor, 'hallazgo' => null];
    }
    return [
        'valor'    => null,
        'hallazgo' => sprintf('Se descartó el %s leído («%s»): no pasa su dígito verificador.', strtoupper($campo), $valor),
    ];
}

// ─────────────── Comprobaciones con los ejemplos oficiales de cada algoritmo ───────────────

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $casos = [
        ['CURP válido (ejemplo oficial)', curpValido('SABC560626MDFLRN01'), true],
        ['CURP con el último dígito cambiado', curpValido('SABC560626MDFLRN02'), false],
        ['RFC válido (ejemplo del SAT)', rfcValido('GODE561231GR8'), true],
        ['RFC con homoclave alterada', rfcValido('GODE561231GR9'), false],
        ['RFC genérico de público en general', rfcValido('XAXX010101000'), false],
        ['CLABE válida (ejemplo de Banxico)', clabeValida('002010077777777771'), true],
        ['CLABE con un dígito cambiado', clabeValida('002010077777777781'), false],
    ];
    $fallas = 0;
    foreach ($casos as [$nombre, $obtenido, $esperado]) {
        $ok = $obtenido === $esperado;
        $fallas += $ok ? 0 : 1;
        printf("%s  %s\n", $ok ? 'OK   ' : 'FALLA', $nombre);
    }
    print_r(depurarIdentificador('curp', 'SABC560626MDFLRN07'));
    exit($fallas === 0 ? 0 : 1);
}
