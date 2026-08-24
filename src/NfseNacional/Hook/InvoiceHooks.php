<?php

namespace GK2\NfseNacional\Hook;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Service\CancelamentoService;
use GK2\NfseNacional\Domain\Service\EmissaoService;
use GK2\NfseNacional\Persistence\NfseRepository;

/**
 * Handlers para hooks de fatura do WHMCS.
 *
 * O AmbienteGuard e inicializado uma vez e compartilhado
 * com todos os services — garantindo que hooks nunca
 * operem em ambiente cruzado.
 */
class InvoiceHooks
{
    private ModuleConfig $config;
    private AmbienteGuard $guard;
    private EmissaoService $emissaoService;
    private CancelamentoService $cancelamentoService;

    public function __construct()
    {
        $this->config = new ModuleConfig();
        $this->guard = AmbienteGuard::getInstance($this->config);
        $this->emissaoService = new EmissaoService($this->config, $this->guard);
        $this->cancelamentoService = new CancelamentoService($this->config, $this->guard);
    }

    /**
     * Hook: InvoiceCreated
     */
    public function onInvoiceCreated(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        $this->tentarEmissao($invoiceId, 'InvoiceCreated');
    }

    /**
     * Hook: InvoicePaid
     */
    public function onInvoicePaid(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        $this->tentarEmissao($invoiceId, 'InvoicePaid');
    }

    /**
     * Hook: InvoiceCancelled
     */
    public function onInvoiceCancelled(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        if (!$this->config->isCancelarComFatura()) {
            return;
        }

        $result = $this->cancelamentoService->cancelar($invoiceId);

        logModuleCall('nfsenacional', '[' . strtoupper($this->guard->value()) . '] Hook-InvoiceCancelled', [
            'invoiceId' => $invoiceId,
        ], $result);
    }

    /**
     * Tenta emitir NFS-e se as condicoes forem atendidas.
     */
    private function tentarEmissao(int $invoiceId, string $hookName): void
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if ($invoice['result'] !== 'success') {
            return;
        }

        if ((float) $invoice['total'] <= 0) {
            return;
        }

        if (($invoice['status'] ?? '') === 'Draft') {
            return;
        }

        // Verificar se ja existe NFS-e emitida (filtrado pelo ambiente ativo)
        $repository = new NfseRepository($this->guard);
        $existente = $repository->findByInvoice($invoiceId);
        if ($existente !== null && $existente->isAutorizada()) {
            return;
        }

        // Verificar politica de emissao com base no hook que disparou
        // (não no status) — evita que FATURA_GERADA emita ao pagar
        $userId = (int) $invoice['userid'];

        if (!$this->emissaoService->deveEmitir($userId, $hookName)) {
            return;
        }

        $result = $this->emissaoService->processarEmissao($invoiceId, $hookName);

        logModuleCall('nfsenacional', '[' . strtoupper($this->guard->value()) . '] Hook-' . $hookName . '-Result', [
            'invoiceId' => $invoiceId,
        ], $result);
    }
}
