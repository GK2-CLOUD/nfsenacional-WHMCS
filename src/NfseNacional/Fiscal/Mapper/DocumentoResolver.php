<?php

namespace GK2\NfseNacional\Fiscal\Mapper;

/**
 * Escolhe o documento fiscal do tomador entre as fontes configuradas.
 *
 * Regras de precedencia:
 * - CNPJ quando preenchido e valido (14 posicoes, incluindo o CNPJ
 *   alfanumerico);
 * - senao, CPF (11 digitos);
 * - senao, a configuracao legada de campo unico (documento_cliente);
 * - por ultimo, o Tax ID do WHMCS.
 *
 * As fontes podem apontar para o mesmo campo do cliente: instalacoes com
 * um unico campo para CPF/CNPJ configuram o mesmo campo nos dois seletores,
 * e o elemento do XML e decidido pelo tamanho do valor lido.
 */
final class DocumentoResolver
{
    /**
     * Resolve o documento a partir dos valores brutos de cada fonte.
     */
    public static function escolher(
        string $cnpjBruto,
        string $cpfBruto,
        string $legadoBruto = '',
        string $taxidBruto = '',
    ): string {
        $cpf = '';

        foreach ([$cnpjBruto, $cpfBruto, $legadoBruto, $taxidBruto] as $bruto) {
            $candidato = self::normalizar($bruto);

            if (strlen($candidato) === 14) {
                return $candidato; // CNPJ numerico ou alfanumerico
            }

            if ($cpf === '' && strlen($candidato) === 11) {
                $cpf = $candidato;
            }
        }

        return $cpf;
    }

    /**
     * Remove pontuacao e normaliza o documento.
     *
     * CNPJ alfanumerico (14 posicoes com letras) e preservado; qualquer
     * outro valor mantem apenas os digitos.
     */
    public static function normalizar(string $valor): string
    {
        $limpo = strtoupper(trim((string) preg_replace('/[.\/\-\s]+/', '', $valor)));

        if (strlen($limpo) === 14 && preg_match('/[A-Z]/', $limpo) === 1) {
            return $limpo;
        }

        return (string) preg_replace('/[^0-9]/', '', $limpo);
    }
}
