<?php

namespace GK2\NfseNacional\Hook;

/**
 * Registra todos os hooks do WHMCS para o modulo NFS-e Nacional.
 *
 * Delegado pelo hooks.php do modulo. Cada hook instancia o handler
 * apropriado e delega a execucao.
 */
class HookHandler
{
    /**
     * Registra todos os hooks do modulo.
     */
    public static function register(): void
    {
        // UI na pagina admin da fatura
        add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
            $ui = new AdminInvoiceUI();
            return $ui->render($vars);
        });

        // Emitir ao gerar fatura
        add_hook('InvoiceCreated', 1, function ($vars) {
            logModuleCall('nfsenacional', 'Hook-InvoiceCreated', $vars, '', '', '');
            $hooks = new InvoiceHooks();
            $hooks->onInvoiceCreated($vars);
        });

        // Emitir ao pagar fatura
        add_hook('InvoicePaid', 1, function ($vars) {
            $hooks = new InvoiceHooks();
            $hooks->onInvoicePaid($vars);
        });

        // Cancelar NFS-e ao cancelar fatura
        add_hook('InvoiceCancelled', 1, function ($vars) {
            $hooks = new InvoiceHooks();
            $hooks->onInvoiceCancelled($vars);
        });

        // Ícone NFS-e nas listagens de faturas do admin
        add_hook('AdminAreaFooterOutput', 1, function ($vars) {
            $ui = new AdminInvoiceListUI();
            return $ui->getScript();
        });

        // Ícone/botão NFS-e na área do cliente (head e footer como fallback)
        add_hook('ClientAreaHeadOutput', 1, function ($vars) {
            $ui = new ClientInvoiceListUI();
            return $ui->getScript($vars);
        });

        add_hook('ClientAreaFooterOutput', 1, function ($vars) {
            $ui = new ClientInvoiceListUI();
            return $ui->getScript($vars);
        });

        // Menu na area do cliente
        $clientMenu = new ClientAreaMenu();
        $clientMenu->register();
    }
}
