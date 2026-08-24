<?php
/**
 * Fase 7 — o rotulo do dropdown do modelo de DANFS-e.
 *
 * O WHMCS grava a OPCAO INTEIRA em tbladdonmodules, nao so o numero. Renomear
 * uma opcao orfana o valor gravado: o formulario abre sem nada marcado e, ao
 * salvar, escreve a primeira opcao. Aconteceu de verdade — quem tinha o modelo
 * local voltou para o oficial e o DANFS-e passou a ser buscado no governo,
 * que responde 404.
 *
 * Dai duas travas: os rotulos saem de um lugar so (o enum), e existe uma
 * migracao que recoloca o valor gravado no rotulo atual.
 */
require __DIR__ . '/../../src/NfseNacional/Domain/Enum/DanfseModelo.php';

use GK2\NfseNacional\Domain\Enum\DanfseModelo;

$pass = 0; $fail = 0; $falhas = [];
function eq(string $what, $got, $want) {
    global $pass, $fail, $falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-46s\n      esperado: %s\n      obtido  : %s",
        $what, var_export($want, true), var_export($got, true));
}
function ok(string $what, bool $cond) { eq($what, $cond, true); }

/* ── 1. O enum ────────────────────────────────────────────────────── */
echo "DanfseModelo\n";
eq('oficial e 1',            DanfseModelo::OFICIAL->value, 1);
eq('local e 2',              DanfseModelo::LOCAL->value, 2);
eq('rotulo do oficial',      DanfseModelo::OFICIAL->rotuloConfig(), '1-Oficial (governo)');
eq('rotulo do local',        DanfseModelo::LOCAL->rotuloConfig(), '2-Local (gerado pelo módulo)');
eq('options do dropdown',    DanfseModelo::opcoes(), '1-Oficial (governo),2-Local (gerado pelo módulo)');
ok('so o local gera aqui',   DanfseModelo::LOCAL->isLocal() && !DanfseModelo::OFICIAL->isLocal());
ok('nenhum rotulo cita a GK2',
   !str_contains(DanfseModelo::opcoes(), 'GK2'));

/* ── 2. ConfigFields nao pode repetir os rotulos ──────────────────── */
echo "\nFonte unica dos rotulos\n";
$cf = file_get_contents(__DIR__ . '/../../src/NfseNacional/Admin/ConfigFields.php');
ok('Options do modelo vem do enum', str_contains($cf, "'Options'      => DanfseModelo::opcoes()"));
ok('sem lista de opcoes escrita a mao',
   !preg_match("/'Options'\s*=>\s*'1-Oficial/", $cf));

/* ── 3. Leitura do valor gravado, inclusive o rotulo antigo ───────── */
echo "\nLeitura de danfse_modelo\n";
/* Mesma extracao de ModuleConfig::getDanfseModelo(). Reproduzida aqui porque
   instanciar o ModuleConfig exigiria o WHMCS. */
$ler = static fn(string $v): DanfseModelo =>
    DanfseModelo::tryFrom((int) preg_replace('/\D.*/', '', $v)) ?? DanfseModelo::OFICIAL;

foreach ([
    '2-Local (gerado pelo módulo)' => DanfseModelo::LOCAL,
    '2-GK2'                        => DanfseModelo::LOCAL,   // rotulo antigo
    '2'                            => DanfseModelo::LOCAL,
    '1-Oficial (governo)'          => DanfseModelo::OFICIAL,
    '1'                            => DanfseModelo::OFICIAL,
    ''                             => DanfseModelo::OFICIAL,
    'banana'                       => DanfseModelo::OFICIAL,
    '9-inexistente'                => DanfseModelo::OFICIAL,
] as $gravado => $esperado) {
    eq("le '$gravado'", $ler($gravado), $esperado);
}

/* ── 4. A migracao: so reescreve quando o rotulo divergiu ─────────── */
echo "\nensureDanfseModeloValido\n";
/* A logica do metodo, sem o banco: reescreve se o gravado nao for o rotulo
   canonico do modelo que ele representa; nao mexe em valor vazio. */
$migrar = static function (string $gravado) use ($ler): ?string {
    if ($gravado === '') { return null; }
    $canonico = $ler($gravado)->rotuloConfig();
    return $gravado === $canonico ? null : $canonico;
};
eq('rotulo antigo vira o novo',  $migrar('2-GK2'), '2-Local (gerado pelo módulo)');
eq('so o numero vira o rotulo',  $migrar('2'), '2-Local (gerado pelo módulo)');
eq('canonico nao e reescrito',   $migrar('2-Local (gerado pelo módulo)'), null);
eq('oficial canonico intacto',   $migrar('1-Oficial (governo)'), null);
eq('vazio nao e tocado',         $migrar(''), null);
eq('lixo cai no padrao',         $migrar('banana'), '1-Oficial (governo)');
ok('migracao e idempotente',     $migrar((string) $migrar('2-GK2')) === null);
/* O que importa de verdade: migrar NAO troca o modelo escolhido. */
foreach (['2-GK2', '2', '2-Local (gerado pelo módulo)'] as $antes) {
    eq("modelo preservado a partir de '$antes'",
       $ler((string) ($migrar($antes) ?? $antes)), DanfseModelo::LOCAL);
}

if ($falhas) { echo "\n── FALHAS ──\n" . implode("\n", $falhas) . "\n"; }
printf("\n%d passaram, %d falharam\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
