<?php
declare(strict_types=1);

namespace App\Telegram;

use RuntimeException;

/**
 * Cliente mínimo de la Bot API de Telegram (HTTPS saliente; no necesita abrir puertos).
 * Se puede reemplazar por un doble en las pruebas (ver tests/pruebas_telegram.php).
 */
class Cliente
{
    private string $base;

    public function __construct(private string $token, private int $timeout = 40)
    {
        if (!preg_match('/^\d+:[\w-]{30,}$/', $token)) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN no válido o vacío en .env');
        }
        $this->base = 'https://api.telegram.org/bot' . $token . '/';
    }

    public function llamar(string $metodo, array $params = [], int $timeout = 0): mixed
    {
        // reintenta fallas de red transitorias (p. ej. "Connection reset by peer"); no reintenta errores de la API
        for ($intento = 1; ; $intento++) {
            try {
                return $this->llamarUnaVez($metodo, $params, $timeout);
            } catch (ErrorDeRed $e) {
                if ($intento >= 3 || $metodo === 'getUpdates') {
                    throw new RuntimeException($e->getMessage());
                }
                usleep(700000 * $intento);
            }
        }
    }

    private function llamarUnaVez(string $metodo, array $params, int $timeout): mixed
    {
        $ch = curl_init($this->base . $metodo);
        $multipart = false;
        foreach ($params as $v) {
            $multipart = $multipart || $v instanceof \CURLFile;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout ?: $this->timeout,
            CURLOPT_POSTFIELDS => $multipart ? $params : json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $multipart ? [] : ['Content-Type: application/json'],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new ErrorDeRed("Telegram {$metodo}: sin conexión ({$err})");
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j) || empty($j['ok'])) {
            throw new RuntimeException("Telegram {$metodo}: " . ($j['description'] ?? 'respuesta no válida'));
        }
        return $j['result'];
    }

    public function enviar(int|string $chat, string $html, ?array $teclado = null): void
    {
        // Telegram limita los mensajes a 4096 caracteres: se parte por líneas
        $partes = [];
        $actual = '';
        foreach (explode("\n", $html) as $l) {
            if (mb_strlen($actual . $l) > 3800) {
                $partes[] = $actual;
                $actual = '';
            }
            $actual .= $l . "\n";
        }
        $partes[] = $actual;
        foreach ($partes as $i => $p) {
            $params = ['chat_id' => $chat, 'text' => rtrim($p), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
            if ($teclado && $i === count($partes) - 1) {
                $params['reply_markup'] = ['inline_keyboard' => $teclado];
            }
            $this->llamar('sendMessage', $params);
        }
    }

    public function enviarDocumento(int|string $chat, string $ruta, string $nombre, string $caption = ''): void
    {
        $this->llamar('sendDocument', ['chat_id' => $chat, 'caption' => $caption, 'parse_mode' => 'HTML',
            'document' => new \CURLFile($ruta, 'text/html', $nombre)], 60);
    }

    public function responderBoton(string $id, string $texto = ''): void
    {
        $this->llamar('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $texto]);
    }

    /** Descarga un archivo enviado al bot y devuelve la ruta local. */
    public function descargar(string $fileId, string $destino): string
    {
        $f = $this->llamar('getFile', ['file_id' => $fileId]);
        $url = 'https://api.telegram.org/file/bot' . $this->token . '/' . $f['file_path'];
        $ch = curl_init($url);
        $fp = fopen($destino, 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code !== 200) {
            @unlink($destino);
            throw new RuntimeException('No se pudo descargar el archivo desde Telegram.');
        }
        return $destino;
    }
}

/** Falla de red (no de la API): se puede reintentar. */
final class ErrorDeRed extends RuntimeException
{
}
