<?php

namespace GK2\NfseNacional\Fiscal;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Enum\Ambiente;
use GK2\NfseNacional\Transport\ApiEndpoints;
use GK2\NfseNacional\Transport\ApiResponse;
use GK2\NfseNacional\Transport\HttpClient;

/**
 * Implementacao do provedor fiscal para a API NFS-e Nacional (ADN).
 *
 * O ambiente e resolvido UMA VEZ via AmbienteGuard e permanece
 * imutavel durante todo o ciclo de vida do provider. Todas as
 * chamadas de API usam este ambiente — nunca se mistura producao
 * com homologacao.
 */
class NacionalProvider implements ProviderInterface
{
    private HttpClient $httpClient;
    private ModuleConfig $config;
    private ApiEndpoints $endpoints;
    private AmbienteGuard $guard;

    /** Ambiente resolvido e congelado no construtor. */
    private Ambiente $ambiente;

    public function __construct(
        ?HttpClient $httpClient = null,
        ?ModuleConfig $config = null,
        ?ApiEndpoints $endpoints = null,
        ?AmbienteGuard $guard = null,
    ) {
        $this->config = $config ?? new ModuleConfig();
        $this->guard = $guard ?? AmbienteGuard::getInstance($this->config);
        $this->endpoints = $endpoints ?? new ApiEndpoints();
        $this->httpClient = $httpClient ?? new HttpClient($this->config);

        // Congelar ambiente — todas as chamadas usam este valor
        $this->ambiente = $this->guard->getAmbiente();
    }

    /**
     * Retorna o ambiente em uso por este provider (para log/auditoria).
     */
    public function getAmbiente(): Ambiente
    {
        return $this->ambiente;
    }

    /**
     * Emite DPS. Recebe XML string, compacta com GZip, codifica base64
     * e envia como {"dpsXmlGZipB64": "..."} conforme Swagger SEFIN Nacional.
     *
     * @param string $dpsXml XML da DPS conforme XSD v1.01
     */
    public function emitirDps(string $dpsXml): ApiResponse
    {
        $endpoint = $this->endpoints->emitirDps($this->ambiente);

        // Compactar XML com GZip e codificar em base64
        $gzipped = gzencode($dpsXml, 9);
        $dpsXmlGZipB64 = base64_encode($gzipped);

        $payload = [
            'dpsXmlGZipB64' => $dpsXmlGZipB64,
        ];

        $response = $this->httpClient->post($endpoint, $payload);

        $this->log('EmitirDps', $dpsXml, $response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function consultarNfse(string $chaveAcesso): ApiResponse
    {
        $endpoint = $this->endpoints->consultarNfseSefin($this->ambiente, $chaveAcesso);

        $response = $this->httpClient->get($endpoint);

        $this->log('ConsultarNfse', ['chave_acesso' => $chaveAcesso], $response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function consultarPorProtocolo(string $protocolo): ApiResponse
    {
        $endpoint = $this->endpoints->consultarPorProtocolo($this->ambiente, $protocolo);

        $response = $this->httpClient->get($endpoint);

        $this->log('ConsultarProtocolo', ['protocolo' => $protocolo], $response);

        return $response;
    }

    /**
     * {@inheritdoc}
     *
     * Compacta o XML do pedRegEvento com GZip, codifica em base64
     * e envia como {"pedRegEventoXmlGZipB64": "..."} — mesmo padrao do emitirDps.
     */
    public function cancelar(string $chaveAcesso, string $eventoXml): ApiResponse
    {
        $endpoint = $this->endpoints->cancelar($this->ambiente, $chaveAcesso);

        $gzipped = gzencode($eventoXml, 9);

        // Campo conforme Swagger SEFIN Nacional: EventosPostRequest.pedidoRegistroEventoXmlGZipB64
        $payload = ['pedidoRegistroEventoXmlGZipB64' => base64_encode($gzipped)];

        $response = $this->httpClient->post($endpoint, $payload);

        $this->log('Cancelar', $eventoXml, $response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function obterDanfse(string $chaveAcesso): ApiResponse
    {
        $endpoint = $this->endpoints->obterDanfse($this->ambiente, $chaveAcesso);

        $response = $this->httpClient->get($endpoint);

        $this->log('ObterDanfse', ['chave_acesso' => $chaveAcesso], $response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function obterXml(string $chaveAcesso): ApiResponse
    {
        $endpoint = $this->endpoints->consultarNfseSefin($this->ambiente, $chaveAcesso);

        $response = $this->httpClient->get($endpoint);

        $this->log('ObterXml', ['chave_acesso' => $chaveAcesso], $response);

        return $response;
    }

    /**
     * Registra log de chamada com indicacao do ambiente.
     */
    private function log(string $action, array|string $request, ApiResponse $response): void
    {
        // Sempre logar o ambiente para auditoria
        $prefix = '[' . strtoupper($this->ambiente->value) . '] Provider-' . $action;

        if ($this->config->isDebug()) {
            logModuleCall(
                'nfsenacional',
                $prefix,
                $request,
                $response->rawBody,
                json_encode($response->data),
            );
        }
    }
}
