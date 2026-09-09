<?php

namespace GK2\NfseNacional\Fiscal\NotaControl;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Enum\Ambiente;
use GK2\NfseNacional\Fiscal\ProviderInterface;
use GK2\NfseNacional\Fiscal\Signer\XmlSigner;
use GK2\NfseNacional\Transport\ApiResponse;
use GK2\NfseNacional\Transport\Auth\CertificateAuth;

/**
 * Provider para emissores baseados na plataforma Nota Control / ISS.net Online.
 *
 * Exemplos de municípios: Ribeirão Preto/SP e outros conveniados.
 *
 * Protocolo: SOAP 1.1 sobre HTTPS com mTLS (certificado de cliente) + XMLDSIG.
 * Namespace: http://www.sped.fazenda.gov.br/nfse (mesmo da Sefin Nacional).
 *
 * Referência: Manual de Integração Webservice v1.01 (Nota Control, ago/2026).
 */
class NotaControlProvider implements ProviderInterface
{
    private ModuleConfig $config;
    private AmbienteGuard $guard;
    private Ambiente $ambiente;
    private CertificateAuth $certificateAuth;

    /**
     * Cliente HTTP injetável (Guzzle). Quando nulo, usa cURL nativo (produção).
     * Permite testar o transporte com MockHandler sem depender de rede.
     */
    private ?\GuzzleHttp\ClientInterface $http;

    /** Cache da URL de visualização consultada (evita SOAP duplicado por requisição). */
    private ?string $cachedDanfseUrl = null;

    private const BASE_URL = 'https://nfse.issnetonline.com.br/wsnfsenacional';

    private const SOAP_ACTION_NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse/';

    private const SOAP_ENV_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const NFSE_NS = 'http://www.sped.fazenda.gov.br/nfse';

    // ─── SOAP Envelope (template estático) ─────────────────────────

    private const SOAP_ENVELOPE = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:nfse="http://www.sped.fazenda.gov.br/nfse">
  <soapenv:Header/>
  <soapenv:Body>
    {body}
  </soapenv:Body>
</soapenv:Envelope>
XML;

    // ─── Construtor ────────────────────────────────────────────────

    public function __construct(
        ?ModuleConfig $config = null,
        ?AmbienteGuard $guard = null,
        ?\GuzzleHttp\ClientInterface $http = null,
    ) {
        $this->config = $config ?? new ModuleConfig();
        $this->guard = $guard ?? AmbienteGuard::getInstance($this->config);
        $this->ambiente = $this->guard->getAmbiente();
        $this->http = $http;
        $this->certificateAuth = new CertificateAuth($this->config);
    }

    // ═══ ProviderInterface ══════════════════════════════════════════

    public function emitirDps(string $dpsXml): ApiResponse
    {
        // $dpsXml é a <DPS> SEM assinatura (DpsPayloadBuilder::buildDpsSemAssinatura).
        // Monta o envelope SOAP completo em um único DOMDocument e assina o
        // <infDPS> já nesse contexto final, para que o C14N inclua os namespaces
        // soapenv/nfse herdados (evita o erro E0714 de digest divergente).
        $envelope = $this->buildEnvelopeAssinado('GerarNfse', $dpsXml);

        return $this->sendEnvelope('GerarNfse', $envelope, function (\DOMDocument $dom): ApiResponse {
            return $this->parseEmitirResposta($dom);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function usaGerarNfseEnvio(): bool
    {
        return true;
    }

    public function consultarNfse(string $chaveAcesso): ApiResponse
    {
        $inner = '<ConsultarNfseDpsEnvio><ChaveAcesso>' . $chaveAcesso . '</ChaveAcesso></ConsultarNfseDpsEnvio>';
        $body = $this->wrapSoapMethod('ConsultarNfseDps', $inner);

        return $this->send('ConsultarNfseDps', $body, function (\DOMDocument $dom): ApiResponse {
            return $this->parseConsultarResposta($dom);
        });
    }

    public function consultarPorProtocolo(string $protocolo): ApiResponse
    {
        $inner = '<ConsultarLoteDpsEnvio><Protocolo>' . $protocolo . '</Protocolo></ConsultarLoteDpsEnvio>';
        $body = $this->wrapSoapMethod('ConsultarLoteDps', $inner);

        return $this->send('ConsultarLoteDps', $body, function (\DOMDocument $dom): ApiResponse {
            return $this->parseConsultarResposta($dom);
        });
    }

    public function cancelar(string $chaveAcesso, string $eventoXml): ApiResponse
    {
        $inner = '<CancelarNfseEnvio>' . $this->stripXmlDeclaration($eventoXml) . '</CancelarNfseEnvio>';
        $body = $this->wrapSoapMethod('CancelarNfse', $inner);

        return $this->send('CancelarNfse', $body, function (\DOMDocument $dom): ApiResponse {
            return $this->parseCancelarResposta($dom);
        });
    }

    public function obterDanfse(string $chaveAcesso): ApiResponse
    {
        $inner = '<ConsultarUrlNfseEnvio><ChaveAcesso>' . $chaveAcesso . '</ChaveAcesso></ConsultarUrlNfseEnvio>';
        $body = $this->wrapSoapMethod('ConsultarUrlNfse', $inner);

        return $this->send('ConsultarUrlNfse', $body, function (\DOMDocument $dom): ApiResponse {
            $url = $this->extractTagValue($dom, 'UrlVisualizacaoNfseNacional');
            if (empty($url)) {
                return ApiResponse::error(['URL DANFSe nao encontrada na resposta.']);
            }
            return ApiResponse::success(['url' => $url]);
        });
    }

    public function obterXml(string $chaveAcesso): ApiResponse
    {
        return $this->consultarNfse($chaveAcesso);
    }

    public function getDanfseUrl(string $chaveAcesso): string
    {
        return $this->consultarUrlVisualizacao($chaveAcesso);
    }

    public function getXmlUrl(string $chaveAcesso): string
    {
        // ConsultarUrlNfse (Nota Control) não devolve URL de XML, apenas a de
        // visualização do DANFS-e (UrlVisualizacaoNfseNacional). O XML autorizado
        // é servido pelo fallback via `xml_retorno` (DownloadController) — ver
        // Tarefa D do handoff. Retorna vazio por design; nunca lança exceção.
        return '';
    }

    // ═══ Transporte SOAP ═══════════════════════════════════════════

    /**
     * Envia uma requisição SOAP e parseia a resposta.
     *
     * @param string $soapAction Nome do método SOAP (ex: 'GerarNfse')
     * @param string $body       XML do corpo (método + cabecMsg + dadosMsg)
     * @param callable $parser   Função que recebe DOMDocument e retorna ApiResponse
     */
    private function send(string $soapAction, string $body, callable $parser): ApiResponse
    {
        $envelope = str_replace('{body}', $body, self::SOAP_ENVELOPE);

        return $this->sendEnvelope($soapAction, $envelope, $parser);
    }

    /**
     * Envia um envelope SOAP já montado (string final) e parseia a resposta.
     */
    private function sendEnvelope(string $soapAction, string $envelope, callable $parser): ApiResponse
    {
        $url = $this->getBaseUrl() . '/nfse.asmx';

        // SOAPAction com namespace, conforme contrato validado (1.2).
        $action = self::SOAP_ACTION_NAMESPACE . $soapAction;

        // Log da requisição completa (envelope SOAP) para diagnóstico
        logModuleCall('nfsenacional', 'NotaControl-' . $soapAction . '-Requisicao', [
            'url' => $url,
            'soap_action' => $action,
        ], mb_substr($envelope, 0, 8000));

        if ($this->http !== null) {
            return $this->sendWithGuzzle($this->http, $url, $action, $envelope, $parser);
        }

        return $this->sendWithCurl($url, $action, $envelope, $parser);
    }

    /**
     * Monta o envelope SOAP completo em um único DOMDocument e assina o
     * <infDPS> já dentro desse contexto final.
     *
     * A assinatura precisa ocorrer com a árvore inteira montada para que o
     * C14N inclusivo inclua os namespaces herdados do nó raiz do SOAP
     * (xmlns:soapenv e xmlns:nfse) — caso contrário o digest diverge do
     * calculado pelo servidor (erro E0714).
     *
     * @param string $method Nome do método SOAP (ex: 'GerarNfse')
     * @param string $dpsXml XML da <DPS> sem assinatura
     * @return string Envelope SOAP completo serializado
     */
    private function buildEnvelopeAssinado(string $method, string $dpsXml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        // <soapenv:Envelope xmlns:soapenv xmlns:nfse>
        $envelope = $dom->createElementNS(self::SOAP_ENV_NS, 'soapenv:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:nfse', self::NFSE_NS);
        $dom->appendChild($envelope);

        $header = $dom->createElementNS(self::SOAP_ENV_NS, 'soapenv:Header');
        $envelope->appendChild($header);

        $body = $dom->createElementNS(self::SOAP_ENV_NS, 'soapenv:Body');
        $envelope->appendChild($body);

        // <nfse:{method}>
        $methodEl = $dom->createElementNS(self::NFSE_NS, 'nfse:' . $method);
        $body->appendChild($methodEl);

        // <nfseCabecMsg> (sem namespace, conforme contrato validado)
        $cabecMsg = $dom->createElement('nfseCabecMsg');
        $methodEl->appendChild($cabecMsg);

        $cabecalho = $dom->createElementNS(self::NFSE_NS, 'cabecalho');
        $cabecalho->setAttribute('versao', '1.01');
        $cabecMsg->appendChild($cabecalho);
        $cabecalho->appendChild($dom->createElementNS(self::NFSE_NS, 'versaoDados', '1.01'));

        // <nfseDadosMsg> (sem namespace)
        $dadosMsg = $dom->createElement('nfseDadosMsg');
        $methodEl->appendChild($dadosMsg);

        // <GerarNfseEnvio> com a <DPS> importada dentro
        $envio = $dom->createElementNS(self::NFSE_NS, 'GerarNfseEnvio');
        $dadosMsg->appendChild($envio);

        $dpsDom = new \DOMDocument('1.0', 'UTF-8');
        $dpsDom->preserveWhiteSpace = true;
        if ($dpsDom->loadXML($dpsXml) === false) {
            throw new \RuntimeException('Falha ao interpretar o XML da DPS (sem assinatura).');
        }
        $envio->appendChild($dom->importNode($dpsDom->documentElement, true));

        // Assina o <infDPS> no contexto completo do envelope SOAP
        $this->signInfDps($dom);

        return $dom->saveXML();
    }

    /**
     * Assina o nó <infDPS> no DOM já montado, se houver certificado configurado.
     */
    private function signInfDps(\DOMDocument $dom): void
    {
        $certPath = $this->config->getCertificadoPath();
        if (empty($certPath)) {
            return; // sem certificado (ex.: testes) — payload segue sem assinatura
        }

        $infDps = $dom->getElementsByTagName('infDPS')->item(0);
        if ($infDps === null) {
            return;
        }

        $signer = new XmlSigner($this->config);
        $signer->signDom($dom, $infDps);
    }

    /**
     * Envia via Guzzle (cliente injetado — usado em testes com MockHandler).
     */
    private function sendWithGuzzle(
        \GuzzleHttp\ClientInterface $http,
        string $url,
        string $action,
        string $envelope,
        callable $parser,
    ): ApiResponse
    {
        try {
            $response = $http->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => $action,
                ],
                'body' => $envelope,
                'verify' => true,
                'http_errors' => false,
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error(['Falha na comunicacao: ' . $e->getMessage()]);
        }

        $rawBody = (string) $response->getBody();
        $httpCode = $response->getStatusCode();

        logModuleCall('nfsenacional', 'NotaControl-' . $action . '-Resposta', [
            'http_code' => $httpCode,
        ], mb_substr($rawBody, 0, 4000));

        if ($httpCode < 200 || $httpCode >= 300) {
            return ApiResponse::error(['HTTP ' . $httpCode . ': ' . mb_substr(strip_tags($rawBody), 0, 300)]);
        }

        return $this->parseSoapBody($rawBody, $parser);
    }

    /**
     * Envia via cURL nativo (produção). Exige mTLS (certificado de cliente,
     * configurado via CURLOPT_SSLCERT/CURLOPT_SSLKEY) + XMLDSIG no documento.
     */
    private function sendWithCurl(string $url, string $action, string $envelope, callable $parser): ApiResponse
    {
        // Certificado de cliente (mTLS) — exigido pela Nota Control
        [$certPem, $keyPem] = $this->certificateAuth->getPemPaths();
        $certPass = $this->config->getCertificadoSenha();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ' . $action,
            ],
            // TODO(debug): bypass temporário de CA para isolar falha de cadeia
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        ]);

        // mTLS: certificado de cliente (autenticação mútua)
        if ($certPem !== null && $keyPem !== null) {
            curl_setopt($ch, CURLOPT_SSLCERT, $certPem);
            curl_setopt($ch, CURLOPT_SSLKEY, $keyPem);
            if (!empty($certPass)) {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
            }
        }

        $rawBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Log da resposta crua + erro interno do cURL para diagnóstico
        logModuleCall('nfsenacional', 'NotaControl-' . $action . '-Resposta', [
            'http_code'  => $httpCode,
            'curl_errno' => $errno,
            'curl_error' => $error,
        ], mb_substr((string) $rawBody, 0, 4000));

        if ($rawBody === false || !empty($error)) {
            return ApiResponse::error(['Falha na comunicacao (curl_errno ' . $errno . '): ' . ($error ?: 'Resposta vazia')]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ApiResponse::error(['HTTP ' . $httpCode . ': ' . mb_substr(strip_tags($rawBody), 0, 300)]);
        }

        return $this->parseSoapBody($rawBody, $parser);
    }

    /**
     * Extrai o corpo da resposta SOAP e aplica o parser.
     */
    private function parseSoapBody(string $rawXml, callable $parser): ApiResponse
    {
        $dom = new \DOMDocument();
        $dom->loadXML($rawXml, LIBXML_NOERROR | LIBXML_NOWARNING);

        // Extrair <soap:Body> → primeiro filho
        $bodyNodes = $dom->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body');
        if ($bodyNodes->length === 0) {
            // Fallback: tentar parse direto (pode vir sem envelope em alguns casos)
            return $parser($dom);
        }

        $bodyNode = $bodyNodes->item(0);

        // Encontrar o PRIMEIRO FILHO ELEMENTO (ignora whitespace/text nodes)
        $responseNode = null;
        foreach ($bodyNode->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $responseNode = $child;
                break;
            }
        }

        // Criar um DOMDocument só com o nó de resposta
        $responseDom = new \DOMDocument();
        if ($responseNode !== null) {
            $imported = $responseDom->importNode($responseNode, true);
            $responseDom->appendChild($imported);
        }

        return $parser($responseDom);
    }

    // ═══ Parsers de resposta ═══════════════════════════════════════

    /**
     * Parseia a resposta de GerarNfse.
     *
     * Sucesso: <GerarNfseResposta><ListaNfse><CompNfse><Nfse>
     * Erro:    <GerarNfseResposta><ListaMensagemRetorno>
     */
    private function parseEmitirResposta(\DOMDocument $dom): ApiResponse
    {
        // Verificar mensagens de erro
        $erros = $this->extractMensagensRetorno($dom);
        if (!empty($erros)) {
            \LogActivity('NFS-e Nacional [NotaControl] Erro emissao: ' . implode('; ', $erros));
            return ApiResponse::error($erros, 200, $dom->saveXML());
        }

        // Extrair chave de acesso do Id do infNFSe
        $infNfseNodes = $dom->getElementsByTagName('infNFSe');
        $chaveAcesso = '';
        if ($infNfseNodes->length > 0) {
            $chaveAcesso = $infNfseNodes->item(0)->getAttribute('Id');
        }

        // Extrair XML completo da NFS-e
        $nfseNodes = $dom->getElementsByTagName('Nfse');
        $nfseXml = '';
        if ($nfseNodes->length > 0) {
            $nfseXml = $dom->saveXML($nfseNodes->item(0));
        }

        if (empty($chaveAcesso)) {
            return ApiResponse::error(['Chave de acesso nao encontrada na resposta.']);
        }

        // GZip + base64 para compatibilidade com ApiResponse do NacionalProvider
        $nfseXmlGZipB64 = base64_encode(gzencode($nfseXml, 9));

        return ApiResponse::success([
            'chaveAcesso'    => $chaveAcesso,
            'nfseXmlGZipB64' => $nfseXmlGZipB64,
        ], 200, $dom->saveXML());
    }

    /**
     * Parseia resposta de consulta (ConsultarNfseDps / ConsultarLoteDps).
     */
    private function parseConsultarResposta(\DOMDocument $dom): ApiResponse
    {
        $erros = $this->extractMensagensRetorno($dom);
        if (!empty($erros)) {
            return ApiResponse::error($erros, 200, $dom->saveXML());
        }

        $nfseXml = '';
        $nfseNodes = $dom->getElementsByTagName('Nfse');
        if ($nfseNodes->length > 0) {
            $nfseXml = $dom->saveXML($nfseNodes->item(0));
        }

        $chaveAcesso = '';
        $infNfseNodes = $dom->getElementsByTagName('infNFSe');
        if ($infNfseNodes->length > 0) {
            $chaveAcesso = $infNfseNodes->item(0)->getAttribute('Id');
        }

        return ApiResponse::success([
            'chaveAcesso'    => $chaveAcesso,
            'nfseXmlGZipB64' => $nfseXml ? base64_encode(gzencode($nfseXml, 9)) : '',
        ], 200, $dom->saveXML());
    }

    /**
     * Parseia resposta de cancelamento (CancelarNfse).
     */
    private function parseCancelarResposta(\DOMDocument $dom): ApiResponse
    {
        $erros = $this->extractMensagensRetorno($dom);
        if (!empty($erros)) {
            return ApiResponse::error($erros, 200, $dom->saveXML());
        }

        return ApiResponse::success(['cancelado' => true], 200, $dom->saveXML());
    }

    // ═══ Helpers ════════════════════════════════════════════════════

    /**
     * Consulta a URL de visualização do DANFS-e via ConsultarUrlNfse.
     *
     * Nunca lança exceção: em falha retorna '' e registra log. Usado por
     * EmissaoService dentro do bloco de sucesso, onde uma exceção indevida
     * transformaria uma emissão AUTORIZADA em ERRO.
     */
    private function consultarUrlVisualizacao(string $chaveAcesso): string
    {
        if ($this->cachedDanfseUrl !== null) {
            return $this->cachedDanfseUrl;
        }

        try {
            $response = $this->obterDanfse($chaveAcesso);
            if ($response->success) {
                $this->cachedDanfseUrl = (string) ($response->data['url'] ?? '');
                return $this->cachedDanfseUrl;
            }

            logModuleCall('nfsenacional', 'NotaControl-ConsultarUrlNfse-Erro', [
                'chave' => $chaveAcesso,
            ], implode('; ', $response->errors));
        } catch (\Throwable $e) {
            logModuleCall('nfsenacional', 'NotaControl-ConsultarUrlNfse-Exception', [
                'chave' => $chaveAcesso,
            ], $e->getMessage());
        }

        $this->cachedDanfseUrl = '';
        return '';
    }

    /**
     * Extrai mensagens de erro do bloco ListaMensagemRetorno.
     *
     * @return string[]
     */
    private function extractMensagensRetorno(\DOMDocument $dom): array
    {
        $erros = [];
        $mensagens = $dom->getElementsByTagName('MensagemRetorno');

        foreach ($mensagens as $msg) {
            $codigo = '';
            $descricao = '';

            foreach ($msg->childNodes as $child) {
                if ($child->nodeName === 'Codigo') {
                    $codigo = trim($child->textContent);
                }
                if ($child->nodeName === 'Descricao') {
                    $descricao = trim($child->textContent);
                }
            }

            $erros[] = ($codigo ? '[' . $codigo . '] ' : '') . ($descricao ?: 'Erro desconhecido');
        }

        return $erros;
    }

    /**
     * Monta o corpo do método SOAP no padrão validado (contrato 1.1):
     *
     * <nfse:{Metodo}>
     *   <nfseCabecMsg><cabecalho versao="1.01">…</cabecalho></nfseCabecMsg>
     *   <nfseDadosMsg>{payload}</nfseDadosMsg>
     * </nfse:{Metodo}>
     *
     * O prefixo nfse: é declarado no envelope raiz (sem redeclarar xmlns aqui).
     */
    private function wrapSoapMethod(string $method, string $payload): string
    {
        return '<nfse:' . $method . '>'
            . '<nfseCabecMsg>'
            . '<cabecalho xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">'
            . '<versaoDados>1.01</versaoDados>'
            . '</cabecalho>'
            . '</nfseCabecMsg>'
            . '<nfseDadosMsg>'
            . $payload
            . '</nfseDadosMsg>'
            . '</nfse:' . $method . '>';
    }

    /**
     * Remove a declaração XML (<?xml ...?>) de um documento.
     *
     * Necessário porque o XML assinado gerado por DOMDocument::saveXML()
     * inclui a declaração, mas quando embutido dentro do corpo SOAP ela
     * gera uma segunda declaração inválida no meio do documento.
     */
    private function stripXmlDeclaration(string $xml): string
    {
        return preg_replace('/^\s*<\?xml[^?]*\?>\s*/i', '', $xml) ?? $xml;
    }

    /**
     * Extrai o valor de uma tag pelo nome local.
     */
    private function extractTagValue(\DOMDocument $dom, string $tagName): string
    {
        $nodes = $dom->getElementsByTagName($tagName);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
    }

    /**
     * Retorna a URL base do serviço conforme ambiente.
     *
     * Produção usa a rota específica do município (nome da cidade configurado
     * no addon, ex: ribeiraopreto); homologação usa a URL genérica informada
     * pela Nota Control.
     */
    private function getBaseUrl(): string
    {
        if ($this->ambiente->isProducao()) {
            $cidade = $this->config->getCidadeNotaControl();
            $suffix = $cidade !== '' ? '/' . $cidade : '';
        } else {
            $suffix = '/homologacao';
        }

        return self::BASE_URL . $suffix;
    }
}
