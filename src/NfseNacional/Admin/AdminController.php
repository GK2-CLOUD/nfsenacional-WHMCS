<?php

namespace GK2\NfseNacional\Admin;

use GK2\NfseNacional\Admin\Action\CancelarAction;
use GK2\NfseNacional\Admin\Action\EmitirAction;
use GK2\NfseNacional\Admin\Action\ExcluirAction;
use GK2\NfseNacional\Admin\Action\ReenviarEmailAction;
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Domain\Enum\NfseStatus;
use GK2\NfseNacional\Domain\Service\DownloadUrlService;
use GK2\NfseNacional\Persistence\NfseRepository;
use GK2\NfseNacional\Security\TokenSigner;

/**
 * Controller principal da área administrativa do addon.
 */
class AdminController
{
    private NfseRepository $repository;

    private const FIELD_LABELS = [
        'id'                   => 'ID',
        'id_client'            => 'ID do Cliente',
        'id_invoice'           => 'Fatura',
        'client_name'          => 'Cliente',
        'total'                => 'Valor (R$)',
        'status'               => 'Status',
        'numero_dps'           => 'Número DPS',
        'serie_dps'            => 'Série DPS',
        'chave_acesso'         => 'Chave de Acesso',
        'numero_nfse_nacional'  => 'Número NFS-e',
        'protocolo'            => 'Protocolo',
        'codigo_verificacao'   => 'Código de Verificação',
        'danfse_url'           => 'Link DANFS-e',
        'xml_url'              => 'Link XML',
        'ambiente'             => 'Ambiente',
        'erro'                 => 'Mensagem de Erro',
        'data_emissao'         => 'Data de Emissão',
        'data_autorizacao'     => 'Data de Autorização',
        'created_at'           => 'Criado em',
        'updated_at'           => 'Atualizado em',
    ];

    public function __construct()
    {
        $this->repository = new NfseRepository();
    }

    // ── Dispatch ─────────────────────────────────────────────────────

    public function dispatch(array $vars): void
    {
        $action     = $_REQUEST['action'] ?? '';
        $modulelink = $vars['modulelink'] ?? '';

        switch ($action) {
            case 'emitir':
                (new EmitirAction())->execute($modulelink);
                return;
            case 'cancelar':
                (new CancelarAction())->execute($modulelink);
                return;
            case 'reenviar_email':
                (new ReenviarEmailAction())->execute($modulelink);
                return;
            case 'excluir':
                (new ExcluirAction())->execute($modulelink);
                return;
            case 'list':
                $this->renderList($vars);
                return;
            case 'detail':
                $this->renderDetail($vars);
                return;
            default:
                $this->renderDashboard($vars);
                return;
        }
    }

    // ── Dashboard ─────────────────────────────────────────────────────

    private function renderDashboard(array $vars): void
    {
        $modulelink    = $vars['modulelink'];
        $recentes      = $this->repository->search([], 'id', 'desc', 10);
        $statsMes      = $this->repository->statsPorMes(12);
        $issMes        = $this->repository->issPorMes(12);
        $errosFreq     = $this->repository->errosFrequentes(5);
        $statsAtual    = $this->repository->stats();

        $this->renderStyles();
        $this->renderNav($modulelink, '');

        // ── Métricas rápidas ──
        $fatTotal    = array_sum(array_column($issMes, 'total_faturado'));
        $taxaSucesso = ($statsAtual['autorizadas'] + $statsAtual['erros']) > 0
            ? round($statsAtual['autorizadas'] / ($statsAtual['autorizadas'] + $statsAtual['erros']) * 100, 1)
            : 100;

        echo '<div class="nfse-kpi-row">';
        $kpis = [
            ['icon' => 'fa-receipt',    'label' => 'Faturamento (12m)', 'value' => 'R$ ' . number_format($fatTotal, 2, ',', '.'), 'color' => '#1a237e'],
            ['icon' => 'fa-check-double','label' => 'Taxa de sucesso',  'value' => $taxaSucesso . '%',                            'color' => $taxaSucesso >= 95 ? '#2e7d32' : '#b71c1c'],
        ];
        foreach ($kpis as $kpi) {
            echo '<div class="nfse-kpi-card">'
                . '<div class="nfse-kpi-icon" style="color:' . $kpi['color'] . '"><i class="fas ' . $kpi['icon'] . '"></i></div>'
                . '<div class="nfse-kpi-value" style="color:' . $kpi['color'] . '">' . $kpi['value'] . '</div>'
                . '<div class="nfse-kpi-label">' . $kpi['label'] . '</div>'
                . '</div>';
        }
        echo '</div>';

        // ── Gráfico + Erros frequentes lado a lado ──
        echo '<div class="nfse-dashboard-grid">';

        // Gráfico
        echo '<div class="nfse-chart-wrap">';
        echo '<div class="nfse-chart-header">';
        echo '<span class="nfse-section-title" style="margin:0;"><i class="fas fa-chart-bar"></i> Notas por período</span>';
        echo '<div class="nfse-chart-controls">';
        echo '<button class="nfse-period-btn active" data-months="12">12m</button>';
        echo '<button class="nfse-period-btn" data-months="6">6m</button>';
        echo '<button class="nfse-period-btn" data-months="3">3m</button>';
        echo '</div></div>';
        echo '<div class="nfse-chart-body"><canvas id="nfseChart"></canvas></div>';
        echo '</div>';

        // Painel lateral: erros frequentes
        echo '<div class="nfse-dashboard-side">';
        echo '<div class="nfse-side-panel">';
        echo '<div class="nfse-side-panel-title"><i class="fas fa-exclamation-triangle"></i> Erros frequentes <small>(90 dias)</small></div>';
        if (!empty($errosFreq)) {
            $maxCount = max(array_column($errosFreq, 'total'));
            foreach ($errosFreq as $err) {
                $pct = $maxCount > 0 ? round($err['total'] / $maxCount * 100) : 0;
                $erroDisplay = mb_strimwidth($err['erro'], 0, 55, '…');
                echo '<div class="nfse-err-row">'
                    . '<div class="nfse-err-text" title="' . htmlspecialchars($err['erro'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($erroDisplay, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div class="nfse-err-bar-wrap"><div class="nfse-err-bar" style="width:' . $pct . '%"></div></div>'
                    . '<div class="nfse-err-count">' . $err['total'] . '</div>'
                    . '</div>';
            }
        } else {
            echo '<div class="nfse-side-empty"><i class="fas fa-check-circle" style="color:#2e7d32;"></i> Sem erros recentes</div>';
        }
        echo '</div>';
        echo '</div>'; // nfse-dashboard-side
        echo '</div>'; // nfse-dashboard-grid

        // Preparar dados para Chart.js
        $labels      = array_keys($statsMes);
        $autorizadas = array_column(array_values($statsMes), 'autorizadas');
        $canceladas  = array_column(array_values($statsMes), 'canceladas');
        $erros       = array_column(array_values($statsMes), 'erros');

        $mesesPt = ['01' => 'Jan','02' => 'Fev','03' => 'Mar','04' => 'Abr','05' => 'Mai','06' => 'Jun',
                    '07' => 'Jul','08' => 'Ago','09' => 'Set','10' => 'Out','11' => 'Nov','12' => 'Dez'];
        $labelsFormatados = array_map(function ($m) use ($mesesPt) {
            [$ano, $mes] = explode('-', $m);
            return ($mesesPt[$mes] ?? $mes) . '/' . substr($ano, 2);
        }, $labels);

        $labelsJson      = json_encode($labelsFormatados);
        $autorizadasJson = json_encode($autorizadas);
        $canceladasJson  = json_encode($canceladas);
        $errosJson       = json_encode($erros);

        echo <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
    var allLabels      = {$labelsJson};
    var allAutorizadas = {$autorizadasJson};
    var allCanceladas  = {$canceladasJson};
    var allErros       = {$errosJson};

    var ctx = document.getElementById('nfseChart').getContext('2d');
    var chart = new Chart(ctx, {
        type: 'bar',
        data: buildData(12),
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, padding: 14 } },
                tooltip: { bodyFont: { size: 11 }, titleFont: { size: 11 } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#f0f0f0' } }
            }
        }
    });

    function buildData(months) {
        var from = allLabels.length - months;
        return {
            labels: allLabels.slice(from),
            datasets: [
                { label: 'Autorizadas', data: allAutorizadas.slice(from), backgroundColor: '#2e7d32', borderRadius: 3 },
                { label: 'Canceladas',  data: allCanceladas.slice(from),  backgroundColor: '#e65100', borderRadius: 3 },
                { label: 'Erros',       data: allErros.slice(from),       backgroundColor: '#b71c1c', borderRadius: 3 }
            ]
        };
    }

    document.querySelectorAll('.nfse-period-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.nfse-period-btn').forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            chart.data = buildData(parseInt(this.getAttribute('data-months')));
            chart.update();
        });
    });
})();
</script>
JS;

        echo '<div class="nfse-section-title" style="margin-top:22px;"><i class="fas fa-history"></i> Últimas NFS-e emitidas</div>';

        echo '<div class="nfse-table-wrap">';
        echo '<table class="nfse-table">';
        echo '<thead><tr>'
            . '<th>#</th><th>Fatura</th><th>Cliente</th>'
            . '<th>Valor</th><th>NFS-e</th><th>Status</th><th>Atualizado em</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';

        if (!empty($recentes['data'])) {
            foreach ($recentes['data'] as $nfse) {
                echo '<tr' . ($nfse->status === NfseStatus::ERRO ? ' class="nfse-row-error"' : '') . '>';
                echo '<td class="nfse-col-id">' . $nfse->id . '</td>';
                echo '<td><a href="invoices.php?action=edit&id=' . $nfse->invoiceId . '">#' . $nfse->invoiceId . '</a></td>';
                echo '<td>' . $this->renderClientLink($nfse->clientId ?? 0, $nfse->clientName ?? '') . '</td>';
                echo '<td class="nfse-col-money">' . $this->formatMoney($nfse->total) . '</td>';
                echo '<td class="nfse-col-num">' . htmlspecialchars($nfse->numeroNfseNacional ?? '—', ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . $this->renderStatusBadge($nfse->status) . '</td>';
                echo '<td class="nfse-col-date">' . $this->formatDate($nfse->updatedAt) . '</td>';
                echo '<td>' . $this->renderRowActions($nfse, $modulelink, true) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="8" class="nfse-empty">'
                . '<i class="fas fa-inbox"></i><br>Nenhuma NFS-e registrada ainda.</td></tr>';
        }

        echo '</tbody></table></div>';

        if (!empty($recentes['data'])) {
            echo '<div class="nfse-table-footer">'
                . '<a href="' . $modulelink . '&action=list" class="nfse-link-all">'
                . 'Ver todas as NFS-e <i class="fas fa-arrow-right"></i></a></div>';
        }
    }

    // ── Listagem ──────────────────────────────────────────────────────

    private function renderList(array $vars): void
    {
        $modulelink = $vars['modulelink'];
        $this->renderStyles();
        $this->renderNav($modulelink, 'list');

        // ── Filtros ativos ──
        $filters = [];
        if (!empty($_GET['filter_status'])) {
            $filters['status']      = $_GET['filter_status'];
        }
        if (!empty($_GET['filter_invoice'])) {
            $filters['id_invoice']  = $_GET['filter_invoice'];
        }
        if (!empty($_GET['filter_client'])) {
            $filters['client_name'] = $_GET['filter_client'];
        }
        if (!empty($_GET['filter_date_start'])) {
            $filters['data_inicio'] = $_GET['filter_date_start'];
        }
        if (!empty($_GET['filter_date_end'])) {
            $filters['data_fim']    = $_GET['filter_date_end'];
        }

        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $result = $this->repository->search($filters, 'id', 'desc', $limit, $offset);
        $stats  = $this->repository->stats();
        $total  = $result['total'] ?? 0;

        // ── Feedback de exclusão ──
        if (!empty($_GET['delete']) && isset($_GET['delete_status'])) {
            $delId = (int) $_GET['delete'];
            if ($_GET['delete_status'] === '1') {
                echo '<div class="nfse-alert nfse-alert-success">'
                    . '<i class="fas fa-check-circle"></i> Registro <strong>#' . $delId . '</strong> excluído.</div>';
            } else {
                echo '<div class="nfse-alert nfse-alert-danger">'
                    . '<i class="fas fa-times-circle"></i> Falha ao excluir o registro <strong>#' . $delId . '</strong>.</div>';
            }
        }

        // ── Chips de estatística (contagens do mês) ──
        $baseListUrl = $modulelink . '&action=list';
        $activeStatus = $_GET['filter_status'] ?? '';

        $chips = [
            ['key' => '',            'label' => 'Todas',      'count' => $stats['autorizadas'] + $stats['processando'] + $stats['erros'] + $stats['canceladas'], 'color' => '#555'],
            ['key' => 'AUTORIZADA',  'label' => 'Autorizadas','count' => $stats['autorizadas'],  'color' => '#2e7d32'],
            ['key' => 'PROCESSANDO', 'label' => 'Processando','count' => $stats['processando'],  'color' => '#1565c0'],
            ['key' => 'ERRO',        'label' => 'Erros',      'count' => $stats['erros'],        'color' => '#b71c1c'],
            ['key' => 'CANCELADA',   'label' => 'Canceladas', 'count' => $stats['canceladas'],   'color' => '#e65100'],
        ];

        echo '<div class="nfse-chips-bar">';
        echo '<span class="nfse-chips-label">' . date('m/Y') . '</span>';
        foreach ($chips as $chip) {
            $isActive = $activeStatus === $chip['key'];
            $url = $chip['key'] === ''
                ? $baseListUrl
                : $baseListUrl . '&filter_status=' . $chip['key'];
            echo '<a href="' . $url . '" class="nfse-chip' . ($isActive ? ' active' : '') . '" '
                . 'style="--chip-color:' . $chip['color'] . ';">'
                . $chip['label']
                . '<span class="nfse-chip-count">' . $chip['count'] . '</span>'
                . '</a>';
        }
        echo '</div>';

        // ── Barra de filtros ──
        echo '<div class="nfse-filter-bar">';
        echo '<form method="get" class="nfse-filter-form">';
        echo '<input type="hidden" name="module" value="nfsenacional">';
        echo '<input type="hidden" name="action" value="list">';
        if ($activeStatus) {
            echo '<input type="hidden" name="filter_status" value="' . htmlspecialchars($activeStatus, ENT_QUOTES, 'UTF-8') . '">';
        }

        echo '<input type="text" name="filter_invoice" class="nfse-input" placeholder="Nº da fatura" '
            . 'value="' . htmlspecialchars($_GET['filter_invoice'] ?? '', ENT_QUOTES, 'UTF-8') . '">';

        echo '<input type="text" name="filter_client" class="nfse-input" placeholder="Nome do cliente" '
            . 'value="' . htmlspecialchars($_GET['filter_client'] ?? '', ENT_QUOTES, 'UTF-8') . '">';

        echo '<div class="nfse-filter-date-group">'
            . '<span class="nfse-filter-date-label">De</span>'
            . '<input type="date" name="filter_date_start" class="nfse-input nfse-input-date" '
            . 'value="' . htmlspecialchars($_GET['filter_date_start'] ?? '', ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="nfse-filter-date-label">até</span>'
            . '<input type="date" name="filter_date_end" class="nfse-input nfse-input-date" '
            . 'value="' . htmlspecialchars($_GET['filter_date_end'] ?? '', ENT_QUOTES, 'UTF-8') . '">'
            . '</div>';

        echo '<button type="submit" class="nfse-btn nfse-btn-primary">'
            . '<i class="fas fa-search"></i> Pesquisar</button>';

        if (!empty($filters)) {
            echo '<a href="' . $baseListUrl . '" class="nfse-btn nfse-btn-ghost">'
                . '<i class="fas fa-times"></i> Limpar</a>';
        }

        echo '<span class="nfse-result-count">' . $total . ' registro' . ($total !== 1 ? 's' : '') . '</span>';
        echo '</form></div>';

        // ── Tabela ──
        echo '<div class="nfse-table-wrap">';
        echo '<table class="nfse-table">';
        echo '<thead><tr>'
            . '<th>#</th>'
            . '<th>Fatura</th>'
            . '<th>Cliente</th>'
            . '<th>NFS-e</th>'
            . '<th>Valor</th>'
            . '<th>Status</th>'
            . '<th>Ambiente</th>'
            . '<th>Atualizado em</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';

        foreach ($result['data'] as $nfse) {
            echo '<tr' . ($nfse->status === NfseStatus::ERRO ? ' class="nfse-row-error"' : '') . '>';
            echo '<td class="nfse-col-id">' . $nfse->id . '</td>';
            echo '<td><a href="invoices.php?action=edit&id=' . $nfse->invoiceId . '">#' . $nfse->invoiceId . '</a></td>';
            echo '<td>' . $this->renderClientLink($nfse->clientId ?? 0, $nfse->clientName ?? '') . '</td>';
            echo '<td class="nfse-col-num">' . htmlspecialchars($nfse->numeroNfseNacional ?? '—', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td class="nfse-col-money">' . $this->formatMoney($nfse->total) . '</td>';
            echo '<td>' . $this->renderStatusBadge($nfse->status) . '</td>';
            echo '<td>' . $this->renderAmbienteBadge($nfse->ambiente) . '</td>';
            echo '<td class="nfse-col-date">' . $this->formatDate($nfse->updatedAt) . '</td>';
            echo '<td>' . $this->renderRowActions($nfse, $modulelink) . '</td>';
            echo '</tr>';
        }

        if (empty($result['data'])) {
            echo '<tr><td colspan="9" class="nfse-empty">'
                . '<i class="fas fa-inbox"></i><br>Nenhuma NFS-e encontrada.</td></tr>';
        }

        echo '</tbody></table></div>';

        // ── Paginação ──
        $totalPages = (int) ceil($total / $limit);
        if ($totalPages > 1) {
            $baseUrl = $baseListUrl
                . (isset($_GET['filter_status']) ? '&filter_status='     . urlencode($_GET['filter_status']) : '')
                . (isset($_GET['filter_invoice']) ? '&filter_invoice='    . urlencode($_GET['filter_invoice']) : '')
                . (isset($_GET['filter_client']) ? '&filter_client='     . urlencode($_GET['filter_client']) : '')
                . (isset($_GET['filter_date_start']) ? '&filter_date_start=' . urlencode($_GET['filter_date_start']) : '')
                . (isset($_GET['filter_date_end']) ? '&filter_date_end='   . urlencode($_GET['filter_date_end']) : '');

            echo '<div class="nfse-pagination">';

            if ($page > 1) {
                echo '<a href="' . $baseUrl . '&page=' . ($page - 1) . '" class="nfse-page-btn">&laquo;</a>';
            }
            $from = max(1, $page - 3);
            $to   = min($totalPages, $page + 3);
            for ($i = $from; $i <= $to; $i++) {
                $cls = $i === $page ? ' active' : '';
                echo '<a href="' . $baseUrl . '&page=' . $i . '" class="nfse-page-btn' . $cls . '">' . $i . '</a>';
            }
            if ($page < $totalPages) {
                echo '<a href="' . $baseUrl . '&page=' . ($page + 1) . '" class="nfse-page-btn">&raquo;</a>';
            }

            echo '<span class="nfse-page-info">Página ' . $page . ' de ' . $totalPages . '</span>';
            echo '</div>';
        }
    }

    // ── Detalhe ───────────────────────────────────────────────────────

    private function renderDetail(array $vars): void
    {
        $modulelink = $vars['modulelink'];
        $this->renderStyles();
        $this->renderNav($modulelink, 'detail');

        $id   = (int) ($_GET['id'] ?? 0);
        $nfse = $this->repository->findById($id);

        if ($nfse === null) {
            echo '<div class="nfse-alert nfse-alert-warning">'
                . '<i class="fas fa-exclamation-triangle"></i> NFS-e não encontrada.</div>';
            return;
        }

        // Feedback de ações (reenviar email, etc.)
        $nfseStatus = $_GET['nfse_status'] ?? '';
        $nfseMsg    = htmlspecialchars(urldecode($_GET['nfse_msg'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($nfseStatus === 'success') {
            echo '<div class="nfse-alert nfse-alert-success">'
                . '<i class="fas fa-check-circle"></i> ' . ($nfseMsg ?: 'Operação realizada com sucesso.') . '</div>';
        } elseif ($nfseStatus === 'error') {
            echo '<div class="nfse-alert nfse-alert-danger">'
                . '<i class="fas fa-times-circle"></i> ' . ($nfseMsg ?: 'Erro ao realizar operação.') . '</div>';
        }

        // ── Cabeçalho ──
        echo '<div class="nfse-detail-header">';
        echo '<div class="nfse-detail-title">';
        echo '<a href="' . $modulelink . '&action=list" class="nfse-back-link">'
            . '<i class="fas fa-chevron-left"></i></a>';
        echo '<span>NFS-e <strong>#' . $nfse->id . '</strong></span>';
        echo $this->renderStatusBadge($nfse->status, 'large');
        echo $this->renderAmbienteBadge($nfse->ambiente);
        echo '</div>';
        echo '<div class="nfse-detail-actions">';
        echo $this->renderDetailActions($nfse, $modulelink);
        echo '</div>';
        echo '</div>';

        // ── Grid de detalhe ──
        echo '<div class="nfse-detail-grid">';

        // Coluna principal
        echo '<div class="nfse-detail-main">';

        echo '<div class="nfse-detail-section">';
        echo '<div class="nfse-detail-section-title">Dados da NFS-e</div>';
        echo '<table class="nfse-kv-table">';

        $mainFields = [
            'id_invoice'           => $nfse->invoiceId
                ? '<a href="invoices.php?action=edit&id=' . $nfse->invoiceId . '">#' . $nfse->invoiceId . '</a>'
                : '—',
            'client_name'          => $this->renderClientLink($nfse->clientId ?? 0, $nfse->clientName ?? ''),
            'total'                => $this->formatMoney($nfse->total),
            'numero_nfse_nacional'  => htmlspecialchars($nfse->numeroNfseNacional ?? '—', ENT_QUOTES, 'UTF-8'),
            'numero_dps'           => $nfse->numeroDps ? $nfse->serieDps . '-' . $nfse->numeroDps : '—',
            'chave_acesso'         => $nfse->chaveAcesso
                ? '<code class="nfse-code">' . htmlspecialchars($nfse->chaveAcesso, ENT_QUOTES, 'UTF-8') . '</code>'
                : '—',
            'protocolo'            => htmlspecialchars($nfse->protocolo ?? '—', ENT_QUOTES, 'UTF-8'),
            'data_emissao'         => $nfse->dataEmissao ?? '—',
            'data_autorizacao'     => $nfse->dataAutorizacao ?? '—',
        ];

        foreach ($mainFields as $key => $value) {
            $label = self::FIELD_LABELS[$key] ?? $key;
            echo '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';
        }

        echo '</table></div>';

        if (!empty($nfse->erro)) {
            echo '<div class="nfse-error-box">'
                . '<div class="nfse-error-box-title"><i class="fas fa-exclamation-triangle"></i> Mensagem de Erro</div>'
                . '<div class="nfse-error-box-body">' . htmlspecialchars($nfse->erro, ENT_QUOTES, 'UTF-8') . '</div>'
                . '</div>';
        }

        echo '</div>'; // nfse-detail-main

        // Coluna lateral
        echo '<div class="nfse-detail-side">';

        if ($nfse->isAutorizada()) {
            $dlService = new DownloadUrlService();
            echo '<div class="nfse-detail-section">';
            echo '<div class="nfse-detail-section-title">Documentos</div>';
            echo '<a href="' . htmlspecialchars($dlService->danfseUrl($nfse, ENT_QUOTES, 'UTF-8')) . '" target="_blank" class="nfse-doc-btn">'
                . '<i class="fas fa-file-pdf"></i> Ver DANFS-e</a>';
            echo '<a href="' . htmlspecialchars($dlService->xmlUrl($nfse, ENT_QUOTES, 'UTF-8')) . '" target="_blank" class="nfse-doc-btn secondary">'
                . '<i class="fas fa-code"></i> Baixar XML</a>';
            echo '</div>';
        }

        echo '<div class="nfse-detail-section">';
        echo '<div class="nfse-detail-section-title">Sistema</div>';
        echo '<table class="nfse-kv-table small">';
        foreach ([
            'id'         => '#' . $nfse->id,
            'id_client'  => '<a href="clientssummary.php?userid=' . $nfse->clientId . '">#' . $nfse->clientId . '</a>',
            'ambiente'   => $this->renderAmbienteBadge($nfse->ambiente),
            'created_at' => $this->formatDate($nfse->createdAt),
            'updated_at' => $this->formatDate($nfse->updatedAt),
        ] as $key => $value) {
            $label = self::FIELD_LABELS[$key] ?? $key;
            echo '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table></div>';

        echo '</div>'; // nfse-detail-side
        echo '</div>'; // nfse-detail-grid
    }

    // ── Helpers de renderização ──────────────────────────────────────

    private function renderClientLink(int $clientId, string $clientName): string
    {
        $name = htmlspecialchars($clientName ?: '—', ENT_QUOTES, 'UTF-8');
        if ($clientId <= 0) {
            return $name;
        }
        return '<a href="clientssummary.php?userid=' . $clientId . '" target="_blank">' . $name . '</a>';
    }

    private function renderStatusBadge(NfseStatus $status, string $size = ''): string
    {
        $map = [
            NfseStatus::PENDENTE->value    => ['color' => '#78909c', 'icon' => 'fa-clock'],
            NfseStatus::PROCESSANDO->value => ['color' => '#1e88e5', 'icon' => 'fa-sync-alt'],
            NfseStatus::AUTORIZADA->value  => ['color' => '#2e7d32', 'icon' => 'fa-check-circle'],
            NfseStatus::CANCELADA->value   => ['color' => '#e65100', 'icon' => 'fa-ban'],
            NfseStatus::SUBSTITUIDA->value => ['color' => '#6d4c41', 'icon' => 'fa-exchange-alt'],
            NfseStatus::ERRO->value        => ['color' => '#b71c1c', 'icon' => 'fa-exclamation-triangle'],
        ];
        $cfg = $map[$status->value] ?? ['color' => '#aaa', 'icon' => 'fa-circle'];
        $cls = 'nfse-status-badge' . ($size === 'large' ? ' large' : '');
        return '<span class="' . $cls . '" style="background:' . $cfg['color'] . ';">'
            . '<i class="fas ' . $cfg['icon'] . '"></i> ' . $status->label() . '</span>';
    }

    private function renderAmbienteBadge(?string $ambiente, string $size = ''): string
    {
        if (empty($ambiente)) {
            return '<span class="nfse-muted">—</span>';
        }
        $isProd = strtolower($ambiente) === 'producao';
        $color  = $isProd ? '#2e7d32' : '#1565c0';
        $label  = $isProd ? 'Produção' : 'Homologação';
        return '<span class="nfse-status-badge" style="background:' . $color . ';">' . $label . '</span>';
    }

    private function renderRowActions(Nfse $nfse, string $modulelink, bool $compact = false): string
    {
        $html = '<div class="nfse-row-actions">';

        $html .= '<a href="' . $modulelink . '&action=detail&id=' . $nfse->id . '" '
            . 'class="nfse-action-btn" title="Ver detalhes">'
            . '<i class="fas fa-eye"></i>' . ($compact ? '' : ' Ver') . '</a>';

        if (!$nfse->isAutorizada()) {
            $tokenDel = TokenSigner::sign((string) $nfse->id);
            $html .= '<a href="' . $modulelink . '&action=excluir&delete=' . $nfse->id . '&token=' . $tokenDel . '" '
                . 'class="nfse-action-btn danger" title="Excluir" '
                . 'onclick="return confirm(\'Excluir NFS-e #' . $nfse->id . '?\')">'
                . '<i class="fas fa-trash-alt"></i></a>';
        }

        $html .= '</div>';
        return $html;
    }

    private function renderDetailActions(Nfse $nfse, string $modulelink): string
    {
        $html = '';

        if ($nfse->invoiceId) {
            $invoiceId = (int) $nfse->invoiceId;
            $token     = TokenSigner::sign((string) $invoiceId);

            if (in_array($nfse->status, [NfseStatus::PENDENTE, NfseStatus::ERRO], true)) {
                $html .= '<a href="' . $modulelink . '&action=emitir&invoiceid=' . $invoiceId . '&token=' . $token . '" '
                    . 'class="nfse-btn nfse-btn-success" '
                    . 'onclick="return confirm(\'Emitir NFS-e para a fatura #' . $invoiceId . '?\')">'
                    . '<i class="fas fa-paper-plane"></i> Emitir NFS-e</a>';
            }

            if ($nfse->podeCancelar()) {
                $html .= '<a href="' . $modulelink . '&action=cancelar&invoiceid=' . $invoiceId . '&token=' . $token . '" '
                    . 'class="nfse-btn nfse-btn-warning" '
                    . 'onclick="return confirm(\'Cancelar a NFS-e da fatura #' . $invoiceId . '? Esta ação não pode ser desfeita.\')">'
                    . '<i class="fas fa-ban"></i> Cancelar</a>';
            }

            if ($nfse->isAutorizada()) {
                $tokenEmail = TokenSigner::sign((string) $invoiceId);
                $html .= '<a href="' . $modulelink . '&action=reenviar_email&invoiceid=' . $invoiceId . '&token=' . $tokenEmail . '&from=detail&nfseid=' . $nfse->id . '" '
                    . 'class="nfse-btn nfse-btn-ghost">'
                    . '<i class="fas fa-envelope"></i> Reenviar E-mail</a>';
            }
        }

        if (!$nfse->isAutorizada()) {
            $tokenDel = TokenSigner::sign((string) $nfse->id);
            $html .= '<a href="' . $modulelink . '&action=excluir&delete=' . $nfse->id . '&token=' . $tokenDel . '" '
                . 'class="nfse-btn nfse-btn-danger" '
                . 'onclick="return confirm(\'Excluir definitivamente este registro #' . $nfse->id . '?\')">'
                . '<i class="fas fa-trash-alt"></i> Excluir</a>';
        }

        return $html;
    }

    private function formatMoney(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        return 'R$&nbsp;' . number_format($value, 2, ',', '.');
    }

    private function formatDate(?string $date): string
    {
        if (empty($date) || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        // YYYY-MM-DD HH:MM:SS → DD/MM/YY HH:MM
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', $date, $m)) {
            return $m[3] . '/' . $m[2] . '/' . substr($m[1], 2) . ' ' . $m[4] . ':' . $m[5];
        }
        return $date;
    }

    // ── Navegação ─────────────────────────────────────────────────────

    private function renderNav(string $modulelink, string $activeAction): void
    {
        echo '<div class="nfse-topbar">';

        echo '<div class="nfse-topbar-brand">'
            . '<i class="fas fa-file-invoice-dollar"></i>'
            . '<span>NFS-e Nacional</span>'
            . '</div>';

        echo '<nav class="nfse-topbar-nav">';
        $items = [
            ''     => 'Visão Geral',
            'list' => 'Notas Fiscais',
        ];
        foreach ($items as $action => $label) {
            $isActive = $activeAction === $action || ($action === 'detail' && $activeAction === 'detail');
            $href = $action === '' ? $modulelink : $modulelink . '&action=' . $action;
            echo '<a href="' . $href . '" class="nfse-topbar-link' . ($isActive ? ' active' : '') . '">'
                . $label . '</a>';
        }
        echo '</nav>';

        echo '<a href="configaddonmods.php?saved=true#nfsenacional" class="nfse-btn nfse-btn-ghost nfse-topbar-config">'
            . '<i class="fas fa-cog"></i> Configurações</a>';

        echo '</div>';
    }

    // ── Estilos ───────────────────────────────────────────────────────

    private function renderStyles(): void
    {
        echo <<<'CSS'
<style>
/* ════════════════════════════════════════
   NFS-e Nacional — Admin UI
   ════════════════════════════════════════ */

/* ── Topbar ── */
.nfse-topbar {
    display: flex;
    align-items: center;
    gap: 0;
    border-bottom: 1px solid #e0e0e0;
    margin-bottom: 20px;
    padding-bottom: 0;
}
.nfse-topbar-brand {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 14px;
    font-weight: 700;
    color: #1a237e;
    padding: 10px 20px 10px 0;
    border-right: 1px solid #e8e8e8;
    margin-right: 4px;
    white-space: nowrap;
}
.nfse-topbar-brand i { font-size: 16px; color: #3949ab; }
.nfse-topbar-nav { display: flex; align-items: stretch; flex: 1; }
.nfse-topbar-link {
    display: block;
    padding: 10px 16px;
    font-size: 13px;
    color: #555;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color .15s, border-color .15s;
}
.nfse-topbar-link:hover { color: #3949ab; text-decoration: none; }
.nfse-topbar-link.active { color: #3949ab; border-bottom-color: #3949ab; font-weight: 600; }
.nfse-topbar-config { margin-left: auto; }

/* ── Botões ── */
.nfse-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 4px;
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: opacity .15s;
}
.nfse-btn:hover { opacity: .85; text-decoration: none; }
.nfse-btn-primary  { background: #3949ab; color: #fff; border-color: #3949ab; }
.nfse-btn-success  { background: #2e7d32; color: #fff; border-color: #2e7d32; }
.nfse-btn-warning  { background: #e65100; color: #fff; border-color: #e65100; }
.nfse-btn-danger   { background: #b71c1c; color: #fff; border-color: #b71c1c; }
.nfse-btn-ghost    { background: #fff; color: #444; border-color: #ccc; }
.nfse-btn-ghost:hover { background: #f5f5f5; }

/* ── KPIs ── */
.nfse-kpi-row {
    display: flex;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.nfse-kpi-card {
    flex: 1;
    min-width: 130px;
    border: 1px solid #e8e8e8;
    border-radius: 5px;
    padding: 14px 16px;
    background: #fff;
    text-align: center;
}
.nfse-kpi-icon { font-size: 18px; margin-bottom: 6px; }
.nfse-kpi-value { font-size: 20px; font-weight: 700; line-height: 1.2; }
.nfse-kpi-label { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; letter-spacing: .4px; }

/* ── Dashboard grid ── */
.nfse-dashboard-grid {
    display: flex;
    gap: 14px;
    margin-bottom: 22px;
    align-items: flex-start;
}
.nfse-dashboard-grid .nfse-chart-wrap { flex: 1; min-width: 0; margin-bottom: 0; }
.nfse-dashboard-side { width: 280px; flex-shrink: 0; display: flex; flex-direction: column; gap: 12px; }

/* ── Painel lateral ── */
.nfse-side-panel {
    border: 1px solid #e8e8e8;
    border-radius: 5px;
    overflow: hidden;
    background: #fff;
}
.nfse-side-panel-title {
    background: #fafafa;
    padding: 9px 14px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #666;
    border-bottom: 1px solid #e8e8e8;
    display: flex;
    align-items: center;
    gap: 6px;
}
.nfse-side-panel-title small { font-weight: 400; text-transform: none; color: #aaa; }

/* ── Erros frequentes ── */
.nfse-err-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px;
    border-bottom: 1px solid #f5f5f5;
}
.nfse-err-row:last-child { border-bottom: none; }
.nfse-err-text { font-size: 11px; color: #555; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nfse-err-bar-wrap { width: 50px; height: 4px; background: #f0f0f0; border-radius: 2px; flex-shrink: 0; }
.nfse-err-bar { height: 4px; background: #b71c1c; border-radius: 2px; }
.nfse-err-count { font-size: 11px; font-weight: 700; color: #b71c1c; width: 20px; text-align: right; flex-shrink: 0; }
.nfse-side-empty { padding: 16px 14px; font-size: 12px; color: #888; display: flex; align-items: center; gap: 8px; }

/* ── Cobertura ── */
.nfse-coverage { padding: 16px 14px; text-align: center; }
.nfse-coverage-num { font-size: 36px; font-weight: 700; line-height: 1; }
.nfse-coverage-label { font-size: 12px; color: #777; margin-top: 6px; line-height: 1.5; }

/* ── Gráfico ── */
.nfse-chart-wrap {
    border: 1px solid #e8e8e8;
    border-radius: 5px;
    margin-bottom: 22px;
    background: #fff;
    overflow: hidden;
}
.nfse-chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    background: #fafafa;
}
.nfse-chart-body { padding: 16px 16px 10px; }
.nfse-chart-controls { display: flex; gap: 4px; }
.nfse-period-btn {
    padding: 3px 11px;
    font-size: 12px;
    border: 1px solid #ddd;
    border-radius: 3px;
    background: #fff;
    color: #555;
    cursor: pointer;
    transition: all .15s;
}
.nfse-period-btn:hover { border-color: #3949ab; color: #3949ab; }
.nfse-period-btn.active { background: #3949ab; border-color: #3949ab; color: #fff; font-weight: 600; }

/* ── Chips de estatística ── */
.nfse-chips-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.nfse-chips-label {
    font-size: 11px;
    color: #999;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-right: 4px;
}
.nfse-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 11px 4px 11px;
    border-radius: 20px;
    border: 1px solid #ddd;
    font-size: 12px;
    color: #444;
    background: #fff;
    text-decoration: none;
    transition: all .15s;
    cursor: pointer;
}
.nfse-chip:hover { border-color: var(--chip-color); color: var(--chip-color); text-decoration: none; }
.nfse-chip.active {
    background: var(--chip-color);
    border-color: var(--chip-color);
    color: #fff;
    font-weight: 600;
}
.nfse-chip-count {
    background: rgba(0,0,0,.1);
    border-radius: 10px;
    padding: 0 7px;
    font-size: 11px;
    font-weight: 700;
    min-width: 20px;
    text-align: center;
}
.nfse-chip.active .nfse-chip-count { background: rgba(255,255,255,.3); }

/* ── Barra de filtros ── */
.nfse-filter-bar { margin-bottom: 10px; }
.nfse-filter-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.nfse-input {
    height: 32px;
    padding: 0 10px;
    font-size: 13px;
    border: 1px solid #d0d0d0;
    border-radius: 4px;
    outline: none;
    transition: border-color .15s;
}
.nfse-input:focus { border-color: #3949ab; }
.nfse-filter-date-group {
    display: flex;
    align-items: center;
    gap: 5px;
}
.nfse-filter-date-label {
    font-size: 12px;
    color: #888;
    white-space: nowrap;
}
.nfse-input-date { width: 130px; }
.nfse-result-count {
    margin-left: auto;
    font-size: 12px;
    color: #888;
}

/* ── Tabela ── */
.nfse-table-wrap {
    border: 1px solid #e8e8e8;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 8px;
}
.nfse-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.nfse-table thead th {
    background: #fafafa;
    color: #666;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 9px 12px;
    border-bottom: 1px solid #e8e8e8;
    white-space: nowrap;
}
.nfse-table tbody td {
    padding: 9px 12px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    color: #333;
}
.nfse-table tbody tr:last-child td { border-bottom: none; }
.nfse-table tbody tr:hover td { background: #f5f7ff; }
.nfse-row-error td { background: #fff8f8; }
.nfse-row-error:hover td { background: #ffefef !important; }

.nfse-col-id    { color: #aaa !important; font-size: 11px; width: 40px; }
.nfse-col-num   { font-size: 12px; color: #666 !important; }
.nfse-col-money { font-weight: 600; white-space: nowrap; }
.nfse-col-date  { font-size: 12px; color: #888 !important; white-space: nowrap; }

/* ── Status badge ── */
.nfse-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
}
.nfse-status-badge.large { font-size: 12px; padding: 3px 10px; }

/* ── Ações na linha ── */
.nfse-row-actions { display: flex; gap: 4px; }
.nfse-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 9px;
    font-size: 12px;
    border-radius: 3px;
    border: 1px solid #d0d0d0;
    background: #fff;
    color: #444;
    text-decoration: none;
    transition: all .15s;
    white-space: nowrap;
}
.nfse-action-btn:hover { background: #f0f0f0; text-decoration: none; }
.nfse-action-btn.danger { border-color: #ffcdd2; color: #b71c1c; }
.nfse-action-btn.danger:hover { background: #ffebee; }

/* ── Alertas ── */
.nfse-alert {
    padding: 10px 14px;
    border-radius: 4px;
    font-size: 13px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.nfse-alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
.nfse-alert-danger  { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
.nfse-alert-warning { background: #fff8e1; color: #e65100; border: 1px solid #ffcc80; }

/* ── Empty state ── */
.nfse-empty {
    text-align: center;
    padding: 32px !important;
    color: #aaa;
    font-size: 13px;
    line-height: 2;
}
.nfse-empty i { font-size: 24px; display: block; margin-bottom: 4px; }

/* ── Paginação ── */
.nfse-pagination {
    display: flex;
    align-items: center;
    gap: 3px;
    justify-content: center;
    margin-top: 10px;
    flex-wrap: wrap;
}
.nfse-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    font-size: 12px;
    border: 1px solid #ddd;
    border-radius: 3px;
    background: #fff;
    color: #444;
    text-decoration: none;
    transition: all .15s;
}
.nfse-page-btn:hover { background: #f0f0f0; text-decoration: none; }
.nfse-page-btn.active { background: #3949ab; border-color: #3949ab; color: #fff; font-weight: 700; }
.nfse-page-info { font-size: 12px; color: #888; margin-left: 8px; }

/* ── Footer da tabela (dashboard) ── */
.nfse-table-footer { text-align: right; margin-top: 6px; }
.nfse-link-all { font-size: 12px; color: #3949ab; text-decoration: none; }
.nfse-link-all:hover { text-decoration: underline; }

/* ── Título de seção ── */
.nfse-section-title {
    font-size: 13px;
    font-weight: 700;
    color: #444;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 7px;
}

/* ── Detalhe ── */
.nfse-detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid #eee;
}
.nfse-detail-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    color: #222;
}
.nfse-back-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #555;
    text-decoration: none;
    font-size: 12px;
}
.nfse-back-link:hover { background: #f5f5f5; text-decoration: none; }
.nfse-detail-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.nfse-detail-grid { display: flex; gap: 20px; align-items: flex-start; }
.nfse-detail-main { flex: 1; min-width: 0; }
.nfse-detail-side { width: 240px; flex-shrink: 0; }
.nfse-detail-section {
    border: 1px solid #e8e8e8;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 14px;
}
.nfse-detail-section-title {
    background: #fafafa;
    padding: 8px 14px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #666;
    border-bottom: 1px solid #e8e8e8;
}
.nfse-kv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.nfse-kv-table th {
    width: 36%;
    padding: 8px 14px;
    font-weight: 600;
    color: #666;
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
    font-size: 12px;
    vertical-align: top;
    white-space: nowrap;
}
.nfse-kv-table td {
    padding: 8px 14px;
    border-bottom: 1px solid #f0f0f0;
    color: #333;
    vertical-align: top;
}
.nfse-kv-table tr:last-child th,
.nfse-kv-table tr:last-child td { border-bottom: none; }
.nfse-kv-table.small th, .nfse-kv-table.small td { padding: 6px 14px; font-size: 12px; }
.nfse-code {
    font-family: monospace;
    font-size: 11px;
    word-break: break-all;
    background: #f5f5f5;
    padding: 1px 4px;
    border-radius: 2px;
}
.nfse-doc-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    background: #2e7d32;
    text-decoration: none;
    transition: opacity .15s;
}
.nfse-doc-btn:hover { opacity: .88; text-decoration: none; color: #fff; }
.nfse-doc-btn.secondary { background: #455a64; }
.nfse-doc-btn + .nfse-doc-btn { border-top: 1px solid rgba(255,255,255,.15); }
.nfse-error-box {
    border: 1px solid #ffcdd2;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 14px;
}
.nfse-error-box-title {
    background: #ffebee;
    color: #b71c1c;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    border-bottom: 1px solid #ffcdd2;
}
.nfse-error-box-body {
    padding: 10px 14px;
    font-family: monospace;
    font-size: 12px;
    word-break: break-word;
    color: #333;
    background: #fff;
}
.nfse-muted { color: #aaa; }

@media (max-width: 768px) {
    .nfse-detail-grid { flex-direction: column; }
    .nfse-detail-side { width: 100%; }
}
</style>
CSS;
    }
}
