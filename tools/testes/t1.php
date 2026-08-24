<?php
/**
 * Fase 1 — valida os 67 tokens da NFS-e 597 contra o DANFS-e oficial.
 */
$SRC = __DIR__ . '/../../src/NfseNacional/Danfse/';
foreach (['NfseXml', 'Formato', 'Codigos', 'Uf', 'TokenMapper'] as $c) {
    require $SRC . $c . '.php';
}

use GK2\NfseNacional\Danfse\NfseXml;
use GK2\NfseNacional\Danfse\TokenMapper;
use GK2\NfseNacional\Danfse\Uf;
use GK2\NfseNacional\Danfse\Formato;

const TPL = __DIR__ . '/../../templates/danfse/DANFSe-GK2.html';
const XML = __DIR__ . '/fixtures/nfse-597.xml';

$pass = 0; $fail = 0; $falhas = [];
function eq(string $what, $got, $want) {
    global $pass, $fail, $falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-32s\n      esperado: %s\n      obtido  : %s", $what, var_export($want, true), var_export($got, true));
}

$xml = NfseXml::fromXml(file_get_contents(XML));

/* Tomador como o WHMCS devolveria (TomadorMapper::mapParaExibicao).
   Valores conferidos contra o DANFS-e oficial. 'uf' proposital em texto
   livre, para exercitar a normalizacao. */
$tomador = [
    'razaoSocial'        => 'CLIENTE EXEMPLO COMERCIO LTDA',
    'documento'          => '11222333000181',
    'inscricaoMunicipal' => '',
    'telefone'           => '4430000000',
    'logradouro'         => 'RUA DAS FLORES',
    'numero'             => '1000',
    'complemento'        => '',
    'bairro'             => 'CENTRO',
    'municipio'          => 'Maringá',
    'uf'                 => 'Paraná',
    'cep'                => '87010000',
    'email'              => 'contato@exemplo.com.br',
];

$t = (new TokenMapper())->map($xml, $tomador);

/* ── 1. Cobertura: mapper x template, exatamente os mesmos tokens ── */
preg_match_all('/\{\{([a-z_0-9]+)\}\}/', file_get_contents(TPL), $m);
$noTemplate = array_values(array_unique($m[1]));
sort($noTemplate);
$noMapper = array_keys($t); sort($noMapper);

echo "COBERTURA\n";
eq('tokens do template = do mapper', $noMapper, $noTemplate);
$faltando = array_diff($noTemplate, $noMapper);
$sobrando = array_diff($noMapper, $noTemplate);
printf("  template=%d  mapper=%d  faltando=%s  sobrando=%s\n",
    count($noTemplate), count($noMapper),
    $faltando ? implode(',', $faltando) : 'nenhum',
    $sobrando ? implode(',', $sobrando) : 'nenhum');

/* ── 2. Valor a valor, contra o PDF oficial ───────────────────────── */
$esperado = [
    'municipio_emissor'  => 'Maringá - PR',
    'numero_nfse'        => '597',
    'competencia'        => '20/08/2026',
    'data_emissao'       => '20/08/2026 16:21:55',
        // agrupada de 4 em 4, como no mockup; o QR usa os digitos crus
    'chave_acesso'       => '4115 2002 2143 2213 6000 1220 0000 0000 0597 2608 2129 3423 19',
    'numero_dps'         => '631',
    'serie_dps'          => '1',
    'situacao'           => 'NFS-e Gerada',
    'finalidade'         => 'NFS-e regular',
    'emitente_tipo'      => 'Prestador',
    'ambiente'           => '1 — Produção',
    'data_processamento' => '20/08/2026 16:21:55',

    'prestador_razao_social' => 'GK2 CLOUD LTDA',
    'prestador_cnpj'         => '14.322.136/0001-22',
    'prestador_im'           => '—',
    'prestador_telefone'     => '(44) 3255-5530',
    'prestador_endereco'     => 'AVENIDA XV DE NOVEMBRO, 1058 — ZONA 01',  // bairro apos travessao (mockup)
    'prestador_municipio'    => 'Maringá',
    'prestador_uf'           => 'PR',
    'prestador_ibge'         => '4115200',
    'prestador_cep'          => '87013-230',
    'prestador_email'        => 'GK2@GK2.COM.BR',
    'prestador_simples'      => 'Optante - Microempresa ou Empresa de Pequeno Porte',
    'prestador_regime'       => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',

    'tomador_razao_social' => 'CLIENTE EXEMPLO COMERCIO LTDA',
    'tomador_documento'    => '11.222.333/0001-81',
    'tomador_im'           => '—',
    'tomador_telefone'     => '(44) 3000-0000',
    'tomador_endereco'     => 'RUA DAS FLORES, 1000 — CENTRO',
    'tomador_municipio'    => 'Maringá',
    'tomador_uf'           => 'PR',
    'tomador_ibge'         => '4115200',
    'tomador_cep'          => '87010-000',
    'tomador_email'        => 'contato@exemplo.com.br',
    'intermediario_info'   => 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e',

    'cod_tributacao_nacional' => '01.03.02 / —',
    'cod_nbs'                 => '1.1506.10.00',
    'local_prestacao'         => 'Maringá / PR / —',
    'descricao_tributacao_nacional' => 'Armazenamento ou hospedagem de dados, textos, imagens, vídeos, páginas eletrônicas, aplicativos e sistemas de informação, entre outros formatos, e congêneres.',
    'discriminacao'           => 'Renovação de Domínio - brazilianperiodontology.com - 1 Ano(s) (21/08/2026 - 20/08/2027)',

    'issqn_tipo'      => 'Operação Tributável',
    'issqn_municipio' => 'Maringá / PR',   // mockup usa barra
    'issqn_bc'        => '—',
    'issqn_aliquota'  => '—',
    'issqn_retencao'  => 'Não Retido',
    'issqn_apurado'   => '—',

    'irrf' => '—', 'prev_retida' => '—', 'contrib_retidas' => '—',
    'pis'  => '—', 'cofins' => '—',

    'ibs_cst'       => '000 / 000001',
    'ibs_indicador' => '050101 / 4115200 — Maringá/PR',  // mockup inclui o IBGE
    'ibs_exclusoes' => 'R$ 0,00',
    'ibs_bc'        => 'R$ 65,54',
    'ibs_aliquotas' => '0,10% / 0,00%',    // mockup: sem espaco antes do %
    'cbs_aliquota'  => '0,90%',
    'ibs_estadual'  => 'R$ 0,07',
    'ibs_municipal' => 'R$ 0,00',
    'ibs_total'     => 'R$ 0,07',
    'cbs_total'     => 'R$ 0,59',

    'valor_servico' => 'R$ 65,54',
    'descontos'     => '—',                // travessao unico quando nao ha desconto
    'retencoes'     => '—',
    'ibs_cbs_total' => 'R$ 0,66',   // oficial: "Total do IBS/CBS R$ 0,66"
    'valor_liquido' => 'R$ 65,54',
    'info_complementares' => 'Totais aproximados dos Tributos cfe. Lei n° 12.741/2012: Federais: —; Estaduais: —; Municipais: —;',
];

echo "\nVALORES (vs DANFS-e oficial da 597)\n";
foreach ($esperado as $k => $v) {
    eq($k, $t[$k] ?? '<<AUSENTE>>', $v);
}

/* ── 3. Chave: 50 digitos, nao 44 ─────────────────────────────────── */
echo "\nCHAVE DE ACESSO\n";
eq('50 digitos sob a formatacao', strlen(preg_replace('/\D/','',$t['chave_acesso'])), 50);
eq('12 grupos de 4 + 1 de 2',      substr_count($t['chave_acesso'], ' '), 12);
eq('agrupada de 5 fecha certo', substr_count(Formato::chave($xml->chaveAcesso(), 5), ' '), 9);

/* ── 4. tpAmb x ambGer — a armadilha ──────────────────────────────── */
echo "\nAMBIENTE (tpAmb=1 producao, ambGer=2 SEFIN)\n";
$mapper = new TokenMapper();
eq('nota de producao NAO e homologacao', $mapper->isHomologacao($xml), false);
eq('tarja ligada em tpAmb, nao em ambGer', $xml->v(NfseXml::INF_NFSE . '/n:ambGer'), '2');

/* ── 5. Normalizacao de UF ────────────────────────────────────────── */
echo "\nUF\n";
foreach (['Paraná'=>'PR','PARANA'=>'PR','parana '=>'PR','pr'=>'PR','PR'=>'PR',
          'São Paulo'=>'SP','Distrito Federal'=>'DF','Rio Grande do Sul'=>'RS',
          'Nárnia'=>'NÁRNIA'] as $in => $out) {
    eq("Uf::sigla('$in')", Uf::sigla($in), $out);
}
eq("Uf::sigla('')", Uf::sigla(''), null);

/* ── 6. Robustez: XML lixo nao passa silenciosamente ──────────────── */
echo "\nROBUSTEZ\n";
foreach (['<a/>' => 'nao e NFS-e', 'nada' => 'nao e XML'] as $bad => $caso) {
    try { NfseXml::fromXml($bad); eq("rejeita: $caso", 'passou', 'RuntimeException'); }
    catch (\RuntimeException) { $pass++; }
}

/* ── 7. Nenhum token com chave nao substituida ────────────────────── */
echo "\nSANIDADE\n";
$vazios = array_keys(array_filter($t, fn($v) => trim($v) === ''));
eq('nenhum token vazio', $vazios, []);
$comChaves = array_keys(array_filter($t, fn($v) => str_contains($v, '{{')));
eq('nenhum token com {{', $comChaves, []);

/* ── Saida ────────────────────────────────────────────────────────── */
if ($falhas) {
    echo "\n── FALHAS ──\n" . implode("\n", $falhas) . "\n";
}
printf("\n%d passaram, %d falharam\n", $pass, $fail);

if (in_array('--dump', $argv, true)) {
    echo "\n── 67 TOKENS ──\n";
    foreach ($t as $k => $v) printf("  %-32s %s\n", $k, mb_strimwidth($v, 0, 76, '…'));
}
exit($fail === 0 ? 0 : 1);
