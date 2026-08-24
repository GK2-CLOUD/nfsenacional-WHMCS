<?php

namespace GK2\NfseNacional\Domain\Service;

use GK2\NfseNacional\Persistence\NfseRepository;

/**
 * Servico de envio de emails de NFS-e Nacional.
 *
 * Utiliza a Local API do WHMCS para enviar o template "NFS-e Nacional"
 * com as variaveis de nota fiscal preenchidas.
 * Os links de DANFS-e e XML apontam para o endpoint proxy do próprio sistema,
 * garantindo controle de acesso e independência dos URLs do governo.
 */
class EmailService
{
    private NfseRepository $repository;

    public function __construct(?NfseRepository $repository = null)
    {
        $this->repository = $repository ?? new NfseRepository();
    }

    /**
     * Envia email de NFS-e para o cliente da fatura.
     *
     * @param int $invoiceId ID da fatura no WHMCS
     * @return array ['sucesso' => bool, 'msg' => string]
     */
    public function enviar(int $invoiceId): array
    {
        $nfse = $this->repository->findByInvoice($invoiceId);

        if ($nfse === null) {
            return ['sucesso' => false, 'msg' => 'Nenhuma NFS-e encontrada para esta fatura.'];
        }

        $urlService = new DownloadUrlService();
        $danfseUrl  = $urlService->danfseUrl($nfse);
        $xmlUrl     = $urlService->xmlUrl($nfse);

        try {
            $result = localAPI('SendEmail', [
                'messagename' => 'NFS-e Nacional',
                'id'          => $nfse->clientId,
                'customvars'  => base64_encode(serialize([
                    'idNFS'       => $nfse->numeroNfseNacional ?? $nfse->chaveAcesso ?? '',
                    'idFatura'    => $invoiceId,
                    'autorizacao' => $nfse->dataAutorizacao ?? '',
                    'danfse_url'  => $danfseUrl,
                    'xml_url'     => $xmlUrl,
                    'link'        => $danfseUrl,
                    'xml'         => $xmlUrl,
                    'chave_acesso' => $nfse->chaveAcesso ?? '',
                ])),
            ]);

            if ($result['result'] === 'success') {
                logModuleCall('nfsenacional', 'Email-Enviado', [
                    'invoiceId' => $invoiceId,
                ], $result);

                return ['sucesso' => true, 'msg' => 'Email enviado com sucesso.'];
            }

            logModuleCall('nfsenacional', 'Email-Erro', [
                'invoiceId' => $invoiceId,
            ], $result);

            return ['sucesso' => false, 'msg' => 'Erro ao enviar email: ' . ($result['message'] ?? 'Erro desconhecido')];
        } catch (\Exception $e) {
            logModuleCall('nfsenacional', 'Email-Exception', [
                'invoiceId' => $invoiceId,
            ], $e->getMessage());

            return ['sucesso' => false, 'msg' => 'Excecao: ' . $e->getMessage()];
        }
    }
}
