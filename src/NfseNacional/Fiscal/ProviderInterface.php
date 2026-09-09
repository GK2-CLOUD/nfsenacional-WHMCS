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
     * Obtem o DANFS-e (documento auxiliar) de uma NFS-e.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return ApiResponse Resposta com URL ou conteudo do DANFS-e
     */
    public function obterDanfse(string $chaveAcesso): ApiResponse;

    /**
     * Obtem o XML autorizado de uma NFS-e.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return ApiResponse Resposta com URL ou conteudo do XML
     */
    public function obterXml(string $chaveAcesso): ApiResponse;

    /**
     * Retorna a URL publica de acesso ao DANFS-e (PDF).
     *
     * Pode consultar o provedor (ex.: Nota Control via ConsultarUrlNfse) ou
     * montar a URL localmente (Sefin). NUNCA deve lancar excecao: e chamado
     * dentro do bloco de sucesso da emissao, e uma excecao indevida marcaria
     * uma NFS-e AUTORIZADA como ERRO.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return string URL completa do DANFS-e (vazia em falha)
     */
    public function getDanfseUrl(string $chaveAcesso): string;

    /**
     * Retorna a URL publica de acesso ao XML autorizado.
     *
     * Pode retornar vazio quando o provedor nao expoe URL de XML (Nota Control
     * so devolve a URL de visualizacao do DANFS-e); nesse caso o download usa
     * o XML autorizado armazenado (xml_retorno). NUNCA deve lancar excecao.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return string URL completa do XML (vazia se indisponivel)
     */
    public function getXmlUrl(string $chaveAcesso): string;
}
