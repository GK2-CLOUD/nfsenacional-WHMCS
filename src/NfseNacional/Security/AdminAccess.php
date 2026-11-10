<?php

namespace GK2\NfseNacional\Security;

/**
 * Resolve o acesso de um perfil de administrador ao modulo.
 *
 * A fonte e a mesma lista mantida pelo Access Control de addons do WHMCS
 * (tbladdonmodules.access, IDs de role separados por virgula). Lista vazia
 * significa nenhum perfil autorizado — a mesma semantica do WHMCS.
 */
final class AdminAccess
{
    /**
     * @param string|null $lista  Lista de role IDs separados por virgula.
     * @param mixed       $roleId Role do administrador logado.
     */
    public static function permite(?string $lista, $roleId): bool
    {
        $lista = trim((string) $lista);
        if ($lista === '') {
            return false;
        }

        $roleId = (string) $roleId;

        foreach (explode(',', $lista) as $permitido) {
            if (trim($permitido) === $roleId) {
                return true;
            }
        }

        return false;
    }
}
