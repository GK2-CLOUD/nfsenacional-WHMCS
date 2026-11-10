<?php

namespace GK2\NfseNacional\Tests\Unit\Fiscal\Mapper;

use GK2\NfseNacional\Fiscal\Mapper\DocumentoResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentoResolverTest extends TestCase
{
    #[DataProvider('casos')]
    public function testEscolheDocumento(
        string $cnpj,
        string $cpf,
        string $legado,
        string $taxid,
        string $esperado,
    ): void {
        self::assertSame($esperado, DocumentoResolver::escolher($cnpj, $cpf, $legado, $taxid));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function casos(): array
    {
        return [
            'cnpj prevalece sobre cpf' => [
                '12.345.678/0001-99', '123.456.789-01', '', '', '12345678000199',
            ],
            'cpf quando apenas ele tem valor' => [
                '', '123.456.789-01', '', '', '12345678901',
            ],
            'campo unico configurado em cpf e cnpj: cnpj' => [
                '12.345.678/0001-99', '12.345.678/0001-99', '', '', '12345678000199',
            ],
            'campo unico configurado em cpf e cnpj: cpf' => [
                '123.456.789-01', '123.456.789-01', '', '', '12345678901',
            ],
            'configuracao legada de campo unico' => [
                '', '', '123.456.789-01', '', '12345678901',
            ],
            'taxid como ultimo recurso' => [
                '', '', '', '12.345.678/0001-99', '12345678000199',
            ],
            'cnpj alfanumerico' => [
                'A1B2C3D4E5F601', '', '', '', 'A1B2C3D4E5F601',
            ],
            'nenhum documento informado' => [
                '', '', '', '', '',
            ],
        ];
    }
}
