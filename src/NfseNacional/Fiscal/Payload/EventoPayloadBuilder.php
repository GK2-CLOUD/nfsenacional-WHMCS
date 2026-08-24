<?php

namespace GK2\NfseNacional\Fiscal\Payload;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Fiscal\Signer\XmlSigner;

/**
 * Monta o XML assinado do Pedido de Registro de Evento (pedRegEvento)
 * conforme leiaute NFS-e Nacional v1.01 (PEDREGEV/EVT).
 *
 * Estrutura do pedRegEvento de cancelamento (e101101):
 *
 *   <pedRegEvento versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">
 *     <infPedReg Id="PRE{chaveNFSe(50)}{codEvento(6)}">
 *       <tpAmb>1|2</tpAmb>
 *       <verAplic>...</verAplic>
 *       <dhEvento>AAAA-MM-DDThh:mm:ss-03:00</dhEvento>
 *       <CNPJAutor>14 digitos</CNPJAutor>   <!-- ou <CPFAutor> 11 digitos -->
 *       <chNFSe>50 digitos</chNFSe>
 *       <e101101>
 *         <xDesc>Cancelamento de NFS-e</xDesc>
 *         <cMotivo>1</cMotivo>
 *         <xMotivo>Descricao (15-255 chars)</xMotivo>
 *       </e101101>
 *     </infPedReg>
 *     <Signature>...</Signature>   <!-- assinatura digital obrigatoria (E1989) -->
 *   </pedRegEvento>
 *
 * Id do PRE: "PRE" + chaveNFSe(50) + codEvento(6) = 59 caracteres totais.
 * codEvento do cancelamento: 101101 (Cat=1, Autor=01, Amb=1, Seq=01).
 */
class EventoPayloadBuilder
{
    private const NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';
    private const VERSAO    = '1.01';

    /** cMotivo para cancelamento: 1=Erro na Emissão, 2=Serviço não Prestado, 9=Outros */
    public const MOTIVO_ERRO_EMISSAO        = 1;
    public const MOTIVO_SERVICO_NAO_PRESTADO = 2;
    public const MOTIVO_OUTROS              = 9;

    private ModuleConfig $config;
    private XmlSigner    $signer;

    public function __construct(?ModuleConfig $config = null, ?XmlSigner $signer = null)
    {
        $this->config = $config ?? new ModuleConfig();
        $this->signer = $signer ?? new XmlSigner($this->config);
    }

    /**
     * Monta o XML assinado do evento de cancelamento (e101101).
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e (50 digitos numericos)
     * @param int    $cMotivo     1=Erro na Emissão, 2=Serviço não Prestado, 9=Outros
     * @param string $xMotivo     Descricao do motivo (15-255 chars). Obrigatoria quando cMotivo=9.
     * @return string XML do pedRegEvento assinado, pronto para compactacao/envio
     */
    public function buildCancelamento(
        string $chaveAcesso,
        int    $cMotivo = self::MOTIVO_ERRO_EMISSAO,
        string $xMotivo = '',
    ): string {
        if (empty($xMotivo)) {
            $xMotivo = match ($cMotivo) {
                self::MOTIVO_SERVICO_NAO_PRESTADO => 'Servico nao prestado.',
                self::MOTIVO_OUTROS               => 'Cancelamento a pedido do emitente.',
                default                            => 'Erro na emissao da NFS-e.',
            };
        }

        // XSD exige tamanho minimo de 15 chars em xMotivo
        if (strlen($xMotivo) < 15) {
            $xMotivo = str_pad($xMotivo, 15);
        }

        $codEvento = '101101';
        $preId     = 'PRE' . $chaveAcesso . $codEvento; // 3 + 50 + 6 = 59 chars
        $tpAmb     = $this->config->getAmbiente()->isProducao() ? '1' : '2';
        $cnpj      = preg_replace('/\D/', '', $this->config->getCnpjPrestador());
        $dhEvento  = date('Y-m-d\TH:i:sP');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        // <pedRegEvento versao="1.01" xmlns="...">
        $pedRegEvento = $dom->createElementNS(self::NAMESPACE, 'pedRegEvento');
        $pedRegEvento->setAttribute('versao', self::VERSAO);
        $dom->appendChild($pedRegEvento);

        // <infPedReg Id="PRE...">
        $infPedReg = $dom->createElement('infPedReg');
        $infPedReg->setAttribute('Id', $preId);
        $pedRegEvento->appendChild($infPedReg);

        $this->addText($dom, $infPedReg, 'tpAmb', $tpAmb);
        $this->addText($dom, $infPedReg, 'verAplic', 'WHMCS-NfseNac-1.0');
        $this->addText($dom, $infPedReg, 'dhEvento', $dhEvento);

        // CNPJAutor ou CPFAutor — choice element (mutuamente exclusivos)
        if (strlen($cnpj) === 14) {
            $this->addText($dom, $infPedReg, 'CNPJAutor', $cnpj);
        } else {
            $this->addText($dom, $infPedReg, 'CPFAutor', str_pad($cnpj, 11, '0', STR_PAD_LEFT));
        }

        $this->addText($dom, $infPedReg, 'chNFSe', $chaveAcesso);

        // <e101101> — parte especifica do cancelamento
        $e101101 = $dom->createElement('e101101');
        $infPedReg->appendChild($e101101);
        $this->addText($dom, $e101101, 'xDesc', 'Cancelamento de NFS-e');
        $this->addText($dom, $e101101, 'cMotivo', (string) $cMotivo);
        $this->addText($dom, $e101101, 'xMotivo', $xMotivo);

        // Assinar <infPedReg> com certificado digital (assinatura obrigatoria - E1989)
        $this->signer->signDom($dom, $infPedReg);

        return $dom->saveXML();
    }

    private function addText(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
    }
}
