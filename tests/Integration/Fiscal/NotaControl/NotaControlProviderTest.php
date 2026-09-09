<?php

namespace GK2\NfseNacional\Tests\Integration\Fiscal\NotaControl;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Fiscal\NotaControl\NotaControlProvider;
use GK2\NfseNacional\Tests\Support\FakeModuleConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Testes de integração do NotaControlProvider com Guzzle MockHandler.
 *
 * Simula respostas SOAP no formato validado (contrato 1.1 do handoff) e
 * verifica o envelope da requisição e o SOAPAction. O Guzzle MockHandler não
 * exercita o cURL nativo (produção), onde ocorre a configuração do mTLS.
 */
final class NotaControlProviderTest extends TestCase
{
    private FakeModuleConfig $config;
    private AmbienteGuard $guard;
    private MockHandler $mock;

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        AmbienteGuard::reset();

        $this->config = new FakeModuleConfig(['provedor' => 'notacontrol', 'ambiente' => 'homologacao']);
        $this->guard = new AmbienteGuard($this->config);
        $this->mock = new MockHandler();
        $this->history = [];
    }

    /**
     * Cria o provider com Guzzle injetado (MockHandler + History).
     */
    private function provider(): NotaControlProvider
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $client = new Client(['handler' => $stack]);

        return new NotaControlProvider($this->config, $this->guard, $client);
    }

    /**
     * Monta o envelope SOAP de resposta.
     */
    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>' . $body . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    public function testEmitirDpsSucesso(): void
    {
        $chave = '35201234567890123456789012345678901234567890';

        $this->mock->append(new Response(200, [], $this->envelope(
            '<GerarNfseResposta xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<ListaNfse><CompNfse><Nfse>'
            . '<infNFSe Id="' . $chave . '"><Numero>1</Numero></infNFSe>'
            . '</Nfse></CompNfse></ListaNfse>'
            . '</GerarNfseResposta>'
        )));

        $response = $this->provider()->emitirDps(
            '<?xml version="1.0"?>'
            . '<GerarNfseEnvio xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<DPS versao="1.01"><infDPS Id="' . $chave . '"><conteudo/></infDPS></DPS>'
            . '</GerarNfseEnvio>'
        );

        $this->assertTrue($response->success);
        $this->assertSame($chave, $response->data['chaveAcesso']);
        $this->assertNotEmpty($response->data['nfseXmlGZipB64']);

        $request = $this->history[0]['request'];
        $body = (string) $request->getBody();

        // Envelope no padrão validado: nfseCabecMsg + nfseDadosMsg
        $this->assertStringContainsString('nfseCabecMsg', $body);
        $this->assertStringContainsString('nfseDadosMsg', $body);
        // DPS envelopada por GerarNfseEnvio dentro de nfseDadosMsg
        $this->assertStringContainsString('GerarNfseEnvio', $body);
        $this->assertStringContainsString('<DPS', $body);
        $this->assertStringContainsString('infDPS', $body);

        // SOAPAction com namespace
        $this->assertSame(
            'http://www.sped.fazenda.gov.br/nfse/GerarNfse',
            $request->getHeaderLine('SOAPAction')
        );
    }

    public function testEmitirDpsErroRetornaMensagens(): void
    {
        $this->mock->append(new Response(200, [], $this->envelope(
            '<GerarNfseResposta xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<ListaMensagemRetorno><MensagemRetorno>'
            . '<Codigo>E101</Codigo><Descricao>DPS invalida</Descricao>'
            . '</MensagemRetorno></ListaMensagemRetorno>'
            . '</GerarNfseResposta>'
        )));

        $response = $this->provider()->emitirDps(
            '<?xml version="1.0"?>'
            . '<GerarNfseEnvio xmlns="http://www.sped.fazenda.gov.br/nfse"><DPS/></GerarNfseEnvio>'
        );

        $this->assertFalse($response->success);
        $this->assertNotEmpty($response->errors);
        $this->assertStringContainsString('DPS invalida', $response->errors[0]);
    }

    public function testGetDanfseUrlRetornaUrlConsultarUrlNfse(): void
    {
        $url = 'https://nfse.issnetonline.com.br/visualizar/abc123';

        $this->mock->append(new Response(200, [], $this->envelope(
            '<ConsultarUrlNfseResposta xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<UrlVisualizacaoNfseNacional>' . $url . '</UrlVisualizacaoNfseNacional>'
            . '</ConsultarUrlNfseResposta>'
        )));

        $this->assertSame($url, $this->provider()->getDanfseUrl('35201234567890123456789012345678901234567890'));
    }

    public function testGetDanfseUrlFalhaSoapRetornaVazioSemExcecao(): void
    {
        $this->mock->append(new Response(500, [], 'Internal Server Error'));

        $this->assertSame('', $this->provider()->getDanfseUrl('35201234567890123456789012345678901234567890'));
    }

    public function testCancelarSucesso(): void
    {
        $this->mock->append(new Response(200, [], $this->envelope(
            '<CancelarNfseResposta xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<Cancelamento><Resultado>OK</Resultado></Cancelamento>'
            . '</CancelarNfseResposta>'
        )));

        $response = $this->provider()->cancelar(
            '35201234567890123456789012345678901234567890',
            '<?xml version="1.0"?><pedRegEvento xmlns="urn:x"/>'
        );

        $this->assertTrue($response->success);
        $this->assertTrue($response->data['cancelado']);
    }

    public function testConsultarNfseSucesso(): void
    {
        $chave = '35201234567890123456789012345678901234567890';

        $this->mock->append(new Response(200, [], $this->envelope(
            '<ConsultarNfseDpsResposta xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<Nfse><infNFSe Id="' . $chave . '"><Numero>1</Numero></infNFSe></Nfse>'
            . '</ConsultarNfseDpsResposta>'
        )));

        $response = $this->provider()->consultarNfse($chave);

        $this->assertTrue($response->success);
        $this->assertSame($chave, $response->data['chaveAcesso']);
    }

    public function testConsultarPorProtocoloSucesso(): void
    {
        $this->mock->append(new Response(200, [], $this->envelope(
            '<ConsultarLoteDpsResposta xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<Nfse><infNFSe Id="35201234567890123456789012345678901234567890"><Numero>1</Numero></infNFSe></Nfse>'
            . '</ConsultarLoteDpsResposta>'
        )));

        $response = $this->provider()->consultarPorProtocolo('20260909123456');

        $this->assertTrue($response->success);
        $this->assertNotEmpty($response->data['chaveAcesso']);
    }
}
