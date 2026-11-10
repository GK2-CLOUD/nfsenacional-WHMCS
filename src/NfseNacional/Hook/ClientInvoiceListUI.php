<?php

namespace GK2\NfseNacional\Hook;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Service\DownloadUrlService;
use GK2\NfseNacional\Persistence\NfseRepository;
use WHMCS\Database\Capsule;

/**
 * Injeta o acesso a NFS-e na area do cliente.
 *
 * - Lista de faturas: badge ao lado do numero da fatura.
 * - Pagina da fatura (viewinvoice.php): botao com DANFS-e e XML assinados.
 *
 * O tema twenty-one usa DataTables e linhas com onclick (sem data-url),
 * por isso a injecao observa o tbody e tambem o evento draw.dt.
 */
class ClientInvoiceListUI
{
    private const TABLE = 'tblnfsenacional';

    /** Evita injetar o script duas vezes (head + footer). */
    private static bool $rendered = false;

    public function getScript(array $vars): string
    {
        if (self::$rendered) {
            return '';
        }

        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($script, ['clientarea.php', 'viewinvoice.php'], true)) {
            return '';
        }

        try {
            $clientId = (int) ($_SESSION['uid'] ?? 0);
            if ($clientId <= 0) {
                return '';
            }

            $ambiente = AmbienteGuard::getInstance()->value();

            if ($script === 'viewinvoice.php') {
                $html = $this->scriptFatura($clientId, $ambiente);
                self::$rendered = $html !== '';

                return $html;
            }

            $action = $_GET['action'] ?? '';
            if (!in_array($action, ['invoices', ''], true)) {
                return '';
            }

            $html = $this->scriptLista($clientId, $ambiente);
            self::$rendered = $html !== '';

            return $html;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Script da pagina da fatura: botoes diretos para DANFS-e e XML.
     */
    private function scriptFatura(int $clientId, string $ambiente): string
    {
        $invoiceId = (int) ($_GET['id'] ?? 0);
        if ($invoiceId <= 0) {
            return '';
        }

        $row = Capsule::table(self::TABLE)
            ->where('id_client', $clientId)
            ->where('id_invoice', $invoiceId)
            ->where('ambiente', $ambiente)
            ->first();

        if ($row === null) {
            return '';
        }

        $danfse = $xml = '';
        if ((string) $row->status === 'AUTORIZADA') {
            $nfse = (new NfseRepository())->findById((int) $row->id);
            if ($nfse !== null) {
                $urls   = new DownloadUrlService();
                $danfse = $urls->danfseUrl($nfse);
                $xml    = $urls->xmlUrl($nfse);
            }
        }

        $moduleUrl = 'index.php?m=nfsenacional&search=' . $invoiceId;
        $json = json_encode([
            'status' => (string) $row->status,
            'danfse' => $danfse,
            'xml'    => $xml,
            'module' => $moduleUrl,
        ], JSON_THROW_ON_ERROR);

        return <<<HTML
<script>
(function () {
    var nfse = {$json};

    function botao(href, label, icon) {
        var a = document.createElement('a');
        a.href = href;
        a.className = 'btn btn-default nfse-action';
        a.title = label;
        a.innerHTML = '<i class="fas ' + icon + '"></i> ' + label;
        return a;
    }

    function montar() {
        if (document.querySelector('.nfse-action')) {
            return;
        }

        var botoes = [];
        if (nfse.danfse) {
            botoes.push(botao(nfse.danfse, 'DANFS-e', 'fa-file-invoice'));
            botoes.push(botao(nfse.xml, 'XML', 'fa-file-code'));
        } else {
            botoes.push(botao(nfse.module, 'NFS-e (' + nfse.status + ')', 'fa-file-invoice'));
        }

        var dl    = document.querySelector('a[href*="dl.php?type=i"]');
        var grupo = dl ? dl.parentNode : document.querySelector('.btn-group.d-print-none');

        if (grupo && grupo.classList.contains('btn-group')) {
            botoes.forEach(function (b) { grupo.appendChild(b); });
            return;
        }

        var alvo = document.querySelector('.invoice-status') || document.querySelector('.invoice-container');
        var box  = document.createElement('div');
        box.style.cssText = 'margin-top:8px;';
        botoes.forEach(function (b) { box.appendChild(b); });

        if (alvo) {
            alvo.appendChild(box);
            return;
        }

        box.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:9999;'
            + 'background:#fff;padding:8px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.18);';
        document.body.appendChild(box);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', montar);
    } else {
        montar();
    }
})();
</script>
HTML;
    }

    /**
     * Script da lista de faturas: badge por fatura, tolerante a DataTables.
     */
    private function scriptLista(int $clientId, string $ambiente): string
    {
        $rows = Capsule::table(self::TABLE)
            ->where('id_client', $clientId)
            ->where('ambiente', $ambiente)
            ->select('id_invoice', 'status')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id_invoice] = $row->status;
        }

        $mapJson = json_encode($map, JSON_THROW_ON_ERROR);
        $permBase = 'index.php?m=nfsenacional&search=';

        return <<<HTML
<script>
(function () {
    var nfseMap  = {$mapJson};
    var nfseBase = '{$permBase}';

    var cores = {
        'AUTORIZADA':  {bg:'#e8f5e9', fg:'#2e7d32', bd:'#c8e6c9'},
        'CANCELADA':   {bg:'#fff3e0', fg:'#e65100', bd:'#ffe0b2'},
        'ERRO':        {bg:'#ffebee', fg:'#c62828', bd:'#ffcdd2'},
        'PROCESSANDO': {bg:'#e3f2fd', fg:'#1565c0', bd:'#bbdefb'},
        'PENDENTE':    {bg:'#f5f5f5', fg:'#757575', bd:'#e0e0e0'},
        'SUBSTITUIDA': {bg:'#efebe9', fg:'#4e342e', bd:'#d7ccc8'}
    };

    function badge(status, invoiceId) {
        var c = cores[status] || {bg:'#f5f5f5', fg:'#888', bd:'#e0e0e0'};
        var a = document.createElement('a');
        a.className = 'nfse-client-badge';
        a.href  = nfseBase + invoiceId;
        a.title = 'Ver NFS-e desta fatura';
        a.style.cssText = 'display:inline-block;margin-left:6px;padding:1px 6px;border-radius:3px;'
            + 'font-size:10px;font-weight:700;text-decoration:none;white-space:nowrap;'
            + 'background:' + c.bg + ';color:' + c.fg + ';border:1px solid ' + c.bd + ';';
        a.innerHTML = '<i class="fas fa-file-invoice" style="font-size:10px;"></i> NFS-e';
        a.addEventListener('click', function (e) { e.stopPropagation(); });
        return a;
    }

    function idDaLinha(tr) {
        var onclick = tr.getAttribute('onclick') || '';
        var m = onclick.match(/viewinvoice\.php\?id=(\d+)/);
        if (m) return parseInt(m[1], 10);

        var link = tr.querySelector('a[href*="viewinvoice.php?id="]');
        if (link) {
            var m2 = link.href.match(/[?&]id=(\d+)/);
            if (m2) return parseInt(m2[1], 10);
        }

        var cells = tr.querySelectorAll('td');
        for (var i = 0; i < cells.length; i++) {
            var t = (cells[i].textContent || '').trim();
            if (/^\d+$/.test(t) && Object.prototype.hasOwnProperty.call(nfseMap, parseInt(t, 10))) {
                return parseInt(t, 10);
            }
        }

        return 0;
    }

    function addIcons() {
        var trs = document.querySelectorAll('table tbody tr');
        trs.forEach(function (tr) {
            var invoiceId = idDaLinha(tr);
            if (!invoiceId || !Object.prototype.hasOwnProperty.call(nfseMap, invoiceId)) return;
            if (tr.querySelector('.nfse-client-badge')) return;

            var cells = tr.querySelectorAll('td');
            var alvo = null;
            for (var i = 0; i < cells.length; i++) {
                if ((cells[i].textContent || '').trim().indexOf(String(invoiceId)) === 0) {
                    alvo = cells[i];
                    break;
                }
            }
            alvo = alvo || cells[cells.length - 1];
            if (alvo) alvo.appendChild(badge(nfseMap[invoiceId], invoiceId));
        });
    }

    function observar() {
        var tbody = document.querySelector('#tableInvoicesList tbody') || document.querySelector('table tbody');
        if (!tbody) return false;

        new MutationObserver(addIcons).observe(tbody, {childList: true, subtree: true});
        return true;
    }

    function iniciar() {
        addIcons();
        if (!observar()) {
            var tentativas = 0;
            var timer = setInterval(function () {
                tentativas++;
                if (observar() || tentativas > 20) clearInterval(timer);
            }, 500);
        }
    }

    if (window.jQuery) {
        window.jQuery(document).on('draw.dt', addIcons);
        window.jQuery(document).ajaxComplete(addIcons);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
</script>
HTML;
    }
}
