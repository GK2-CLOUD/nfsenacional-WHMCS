<?php
/**
 * Fase 2 — render do HTML preenchido.
 */
$SRC = __DIR__ . '/../../src/NfseNacional/Danfse/';
foreach (['NfseXml','Formato','Codigos','Uf','TokenMapper','TemplateRenderer'] as $c) require $SRC.$c.'.php';

use GK2\NfseNacional\Danfse\{NfseXml, TokenMapper, TemplateRenderer};

const XML = __DIR__ . '/fixtures/nfse-597.xml';
const TPL = __DIR__ . '/../../templates/danfse/DANFSe-GK2.html';
define('OUT', sys_get_temp_dir() . '/danfse-render');

$pass=0; $fail=0; $falhas=[];
function eq(string $w, $got, $want) {
    global $pass,$fail,$falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-44s\n      esperado: %s\n      obtido  : %s", $w, var_export($want,true), var_export($got,true));
}
function ok(string $w, bool $c) { eq($w, $c, true); }

$xml = NfseXml::fromXml(file_get_contents(XML));
$tomador = [
    'razaoSocial'=>'DENTAL PRESS ENSINO E PESQUISA LTDA','documento'=>'80898828000148',
    'inscricaoMunicipal'=>'','telefone'=>'4430339816','logradouro'=>'AV DR LUIZ TEIXEIRA MENDES',
    'numero'=>'S/N','complemento'=>'','bairro'=>'ZONA 05','municipio'=>'Maringá','uf'=>'Paraná',
    'cep'=>'87015001','email'=>'webmaster@dentalpress.com.br',
];

const LOGO = __DIR__ . '/../../templates/danfse/gk2-logo.png';

$mapper   = new TokenMapper();
$tokens   = $mapper->map($xml, $tomador);
// A logo e configuravel e o padrao e NAO ter — quem instala o modulo poe a
// sua. Este e o renderer "com logo"; o sem vem na secao 1b.
$renderer = new TemplateRenderer(null, LOGO);

/* ── 1. Producao: sem tarja, sem token sobrando ───────────────────── */
echo "RENDER — PRODUÇÃO (tpAmb=1)\n";
$prod = $renderer->render($tokens, ['se_homologacao' => $mapper->isHomologacao($xml)]);

eq('zero token sobrando', TemplateRenderer::tokensRestantes($prod), []);
ok('sem "{{" em lugar nenhum',        !str_contains($prod, '{{'));
ok('sem tarja SEM VALOR FISCAL',      !str_contains($prod, 'SEM VALOR FISCAL'));
ok('valor liquido presente',           str_contains($prod, 'R$ 65,54'));
ok('chave presente, agrupada de 4',    str_contains($prod, '4115 2002 2143 2213 6000 1220 0000 0000 0597 2608 2129 3423 19'));

/* O condicional de homologacao ja envolveu o cabecalho inteiro uma vez, e
   passou despercebido porque toda asserção aqui era NEGATIVA — verificar
   que a tarja sumiu nao distingue "cabecalho intacto" de "cabecalho
   inteiro sumiu junto". Estas sao positivas de proposito. */
foreach ([
    'logo'                => '<img',
    'titulo DANFS-e'      => 'DANFS-e',
    'subtitulo'           => 'Documento Auxiliar da NFS-e',
    'municipio emissor'   => 'Município:',
    'rotulo do numero'    => 'Número da NFS-e',
    'competencia'         => 'Competência',
    'emissao'             => 'Emissão da NFS-e',
    'regua tri-cor'       => '#F26522',
    'bloco da chave'      => 'Chave de acesso da NFS-e',
] as $oque => $marca) {
    ok("producao mantem: $oque", str_contains($prod, $marca));
}
ok('placeholder da logo resolvido',    !str_contains($prod, '__LOGO__'));
ok('sem marcador de largura sobrando', !str_contains($prod, '__LOGO_W__'));
ok('sem marcador de altura sobrando',  !str_contains($prod, '__LOGO_H__'));

/* ── 1b. Logo configuravel ────────────────────────────────────────── */
echo "\nLOGO\n";
$logo = $renderer->logo();
eq('logo resolvida', $logo['src'] ?? null, LOGO);
// 280x150 na caixa de 142x44: a altura e quem limita (44/150 < 142/280)
eq('altura encaixa na caixa',  $logo['h'], 44);
eq('largura pela proporcao',   $logo['w'], 82);
ok('proporcao preservada', abs($logo['w'] / $logo['h'] - 280 / 150) < 0.02);
ok('nao acusa configuracao invalida', !$renderer->logoConfiguradaInvalida());
ok('img sai com as duas dimensoes',
   str_contains($prod, 'width="82" height="44"'));

$semLogo = new TemplateRenderer();
$semHtml = $semLogo->render($tokens, ['se_homologacao' => false]);
eq('padrao: nenhuma logo',       $semLogo->logo(), null);
ok('padrao nao e configuracao invalida', !$semLogo->logoConfiguradaInvalida());
ok('sem <img> no cabecalho',     !str_contains($semHtml, '<img'));
ok('razao social no lugar dela',  str_contains($semHtml, 'GK2 CLOUD LTDA'));
eq('zero token sobrando sem logo', TemplateRenderer::tokensRestantes($semHtml), []);

/* Caminhos que nao servem caem no mesmo lugar, mas ACUSAM — quem configurou
   precisa saber por que a imagem nao apareceu. */
foreach ([
    'arquivo inexistente' => '/tmp/nao-existe-' . md5('x') . '.png',
    'URL remota'          => 'https://exemplo.com/logo.png',
    'URL local'           => 'file:///etc/hostname',
    'diretorio'           => __DIR__,
    'nao e imagem'        => __FILE__,
] as $caso => $caminho) {
    $r = new TemplateRenderer(null, $caminho);
    eq("rejeita: $caso", $r->logo(), null);
    ok("acusa invalida: $caso", $r->logoConfiguradaInvalida());
}
$vazio = new TemplateRenderer(null, '   ');
eq('so espaco = sem logo', $vazio->logo(), null);
ok('so espaco nao acusa', !$vazio->logoConfiguradaInvalida());

/* ── 2. A armadilha do guia §4: comentario vazando ────────────────── */
echo "\nBLOCO CONDICIONAL — vazamento de comentário\n";
$fora = substr($prod, strpos($prod, '<body>'));
foreach (['RESTRICOES RESPEITADAS','AREAS ESPECIAIS','CAMPOS VARIAVEIS','BLOCO CONDICIONAL'] as $t) {
    ok("texto de comentário nao vazou: $t", !str_contains($fora, $t));
}
eq('nenhum "-->" orfao no body', substr_count($fora, '-->'), substr_count($fora, '<!--'));

/* ── 3. Homologacao: tarja aparece ────────────────────────────────── */
echo "\nRENDER — HOMOLOGAÇÃO\n";
$homolog = $renderer->render($tokens, ['se_homologacao' => true]);
ok('tarja presente', str_contains($homolog, 'SEM VALOR FISCAL'));
eq('zero token sobrando', TemplateRenderer::tokensRestantes($homolog), []);
ok('homologacao e maior que producao', strlen($homolog) > strlen($prod));

/* ── 4. Escape de HTML — texto livre do WHMCS ─────────────────────── */
echo "\nESCAPE\n";
$venenoso = $tokens;
$venenoso['discriminacao'] = 'Plano <b>PRO</b> & suporte "24/7" <script>alert(1)</script>';
$venenoso['tomador_razao_social'] = 'A & B <LTDA>';
$r = $renderer->render($venenoso, ['se_homologacao' => false]);
ok('tag do usuario neutralizada',  !str_contains($r, '<script>'));
ok('script escapado',               str_contains($r, '&lt;script&gt;'));
ok('& virou &amp;',                 str_contains($r, 'A &amp; B &lt;LTDA&gt;'));
ok('<b> do usuario nao virou markup', !str_contains($r, 'Plano <b>PRO</b>'));

/* ── 4b. nobr nas secoes: quebra de pagina nao parte uma secao ────── */
echo "\nQuebra de pagina\n";
$tpl = file_get_contents(TPL);
/* Cada <tr> filho direto da tabela container e uma secao inteira. Sem nobr o
   TCPDF quebra no meio: medido no homolog, com 18 a 20 itens a pagina 2
   abria com a faixa laranja do valor liquido, tendo deixado o rotulo dela na
   pagina 1. Estas asserções sao estruturais — renderizar exige o TCPDF, que
   so existe no servidor. */
preg_match_all('/^  <tr( nobr="true")?>/m', $tpl, $linhas);
eq('11 secoes na tabela container', count($linhas[0]), 11);
eq('todas com nobr', count(array_filter($linhas[1])), 11);
// a 12a linha e a do aviso, que nasce colada no token e nao no comeco da linha
ok('condicional de homologacao tambem',
   str_contains($tpl, '{{#se_homologacao}}<tr nobr="true">'));

/* ── 5. Quebra de linha vira <br> DEPOIS do escape ────────────────── */
$multi = $tokens; $multi['info_complementares'] = "linha 1 & cia\nlinha 2";
$r2 = $renderer->render($multi, ['se_homologacao' => false]);
// nl2br(..., false) INSERE o <br> e mantem o \n original.
ok('nl2br aplicado',        str_contains($r2, "linha 1 &amp; cia<br>\nlinha 2"));
ok('info_compl sem <br> literal escapado', !str_contains($r2, '&lt;br&gt;'));

/* ── 6. Guarda: token faltando explode em vez de imprimir "{{" ────── */
echo "\nGUARDA\n";
$incompleto = $tokens; unset($incompleto['valor_liquido']);
try { $renderer->render($incompleto, ['se_homologacao'=>false]); eq('token faltando lanca', 'passou', 'RuntimeException'); }
catch (\RuntimeException $e) { ok('token faltando lanca', str_contains($e->getMessage(), 'valor_liquido')); }

try { $renderer->render($tokens, []); eq('condicional nao tratada lanca', 'passou', 'RuntimeException'); }
catch (\RuntimeException $e) { ok('condicional nao tratada lanca', str_contains($e->getMessage(), 'se_homologacao')); }

/* ── 7. aplicarCondicional isolado ────────────────────────────────── */
echo "\naplicarCondicional()\n";
$t = 'A{{#x}}MEIO{{/x}}B';
eq('mostrar=true mantem conteudo', TemplateRenderer::aplicarCondicional($t,'x',true),  'AMEIOB');
eq('mostrar=false remove tudo',    TemplateRenderer::aplicarCondicional($t,'x',false), 'AB');
eq('nao deixa comentario',   str_contains(TemplateRenderer::aplicarCondicional($t,'x',false), '<!--'), false);
eq('tag ausente e no-op',    TemplateRenderer::aplicarCondicional($t,'z',false), $t);
eq('dois blocos',            TemplateRenderer::aplicarCondicional('{{#x}}1{{/x}}-{{#x}}2{{/x}}','x',false), '-');

/* ── Saida ────────────────────────────────────────────────────────── */
@mkdir(OUT, 0777, true);
file_put_contents(OUT.'/producao.html', $prod);
file_put_contents(OUT.'/homologacao.html', $homolog);

if ($falhas) echo "\n── FALHAS ──\n".implode("\n",$falhas)."\n";
printf("\n%d passaram, %d falharam\n", $pass, $fail);
printf("HTML gerado em %s (%d KB / %d KB)\n", OUT, strlen($prod)>>10, strlen($homolog)>>10);
exit($fail===0 ? 0 : 1);
