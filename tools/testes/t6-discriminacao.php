<?php
/**
 * Fase 6 — os dois niveis da discriminacao do servico.
 *
 * Lado fiscal  : ServicoMapper monta uma linha so, com SEP_ITEM entre os
 *                itens da fatura e SEP_LINHA dentro de cada um.
 * Lado DANFS-e : Formato::discriminacao desfaz isso numa lista.
 *
 * O caso real e a fatura 8540 do homolog: tres itens, cada um com a linha do
 * produto e uma linha de opcao configuravel.
 */

namespace GK2\NfseNacional\Config {
    /** Stub: o ServicoMapper so le configuracao, e nada dela pesa aqui. */
    class ModuleConfig
    {
        public function isExcluirLateFee(): bool { return false; }
        public function isDescontarDesconto(): bool { return false; }
        public function isDescontarCredito(): bool { return false; }
        public function getCodigoServicoNacional(): string { return ''; }
        public function getCodigoServico(): string { return '010302'; }
        public function getCodigoMunicipal(): string { return ''; }
        public function getCnae(): string { return ''; }
        public function getCodigoMunicipioPrestador(): string { return '4115200'; }
    }
}

namespace {

require __DIR__ . '/../../src/NfseNacional/Danfse/Formato.php';
require __DIR__ . '/../../src/NfseNacional/Fiscal/Mapper/ServicoMapper.php';

use GK2\NfseNacional\Danfse\Formato;
use GK2\NfseNacional\Fiscal\Mapper\ServicoMapper;

$pass = 0; $fail = 0; $falhas = [];
function eq(string $what, $got, $want) {
    global $pass, $fail, $falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-44s\n      esperado: %s\n      obtido  : %s",
        $what, var_export($want, true), var_export($got, true));
}
function ok(string $what, bool $cond) { eq($what, $cond, true); }

/* ── 1. Os dois arquivos tem que usar os mesmos separadores ───────── */
echo "SEPARADORES (duplicados de proposito; nao podem divergir)\n";
function sep(string $arquivo, string $const): string {
    preg_match("/const $const\s*=\s*'([^']*)'/", file_get_contents(__DIR__ . $arquivo), $m);
    return $m[1] ?? '<<NAO ACHOU>>';
}
$fiscal = '/../../src/NfseNacional/Fiscal/Mapper/ServicoMapper.php';
$danfse = '/../../src/NfseNacional/Danfse/Formato.php';
eq('SEP_ITEM igual nos dois',  sep($danfse, 'SEP_ITEM'),  sep($fiscal, 'SEP_ITEM'));
eq('SEP_LINHA igual nos dois', sep($danfse, 'SEP_LINHA'), sep($fiscal, 'SEP_LINHA'));
eq('SEP_ITEM e o bullet',  sep($fiscal, 'SEP_ITEM'),  ' • ');
eq('SEP_LINHA e a barra',  sep($fiscal, 'SEP_LINHA'), ' | ');

/* ── 2. Lado fiscal: a fatura 8540, item por item ─────────────────── */
echo "\nServicoMapper (fatura 8540 do homolog)\n";
$fatura = ['invoiceid' => 8540, 'items' => ['item' => [
    ['type' => 'Hosting', 'amount' => '199.00',
     'description' => "Hosting 20GB - testezada1.com.br (24/08/2026 - 23/09/2026)\nrecki shéki jack shan: 0 x blablabla2 R\$ 10,00 "],
    ['type' => 'Hosting', 'amount' => '29.99',
     'description' => "Thunder 1GB - testezada.com.br (24/08/2026 - 23/09/2026)\nrecki shéki jack shan: 0 x blablabla2 R\$ 10,00 "],
    ['type' => 'Hosting', 'amount' => '1890.99',
     'description' => "Hospedagem 400GB - erereerre.com.br (24/08/2026 - 23/09/2026)\nrecki shéki jack shan: 0 x blablabla2 R\$ 10,00"],
]]];
$s = (new ServicoMapper())->map($fatura);
$d = $s['discriminacao'];

// conferido contra a saida real do homolog
eq('xDescServ bate com o do servidor', $d,
   'Hosting 20GB - testezada1.com.br (24/08/2026 - 23/09/2026) - R$ 199,00 | recki shéki jack shan: 0 x blablabla2 R$ 10,00'
 . ' • Thunder 1GB - testezada.com.br (24/08/2026 - 23/09/2026) - R$ 29,99 | recki shéki jack shan: 0 x blablabla2 R$ 10,00'
 . ' • Hospedagem 400GB - erereerre.com.br (24/08/2026 - 23/09/2026) - R$ 1.890,99 | recki shéki jack shan: 0 x blablabla2 R$ 10,00');
eq('valorServicos soma os itens', $s['valorServicos'], 2119.98);
eq('nenhuma quebra de linha (E999)', preg_match('/[\r\n]/', $d), 0);
eq('tres itens', substr_count($d, ' • '), 2);
eq('milhar com ponto, decimal com virgula', str_contains($d, 'R$ 1.890,99'), true);

/* ── 3. Casos de borda do lado fiscal ─────────────────────────────── */
echo "\nBordas do lado fiscal\n";
$um = fn(string $desc, string $val) => (new ServicoMapper())->map(
    ['invoiceid' => 1, 'items' => ['item' => [['type' => 'Hosting', 'amount' => $val, 'description' => $desc]]]]
)['discriminacao'];
eq('item de uma linha so',        $um('Plano PRO', '10.00'), 'Plano PRO - R$ 10,00');
eq('tres linhas viram duas |',    substr_count($um("A\nB\nC", '1.00'), ' | '), 2);
eq('linha vazia no meio some',    $um("A\n\nB", '1.00'), 'A - R$ 1,00 | B');
eq('HTML sai da descricao',       $um('<b>Plano</b> PRO', '1.00'), 'Plano PRO - R$ 1,00');
eq('descricao vazia nao vira item', $um('', '1.00'),
   'Servicos de tecnologia - Fatura #1');
eq('item de valor zero sai fora', (new ServicoMapper())->map(
    ['invoiceid' => 1, 'items' => ['item' => [
        ['type' => 'Hosting', 'amount' => '0.00', 'description' => 'Brinde'],
        ['type' => 'Hosting', 'amount' => '5.00', 'description' => 'Plano'],
    ]]])['discriminacao'], 'Plano - R$ 5,00');

/* ── 4. Lado DANFS-e: remontar os dois niveis ─────────────────────── */
echo "\nFormato::discriminacao\n";
$lista = explode("\n", Formato::discriminacao($d));
eq('6 linhas: 3 itens x (produto + detalhe)', count($lista), 6);
ok('item comeca com marcador',     str_starts_with($lista[0], '• '));
ok('detalhe nao tem marcador',     !str_starts_with($lista[1], '• '));
ok('detalhe recuado com espaco duro', str_starts_with($lista[1], "\u{00A0}"));
eq('valor do item na linha do produto',
   $lista[0], '• Hosting 20GB - testezada1.com.br (24/08/2026 - 23/09/2026) - R$ 199,00');
eq('terceiro item',    $lista[4], '• Hospedagem 400GB - erereerre.com.br (24/08/2026 - 23/09/2026) - R$ 1.890,99');
ok('nenhum separador sobrou',      !str_contains(Formato::discriminacao($d), ' • '));
ok('nenhuma barra sobrou',         !str_contains(Formato::discriminacao($d), ' | '));

/* ── 5. Notas antigas: sem SEP_ITEM, nao inventar hierarquia ──────── */
echo "\nCompatibilidade com nota ja emitida\n";
$legado = 'Renovação de Domínio - x.com | Hosting 20GB - y.com | outra coisa';
$velho = explode("\n", Formato::discriminacao($legado));
eq('uma linha por pedaco', count($velho), 3);
ok('sem marcador de item',  !str_contains(Formato::discriminacao($legado), '• '));
ok('sem recuo',             !str_contains(Formato::discriminacao($legado), "\u{00A0}"));
eq('conteudo intacto', $velho[0], 'Renovação de Domínio - x.com');

/* ── 6. Vazio e item unico ────────────────────────────────────────── */
echo "\nBordas do lado DANFS-e\n";
eq('vazio vira travessao',  Formato::discriminacao(''),   '—');
eq('null vira travessao',   Formato::discriminacao(null), '—');
eq('so espaco vira travessao', Formato::discriminacao("   "), '—');
eq('item unico sem detalhe fica cru', Formato::discriminacao('Plano PRO - R$ 10,00'),
   'Plano PRO - R$ 10,00');
eq('dois itens sem detalhe', Formato::discriminacao('A - R$ 1,00 • B - R$ 2,00'),
   "• A - R$ 1,00\n• B - R$ 2,00");

if ($falhas) { echo "\n── FALHAS ──\n" . implode("\n", $falhas) . "\n"; }
printf("\n%d passaram, %d falharam\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

}
