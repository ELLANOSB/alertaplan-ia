<?php
declare(strict_types=1);

namespace App\IA;

use RuntimeException;

/**
 * Cliente mínimo de la API de mensajes de Anthropic (Claude).
 * Solo se usa si ANTHROPIC_API_KEY está definido en .env. Nunca es obligatorio para la demo.
 */
final class ClienteClaude implements ProveedorLLM
{
    public function __construct(
        private string $apiKey,
        private string $modelo,
        private int $timeout = 20,
    ) {
    }

    public function disponible(): bool
    {
        return $this->apiKey !== '' && function_exists('curl_init');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /** Envía el mensaje y devuelve el texto de la respuesta. */
    public function completar(string $sistema, string $usuario, int $maxTokens = 400): string
    {
        $payload = json_encode([
            'model' => $this->modelo,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
            'system' => $sistema,
            'messages' => [['role' => 'user', 'content' => $usuario]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Sin conexión con la API de IA: ' . $err);
        }
        $j = json_decode((string)$raw, true);
        if ($code !== 200 || !is_array($j)) {
            $msg = is_array($j) ? ($j['error']['message'] ?? 'respuesta no válida') : 'respuesta no válida';
            throw new RuntimeException("La API de IA respondió {$code}: {$msg}");
        }
        $texto = '';
        foreach ($j['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'text') {
                $texto .= $b['text'];
            }
        }
        return $texto;
    }
}
