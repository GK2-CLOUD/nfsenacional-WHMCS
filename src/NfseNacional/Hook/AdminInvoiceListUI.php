<?php

namespace GK2\NfseNacional\Hook;

use GK2\NfseNacional\Domain\AmbienteGuard;
use WHMCS\Database\Capsule;

/**
 * Injeta ícone de NFS-e ao lado de cada fatura que tenha nota fiscal
 * emitida, nas telas de listagem do admin (invoice list, perfil do cliente, etc).
 */
class AdminInvoiceListUI
{
    private const TABLE = 'tblnfsenacional';

    /** Scripts admin onde listas de faturas aparecem. */
    private const ALLOWED_SCRIPTS = ['invoices.php', 'clientsinvoices.php'];

    public function getScript(): string
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($script, self::ALLOWED_SCRIPTS, true)) {
            return '';
        }

        try {
            $ambiente = AmbienteGuard::getInstance()->value();

            $rows = Capsule::table(self::TABLE)
                ->where('ambiente', $ambiente)
                ->select('id', 'id_invoice', 'status')
                ->get();
        } catch (\Exception $e) {
            return '';
        }

        if ($rows->isEmpty()) {
            return '';
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id_invoice] = ['status' => $row->status, 'nfse_id' => (int) $row->id];
        }

        $mapJson    = json_encode($map, JSON_THROW_ON_ERROR);
        $baseUrl    = 'addonmodules.php?module=nfsenacional&action=detail&id=';

        return <<<HTML
<script>
(function () {
    var nfseMap  = {$mapJson};
    var detailBase = '{$baseUrl}';

    var nfseColors = {
        'AUTORIZADA':  {bg:'#e8f5e9', fg:'#2e7d32', border:'#c8e6c9'},
        'CANCELADA':   {bg:'#fff3e0', fg:'#e65100', border:'#ffe0b2'},
        'ERRO':        {bg:'#ffebee', fg:'#c62828', border:'#ffcdd2'},
        'PROCESSANDO': {bg:'#e3f2fd', fg:'#1565c0', border:'#bbdefb'},
        'PENDENTE':    {bg:'#f5f5f5', fg:'#757575', border:'#e0e0e0'},
        'SUBSTITUIDA': {bg:'#efebe9', fg:'#4e342e', border:'#d7ccc8'}
    };

    var nfseIcons = {
        'AUTORIZADA':  'fa-check-circle',
        'CANCELADA':   'fa-ban',
        'ERRO':        'fa-exclamation-circle',
        'PROCESSANDO': 'fa-sync-alt',
        'PENDENTE':    'fa-clock',
        'SUBSTITUIDA': 'fa-exchange-alt'
    };

    function buildPlaceholder() {
        var el = document.createElement('span');
        el.className = 'nfse-list-placeholder';
        el.style.cssText = [
            'display:inline-flex', 
            'align-items:center', 
            'gap:3px',
            'margin-left:6px', 
            'padding:1px 6px', 
            'border-radius:3px',
            'font-size:10px', 
            'font-weight:700', 
            'vertical-align:middle',
            'text-decoration:none', 
            'cursor:pointer', 
            'white-space:nowrap',
            'background: #e9e9e9',
            'visibility: hidden',
            'color: #606060',
            'border:1px solid #c4c4c4'
        ].join(';');
        el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" class="bi bi-x-circle" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/></svg> none';
        return el;
    }

    function buildBadge(status, nfseId) {
        var c    = nfseColors[status] || {bg:'#f5f5f5', fg:'#888', border:'#e0e0e0'};
        var icon = nfseIcons[status]  || 'fa-file-invoice';
        var a    = document.createElement('a');
        a.className = 'nfse-list-badge';
        a.href  = detailBase + nfseId;
        a.title = 'Ver NFS-e (' + status.charAt(0) + status.slice(1).toLowerCase() + ')';
        a.style.cssText = [
            'display:inline-flex', 'align-items:center', 'gap:3px',
            'margin-left:6px', 'padding:1px 6px', 'border-radius:3px',
            'font-size:10px', 'font-weight:700', 'vertical-align:middle',
            'text-decoration:none', 'cursor:pointer', 'white-space:nowrap',
            'background:' + c.bg, 'color:' + c.fg, 'border:1px solid ' + c.border
        ].join(';');
        a.innerHTML = '<i class="fas ' + icon + '" style="font-size:10px;"></i> NFS-e';
        a.addEventListener('click', function(e){ e.stopPropagation(); });
        return a;
    }

    function addIcons() {
        /*
         * Perfil do cliente no admin (clientsinvoices.php):
         * <tr> com checkbox name="selectedinvoices[]" value="XXXX"
         * O número da fatura fica na 2ª <td> como link.
         */
        /* Abordagem unificada: processa TODOS os checkboxes, com ou sem NFS-e */
        document.querySelectorAll('input[name="selectedinvoices[]"]').forEach(function (cb) {
            var invoiceId = parseInt(cb.value, 10);
            var tr = cb.closest('tr');
            if (!tr) return;
            var td = tr.querySelectorAll('td')[1];
            if (!td || td.querySelector('.nfse-list-badge,.nfse-list-placeholder')) return;
            var entry = nfseMap[invoiceId];
            td.appendChild(entry ? buildBadge(entry.status, entry.nfse_id) : buildPlaceholder());
        });

        /* Fallback: <a id="viewInvoiceXXXX"> (lista geral invoices.php) */
        document.querySelectorAll('a[id^="viewInvoice"]').forEach(function (link) {
            var invoiceId = parseInt(link.id.replace('viewInvoice', ''), 10);
            if (!invoiceId) return;
            var tr = link.closest('tr');
            if (!tr) return;
            var td = tr.querySelectorAll('td')[1];
            if (!td || td.querySelector('.nfse-list-badge,.nfse-list-placeholder')) return;
            var entry = nfseMap[invoiceId];
            td.appendChild(entry ? buildBadge(entry.status, entry.nfse_id) : buildPlaceholder());
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addIcons);
    } else {
        addIcons();
    }
})();
</script>
HTML;
    }
}
