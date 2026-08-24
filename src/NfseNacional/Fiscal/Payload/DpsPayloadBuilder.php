<?php

namespace GK2\NfseNacional\Fiscal\Payload;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Fiscal\Mapper\PrestadorMapper;
use GK2\NfseNacional\Fiscal\Mapper\ServicoMapper;
use GK2\NfseNacional\Fiscal\Mapper\TomadorMapper;
use GK2\NfseNacional\Fiscal\Mapper\TributoMapper;

/**
 * Monta o XML completo da DPS (Declaracao de Prestacao de Servicos)
 * conforme XSD v1.01 da NFS-e Nacional.
 *
 * Namespace: http://www.sped.fazenda.gov.br/nfse
 * Elemento raiz: <DPS versao="1.01">
 *   <infDPS Id="DPS{cnpj}{serie}{nDPS:15}">
 *
 * O XML gerado sera compactado (GZip) e codificado (base64) antes
 * do envio pela NacionalProvider.
 */
class DpsPayloadBuilder
{
    private const NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';
    private const VERSAO = '1.01';

    private ModuleConfig $config;
    private PrestadorMapper $prestadorMapper;
    private TomadorMapper $tomadorMapper;
    private ServicoMapper $servicoMapper;
    private TributoMapper $tributoMapper;

    public function __construct(
        ?ModuleConfig $config = null,
        ?PrestadorMapper $prestadorMapper = null,
        ?TomadorMapper $tomadorMapper = null,
        ?ServicoMapper $servicoMapper = null,
        ?TributoMapper $tributoMapper = null,
    ) {
        $this->config = $config ?? new ModuleConfig();
        $this->prestadorMapper = $prestadorMapper ?? new PrestadorMapper($this->config);
        $this->tomadorMapper = $tomadorMapper ?? new TomadorMapper($this->config);
        $this->servicoMapper = $servicoMapper ?? new ServicoMapper($this->config);
        $this->tributoMapper = $tributoMapper ?? new TributoMapper($this->config);
    }

    /**
     * Monta o XML completo da DPS.
     *
     * @param array $invoice Dados da fatura (retorno de GetInvoice com client)
     * @param int $numeroDps Numero sequencial da DPS
     * @param string $serieDps Serie da DPS
     * @param string $origem Origem da emissao: 'hook', 'cron', 'manual'
     * @return string XML da DPS pronto para compactacao
     */
    public function build(array $invoice, int $numeroDps, string $serieDps, string $origem = 'hook'): string
    {
        $prestador = $this->prestadorMapper->map();
        $tomador = $this->tomadorMapper->map($invoice);
        $servico = $this->servicoMapper->map($invoice);
        $tributos = $this->tributoMapper->map(
            $servico['valorServicos'],
            (int) $invoice['userid'],
        );

        $competencia = $this->getCompetencia($invoice, $origem);
        $cnpj = preg_replace('/\D/', '', $this->config->getCnpjPrestador());
        $codMun = $this->config->getCodigoMunicipioPrestador();
        // tpInscFed: 1=CPF, 2=CNPJ (padrão NFSe Nacional XSD v1.01)
        $tipoInsc = strlen($cnpj) === 14 ? '2' : '1';
        // Inscrição federal: 14 posições (CPF preenche com 000 à esquerda)
        $inscFederal = str_pad($cnpj, 14, '0', STR_PAD_LEFT);
        // Série: 5 posições numéricas (se alfanumérica, converter para hash numérico)
        $serieNum = preg_replace('/\D/', '', $serieDps);
        $serieId = str_pad($serieNum ?: '1', 5, '0', STR_PAD_LEFT);
        // Número DPS: 15 posições
        $numId = str_pad((string) $numeroDps, 15, '0', STR_PAD_LEFT);
        // Preparar valores usados na formação do Id (Id será definido após criar os elementos)
        // DPS + CodMun(7) + tpInscFed(1) + InscFederal(14) + Serie(5) + NumDPS(15) = DPS + 42 dígitos
        $idPieces = [
            'codMun' => $codMun,
            'tipoInsc' => $tipoInsc,
            'inscFederal' => $inscFederal,
            'serieId' => $serieId,
            'numId' => $numId,
        ];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        // <DPS versao="1.01" xmlns="...">
        $dps = $dom->createElementNS(self::NAMESPACE, 'DPS');
        $dps->setAttribute('versao', self::VERSAO);
        $dom->appendChild($dps);

        // <infDPS> (Id será calculado e aplicado mais abaixo, após inserção dos campos)
        $infDPS = $dom->createElement('infDPS');
        $dps->appendChild($infDPS);

        // Campos obrigatorios da TCInfDPS
        $this->addElement($dom, $infDPS, 'tpAmb', $this->config->getAmbiente()->isProducao() ? '1' : '2');
        // dhEmi: 30s de buffer para evitar E0008 (clock skew servidor vs Sefin)
$this->addElement($dom, $infDPS, 'dhEmi', date('Y-m-d\TH:i:sP', time() - 30));
        $this->addElement($dom, $infDPS, 'verAplic', $this->config->getVerAplic());
        // Série deve ser numérica conforme XSD (usar serieId gerada: 5 dígitos numéricos)
        $this->addElement($dom, $infDPS, 'serie', $serieId);
        // nDPS deve ser o número natural sem zeros à esquerda (TSNumDPS exige primeiro dígito 1-9)
        // O Id da DPS é formado usando a versão zero-padded ($numId) mas o elemento nDPS não deve ser padded.
        $this->addElement($dom, $infDPS, 'nDPS', (string) $numeroDps);
        $this->addElement($dom, $infDPS, 'dCompet', $competencia);
        $this->addElement($dom, $infDPS, 'tpEmit', '1'); // 1 = Prestador
        $this->addElement($dom, $infDPS, 'cLocEmi', $this->config->getCodigoMunicipioPrestador());

        // <prest> — TCInfoPrestador
        $this->buildPrestador($dom, $infDPS, $prestador);

        // <toma> — TCInfoPessoa
        $this->buildTomador($dom, $infDPS, $tomador);

        // <serv> — TCServ
        $this->buildServico($dom, $infDPS, $servico);

        // <valores> — TCInfoValores
        $this->buildValores($dom, $infDPS, $servico, $tributos);

        // <IBSCBS> — IBS/CBS Reforma Tributária (obrigatório a partir de 2026 na v1.01)
        $this->buildIBSCBS($dom, $infDPS);

        // Agora que todos os campos obrigatórios foram adicionados, recalcular e aplicar o Id
        // Usar valores realmente colocados nos elementos para evitar divergência
        // (usar o CNPJ do prestador mapeado para formar a inscrição federal)
        $prestCnpj = preg_replace('/\D/', '', $prestador['cnpj'] ?? '');
        $tipoInscFinal = strlen($prestCnpj) === 14 ? '2' : '1';
        $inscFederalFinal = str_pad($prestCnpj, 14, '0', STR_PAD_LEFT);
        // Garantir que numId usado no Id seja corretamente zero-padded para 15 dígitos
        $numIdFinal = str_pad((string) $numeroDps, 15, '0', STR_PAD_LEFT);
        $idDpsFinal = 'DPS' . $idPieces['codMun'] . $tipoInscFinal . $inscFederalFinal . $idPieces['serieId'] . $numIdFinal;
        $infDPS->setAttribute('Id', $idDpsFinal);

        // Assinar XML (se certificado configurado)
        $certPath = $this->config->getCertificadoPath();
        if (!empty($certPath)) {
            $signer = new \GK2\NfseNacional\Fiscal\Signer\XmlSigner($this->config);
            $signer->signDom($dom, $infDPS);
        }

        return $dom->saveXML();
    }

    /**
     * Constroi o bloco <prest> (TCInfoPrestador).
     */
    private function buildPrestador(\DOMDocument $dom, \DOMElement $parent, array $prest): void
    {
        $el = $dom->createElement('prest');
        $parent->appendChild($el);

        $cnpj = preg_replace('/\D/', '', $prest['cnpj'] ?? '');
        if (strlen($cnpj) === 14) {
            $this->addElement($dom, $el, 'CNPJ', $cnpj);
        } else {
            $this->addElement($dom, $el, 'CPF', $cnpj);
        }

        if (!empty($prest['inscricaoMunicipal'])) {
            $this->addElement($dom, $el, 'IM', $prest['inscricaoMunicipal']);
        }

        // <regTrib> — TCRegTrib (obrigatório)
        $regTrib = $dom->createElement('regTrib');
        $el->appendChild($regTrib);

        // opSimpNac: 1=Não Optante, 2=MEI, 3=ME/EPP
        $this->addElement($dom, $regTrib, 'opSimpNac', $prest['opSimpNac'] ?? '1');

        // regApTribSN: obrigatório para MEI (2) e ME/EPP (3); 1=Competência, 2=Caixa
        if (!empty($prest['regApTribSN'])) {
            $this->addElement($dom, $regTrib, 'regApTribSN', $prest['regApTribSN']);
        }

        // regEspTrib: 0=Nenhum (obrigatório)
        $this->addElement($dom, $regTrib, 'regEspTrib', $prest['regEspTrib'] ?? '0');
    }

    /**
     * Constroi o bloco <toma> (TCInfoPessoa).
     */
    private function buildTomador(\DOMDocument $dom, \DOMElement $parent, array $toma): void
    {
        $el = $dom->createElement('toma');
        $parent->appendChild($el);

        $doc = preg_replace('/\D/', '', $toma['documento'] ?? '');
        if (strlen($doc) === 14) {
            $this->addElement($dom, $el, 'CNPJ', $doc);
        } elseif (strlen($doc) === 11) {
            $this->addElement($dom, $el, 'CPF', $doc);
        }

        $this->addElement($dom, $el, 'xNome', $toma['razaoSocial'] ?? '');

        // <end> — TCEndereco
        if (!empty($toma['endereco'])) {
            $this->buildEndereco($dom, $el, $toma['endereco']);
        }

        if (!empty($toma['telefone'])) {
            $this->addElement($dom, $el, 'fone', $toma['telefone']);
        }

        if (!empty($toma['email'])) {
            $this->addElement($dom, $el, 'email', $toma['email']);
        }
    }

    /**
     * Constroi o bloco <end> (TCEndereco) com <endNac>.
     */
    private function buildEndereco(\DOMDocument $dom, \DOMElement $parent, array $end): void
    {
        $elEnd = $dom->createElement('end');
        $parent->appendChild($elEnd);

        // <endNac> — TCEnderNac (cMun + CEP)
        if (!empty($end['cMun']) || !empty($end['cep'])) {
            $endNac = $dom->createElement('endNac');
            $elEnd->appendChild($endNac);

            if (!empty($end['cMun'])) {
                $this->addElement($dom, $endNac, 'cMun', $end['cMun']);
            }
            if (!empty($end['cep'])) {
                $this->addElement($dom, $endNac, 'CEP', $end['cep']);
            }
        }

        // xLgr (obrigatorio no TCEndereco)
        if (!empty($end['logradouro'])) {
            $this->addElement($dom, $elEnd, 'xLgr', $end['logradouro']);
        }

        // nro (obrigatorio)
        $this->addElement($dom, $elEnd, 'nro', $end['numero'] ?? 'S/N');

        // xCpl (opcional)
        if (!empty($end['complemento'])) {
            $this->addElement($dom, $elEnd, 'xCpl', $end['complemento']);
        }

        // xBairro (obrigatorio)
        if (!empty($end['bairro'])) {
            $this->addElement($dom, $elEnd, 'xBairro', $end['bairro']);
        }
    }

    /**
     * Constroi o bloco <serv> (TCServ).
     */
    private function buildServico(\DOMDocument $dom, \DOMElement $parent, array $serv): void
    {
        $el = $dom->createElement('serv');
        $parent->appendChild($el);

        // <locPrest> — TCLocPrest
        $locPrest = $dom->createElement('locPrest');
        $el->appendChild($locPrest);
        $this->addElement($dom, $locPrest, 'cLocPrestacao', $serv['codigoMunicipioIncidencia']);

        // <cServ> — TCCServ
        $cServ = $dom->createElement('cServ');
        $el->appendChild($cServ);

        // cTribNac: 6 dígitos numéricos (Item+Subitem LC 116 + Desdobro Nacional)
        $cTribNac = preg_replace('/\D/', '', $serv['codigoServico']);
        $cTribNac = str_pad($cTribNac, 6, '0', STR_PAD_LEFT);
        $this->addElement($dom, $cServ, 'cTribNac', $cTribNac);

        // cTribMun: exatamente 3 digitos numericos (TCCodTribMun = [0-9]{3}) — opcional
        $cTribMunRaw = preg_replace('/\D/', '', $serv['codigoMunicipal'] ?? '');
        if ($cTribMunRaw !== '') {
            if (strlen($cTribMunRaw) === 3) {
                $this->addElement($dom, $cServ, 'cTribMun', $cTribMunRaw);
            } else {
                // Codigo informado mas com comprimento invalido — omitir e logar para evitar E0314
                logActivity(
                    '[NFS-e Nacional] cTribMun ignorado: "' . $cTribMunRaw . '" nao tem exatamente 3 digitos. '
                    . 'Verifique o Codigo de Tributacao Municipal nas configuracoes do addon.',
                );
            }
        }

        $this->addElement($dom, $cServ, 'xDescServ', $serv['discriminacao']);

        // cNBS: 9 dígitos numéricos (Nomenclatura Brasileira de Serviços) — opcional
        if (!empty($serv['codigoServicoNacional'])) {
            $cNBS = preg_replace('/\D/', '', $serv['codigoServicoNacional']);
            if (!empty($cNBS)) {
                $cNBS = str_pad($cNBS, 9, '0', STR_PAD_RIGHT);
                $this->addElement($dom, $cServ, 'cNBS', $cNBS);
            }
        }
    }

    /**
     * Constroi o bloco <valores> (TCInfoValores).
     */
    private function buildValores(\DOMDocument $dom, \DOMElement $parent, array $serv, array $tributos): void
    {
        $el = $dom->createElement('valores');
        $parent->appendChild($el);

        // <vServPrest> — TCVServPrest
        $vServPrest = $dom->createElement('vServPrest');
        $el->appendChild($vServPrest);
        $this->addElement($dom, $vServPrest, 'vServ', $this->formatDecimal($serv['valorServicos']));

        // <trib> — TCInfoTributacao
        $trib = $dom->createElement('trib');
        $el->appendChild($trib);

        // <tribMun> — TCTribMunicipal
        $tribMun = $dom->createElement('tribMun');
        $trib->appendChild($tribMun);

        $this->addElement($dom, $tribMun, 'tribISSQN', '1'); // 1 = Operação tributável
        $this->addElement($dom, $tribMun, 'tpRetISSQN', $this->getTpRetISSQN($tributos));

        if (!empty($tributos['issqn']['aliquota']) && $tributos['issqn']['aliquota'] > 0) {
            $this->addElement($dom, $tribMun, 'pAliq', $this->formatDecimal($tributos['issqn']['aliquota']));
        }

        // <totTrib> — TCTribTotal
        $totTrib = $dom->createElement('totTrib');
        $trib->appendChild($totTrib);

        if ($this->config->isOptanteSimplesNacional()) {
            // Simples Nacional — usar pTotTribSN (aliquota do SN)
            $aliqSN = $tributos['issqn']['aliquota'] ?? 0;
            $this->addElement($dom, $totTrib, 'pTotTribSN', $this->formatDecimal($aliqSN));
        } else {
            // Não informar valor estimado
            $this->addElement($dom, $totTrib, 'indTotTrib', '0');
        }
    }

    /**
     * Retorna o tipo de retencao do ISSQN conforme XSD.
     * 1 - Não Retido; 2 - Retido pelo Tomador; 3 - Retido pelo Intermediario
     */
    private function getTpRetISSQN(array $tributos): string
    {
        if (!empty($tributos['issqn']['retido']) && $tributos['issqn']['retido']) {
            return '2'; // Retido pelo Tomador
        }
        return '1'; // Não Retido
    }

    /**
     * Determina a data de competencia da nota.
     * Formato XSD: AAAA-MM-DD
     *
     * Regras:
     * - datepaid valida (fatura paga) → usa datepaid
     * - origem 'manual'              → usa data de hoje
     * - demais origens (hook/cron)   → usa date (data de emissao da fatura)
     *
     * Faturas nao pagas tem datepaid = '0000-00-00' no MySQL.
     * Lanca excecao em vez de usar data invalida — preferivel ao erro fiscal.
     *
     * @throws \RuntimeException se a data de competencia nao puder ser determinada
     */
    private function getCompetencia(array $invoice, string $origem = 'hook'): string
    {
        $datepaid = substr($invoice['datepaid'] ?? '', 0, 10);

        if (!empty($datepaid) && $datepaid > '1900-01-01') {
            return $datepaid;
        }

        if ($origem === 'manual') {
            return date('Y-m-d');
        }

        $date = substr($invoice['date'] ?? '', 0, 10);
        if (!empty($date) && $date > '1900-01-01') {
            return $date;
        }

        throw new \RuntimeException(
            'Nao foi possivel determinar a data de competencia da fatura #' .
            ($invoice['invoiceid'] ?? '?') .
            '. Fatura nao paga e sem data de emissao valida.',
        );
    }

    /**
     * Constroi o bloco <IBSCBS> — IBS/CBS da Reforma Tributária.
     *
     * Obrigatório na v1.01 a partir de 2026. Para serviços internos sem
     * exportação, os valores padrão são:
     *   finNFSe  = 0 (Normal)
     *   cIndOp   = configurável (ex: 050101 para serviços de tecnologia)
     *   indDest  = 0 (Operação interna)
     *   CST      = 000 (Tributado normalmente pelo IBS e CBS)
     *   cClassTrib = configurável (ex: 000001)
     */
    private function buildIBSCBS(\DOMDocument $dom, \DOMElement $parent): void
    {
        $cIndOp    = $this->config->get('ibscbs_cind_op', '050101');
        $cst       = $this->config->get('ibscbs_cst', '000');
        $cClassTrib = $this->config->get('ibscbs_cclass_trib', '000001');

        $ibscbs = $dom->createElement('IBSCBS');
        $parent->appendChild($ibscbs);

        $this->addElement($dom, $ibscbs, 'finNFSe', '0');
        $this->addElement($dom, $ibscbs, 'cIndOp', $cIndOp);
        $this->addElement($dom, $ibscbs, 'indDest', '0');

        $valores = $dom->createElement('valores');
        $ibscbs->appendChild($valores);

        $trib = $dom->createElement('trib');
        $valores->appendChild($trib);

        $gIBSCBS = $dom->createElement('gIBSCBS');
        $trib->appendChild($gIBSCBS);

        $this->addElement($dom, $gIBSCBS, 'CST', $cst);
        $this->addElement($dom, $gIBSCBS, 'cClassTrib', $cClassTrib);
    }

    /**
     * Formata valor decimal para o padrao do XML (2 casas, ponto como separador).
     */
    private function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Helper para adicionar um elemento texto ao DOM.
     */
    private function addElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $el = $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($el);
    }
}
