<?php

namespace GK2\NfseNacional\Admin\Action;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Persistence\NfseRepository;
use GK2\NfseNacional\Security\TokenSigner;
use WHMCS\Database\Capsule;

/**
 * Acao de exclusao de registro NFS-e do banco (suporte).
 *
 * Permite excluir registros com erro ou do mesmo mes,
 * para reemissao. Somente registros NAO autorizados podem
 * ser excluidos. Registros autorizados devem ser cancelados
 * pela API Nacional.
 */
class ExcluirAction
{
    public function execute(string $modulelink): void
    {
        $id = (int) ($_REQUEST['delete'] ?? 0);
        $token = $_REQUEST['token'] ?? '';

        // Validar token
        if ($id <= 0 || !TokenSigner::verify((string) $id, $token)) {
            logActivity('NFS-e Nacional: Tentativa de exclusao com token invalido. ID: ' . $id);
            header('Location: ' . $modulelink . '&action=list&delete=' . $id . '&delete_status=0');
            exit;
        }

        // Verificar permissao de acesso por role
        $adminId = \WHMCS\Session::get('adminid');
        $roleId = \WHMCS\User\Admin::findOrNew($adminId ?: 0)->roleId;
        if (!$this->checkAccess($roleId)) {
            logActivity('NFS-e Nacional: Admin sem permissao para exclusao. ID: ' . $id . ', roleId: ' . $roleId);
            header('Location: ' . $modulelink . '&action=list&delete=' . $id . '&delete_status=0');
            exit;
        }

        $guard = AmbienteGuard::getInstance();
        $repository = new NfseRepository($guard);
        $nfse = $repository->findById($id);

        if ($nfse === null) {
            header('Location: ' . $modulelink . '&action=list&delete=' . $id . '&delete_status=0');
            exit;
        }

        // Nao permitir exclusao de NFS-e autorizada (deve ser cancelada via API)
        if ($nfse->isAutorizada()) {
            logActivity('NFS-e Nacional: Tentativa de exclusao de NFS-e autorizada. ID: ' . $id);
            header('Location: ' . $modulelink . '&action=list&delete=' . $id . '&delete_status=0');
            exit;
        }

        // Excluir registro
        $repository->delete($id);
        logActivity('NFS-e Nacional: Registro ID ' . $id . ' excluido por admin ' . $adminId);

        header('Location: ' . $modulelink . '&action=list&delete=' . $id . '&delete_status=1');
        exit;
    }

    private function checkAccess($roleId): bool
    {
        $access = Capsule::table('tbladdonmodules')
            ->where('module', 'nfsenacional')
            ->where('setting', 'perfis_manuais')
            ->value('value');

        if (empty($access)) {
            return true;
        }

        $allowed = array_map('trim', explode(',', (string) $access));
        return in_array((string) $roleId, $allowed, true);
    }
}
