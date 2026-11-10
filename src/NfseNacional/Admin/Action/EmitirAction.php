<?php

namespace GK2\NfseNacional\Admin\Action;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Service\EmissaoService;
use GK2\NfseNacional\Security\TokenSigner;

/**
 * Acao de emissao manual de NFS-e Nacional a partir do painel admin.
 *
 * Respeita a politica de emissao configurada por cliente (custom field
 * "Emitir NFS-e (Nacional)") e o status atual da fatura, da mesma forma
 * que os hooks automaticos. Sem politica definida no cliente, usa o
 * comportamento global configurado no addon.
 */
class EmitirAction
{
    public function execute(string $modulelink): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        $token = $_REQUEST['token'] ?? '';

        // Validar token
        if ($invoiceId <= 0 || !TokenSigner::verify((string) $invoiceId, $token)) {
            logActivity('NFS-e Nacional: Tentativa de emissao com token invalido. Invoice: ' . $invoiceId);
            header('Location: invoices.php?action=edit&id=' . $invoiceId . '&nfse_error=token');
            exit;
        }

        // Buscar dados da fatura
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($invoice['result'] ?? '') !== 'success') {
            $msg = urlencode('Fatura não encontrada.');
            header('Location: invoices.php?action=edit&id=' . $invoiceId . '&nfse_status=error&nfse_msg=' . $msg);
            exit;
        }

        $guard   = AmbienteGuard::getInstance();
        $service = new EmissaoService(null, $guard);

        // Bloquear apenas se o cliente estiver EXPLICITAMENTE como "Não Emitir".
        // Campo vazio (nenhum) ou global "Não Emitir" não impedem emissão manual.
        $userId = (int) ($invoice['userid'] ?? 0);

        if ($service->emissaoManualBloqueada($userId)) {
            $msg = urlencode('Emissão bloqueada: o cliente está configurado como "Não Emitir NFS-e".');
            header('Location: invoices.php?action=edit&id=' . $invoiceId . '&nfse_status=error&nfse_msg=' . $msg);
            exit;
        }

        if ($service->emissaoBloqueadaPorPais($userId)) {
            $msg = urlencode('Emissão bloqueada: o país do cliente não está na lista de países permitidos.');
            header('Location: invoices.php?action=edit&id=' . $invoiceId . '&nfse_status=error&nfse_msg=' . $msg);
            exit;
        }

        $result = $service->processarEmissao($invoiceId, 'manual');

        $status = $result['sucesso'] ? 'success' : 'error';
        $msg = urlencode($result['msg'] ?? '');

        header('Location: invoices.php?action=edit&id=' . $invoiceId . '&nfse_status=' . $status . '&nfse_msg=' . $msg);
        exit;
    }
}
