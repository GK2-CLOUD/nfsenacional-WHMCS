<?php

namespace GK2\NfseNacional\Fiscal;

use GK2\NfseNacional\Transport\ApiResponse;

/**
 * Contrato para provedores fiscais de NFS-e.
 *
 * Define as operacoes que qualquer provedor fiscal deve implementar.
 * Permite trocar o backend (Nacional, Municipal, etc.) sem alterar
 * a logica de dominio.
 */
interface ProviderInterface
{
    /**
     * Emite uma DPS (Declaracao de Prestacao de Servicos).
     *
     * @param string $dpsXml XML da DPS conforme XSD da NFS-e Nacional
     * @return ApiResponse Resposta da API com dados da NFS-e emitida
     */
    public function emitirDps(string $dpsXml): ApiResponse;

    /**
     * Consulta uma NFS-e pela chave de acesso.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return ApiResponse Resposta com dados do documento
     */
    public function consultarNfse(string $chaveAcesso): ApiResponse;

    /**
     * Consulta o status de processamento por protocolo.
     *
     * @param string $protocolo Protocolo de envio
     * @return ApiResponse Resposta com status do processamento
     */
    public function consultarPorProtocolo(string $protocolo): ApiResponse;

    /**
     * Cancela uma NFS-e enviando o XML assinado do pedRegEvento (e101101).
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string $eventoXml   XML assinado do pedRegEvento (gerado por EventoPayloadBuilder)
     * @return ApiResponse Resposta com resultado do cancelamento
     */
    public function cancelar(string $chaveAcesso, string $eventoXml): ApiResponse;

    /**
     * Obtem o XML autorizado de uma NFS-e.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return ApiResponse Resposta com URL ou conteudo do XML
     */
    public function obterXml(string $chaveAcesso): ApiResponse;
}
