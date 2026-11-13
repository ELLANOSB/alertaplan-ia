<?php
declare(strict_types=1);

namespace App\IA;

/** Contrato mínimo de un proveedor de LLM (permite cambiar de modelo o simularlo en pruebas). */
interface ProveedorLLM
{
    public function disponible(): bool;

    public function modelo(): string;

    public function completar(string $sistema, string $usuario, int $maxTokens = 400): string;
}
