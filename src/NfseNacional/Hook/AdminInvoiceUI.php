<?php

namespace GK2\NfseNacional\Hook;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Enum\NfseStatus;
use GK2\NfseNacional\Domain\Service\DownloadUrlService;
use GK2\NfseNacional\Persistence\NfseRepository;
use GK2\NfseNacional\Security\TokenSigner;
use WHMCS\Database\Capsule;

/**
 * Renderiza o painel de NFS-e Nacional na pagina admin da fatura.
 *
 * Exibe status, botoes de acao (emitir, cancelar, reenviar email)
 * e links para DANFS-e e XML via proxy seguro (não links diretos do governo).
 *
 * Respeita controle de acesso por role de admin (campo "access").
 */
class AdminInvoiceUI
{
    /**
     * Renderiza o HTML do painel NFS-e Nacional.
     *
     * @param array $vars Variaveis do hook AdminInvoicesControlsOutput
     * @return string HTML do painel
     */
    public function render(array $vars): string
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return '';
        }

        $guard      = AmbienteGuard::getInstance();
        $repository = new NfseRepository($guard);
        $nfse       = $repository->findByInvoice($invoiceId);

        $token = TokenSigner::sign((string) $invoiceId);

        // Verificar permissao de acesso por role
        $adminId   = $vars['adminid'] ?? \WHMCS\Session::get('adminid');
        $roleId    = \WHMCS\User\Admin::findOrNew($adminId ?: 0)->roleId;
        $hasAccess = $this->checkAccess($roleId);

        // URL base do addon — relativa, pois o hook ja roda dentro do admin
        $baseUrl = 'addonmodules.php?module=nfsenacional';

        $html  = '<div class="invoice-nfse-nacional" style="margin-top:10px;">';
        $html .= '<div class="panel panel-default">';
        $html .= '<div class="panel-heading"><h3 class="panel-title"><i class="fas fa-file-invoice"></i> NFS-e Nacional</h3></div>';
        $html .= '<div class="panel-body">';

        // Feedback de ações (emissão bloqueada, erros, sucesso)
        $nfseStatus = $_GET['nfse_status'] ?? '';
        $nfseMsg    = htmlspecialchars(urldecode($_GET['nfse_msg'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($nfseStatus === 'success' && !empty($nfseMsg)) {
            $html .= '<div class="alert alert-success" style="margin-bottom:10px;">'
                . '<i class="fas fa-check-circle"></i> ' . $nfseMsg . '</div>';
        } elseif ($nfseStatus === 'error' && !empty($nfseMsg)) {
            $html .= '<div class="alert alert-danger" style="margin-bottom:10px;">'
                . '<i class="fas fa-exclamation-circle"></i> ' . $nfseMsg . '</div>';
        }

        if ($nfse === null) {
            // Sem NFS-e - mostrar botao de emissao (se tem acesso)
            $html .= '<p class="text-muted">Nenhuma NFS-e Nacional emitida para esta fatura.</p>';
            if ($hasAccess) {
                $emitUrl = $baseUrl . '&action=emitir&invoiceid=' . $invoiceId . '&token=' . $token;
                $html .= '<a href="javascript:void(0)" class="btn btn-success btn-sm"'
                    . ' onclick="if(confirm(\'Deseja emitir NFS-e para a fatura #' . $invoiceId . '?\')){window.location.href=\'' . $emitUrl . '\';}">';
                $html .= '<i class="fas fa-paper-plane"></i> Emitir NFS-e Nacional</a>';
            }
        } else {
            // Auto-refresh quando PROCESSANDO
            if ($nfse->status === NfseStatus::PROCESSANDO) {
                $html .= '<meta http-equiv="refresh" content="5">';
            }

            // Status badge
            $html .= '<p><strong>Status:</strong> <span class="label ' . $nfse->status->badgeClass() . '">' . $nfse->status->label() . '</span></p>';

            // Dados da nota
            if ($nfse->numeroNfseNacional) {
                $html .= '<p><strong>Numero NFS-e:</strong> ' . htmlspecialchars($nfse->numeroNfseNacional, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            if ($nfse->chaveAcesso) {
                $html .= '<p><strong>Chave de Acesso:</strong> <small>' . htmlspecialchars($nfse->chaveAcesso, ENT_QUOTES, 'UTF-8') . '</small></p>';
            }
            if ($nfse->dataAutorizacao) {
                $html .= '<p><strong>Autorizacao:</strong> ' . htmlspecialchars($nfse->dataAutorizacao, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            if ($nfse->erro) {
                $html .= '<p class="text-danger"><strong>Erro:</strong> ' . htmlspecialchars($nfse->erro, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            // Botoes de acao
            $html .= '<div style="margin-top:10px;">';

            // Links de download via proxy (exigem mTLS — não usar URLs diretas do governo)
            if ($nfse->isAutorizada()) {
                $dlService = new DownloadUrlService();
                $html .= '<a href="' . htmlspecialchars($dlService->danfseUrl($nfse), ENT_QUOTES, 'UTF-8') . '" target="_blank" class="btn btn-info btn-sm">';
                $html .= '<i class="fas fa-file-pdf"></i> DANFS-e</a> ';

                $html .= '<a href="' . htmlspecialchars($dlService->xmlUrl($nfse), ENT_QUOTES, 'UTF-8') . '" target="_blank" class="btn btn-default btn-sm">';
                $html .= '<i class="fas fa-code"></i> XML</a> ';
            }

            // Reenviar email (todos podem, desde que autorizada)
            if ($nfse->isAutorizada()) {
                $html .= '<a href="' . $baseUrl . '&action=reenviar_email&invoiceid=' . $invoiceId . '&token=' . $token . '" class="btn btn-primary btn-sm">';
                $html .= '<i class="fas fa-envelope"></i> Reenviar Email</a> ';
            }

            // Cancelar (somente com acesso)
            if ($hasAccess && $nfse->podeCancelar()) {
                $cancelUrl = $baseUrl . '&action=cancelar&invoiceid=' . $invoiceId . '&token=' . $token;
                $html .= '<a href="javascript:void(0)" class="btn btn-danger btn-sm"'
                    . ' onclick="if(confirm(\'Confirma o cancelamento da NFS-e #' . $invoiceId . '?\')){window.location.href=\'' . $cancelUrl . '\';}">';
                $html .= '<i class="fas fa-times"></i> Cancelar NFS-e</a> ';
            }

            // Re-emitir se erro (somente com acesso)
            if ($hasAccess && $nfse->status === NfseStatus::ERRO) {
                $emitUrl = $baseUrl . '&action=emitir&invoiceid=' . $invoiceId . '&token=' . $token;
                $html .= '<a href="javascript:void(0)" class="btn btn-warning btn-sm"'
                    . ' onclick="if(confirm(\'Deseja reemitir a NFS-e para a fatura #' . $invoiceId . '?\')){window.location.href=\'' . $emitUrl . '\';}">';
                $html .= '<i class="fas fa-redo"></i> Reemitir</a> ';
            }

            $html .= '</div>';
        }

        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * Verifica se o admin com o roleId informado tem acesso.
     *
     * Se o campo "access" estiver vazio, todos tem acesso.
     * Caso contrario, o roleId deve estar na lista.
     */
    private function checkAccess($roleId): bool
    {
        $access = Capsule::table('tbladdonmodules')
            ->where('module', 'nfsenacional')
            ->where('setting', 'access')
            ->value('value');

        if (empty($access)) {
            return true;
        }

        $allowed = array_map('trim', explode(',', (string) $access));
        return in_array((string) $roleId, $allowed, true);
    }
}
