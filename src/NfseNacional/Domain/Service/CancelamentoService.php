<?php

namespace GK2\NfseNacional\Domain\Service;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\AmbienteMismatchException;
use GK2\NfseNacional\Domain\Enum\NfseStatus;
use GK2\NfseNacional\Fiscal\NacionalProvider;
use GK2\NfseNacional\Fiscal\Payload\EventoPayloadBuilder;
use GK2\NfseNacional\Persistence\NfseRepository;

/**
 * Orquestra o cancelamento de NFS-e Nacional via evento.
 *
 * Valida que a nota sendo cancelada pertence ao ambiente ativo.
 * Nunca cancela uma nota de producao quando o modulo esta em homologacao.
 */
class CancelamentoService
{
    private ModuleConfig $config;
    private AmbienteGuard $guard;
    private NfseRepository $repository;
    private NacionalProvider $provider;
    private EventoPayloadBuilder $eventoBuilder;

    public function __construct(
        ?ModuleConfig $config = null,
        ?AmbienteGuard $guard = null,
        ?NfseRepository $repository = null,
        ?NacionalProvider $provider = null,
        ?EventoPayloadBuilder $eventoBuilder = null,
    ) {
        $this->config = $config ?? new ModuleConfig();
        $this->guard = $guard ?? AmbienteGuard::getInstance($this->config);
        $this->repository = $repository ?? new NfseRepository($this->guard);
        $this->provider = $provider ?? new NacionalProvider(null, $this->config, null, $this->guard);
        $this->eventoBuilder = $eventoBuilder ?? new EventoPayloadBuilder();
    }

    /**
     * Cancela uma NFS-e Nacional.
     *
     * @param int $invoiceId ID da fatura no WHMCS
     * @param string $motivo Motivo do cancelamento
     * @return array ['sucesso' => bool, 'msg' => string]
     */
    public function cancelar(int $invoiceId, string $motivo = ''): array
    {
        $ambiente = $this->guard->getAmbiente();

        // 1. Buscar registro existente (ja filtrado pelo ambiente ativo)
        $nfse = $this->repository->findByInvoice($invoiceId);

        if ($nfse === null) {
            return ['sucesso' => false, 'msg' => 'Nenhuma NFS-e encontrada para esta fatura no ambiente ' . $ambiente->label() . '.'];
        }

        // 2. Cross-check explícito: bloquear se ambientes divergem
        try {
            $nfse->assertAmbiente($this->guard);
        } catch (AmbienteMismatchException $e) {
            logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Cancelamento-Bloqueado', [
                'invoiceId' => $invoiceId,
                'ambiente_nota' => $nfse->ambiente,
                'ambiente_ativo' => $ambiente->value,
            ], $e->getMessage());

            return ['sucesso' => false, 'msg' => $e->getMessage()];
        }

        if (!$nfse->podeCancelar()) {
            return [
                'sucesso' => false,
                'msg' => 'NFS-e nao pode ser cancelada. Status atual: ' . $nfse->status->label(),
            ];
        }

        if (empty($nfse->chaveAcesso)) {
            return ['sucesso' => false, 'msg' => 'Chave de acesso nao disponivel para cancelamento.'];
        }

        try {
            // 3. Montar XML assinado do evento de cancelamento (pedRegEvento e101101)
            $eventoXml = $this->eventoBuilder->buildCancelamento(
                $nfse->chaveAcesso,
                EventoPayloadBuilder::MOTIVO_ERRO_EMISSAO,
                $motivo,
            );

            // 4. Enviar para API Nacional (provider travado no mesmo ambiente)
            $response = $this->provider->cancelar($nfse->chaveAcesso, $eventoXml);

            if ($response->success) {
                $this->repository->updateStatus($nfse->id, NfseStatus::CANCELADA, [
                    'erro' => null,
                ]);

                logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Cancelamento-Sucesso', [
                    'invoiceId' => $invoiceId,
                    'chaveAcesso' => $nfse->chaveAcesso,
                ], $response->data);

                return ['sucesso' => true, 'msg' => 'NFS-e cancelada com sucesso.'];
            }

            // Verificar se ja estava cancelada (idempotencia)
            foreach ($response->errors as $erro) {
                if (stripos($erro, 'cancelad') !== false) {
                    $this->repository->updateStatus($nfse->id, NfseStatus::CANCELADA);
                    return ['sucesso' => true, 'msg' => 'NFS-e ja estava cancelada.'];
                }
            }

            $erroMsg = implode('; ', $response->errors);

            logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Cancelamento-Erro', [
                'invoiceId' => $invoiceId,
                'chaveAcesso' => $nfse->chaveAcesso,
            ], $response->rawBody);

            return ['sucesso' => false, 'msg' => 'Erro no cancelamento: ' . $erroMsg];
        } catch (\Exception $e) {
            logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Cancelamento-Exception', [
                'invoiceId' => $invoiceId,
            ], $e->getMessage(), $e->getTraceAsString());

            return ['sucesso' => false, 'msg' => 'Excecao: ' . $e->getMessage()];
        }
    }
}
