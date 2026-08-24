<?php
/**
 * Trava os dicionarios de Codigos contra as fontes locais do modulo:
 * os dropdowns de ConfigFields sao a verdade para os dominios @modulo.
 */
$SRC = __DIR__ . '/../../src/NfseNacional/Danfse/';
require $SRC . 'Codigos.php';
use GK2\NfseNacional\Danfse\Codigos;

const CF = __DIR__ . '/../../src/NfseNacional/Admin/ConfigFields.php';

$pass=0; $fail=0; $falhas=[];
function eq(string $w, $got, $want) {
    global $pass,$fail,$falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-46s\n      esperado: %s\n      obtido  : %s", $w, var_export($want,true), var_export($got,true));
}

/** Extrai o dominio de um dropdown de ConfigFields: "1-Foo,2-Bar" => ['1'=>'Foo',...] */
function dominioDoModulo(string $campo): array {
    $src = file_get_contents(CF);
    if (!preg_match("/\\['" . preg_quote($campo,'/') . "'\\](.*?)\\];/s", $src, $bloco)) return [];
    if (!preg_match("/'Options'\s*=>\s*'([^']+)'/", $bloco[1], $m)) return [];
    $out = [];
    foreach (explode(',', $m[1]) as $par) {
        [$cod, $rot] = array_pad(explode('-', $par, 2), 2, '');
        $out[trim($cod)] = trim($rot);
    }
    return $out;
}

/* ── tribISSQN: dominio de exigibilidade_iss ──────────────────────── */
echo "tribISSQN vs ConfigFields['exigibilidade_iss']\n";
$dom = dominioDoModulo('exigibilidade_iss');
eq('dominio tem 7 valores', count($dom), 7);
foreach ($dom as $cod => $rotulo) {
    // 1 sai com o rotulo do DANFS-e oficial, nao com o do dropdown
    $esperado = (string) $cod === '1' ? 'Operação Tributável' : $rotulo;
    eq("tribISSQN($cod)", Codigos::tributacaoIssqn($cod), $esperado);
}

/* ── regEspTrib ───────────────────────────────────────────────────── */
echo "\nregEspTrib vs ConfigFields['reg_esp_trib']\n";
$dom = dominioDoModulo('reg_esp_trib');
eq('dominio tem 7 valores', count($dom), 7);
foreach ($dom as $cod => $rotulo) {
    eq("regEspTrib($cod)", Codigos::regimeEspecial($cod), $rotulo);
}

/* ── regApTribSN: conflito nao resolvido ──────────────────────────── */
echo "\nregApTribSN — conflito de fontes\n";
eq('1 usa o texto do DANFS-e oficial',
    Codigos::regimeApuracaoSn('1'),
    'Regime de apuração dos tributos federais e municipal pelo Simples Nacional');
eq('2 cai no fallback (nao escolhe rotulo)', Codigos::regimeApuracaoSn('2'), '2');

/* ── Fallback: nunca inventa ──────────────────────────────────────── */
echo "\nFallback — codigo sem fonte imprime o codigo\n";
foreach ([
    ['situacao',        '999'],
    ['emitente',        '2'],
    ['finalidade',      '9'],
    ['tributacaoIssqn', '8'],
    ['retencaoIssqn',   '9'],
    ['simplesNacional', '4'],
    ['regimeEspecial',  '9'],
] as [$metodo, $cod]) {
    eq("Codigos::$metodo('$cod')", Codigos::$metodo($cod), $cod);
}

/* ── Nulos ────────────────────────────────────────────────────────── */
echo "\nNulo/vazio\n";
foreach (['situacao','emitente','finalidade','tributacaoIssqn','retencaoIssqn',
          'simplesNacional','regimeApuracaoSn','regimeEspecial','tipoAmbiente'] as $m) {
    eq("$m(null)", Codigos::$m(null), null);
    eq("$m('')",   Codigos::$m(''),   null);
}

/* ── Ambiente: tpAmb x ambGer ─────────────────────────────────────── */
echo "\nAmbiente\n";
eq('tipoAmbiente(1)', Codigos::tipoAmbiente('1'), '1 — Produção');
eq('tipoAmbiente(2)', Codigos::tipoAmbiente('2'), '2 — Homologação');
eq('tipoAmbiente(9) mantem o codigo', Codigos::tipoAmbiente('9'), '9');
eq('isHomologacao(1) = false', Codigos::isHomologacao('1'), false);
eq('isHomologacao(2) = true',  Codigos::isHomologacao('2'), true);
eq('isHomologacao(null) = false', Codigos::isHomologacao(null), false);

/* ── Toda entrada tem fonte declarada ─────────────────────────────── */
echo "\nProcedencia\n";
$src = file_get_contents($SRC . 'Codigos.php');
preg_match_all("/^\s+'[^']+'\s*=>\s*'[^']*',(.*)$/m", $src, $m);
$semFonte = array_values(array_filter($m[1], fn($c) => !str_contains($c,'@conferido') && !str_contains($c,'@modulo')));
eq('nenhuma entrada sem @conferido/@modulo', $semFonte, []);

if ($falhas) echo "\n── FALHAS ──\n".implode("\n",$falhas)."\n";
printf("\n%d passaram, %d falharam\n", $pass, $fail);
exit($fail===0 ? 0 : 1);
