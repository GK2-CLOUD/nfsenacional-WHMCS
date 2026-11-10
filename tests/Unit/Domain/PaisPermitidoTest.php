<?php

namespace GK2\NfseNacional\Tests\Unit\Domain;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\PaisPermitido;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaisPermitidoTest extends TestCase
{
    #[DataProvider('casos')]
    public function testPermite(string $modo, array $paises, string $paisCliente, bool $esperado): void
    {
        self::assertSame($esperado, PaisPermitido::permite($modo, $paises, $paisCliente));
    }

    /**
     * @return array<string, array{0: string, 1: string[], 2: string, 3: bool}>
     */
    public static function casos(): array
    {
        return [
            'modo todos libera qualquer pais' => ['todos', [], 'US', true],
            'modo todos com cliente sem pais' => ['todos', [], '', true],
            'selecionados com pais na lista' => ['selecionados', ['BR'], 'BR', true],
            'selecionados minusculo' => ['selecionados', ['BR'], 'br', true],
            'selecionados com pais fora da lista' => ['selecionados', ['BR'], 'US', false],
            'selecionados com cliente sem pais' => ['selecionados', ['BR'], '', false],
            'selecionados com lista vazia' => ['selecionados', [], 'BR', false],
        ];
    }

    #[DataProvider('listas')]
    public function testParsePaisesCodigos(string $entrada, array $esperado): void
    {
        self::assertSame($esperado, ModuleConfig::parsePaisesCodigos($entrada));
    }

    /**
     * @return array<string, array{0: string, 1: string[]}>
     */
    public static function listas(): array
    {
        return [
            'virgula e espaco' => ['BR, US, pt', ['BR', 'US', 'PT']],
            'duplicados' => ['BR,br,BR', ['BR']],
            'codigos invalidos ignorados' => ['BR, USA, 1, ,US', ['BR', 'US']],
            'vazio' => ['', []],
        ];
    }
}
