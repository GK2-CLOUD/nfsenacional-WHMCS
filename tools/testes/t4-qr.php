<?php
/**
 * Trava a URL do QR contra o que foi LIDO do DANFS-e oficial da NFS-e 597.
 * A string abaixo nao e suposicao: saiu do bitmap do documento do governo
 * via tools/ler-qr.py, e os 50 digitos batem com a chave da propria nota.
 */
require __DIR__ . '/../../src/NfseNacional/Danfse/ConsultaPublica.php';
use GK2\NfseNacional\Danfse\ConsultaPublica;

const CHAVE   = '41152002214322136000122000000000059726082129342319';
const OFICIAL = 'https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=' . CHAVE;

$pass=0; $fail=0; $falhas=[];
function eq(string $w, $got, $want) {
    global $pass,$fail,$falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-46s\n      esperado: %s\n      obtido  : %s", $w, var_export($want,true), var_export($got,true));
}

echo "URL do QR vs DANFS-e oficial\n";
eq('bate com o QR lido do oficial', ConsultaPublica::url(CHAVE), OFICIAL);
eq('chave com pontuacao e normalizada',
    ConsultaPublica::url(trim(chunk_split(CHAVE, 4, ' '))), OFICIAL);

echo "\nChave invalida nao gera URL (QR vazio nao e desenhado)\n";
foreach ([
    'null'            => null,
    'vazia'           => '',
    '49 digitos'      => substr(CHAVE, 0, 49),
    '51 digitos'      => CHAVE . '0',
    'so letras'       => 'abcdefgh',
    'chave de NF-e44' => str_repeat('1', 44),
] as $caso => $v) {
    eq("rejeita: $caso", ConsultaPublica::url($v), '');
}

echo "\nForma da URL\n";
$u = ConsultaPublica::url(CHAVE);
eq('host oficial',      parse_url($u, PHP_URL_HOST), 'www.nfse.gov.br');
eq('https',             parse_url($u, PHP_URL_SCHEME), 'https');
eq('path ConsultaPublica', parse_url($u, PHP_URL_PATH), '/ConsultaPublica');
parse_str(parse_url($u, PHP_URL_QUERY), $q);
eq('tpc=1',             $q['tpc'] ?? null, '1');
eq('chave = 50 digitos da nota', $q['chave'] ?? null, CHAVE);
eq('sem parametro extra', array_keys($q), ['tpc','chave']);

// O endpoint de download usa um token opaco do governo, NAO a chave —
// confundir os dois foi o que manteve o QR em aberto. Guarda contra
// alguem "corrigir" a URL para o endereco errado no futuro.
eq('nao aponta para o Download/DANFSe', str_contains($u, '/Download/'), false);

if ($falhas) echo "\n── FALHAS ──\n".implode("\n",$falhas)."\n";
printf("\n%d passaram, %d falharam\n", $pass, $fail);
exit($fail===0 ? 0 : 1);
