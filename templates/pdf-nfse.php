<?php
/**
 * Bloco de NFS-e no PDF da fatura.
 *
 * Incluido pelo invoicepdf.tpl do tema ativo (uma linha). Nao depende de
 * variaveis do template: usa o id da fatura da requisicao do dl.php.
 * Qualquer falha e silenciosa — nunca interrompe a geracao do PDF.
 */

if (!defined('WHMCS')) {
    return;
}

try {
    $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 3);
    $bootstrap = $root . '/modules/addons/nfsenacional/src/NfseNacional/Bootstrap.php';

    if (!is_file($bootstrap)) {
        return;
    }

    require_once $bootstrap;
    \GK2\NfseNacional\Bootstrap::boot();

    $config = new \GK2\NfseNacional\Config\ModuleConfig();
    if (!$config->isPdfNfseLinkEnabled()) {
        return;
    }

    $invoiceId = (int) ($_GET['id'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    $row = \WHMCS\Database\Capsule::table('tblnfsenacional')
        ->where('id_invoice', $invoiceId)
        ->where('ambiente', $config->getAmbiente()->value)
        ->first();

    if ($row === null || (string) $row->status !== 'AUTORIZADA') {
        return;
    }

    $nfse = (new \GK2\NfseNacional\Persistence\NfseRepository())->findById((int) $row->id);
    if ($nfse === null) {
        return;
    }

    $urls   = new \GK2\NfseNacional\Domain\Service\DownloadUrlService();
    $danfse = $urls->danfseUrl($nfse);
    $xml    = $urls->xmlUrl($nfse);

    $numero = htmlspecialchars((string) ($row->numero_nfse_nacional ?: $row->id), ENT_QUOTES, 'UTF-8');
    $danfseEsc = htmlspecialchars($danfse, ENT_QUOTES, 'UTF-8');
    $xmlEsc    = htmlspecialchars($xml, ENT_QUOTES, 'UTF-8');

    $html = '<br><br><table border="0" cellpadding="2" cellspacing="0" width="100%">'
        . '<tr><td style="font-size:9px;"><strong>NFS-e n&ordm; ' . $numero . '</strong> &mdash; Autorizada</td></tr>'
        . '<tr><td style="font-size:8px;">DANFS-e: <a href="' . $danfseEsc . '">' . $danfseEsc . '</a></td></tr>'
        . '<tr><td style="font-size:8px;">XML: <a href="' . $xmlEsc . '">' . $xmlEsc . '</a></td></tr>'
        . '</table>';

    if (isset($pdf) && is_object($pdf) && method_exists($pdf, 'writeHTML')) {
        $pdf->Ln(4);
        $pdf->writeHTML($html, true, false, false, false, '');
    }
} catch (\Throwable $e) {
    // Silencioso de proposito.
}
