<?php
/**
 * Gera o DANFS-e de uma NFS-e real. RODA NO SERVIDOR WHMCS — precisa do
 * TCPDF e do banco.
 *
 *   php tools/danfse-render.php <id_nfse> [saida.pdf]
 *
 * Nao emite nada nem altera registro: le a nota, pede o PDF ao DanfseService
 * e grava em disco. E o que tools/ciclo-danfse.sh chama a cada rodada de
 * calibracao de layout.
 */
chdir(dirname(__DIR__, 4));           // .../modules/addons/nfsenacional -> raiz do WHMCS
require 'init.php';

use GK2\NfseNacional\Danfse\DanfseService;
use GK2\NfseNacional\Persistence\NfseRepository;

$id    = (int) ($argv[1] ?? 0);
$saida = $argv[2] ?? sys_get_temp_dir() . '/danfse-' . $id . '.pdf';

if ($id < 1) {
    fwrite(STDERR, "uso: php tools/danfse-render.php <id_nfse> [saida.pdf]\n");
    exit(1);
}

$nfse = (new NfseRepository())->findById($id);

if (!$nfse) {
    fwrite(STDERR, "NFS-e $id nao encontrada\n");
    exit(1);
}

$t0  = microtime(true);
$pdf = (new DanfseService())->gerarPdf($nfse);
$ms  = (microtime(true) - $t0) * 1000;

file_put_contents($saida, $pdf);

printf("%s | NFS-e %d | fatura %s | %s | %s | %.1f KB | %.0f ms\n",
    $saida, $nfse->id, $nfse->invoiceId, $nfse->status->value, $nfse->ambiente,
    strlen($pdf) / 1024, $ms);
