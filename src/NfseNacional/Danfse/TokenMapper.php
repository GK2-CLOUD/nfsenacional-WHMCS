<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Traduz a NFS-e nos 67 tokens {{...}} do template DANFSe-GK2.html.
 *
 * Duas fontes, por decisao registrada no plano (§3.4):
 *
 *   PRESTADOR e valores fiscais  → XML da NFS-e (imutavel, assinado)
 *   TOMADOR                      → cadastro do WHMCS, pela mesma leitura
 *                                  que a emissao usa (TomadorMapper), porque
 *                                  o XML nao traz nome nem UF do municipio
 *
 * A unica excecao no bloco do tomador e o codigo IBGE, que sai do XML:
 * ele e declaracao fiscal validada pela SEFIN contra o CEP, nao cadastro.
 *
 * Campo sem valor sai como travessao — nunca em branco, nunca inventado.
 */
class TokenMapper
{
    /** Inscricao municipal do prestador, quando o CNC nao a devolve no XML. */
    private ?string $imPrestador;

    public function __construct(?string $imPrestador = null)
    {
        $this->imPrestador = $imPrestador !== null && trim($imPrestador) !== ''
            ? trim($imPrestador)
            : null;
    }

    /**
     * @param array $tomador Retorno de TomadorMapper::mapParaExibicao()
     * @return array<string,string> token => valor ja formatado
     */
    public function map(NfseXml $x, array $tomador): array
    {
        return array_merge(
            $this->identificacao($x),
            $this->prestador($x),
            $this->tomador($x, $tomador),
            $this->servico($x),
            $this->issqn($x),
            $this->federal($x),
            $this->ibsCbs($x),
            $this->valores($x),
        );
    }

    /**
     * Fonte unica da verdade para a tarja de homologacao.
     * tpAmb — jamais ambGer (ver Codigos::TP_AMB).
     */
    public function isHomologacao(NfseXml $x): bool
    {
        return Codigos::isHomologacao($x->v(NfseXml::INF_DPS . '/n:tpAmb'));
    }

    // ─────────────────────────────────────────────────────────────────

    private function identificacao(NfseXml $x): array
    {
        return [
            'municipio_emissor' => Formato::juntar(
                ' - ',
                $x->v(NfseXml::INF_NFSE . '/n:xLocEmi'),
                $x->v(NfseXml::EMIT . '/n:enderNac/n:UF')
            ),
            'numero_nfse'        => Formato::texto($x->v(NfseXml::INF_NFSE . '/n:nNFSe')),
            'competencia'        => Formato::data($x->v(NfseXml::INF_DPS . '/n:dCompet')),
            'data_emissao'       => Formato::dataHora($x->v(NfseXml::INF_DPS . '/n:dhEmi')),
            'chave_acesso'       => Formato::chave($x->chaveAcesso(), 4),
            'numero_dps'         => Formato::texto($x->v(NfseXml::INF_DPS . '/n:nDPS')),
            'serie_dps'          => Formato::serie($x->v(NfseXml::INF_DPS . '/n:serie')),
            'situacao'           => Formato::texto(Codigos::situacao($x->v(NfseXml::INF_NFSE . '/n:cStat'))),
            'finalidade'         => Formato::texto(Codigos::finalidade($x->v(NfseXml::INF_DPS . '/n:IBSCBS/n:finNFSe'))),
            'emitente_tipo'      => Formato::texto(Codigos::emitente($x->v(NfseXml::INF_DPS . '/n:tpEmit'))),
            'ambiente'           => Formato::texto(Codigos::tipoAmbiente($x->v(NfseXml::INF_DPS . '/n:tpAmb'))),
            'data_processamento' => Formato::dataHora($x->v(NfseXml::INF_NFSE . '/n:dhProc')),
        ];
    }

    private function prestador(NfseXml $x): array
    {
        $end = NfseXml::EMIT . '/n:enderNac';

        // O CNC frequentemente nao devolve a IM no XML; a config do addon
        // tem o valor que o proprio prestador cadastrou.
        $im = $x->v(NfseXml::EMIT . '/n:IM') ?? $this->imPrestador;

        // regApTribSN so existe para optante do Simples; fora dele, o que
        // descreve o regime e o regEspTrib.
        $regime = Codigos::regimeApuracaoSn($x->v(NfseXml::PREST . '/n:regTrib/n:regApTribSN'))
            ?? Codigos::regimeEspecial($x->v(NfseXml::PREST . '/n:regTrib/n:regEspTrib'));

        return [
            'prestador_razao_social' => Formato::texto($x->v(NfseXml::EMIT . '/n:xNome')),
            'prestador_cnpj'         => Formato::documento(
                $x->first(NfseXml::EMIT . '/n:CNPJ', NfseXml::EMIT . '/n:CPF', NfseXml::EMIT . '/n:NIF')
            ),
            'prestador_im'        => Formato::texto($im),
            'prestador_telefone'  => Formato::telefone($x->v(NfseXml::EMIT . '/n:fone')),
            'prestador_endereco'  => $this->endereco(
                $x->v($end . '/n:xLgr'),
                $x->v($end . '/n:nro'),
                $x->v($end . '/n:xCpl'),
                $x->v($end . '/n:xBairro')
            ),
            'prestador_municipio' => Formato::texto($x->v(NfseXml::INF_NFSE . '/n:xLocEmi')),
            'prestador_uf'        => Formato::texto($x->v($end . '/n:UF')),
            'prestador_ibge'      => Formato::ibge($x->v($end . '/n:cMun')),
            'prestador_cep'       => Formato::cep($x->v($end . '/n:CEP')),
            'prestador_email'     => Formato::texto($x->v(NfseXml::EMIT . '/n:email')),
            'prestador_simples'   => Formato::texto(
                Codigos::simplesNacional($x->v(NfseXml::PREST . '/n:regTrib/n:opSimpNac'))
            ),
            'prestador_regime'    => Formato::texto($regime),
        ];
    }

    private function tomador(NfseXml $x, array $t): array
    {
        return [
            'tomador_razao_social' => Formato::texto($t['razaoSocial'] ?? null),
            'tomador_documento'    => Formato::documento($t['documento'] ?? null),
            'tomador_im'           => Formato::texto($t['inscricaoMunicipal'] ?? null),
            'tomador_telefone'     => Formato::telefone($t['telefone'] ?? null),
            'tomador_endereco'     => $this->endereco(
                $t['logradouro'] ?? null,
                $t['numero'] ?? null,
                $t['complemento'] ?? null,
                $t['bairro'] ?? null
            ),
            'tomador_municipio'    => Formato::texto($t['municipio'] ?? null),
            'tomador_uf'           => Formato::texto(Uf::sigla($t['uf'] ?? null)),
            // Do XML: declaracao fiscal, validada pela SEFIN contra o CEP
            'tomador_ibge'         => Formato::ibge($x->v(NfseXml::TOMA . '/n:end/n:endNac/n:cMun')),
            'tomador_cep'          => Formato::cep($t['cep'] ?? null),
            'tomador_email'        => Formato::texto($t['email'] ?? null),
            'intermediario_info'   => $x->has(NfseXml::INF_DPS . '/n:interm')
                ? Formato::texto($x->v(NfseXml::INF_DPS . '/n:interm/n:xNome'))
                : 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e',
        ];
    }

    private function servico(NfseXml $x): array
    {
        $cServ = NfseXml::SERV . '/n:cServ';

        return [
            'cod_tributacao_nacional' => Formato::juntar(
                ' / ',
                Formato::cTribNac($x->v($cServ . '/n:cTribNac')),
                $x->v($cServ . '/n:cTribMun')
            ),
            'cod_nbs'         => Formato::cNBS($x->v($cServ . '/n:cNBS')),
            'local_prestacao' => Formato::juntar(
                ' / ',
                $x->v(NfseXml::INF_NFSE . '/n:xLocPrestacao'),
                $this->ufDoMunicipio($x, $x->v(NfseXml::SERV . '/n:locPrest/n:cLocPrestacao')),
                null // Pais: so preenchido em prestacao no exterior
            ),
            'descricao_tributacao_nacional' => Formato::texto($x->v(NfseXml::INF_NFSE . '/n:xTribNac')),
            'discriminacao' => Formato::texto($x->v($cServ . '/n:xDescServ')),
        ];
    }

    private function issqn(NfseXml $x): array
    {
        $mun = NfseXml::VAL_DPS . '/n:trib/n:tribMun';

        return [
            'issqn_tipo'      => Formato::texto(Codigos::tributacaoIssqn($x->v($mun . '/n:tribISSQN'))),
            'issqn_municipio' => Formato::juntar(
                ' - ',
                $x->v(NfseXml::INF_NFSE . '/n:xLocIncid'),
                $this->ufDoMunicipio($x, $x->v(NfseXml::INF_NFSE . '/n:cLocIncid'))
            ),
            // Optante do Simples nao destaca ISS: o XML nao traz BC, aliquota
            // nem valor apurado, e o DANFS-e oficial imprime travessao nos tres.
            'issqn_bc'        => Formato::moeda($x->v($mun . '/n:vBC')),
            'issqn_aliquota'  => Formato::percentual($x->v($mun . '/n:pAliq')),
            'issqn_retencao'  => Formato::texto(Codigos::retencaoIssqn($x->v($mun . '/n:tpRetISSQN'))),
            'issqn_apurado'   => Formato::moeda($x->v($mun . '/n:vISSQN')),
        ];
    }

    private function federal(NfseXml $x): array
    {
        $fed = NfseXml::VAL_DPS . '/n:trib/n:tribFed';

        return [
            'irrf'            => Formato::moeda($x->v($fed . '/n:vRetIRRF')),
            'prev_retida'     => Formato::moeda($x->v($fed . '/n:vRetCP')),
            'contrib_retidas' => Formato::moeda($x->v($fed . '/n:vRetCSLL')),
            'pis'             => Formato::moeda($x->v($fed . '/n:vRetPIS')),
            'cofins'          => Formato::moeda($x->v($fed . '/n:vRetCOFINS')),
        ];
    }

    private function ibsCbs(NfseXml $x): array
    {
        $val = NfseXml::IBSCBS . '/n:valores';
        $tot = NfseXml::IBSCBS . '/n:totCIBS';
        $gib = NfseXml::INF_DPS . '/n:IBSCBS/n:valores/n:trib/n:gIBSCBS';

        return [
            'ibs_cst' => Formato::juntar(
                ' / ',
                $x->v($gib . '/n:CST'),
                $x->v($gib . '/n:cClassTrib')
            ),
            'ibs_indicador' => Formato::juntar(
                ' / ',
                $x->v(NfseXml::INF_DPS . '/n:IBSCBS/n:cIndOp'),
                Formato::juntar(
                    ' - ',
                    $x->v(NfseXml::IBSCBS . '/n:xLocalidadeIncid'),
                    $this->ufDoMunicipio($x, $x->v(NfseXml::IBSCBS . '/n:cLocalidadeIncid'))
                )
            ),
            'ibs_exclusoes' => Formato::moeda($x->v($val . '/n:vCalcReeRepRes')),
            'ibs_bc'        => Formato::moeda($x->v($val . '/n:vBC')),
            'ibs_aliquotas' => Formato::juntar(
                ' / ',
                Formato::percentual($x->v($val . '/n:uf/n:pIBSUF')),
                Formato::percentual($x->v($val . '/n:mun/n:pIBSMun'))
            ),
            'cbs_aliquota'  => Formato::percentual($x->v($val . '/n:fed/n:pCBS')),
            'ibs_estadual'  => Formato::moeda($x->v($tot . '/n:gIBS/n:gIBSUFTot/n:vIBSUF')),
            'ibs_municipal' => Formato::moeda($x->v($tot . '/n:gIBS/n:gIBSMunTot/n:vIBSMun')),
            'ibs_total'     => Formato::moeda($x->v($tot . '/n:gIBS/n:vIBSTot')),
            'cbs_total'     => Formato::moeda($x->v($tot . '/n:gCBS/n:vCBS')),
        ];
    }

    private function valores(NfseXml $x): array
    {
        $vsp = NfseXml::VAL_DPS . '/n:vServPrest';
        $tot = NfseXml::IBSCBS . '/n:totCIBS';
        $mun = NfseXml::VAL_DPS . '/n:trib/n:tribMun';
        $fed = NfseXml::VAL_DPS . '/n:trib/n:tribFed';

        $retencoes = Formato::somar(
            $x->v($mun . '/n:vISSQN'),
            $x->v($fed . '/n:vRetIRRF'),
            $x->v($fed . '/n:vRetCP'),
            $x->v($fed . '/n:vRetCSLL'),
            $x->v($fed . '/n:vRetPIS'),
            $x->v($fed . '/n:vRetCOFINS'),
        );

        $ibsCbs = Formato::somar(
            $x->v($tot . '/n:gIBS/n:vIBSTot'),
            $x->v($tot . '/n:gCBS/n:vCBS'),
        );

        return [
            'valor_servico' => Formato::moeda($x->v($vsp . '/n:vServ')),
            'descontos'     => Formato::juntar(
                ' / ',
                Formato::moeda($x->v($vsp . '/n:vDescIncond')),
                Formato::moeda($x->v($vsp . '/n:vDescCond'))
            ),
            'retencoes'     => Formato::moeda($retencoes),
            'ibs_cbs_total' => Formato::moeda($ibsCbs),
            'valor_liquido' => Formato::moeda($x->v(NfseXml::INF_NFSE . '/n:valores/n:vLiq')),
            'info_complementares' => $this->infoComplementares($x),
        ];
    }

    // ─── Auxiliares ──────────────────────────────────────────────────

    /**
     * "AVENIDA XV DE NOVEMBRO, 1058, ZONA 01" — partes vazias somem,
     * em vez de virar travessao no meio do endereco.
     */
    private function endereco(?string ...$partes): string
    {
        $limpo = array_values(array_filter(
            array_map(fn($p) => trim((string) $p), $partes),
            fn($p) => $p !== ''
        ));

        return $limpo === [] ? Formato::TRACO : implode(', ', $limpo);
    }

    /**
     * UF de um municipio pelo codigo IBGE.
     *
     * O XML so carrega UF do emitente. Quando o municipio consultado e o
     * do emitente — o caso de longe mais comum — a UF e conhecida. Fora
     * disso devolvemos null (vira travessao) em vez de chutar: resolver
     * IBGE->UF exigiria a tabela de municipios que o plano descartou.
     */
    private function ufDoMunicipio(NfseXml $x, ?string $cMun): ?string
    {
        if ($cMun === null || trim($cMun) === '') {
            return null;
        }

        $cMunEmit = $x->v(NfseXml::EMIT . '/n:enderNac/n:cMun');

        return trim($cMun) === trim((string) $cMunEmit)
            ? $x->v(NfseXml::EMIT . '/n:enderNac/n:UF')
            : null;
    }

    /**
     * Texto da Lei 12.741/2012, montado — nao vem do XML.
     * Anexa xInfComp da DPS quando o emitente informou algo.
     *
     * Separa com \n, nao com <br>: o TemplateRenderer escapa todo valor
     * como HTML e so depois converte quebras de linha. Um <br> literal
     * aqui sairia impresso como "&lt;br&gt;".
     */
    private function infoComplementares(NfseXml $x): string
    {
        $t = NfseXml::VAL_DPS . '/n:trib/n:totTrib';

        $linhas = [sprintf(
            'Totais aproximados dos Tributos cfe. Lei n° 12.741/2012: Federais: %s; Estaduais: %s; Municipais: %s;',
            Formato::moeda($x->v($t . '/n:vTotTribFed')),
            Formato::moeda($x->v($t . '/n:vTotTribEst')),
            Formato::moeda($x->v($t . '/n:vTotTribMun'))
        )];

        $extra = $x->v(NfseXml::SERV . '/n:infoCompl/n:xInfComp');
        if ($extra !== null) {
            $linhas[] = $extra;
        }

        return implode("\n", $linhas);
    }
}
