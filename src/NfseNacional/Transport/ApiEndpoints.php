<?php

namespace GK2\NfseNacional\Transport;

use GK2\NfseNacional\Domain\Enum\Ambiente;

/**
 * Gerencia as URLs dos endpoints da API NFS-e Nacional.
 *
 * O dominio e determinado unicamente pelo ambiente:
 *   - Producao:    *.nfse.gov.br
 *   - Homologacao: *.producaorestrita.nfse.gov.br
 *
 * Cada servico (SEFIN, ADN, DANFSE, etc.) define apenas seu subdominio e path.
 *
 * Referencia oficial:
 * https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao
 *
 * Swagger docs:
 * - SEFIN:           https://sefin.nfse.gov.br/SefinNacional/docs/index
 * - ADN Contribuintes: https://adn.nfse.gov.br/contribuintes/docs/index.html
 * - ADN DANFSE:      https://adn.nfse.gov.br/danfse/docs/index.html
 * - Parametrizacao:  https://adn.nfse.gov.br/parametrizacao/docs/index.html
 * - CNC:            https://adn.nfse.gov.br/cnc/docs/index.html
 */
class ApiEndpoints
{
    // ─── Dominios por ambiente ─────────────────────────────────────
    private const DOMAIN_SUFFIX = [
        'homologacao' => 'producaorestrita.nfse.gov.br',
        'producao'    => 'nfse.gov.br',
    ];

    // ─── Servicos (subdominio + path) ──────────────────────────────
    private const SERVICOS = [
        'sefin'          => ['subdominio' => 'sefin',  'path' => '/SefinNacional'],
        'contribuintes'  => ['subdominio' => 'adn',    'path' => '/contribuintes'],
        'danfse'         => ['subdominio' => 'adn',    'path' => '/danfse'],
        'parametrizacao' => ['subdominio' => 'adn',    'path' => '/parametrizacao'],
        'cnc'            => ['subdominio' => 'adn',    'path' => '/cnc'],
    ];

    // ═══ URL base centralizada ═════════════════════════════════════

    /**
     * Monta a URL base de qualquer servico a partir do ambiente.
     *
     * Ex: baseUrl('sefin', HOMOLOGACAO)
     *     => https://sefin.producaorestrita.nfse.gov.br/SefinNacional
     *
     * Ex: baseUrl('sefin', PRODUCAO)
     *     => https://sefin.nfse.gov.br/SefinNacional
     */
    public function baseUrl(string $servico, Ambiente $ambiente): string
    {
        $def = self::SERVICOS[$servico]
            ?? throw new \InvalidArgumentException("Servico desconhecido: {$servico}");

        $domain = self::DOMAIN_SUFFIX[$ambiente->value];

        return 'https://' . $def['subdominio'] . '.' . $domain . $def['path'];
    }

    // ═══ Endpoints SEFIN (emissao e eventos) ═══════════════════════

    /** POST /nfse - Recepciona a DPS e gera a NFS-e. */
    public function emitirDps(Ambiente $ambiente): string
    {
        return $this->baseUrl('sefin', $ambiente) . '/nfse';
    }

    /** GET /dps/{id} - Consulta DPS por identificador. */
    public function consultarDps(Ambiente $ambiente, string $dpsId): string
    {
        return $this->baseUrl('sefin', $ambiente) . '/dps/' . urlencode($dpsId);
    }

    /** GET /dps/protocolo/{protocolo} - Consulta por protocolo de envio. */
    public function consultarPorProtocolo(Ambiente $ambiente, string $protocolo): string
    {
        return $this->baseUrl('sefin', $ambiente) . '/dps/protocolo/' . urlencode($protocolo);
    }

    /** POST /nfse/{chaveAcesso}/eventos - Registrar evento (cancelamento, substituicao). */
    public function registrarEvento(Ambiente $ambiente, string $chaveAcesso): string
    {
        return $this->baseUrl('sefin', $ambiente) . '/nfse/' . urlencode($chaveAcesso) . '/eventos';
    }

    /** GET /nfse/{chaveAcesso}/eventos - Listar eventos de uma NFS-e. */
    public function listarEventos(Ambiente $ambiente, string $chaveAcesso): string
    {
        return $this->baseUrl('sefin', $ambiente) . '/nfse/' . urlencode($chaveAcesso) . '/eventos';
    }

    /**
     * GET /nfse/{chaveAcesso}/eventos/{tipoEvento}/{numSeqEvento}
     * Consultar evento por tipo e numero sequencial.
     *
     * tipoEvento (codigos oficiais):
     *   101101 = Cancelamento
     *   101103 = Cancelamento por Substituicao
     *   105102 = Solicitacao Cancelamento Analise Fiscal
     *   105104 = Cancelamento Deferido Analise Fiscal
     *   105105 = Cancelamento Indeferido Analise Fiscal
     *   202201 = Confirmacao Prestador
     *   202205 = Rejeicao Prestador
     *   203202 = Confirmacao Tomador
     *   203206 = Rejeicao Tomador
     *   204203 = Confirmacao Intermediario
     *   204207 = Rejeicao Intermediario
     *   205204 = Confirmacao Tacita
     *   205208 = Anulacao Rejeicao
     *   305101 = Cancelamento por Oficio
     *   305102 = Bloqueio por Oficio
     *   305103 = Desbloqueio por Oficio
     *   467201 = Inclusao NFS-e DAN
     *   907201 = Tributos NFS-e Recolhidos
     */
    public function consultarEvento(
        Ambiente $ambiente,
        string $chaveAcesso,
        int $tipoEvento,
        int $numSeqEvento,
    ): string {
        return $this->baseUrl('sefin', $ambiente)
            . '/nfse/' . urlencode($chaveAcesso)
            . '/eventos/' . $tipoEvento
            . '/' . $numSeqEvento;
    }

    /** GET /nfse/{chaveAcesso} - Consulta NFS-e por chave de acesso (SEFIN). */
    public function consultarNfseSefin(Ambiente $ambiente, string $chaveAcesso): string
    {
        return $this->baseUrl('sefin', $ambiente) . '/nfse/' . urlencode($chaveAcesso);
    }

    // ═══ Endpoints ADN Contribuintes (distribuicao) ════════════════

    /** GET /DFe/{NSU} - Distribuicao de DF-e por NSU (inclui NFS-e, DPS, Eventos). */
    public function distribuicaoDfe(Ambiente $ambiente, int $nsu): string
    {
        return $this->baseUrl('contribuintes', $ambiente) . '/DFe/' . $nsu;
    }

    /** GET /NFSe/{ChaveAcesso}/Eventos - Eventos vinculados a uma NFS-e (ADN). */
    public function consultarNfseEventos(Ambiente $ambiente, string $chaveAcesso): string
    {
        return $this->baseUrl('contribuintes', $ambiente) . '/NFSe/' . urlencode($chaveAcesso) . '/Eventos';
    }

    // ═══ Endpoints ADN DANFSE ══════════════════════════════════════

    /** GET /{chaveAcesso} - Obtencao do DANFS-e (PDF) via API dedicada. */
    public function obterDanfse(Ambiente $ambiente, string $chaveAcesso): string
    {
        return $this->baseUrl('danfse', $ambiente) . '/' . urlencode($chaveAcesso);
    }

    // ═══ Endpoints ADN Parametrizacao Municipal ════════════════════

    /**
     * GET /{codigoMunicipio}/{codigoServico}/{competencia}/aliquota
     * Consulta aliquota ISS parametrizada pelo municipio.
     */
    public function consultarAliquota(
        Ambiente $ambiente,
        int $codigoMunicipio,
        string $codigoServico,
        string $competencia,
    ): string {
        return $this->baseUrl('parametrizacao', $ambiente)
            . '/' . $codigoMunicipio
            . '/' . urlencode($codigoServico)
            . '/' . urlencode($competencia)
            . '/aliquota';
    }

    /**
     * GET /{codigoMunicipio}/{codigoServico}/historicoaliquotas
     * Consulta historico de aliquotas ISS.
     */
    public function consultarHistoricoAliquotas(
        Ambiente $ambiente,
        int $codigoMunicipio,
        string $codigoServico,
    ): string {
        return $this->baseUrl('parametrizacao', $ambiente)
            . '/' . $codigoMunicipio
            . '/' . urlencode($codigoServico)
            . '/historicoaliquotas';
    }

    /**
     * GET /{codigoMunicipio}/convenio
     * Consulta parametros do convenio municipal.
     */
    public function consultarConvenio(Ambiente $ambiente, int $codigoMunicipio): string
    {
        return $this->baseUrl('parametrizacao', $ambiente) . '/' . $codigoMunicipio . '/convenio';
    }

    /**
     * GET /{codigoMunicipio}/{competencia}/retencoes
     * Consulta parametros de retencao ISS do municipio.
     */
    public function consultarRetencoes(
        Ambiente $ambiente,
        int $codigoMunicipio,
        string $competencia,
    ): string {
        return $this->baseUrl('parametrizacao', $ambiente)
            . '/' . $codigoMunicipio
            . '/' . urlencode($competencia)
            . '/retencoes';
    }

    /**
     * GET /{codigoMunicipio}/{codigoServico}/{competencia}/regimes_especiais
     * Consulta regimes especiais de tributacao.
     */
    public function consultarRegimesEspeciais(
        Ambiente $ambiente,
        int $codigoMunicipio,
        string $codigoServico,
        string $competencia,
    ): string {
        return $this->baseUrl('parametrizacao', $ambiente)
            . '/' . $codigoMunicipio
            . '/' . urlencode($codigoServico)
            . '/' . urlencode($competencia)
            . '/regimes_especiais';
    }

    // ═══ Aliases de conveniencia ═══════════════════════════════════

    /** Cancelamento usa registrarEvento internamente (tipoEvento = 101101). */
    public function cancelar(Ambiente $ambiente, string $chaveAcesso): string
    {
        return $this->registrarEvento($ambiente, $chaveAcesso);
    }
}
