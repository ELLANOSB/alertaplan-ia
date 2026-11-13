<?php
declare(strict_types=1);

namespace App\IA;

use Throwable;

/**
 * C5 · Servicio de IA.
 * Propone la causa de incumplimiento de una actividad a partir de su observación de campo.
 * Reglas de producto:
 *  - La propuesta siempre trae una cita literal de la nota y un nivel de confianza.
 *  - Toda salida pasa por validación automática (esquema, categoría permitida, cita existente).
 *  - Si la API falla o la validación no pasa, se usa el clasificador local; si este se abstiene,
 *    la causa queda como "sin dato suficiente" para que la persona la complete.
 *  - La IA no cambia fechas, semáforos ni PPC, y nada se aprueba sin revisión humana.
 */
final class ServicioIA
{
    public const CATEGORIAS = [
        'materiales'  => 'Materiales / suministro',
        'mano_obra'   => 'Mano de obra / cuadrilla',
        'ingenieria'  => 'Ingeniería, planos y permisos',
        'calidad'     => 'Calidad / retrabajo',
        'clima'       => 'Clima',
        'equipos'     => 'Equipos y maquinaria',
        'predecesora' => 'Actividad previa / frente no liberado',
        'sin_dato'    => 'Sin dato suficiente',
    ];

    public function __construct(
        private ClasificadorLocal $local,
        private ?ProveedorLLM $claude = null,
        private string $modo = 'auto', // auto | local | manual
    ) {
    }

    public function modoEfectivo(): string
    {
        if ($this->modo === 'manual') {
            return 'manual';
        }
        if ($this->modo !== 'local' && $this->claude && $this->claude->disponible()) {
            return 'claude';
        }
        return 'local';
    }

    public function descripcionModo(): string
    {
        return match ($this->modoEfectivo()) {
            'claude' => 'Claude API (' . $this->claude->modelo() . ') con respaldo local',
            'local' => 'Clasificador local Naive Bayes (sin internet)',
            default => 'Modo manual: la IA está desactivada',
        };
    }

    /**
     * @param array $act actividad con codigo, nombre, observacion, motivo, estado
     * @return array{categoria:string,cita:?string,confianza:float,justificacion:string,modo:string,modelo:string,validacion:array}
     */
    public function proponerCausa(array $act): array
    {
        $nota = trim((string)($act['observacion'] ?? ''));
        $modo = $this->modoEfectivo();
        if ($modo === 'manual') {
            return $this->sinDato('IA desactivada (modo manual). Registre la causa a mano.', 'manual', '-', ['modo_manual']);
        }
        if (mb_strlen($nota) < 4) {
            return $this->sinDato('La actividad no tiene observación de campo; no hay evidencia para proponer una causa.', $modo, '-', ['sin_observacion']);
        }
        $fallos = [];
        if ($modo === 'claude') {
            try {
                $r = $this->conClaude($act, $nota);
                $val = $this->validar($r, $nota);
                if ($val['ok']) {
                    return [
                        'categoria' => $r['categoria'],
                        'cita' => $r['categoria'] === 'sin_dato' ? null : $r['cita'],
                        'confianza' => round((float)$r['confianza'], 2),
                        'justificacion' => mb_substr(trim((string)$r['justificacion']), 0, 400),
                        'modo' => 'claude',
                        'modelo' => $this->claude->modelo(),
                        'validacion' => $val['checks'],
                    ];
                }
                $fallos = $val['fallos'];
            } catch (Throwable $e) {
                $fallos = ['api: ' . $e->getMessage()];
            }
        }
        // respaldo local
        $c = $this->local->clasificar($nota);
        if (!$c['aceptada']) {
            return $this->sinDato(
                sprintf('El clasificador local se abstuvo (puntaje %.2f, cobertura %.0f%%). La nota no basta para atribuir una causa.', $c['puntaje'], $c['cobertura'] * 100),
                'local', 'naive-bayes-v1', array_merge($fallos, ['abstencion_local']), (float)$c['puntaje']
            );
        }
        $ok = ['esquema' => true, 'categoria_permitida' => true, 'cita_en_fuente' => str_contains(self::norm($nota), self::norm((string)$c['cita']))];
        return [
            'categoria' => $c['categoria'],
            'cita' => $c['cita'],
            'confianza' => (float)$c['puntaje'],
            'justificacion' => 'Términos de la nota asociados a la categoría: ' . implode(', ', $c['evidencia']) . '.'
                . ($fallos ? ' (Respaldo local: ' . implode('; ', $fallos) . ')' : ''),
            'modo' => 'local',
            'modelo' => 'naive-bayes-v1',
            'validacion' => $ok,
        ];
    }

    private function conClaude(array $act, string $nota): array
    {
        $cats = '';
        foreach (self::CATEGORIAS as $k => $v) {
            $cats .= "- {$k}: {$v}\n";
        }
        $sistema = "Eres asistente de control de producción en obras de construcción en Perú. "
            . "Clasificas la causa de no cumplimiento (Last Planner) de una actividad usando SOLO la observación de campo. "
            . "No inventes datos. Si la observación no permite atribuir una causa, usa 'sin_dato'. "
            . "Responde únicamente un objeto JSON válido, sin texto adicional.";
        $usuario = "Categorías permitidas:\n{$cats}\n"
            . "Actividad: {$act['codigo']} - {$act['nombre']}\n"
            . "Estado calculado por el motor (no lo cambies): " . ($act['motivo'] ?? '') . "\n"
            . "Observación de campo (fuente):\n\"\"\"{$nota}\"\"\"\n\n"
            . "Devuelve: {\"categoria\": <clave permitida>, \"cita\": <fragmento copiado literalmente de la observación que sustenta la causa, o null si sin_dato>, "
            . "\"confianza\": <número entre 0 y 1>, \"justificacion\": <una oración en español>}";
        $txt = $this->claude->completar($sistema, $usuario);
        if (!preg_match('/\{.*\}/s', $txt, $m)) {
            throw new \RuntimeException('respuesta sin JSON');
        }
        $j = json_decode($m[0], true);
        if (!is_array($j)) {
            throw new \RuntimeException('JSON inválido');
        }
        return $j;
    }

    /** Validación automática de la salida del LLM. */
    public function validar(array $r, string $nota): array
    {
        $checks = [
            'esquema' => isset($r['categoria'], $r['confianza'], $r['justificacion']) && array_key_exists('cita', $r),
            'categoria_permitida' => isset($r['categoria']) && isset(self::CATEGORIAS[$r['categoria']]),
            'confianza_en_rango' => isset($r['confianza']) && is_numeric($r['confianza']) && $r['confianza'] >= 0 && $r['confianza'] <= 1,
            'cita_en_fuente' => ($r['categoria'] ?? '') === 'sin_dato'
                || (is_string($r['cita'] ?? null) && mb_strlen(trim($r['cita'])) >= 3 && str_contains(self::norm($nota), self::norm($r['cita']))),
        ];
        $fallos = array_keys(array_filter($checks, fn($v) => !$v));
        return ['ok' => !$fallos, 'checks' => $checks, 'fallos' => array_map(fn($f) => "validacion:{$f}", $fallos)];
    }

    private function sinDato(string $por, string $modo, string $modelo, array $val, float $conf = 0.0): array
    {
        return ['categoria' => 'sin_dato', 'cita' => null, 'confianza' => round($conf, 2), 'justificacion' => $por,
            'modo' => $modo, 'modelo' => $modelo, 'validacion' => $val];
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '“' => '"', '”' => '"']);
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }
}
