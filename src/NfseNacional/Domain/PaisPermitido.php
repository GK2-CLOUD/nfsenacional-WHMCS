<?php

namespace GK2\NfseNacional\Domain;

/**
 * Decide se o pais do cliente permite a emissao de NFS-e.
 *
 * Modo "todos" mantem o comportamento historico: qualquer pais emite.
 * Modo "selecionados" so permite os paises listados na configuracao.
 */
final class PaisPermitido
{
    /**
     * @param string[] $paisesPermitidos Codigos ISO de 2 letras.
     */
    public static function permite(
        string $modo,
        array $paisesPermitidos,
        string $paisCliente,
    ): bool {
        if ($modo !== 'selecionados') {
            return true;
        }

        $pais = strtoupper(trim($paisCliente));
        if ($pais === '') {
            return false;
        }

        foreach ($paisesPermitidos as $permitido) {
            if (strtoupper(trim((string) $permitido)) === $pais) {
                return true;
            }
        }

        return false;
    }
}
