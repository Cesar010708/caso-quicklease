<?php
/**
 * Extracción de datos de un expediente con un modelo de visión (versión genérica).
 *
 * La técnica:
 *   1. Un CATÁLOGO de campos es la única fuente de verdad: de él salen el esquema
 *      JSON, el prompt y el formulario que confirma el usuario. Agregar un campo es
 *      agregar una línea aquí.
 *   2. Todos los documentos van en UNA sola llamada, cada uno precedido de una
 *      etiqueta, para que el modelo pueda cruzarlos y citar de dónde sacó cada dato.
 *   3. Las imágenes viajan como data URL; los PDF como parte `file` en base64, sin
 *      rasterizar ni extraer texto antes.
 *   4. Salida con `json_schema` en modo estricto: la respuesta cumple el esquema o
 *      la llamada falla. No hay prosa que interpretar.
 *   5. El prompt lleva la FECHA DE HOY: sin ella el modelo juzga vencimientos con
 *      su propia fecha de corte y marca como "futuros" periodos que no lo son.
 *   6. El proveedor es intercambiable: quien llama recibe siempre la misma
 *      estructura y pregunta por `origen === 'simulada'`, nunca por un proveedor.
 *
 * Nunca lanza excepciones: al usuario se le da un mensaje genérico y el error real
 * va al log.
 */

declare(strict_types=1);

/** Catálogo de campos (recortado para el ejemplo). */
const CAMPOS = [
    'nombre_completo' => ['etiqueta' => 'Nombre completo',        'de' => 'Identificación oficial'],
    'curp'            => ['etiqueta' => 'CURP',                   'de' => 'Identificación oficial'],
    'domicilio'       => ['etiqueta' => 'Domicilio',              'de' => 'Identificación y comprobante de domicilio'],
    'ingreso_neto'    => ['etiqueta' => 'Ingreso neto del periodo', 'de' => 'Recibo de nómina'],
    'abono_nomina'    => ['etiqueta' => 'Abono de nómina',        'de' => 'Estado de cuenta'],
    'clabe'           => ['etiqueta' => 'CLABE',                  'de' => 'Estado de cuenta'],
];

function esquemaDeExtraccion(): array
{
    // En modo estricto cada objeto lleva additionalProperties:false y todas sus
    // propiedades en `required`. Un dato que no se ve se devuelve vacío, no se omite.
    $campo = [
        'type'       => 'object',
        'properties' => [
            'valor'     => ['type' => 'string', 'description' => 'Texto tal como aparece; vacío si no se lee.'],
            'confianza' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
            'fuente'    => ['type' => 'string', 'description' => 'Documento del que salió el dato.'],
        ],
        'required'             => ['valor', 'confianza', 'fuente'],
        'additionalProperties' => false,
    ];
    $propiedades = array_map(fn () => $campo, CAMPOS);

    return [
        'type'       => 'object',
        'properties' => [
            'campos' => [
                'type'                 => 'object',
                'properties'           => $propiedades,
                'required'             => array_keys($propiedades),
                'additionalProperties' => false,
            ],
            'observaciones' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'             => ['campos', 'observaciones'],
        'additionalProperties' => false,
    ];
}

function promptDeSistema(): string
{
    $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    $lista = implode("\n", array_map(
        fn ($clave, $c) => "- {$clave}: {$c['etiqueta']} (se espera en: {$c['de']})",
        array_keys(CAMPOS),
        CAMPOS
    ));

    return <<<TXT
    Eres un capturista que transcribe datos de documentos de un solicitante de crédito.
    La fecha de hoy es {$hoy}; úsala para juzgar vigencias y periodos.

    Reglas:
    - Transcribe solo lo que se ve. Si un dato no se lee con claridad, deja `valor`
      vacío y confianza "baja". Nunca completes ni adivines un identificador.
    - La confianza es alta cuando el dato aparece legible y coincide entre documentos;
      baja cuando se lee con dificultad o difiere entre documentos.
    - En `fuente` indica de qué documento salió cada dato.
    - En `observaciones` anota lo que una persona deba revisar (tachaduras, documentos
      vencidos, datos que no coinciden).

    Campos:
    {$lista}
    TXT;
}

/**
 * @param array<int, array{tipo:string, etiqueta:string, ruta:string, mime:string}> $documentos
 */
function partesDelMensaje(array $documentos): array
{
    $partes = [['type' => 'text', 'text' => 'Documentos del solicitante. Extrae los datos según el esquema.']];

    foreach ($documentos as $doc) {
        $contenido = @file_get_contents($doc['ruta']);
        if ($contenido === false) {
            continue;
        }
        if (str_starts_with($doc['mime'], 'image/')) {
            // (En producción la imagen se reduce antes a ~2000 px de lado mayor:
            // el texto sigue legible y se consumen muchos menos tokens.)
            $parte = ['type' => 'image_url', 'image_url' => [
                'url'    => 'data:' . $doc['mime'] . ';base64,' . base64_encode($contenido),
                'detail' => 'high',
            ]];
        } elseif ($doc['mime'] === 'application/pdf') {
            $parte = ['type' => 'file', 'file' => [
                'filename'  => $doc['tipo'] . '.pdf',   // nombre del servidor, nunca el del usuario
                'file_data' => 'data:application/pdf;base64,' . base64_encode($contenido),
            ]];
        } else {
            continue;
        }
        $partes[] = ['type' => 'text', 'text' => "--- Documento: {$doc['etiqueta']} ---"];
        $partes[] = $parte;
    }
    return $partes;
}

/** @return array{origen:string, campos:array, observaciones:array, error:string} */
function extraerConOpenAi(array $documentos, string $apiKey, string $modelo = 'gpt-4o'): array
{
    $vacio = ['origen' => 'openai', 'campos' => [], 'observaciones' => [], 'error' => ''];

    try {
        $carga = [
            'model'           => $modelo,
            'messages'        => [
                ['role' => 'system', 'content' => promptDeSistema()],
                ['role' => 'user',   'content' => partesDelMensaje($documentos)],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => [
                'name'   => 'extraccion_documental',
                'strict' => true,
                'schema' => esquemaDeExtraccion(),
            ]],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode($carga, JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
        ]);
        $cuerpo = curl_exec($ch);
        $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $falla  = curl_error($ch);

        if ($cuerpo === false || $http !== 200) {
            throw new RuntimeException("HTTP {$http} {$falla}");
        }
        $respuesta = json_decode((string) $cuerpo, true, 512, JSON_THROW_ON_ERROR);
        $mensaje   = $respuesta['choices'][0]['message'] ?? [];
        if (!empty($mensaje['refusal'])) {
            throw new RuntimeException('El modelo rechazó la solicitud: ' . $mensaje['refusal']);
        }
        $datos = json_decode((string) ($mensaje['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        return ['campos' => $datos['campos'], 'observaciones' => $datos['observaciones']] + $vacio;
    } catch (\Throwable $e) {
        error_log('Extracción con visión: ' . $e->getMessage());
        return ['error' => 'No se pudieron leer los documentos. Inténtelo de nuevo.'] + $vacio;
    }
}

/** Respaldo sin IA (demostraciones y pruebas): misma estructura, campos vacíos. */
function extraccionSimulada(): array
{
    $campos = array_map(fn () => ['valor' => '', 'confianza' => 'baja', 'fuente' => ''], CAMPOS);
    return ['origen' => 'simulada', 'campos' => $campos, 'observaciones' => [], 'error' => ''];
}

/** Único punto de entrada. Quien llama no sabe ni le importa qué proveedor contestó. */
function extraerDatos(array $documentos): array
{
    $proveedor = getenv('VISION_PROVEEDOR') ?: 'openai';
    $clave     = (string) getenv('OPENAI_API_KEY');

    $resultado = match (true) {
        $proveedor === 'openai' && $clave !== '' => extraerConOpenAi($documentos, $clave),
        // Un segundo proveedor entra aquí con su propia función, devolviendo la
        // MISMA estructura y usando el MISMO esquema y prompt de arriba.
        default => extraccionSimulada(),
    };

    // Lo que devuelve el modelo nunca llega directo al usuario: antes pasa por la
    // depuración (dígitos verificadores, techos de importe, fechas posibles).
    // Ver digitos-verificadores.php.
    return $resultado;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    // Sin clave muestra el esquema que se enviaría; con OPENAI_API_KEY y archivos
    // de prueba (ficticios) hace la llamada real:  php extraccion-con-vision.php ine.jpg recibo.pdf
    $archivos = array_slice($argv, 1);
    if ($archivos === [] || getenv('OPENAI_API_KEY') === false) {
        echo json_encode(esquemaDeExtraccion(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    $docs = array_map(fn ($r) => [
        'tipo' => pathinfo($r, PATHINFO_FILENAME), 'etiqueta' => basename($r),
        'ruta' => $r, 'mime' => mime_content_type($r) ?: 'application/octet-stream',
    ], $archivos);
    print_r(extraerDatos($docs));
}
