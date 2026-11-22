<?php

namespace GK2\NfseNacional\Domain\Service;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Enum\NfseStatus;
use GK2\NfseNacional\Fiscal\NacionalProvider;
use GK2\NfseNacional\Persistence\NfseRepository;

/**
 * Servico de consulta de status de NFS-e Nacional.
 *
 * Usado pelo cron para verificar DPS pendentes (PROCESSANDO)
 * e atualizar o status quando a API retornar autorizacao.
 *
 * REGRA: so processa pendentes do ambiente ativo.
 * O cron NUNCA consulta notas de um ambiente diferente.
 */
class ConsultaService
{
    private ModuleConfig $config;
    private AmbienteGuard $guard;
    private NfseRepository $repository;
    private NacionalProvider $provider;
    private EmailService $emailService;

    public function __construct(
        ?ModuleConfig $config = null,
        ?AmbienteGuard $guard = null,
        ?NfseRepository $repository = null,
        ?NacionalProvider $provider = null,
        ?EmailService $emailService = null,
    ) {
        $this->config = $config ?? new ModuleConfig();
        $this->guard = $guard ?? AmbienteGuard::getInstance($this->config);
        $this->repository = $repository ?? new NfseRepository($this->guard);
        $this->provider = $provider ?? new NacionalProvider(null, $this->config, null, $this->guard);
        $this->emailService = $emailService ?? new EmailService();
    }

    /**
     * Processa todas as NFS-e com status PROCESSANDO do ambiente ativo.
     *
     * @return int Quantidade de documentos processados com sucesso
     */
    public function processarPendentes(): int
    {
        $ambiente = $this->guard->getAmbiente();

        // findPendentes() ja filtra pelo ambiente ativo via guard
        $pendentes = $this->repository->findPendentes();
        $processados = 0;

        logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Cron-Inicio', [
            'pendentes' => count($pendentes),
        ], []);

        foreach ($pendentes as $nfse) {
            $resultado = $this->consultarUnica($nfse);

            if ($resultado['sucesso']) {
                $processados++;
            }
        }

        logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Cron-Fim', [
            'processados' => $processados,
            'total_pendentes' => count($pendentes),
        ], []);

        return $processados;
    }

    /**
     * Consulta o status de uma NFS-e especifica.
     */
    public function consultarUnica(object $nfse): array
    {
        $ambiente = $this->guard->getAmbiente();
        $invoiceId = $nfse->id_invoice ?? $nfse->invoiceId ?? null;
        $id = $nfse->id ?? null;

        // Validar que o registro pertence ao ambiente ativo
        $ambienteRegistro = $nfse->ambiente ?? null;
        try {
            $this->guard->assertMesmoAmbiente($ambienteRegistro);
        } catch (\Exception $e) {
            logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Consulta-Bloqueada', [
                'id' => $id,
                'ambiente_registro' => $ambienteRegistro,
            ], ['error' => $e->getMessage()]);

            return ['sucesso' => false, 'msg' => $e->getMessage()];
        }

        try {
            $chaveAcesso = $nfse->chave_acesso ?? $nfse->chaveAcesso ?? null;
            $protocolo = $nfse->protocolo ?? null;

            $response = null;

            if (!empty($chaveAcesso)) {
                $response = $this->provider->consultarNfse($chaveAcesso);
            } elseif (!empty($protocolo)) {
                $response = $this->provider->consultarPorProtocolo($protocolo);
            } else {
                return ['sucesso' => false, 'msg' => 'Sem chave de acesso ou protocolo para consulta.'];
            }

            if ($response->success && !empty($response->data)) {
                $updateData = [
                    'status' => NfseStatus::AUTORIZADA->value,
                    'data_autorizacao' => date('Y-m-d H:i:s'),
                ];

                if (!empty($response->data['chaveAcesso'])) {
                    $updateData['chave_acesso'] = $response->data['chaveAcesso'];
                }
                if (!empty($response->data['nfseXmlGZipB64'])) {
                    $updateData['xml_retorno'] = $response->data['nfseXmlGZipB64'];
                }

                $this->repository->updateStatus($id, NfseStatus::AUTORIZADA, $updateData);

                // Enviar email se configurado
                if ($this->config->isEmailHabilitado() && $invoiceId) {
                    $this->emailService->enviar((int) $invoiceId);
                }

                logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Consulta-Autorizada', [
                    'invoiceId' => $invoiceId,
                ], $response->data);

                return ['sucesso' => true, 'msg' => 'NFS-e autorizada.'];
            }

            // Erro
            if (!empty($response->errors)) {
                $erroMsg = implode('; ', $response->errors);
                $this->repository->updateStatus($id, NfseStatus::ERRO, [
                    'erro' => $erroMsg,
                ]);

                return ['sucesso' => false, 'msg' => 'Erro: ' . $erroMsg];
            }

            return ['sucesso' => false, 'msg' => 'Sem resposta da API.'];
        } catch (\Exception $e) {
            logModuleCall('nfsenacional', '[' . strtoupper($ambiente->value) . '] Consulta-Exception', [
                'invoiceId' => $invoiceId,
            ], ['error' => $e->getMessage()], ['trace' => $e->getTraceAsString()]);

            return ['sucesso' => false, 'msg' => 'Excecao: ' . $e->getMessage()];
        }
    }
}
