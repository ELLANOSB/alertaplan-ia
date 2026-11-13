<?php
declare(strict_types=1);

namespace App\IA;

/**
 * Clasificador de causas de incumplimiento (Naive Bayes multinomial) que corre en PHP,
 * sin internet ni claves. Es el respaldo permanente del servicio de IA.
 * Se abstiene cuando la nota no tiene suficiente evidencia.
 */
final class ClasificadorLocal
{
    public const UMBRAL_PUNTAJE = 0.50;
    public const UMBRAL_COBERTURA = 0.25;

    private const VACIAS = ['de','la','el','los','las','en','por','para','con','un','una','y','del','al','no','sin','se','que','su','sus','lo','le','a','o','e','es','esta','este','hay','fue','son','mas','muy','ya','aun','hasta','desde','entre','sobre'];

    private array $vocab = [];
    private array $clases = [];
    private int $total = 0;

    public function __construct(array $corpus)
    {
        foreach ($corpus as $clase => $ejemplos) {
            $conteo = [];
            $tokens = 0;
            foreach ($ejemplos as $txt) {
                $this->total++;
                foreach (self::tokens($txt) as $t) {
                    $this->vocab[$t] = true;
                    $conteo[$t] = ($conteo[$t] ?? 0) + 1;
                    $tokens++;
                }
            }
            $this->clases[$clase] = ['conteo' => $conteo, 'tokens' => $tokens, 'n' => count($ejemplos)];
        }
    }

    public static function desdeArchivo(string $ruta): self
    {
        $j = json_decode((string)file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        return new self($j['categorias']);
    }

    public static function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return (string)preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    }

    /** Tokens normalizados con raíz simple (prefijo de 5 letras) para tolerar plural/conjugación. */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s+/', self::normalizar($s)) ?: [] as $w) {
            if (strlen($w) < 3 || in_array($w, self::VACIAS, true) || ctype_digit($w)) {
                continue;
            }
            $out[] = substr($w, 0, 5);
        }
        return $out;
    }

    public function categorias(): array
    {
        return array_keys($this->clases);
    }

    /**
     * @return array{categoria:string,candidata:?string,puntaje:float,cobertura:float,aceptada:bool,evidencia:array,cita:?string}
     */
    public function clasificar(string $nota): array
    {
        $palabras = self::tokens($nota);
        $conocidas = array_values(array_filter($palabras, fn($t) => isset($this->vocab[$t])));
        $cob = $palabras ? count($conocidas) / count($palabras) : 0.0;
        if (!$conocidas) {
            return ['categoria' => 'sin_dato', 'candidata' => null, 'puntaje' => 0.0, 'cobertura' => 0.0,
                'aceptada' => false, 'evidencia' => [], 'cita' => null];
        }
        $V = count($this->vocab);
        $pts = [];
        foreach ($this->clases as $nombre => $c) {
            $s = log($c['n'] / $this->total);
            foreach ($conocidas as $w) {
                $s += log((($c['conteo'][$w] ?? 0) + 1) / ($c['tokens'] + $V));
            }
            $pts[$nombre] = $s;
        }
        arsort($pts);
        $max = reset($pts);
        $sum = 0.0;
        foreach ($pts as $v) {
            $sum += exp($v - $max);
        }
        $puntaje = 1 / $sum; // probabilidad posterior normalizada (no calibrada)
        $cand = (string)array_key_first($pts);
        $acept = $puntaje >= self::UMBRAL_PUNTAJE && $cob >= self::UMBRAL_COBERTURA;
        // evidencia: palabras de la nota que pesan a favor de la clase candidata
        $evid = [];
        foreach (array_unique($conocidas) as $w) {
            if (($this->clases[$cand]['conteo'][$w] ?? 0) > 0) {
                $evid[] = $w;
            }
        }
        return [
            'categoria' => $acept ? $cand : 'sin_dato',
            'candidata' => $cand,
            'puntaje' => round($puntaje, 3),
            'cobertura' => round($cob, 3),
            'aceptada' => $acept,
            'evidencia' => $evid,
            'cita' => $acept ? self::citaDesdeNota($nota, $evid) : null,
        ];
    }

    /** Devuelve el fragmento literal de la nota (oración o cláusula) que contiene más evidencia. */
    public static function citaDesdeNota(string $nota, array $evid): string
    {
        $partes = preg_split('/\R+|(?<=[\.;])\s+|\s+-\s+/u', trim($nota)) ?: [trim($nota)];
        $mejor = trim($nota);
        $max = -1;
        foreach ($partes as $p) {
            $tk = self::tokens($p);
            $n = count(array_intersect($tk, $evid));
            if ($n > $max && trim($p) !== '') {
                $max = $n;
                $mejor = trim($p);
            }
        }
        return rtrim($mejor, '.;: ');
    }
}
