<?php

namespace GK2\NfseNacional\Persistence;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Domain\Enum\NfseStatus;
use WHMCS\Database\Capsule;

/**
 * Repositorio para operacoes CRUD na tabela tblnfsenacional.
 *
 * Centraliza todos os acessos ao banco de dados relacionados
 * a registros de NFS-e Nacional.
 *
 * REGRA CRITICA: todas as queries que retornam notas filtram
 * pelo ambiente ativo (via AmbienteGuard), impedindo que notas
 * de producao sejam visíveis/operaveis em homologacao e vice-versa.
 */
class NfseRepository
{
    private const TABLE = 'tblnfsenacional';

    private AmbienteGuard $guard;

    public function __construct(?AmbienteGuard $guard = null)
    {
        $this->guard = $guard ?? AmbienteGuard::getInstance();
    }

    /**
     * Busca NFS-e por ID da fatura (retorna a mais recente DO AMBIENTE ATIVO).
     */
    public function findByInvoice(int $invoiceId): ?Nfse
    {
        $row = Capsule::table(self::TABLE)
            ->where('id_invoice', $invoiceId)
            ->where('ambiente', $this->guard->value())
            ->orderBy('id', 'desc')
            ->first();

        return $row ? Nfse::fromRow($row) : null;
    }

    /**
     * Busca NFS-e por chave de acesso (valida ambiente).
     */
    public function findByChaveAcesso(string $chaveAcesso): ?Nfse
    {
        $row = Capsule::table(self::TABLE)
            ->where('chave_acesso', $chaveAcesso)
            ->where('ambiente', $this->guard->value())
            ->first();

        return $row ? Nfse::fromRow($row) : null;
    }

    /**
     * Busca NFS-e por ID interno (valida ambiente apos busca).
     */
    public function findById(int $id): ?Nfse
    {
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        $nfse = Nfse::fromRow($row);
        // Cross-check: bloqueia se a nota pertence a outro ambiente
        $nfse->assertAmbiente($this->guard);

        return $nfse;
    }

    /**
     * Retorna todas as NFS-e com status PROCESSANDO DO AMBIENTE ATIVO.
     *
     * @return array Array de stdClass (registros brutos)
     */
    public function findPendentes(): array
    {
        return Capsule::table(self::TABLE)
            ->where('status', NfseStatus::PROCESSANDO->value)
            ->where('ambiente', $this->guard->value())
            ->orderBy('id', 'asc')
            ->get()
            ->all();
    }

    /**
     * Retorna NFS-e de um cliente com paginacao e busca (somente ambiente ativo).
     *
     * @param string $search Termo de busca (nº NFS-e ou nº fatura)
     * @return array Array de Nfse entities
     */
    public function findByClient(
        int $clientId,
        int $limit = 10,
        int $offset = 0,
        string $search = '',
        string $orderBy = 'id',
        string $direction = 'desc',
    ): array {
        $allowed = ['id', 'numero_nfse_nacional', 'id_invoice', 'data_autorizacao', 'total', 'status'];
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $query = Capsule::table(self::TABLE)
            ->where('id_client', $clientId)
            ->where('ambiente', $this->guard->value());

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('numero_nfse_nacional', 'like', '%' . $search . '%')
                  ->orWhere('id_invoice', 'like', '%' . $search . '%');
            });
        }

        $rows = $query->orderBy($orderBy, $direction)->offset($offset)->limit($limit)->get();

        return array_map(fn ($row) => Nfse::fromRow($row), $rows->all());
    }

    /**
     * Conta NFS-e de um cliente com busca (somente ambiente ativo).
     */
    public function countByClient(int $clientId, string $search = ''): int
    {
        $query = Capsule::table(self::TABLE)
            ->where('id_client', $clientId)
            ->where('ambiente', $this->guard->value());

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('numero_nfse_nacional', 'like', '%' . $search . '%')
                  ->orWhere('id_invoice', 'like', '%' . $search . '%');
            });
        }

        return $query->count();
    }

    /**
     * Cria ou atualiza registro de NFS-e para uma fatura.
     *
     * @param int $invoiceId ID da fatura
     * @param array $fields Campos a inserir/atualizar
     * @return int ID do registro
     */
    public function createOrUpdate(int $invoiceId, array $fields): int
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $fields['id_invoice'] = $invoiceId;

        $existing = Capsule::table(self::TABLE)
            ->where('id_invoice', $invoiceId)
            ->first();

        if ($existing) {
            Capsule::table(self::TABLE)
                ->where('id', $existing->id)
                ->update($fields);

            return $existing->id;
        }

        $fields['created_at'] = date('Y-m-d H:i:s');
        return Capsule::table(self::TABLE)->insertGetId($fields);
    }

    /**
     * Atualiza o status de uma NFS-e.
     *
     * @param int $id ID do registro
     * @param NfseStatus $status Novo status
     * @param array $extra Campos adicionais a atualizar
     */
    public function updateStatus(int $id, NfseStatus $status, array $extra = []): bool
    {
        $fields = array_merge($extra, [
            'status' => $status->value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return Capsule::table(self::TABLE)
            ->where('id', $id)
            ->update($fields) > 0;
    }

    /**
     * Retorna estatisticas do mes atual (somente ambiente ativo).
     */
    public function stats(?string $yearMonth = null): array
    {
        $yearMonth = $yearMonth ?? date('Y-m');

        $query = Capsule::table(self::TABLE)
            ->where('ambiente', $this->guard->value())
            ->where('updated_at', 'like', $yearMonth . '%');

        return [
            'autorizadas' => (clone $query)->where('status', NfseStatus::AUTORIZADA->value)->count(),
            'processando' => (clone $query)->where('status', NfseStatus::PROCESSANDO->value)->count(),
            'erros' => (clone $query)->where('status', NfseStatus::ERRO->value)->count(),
            'canceladas' => (clone $query)->where('status', NfseStatus::CANCELADA->value)->count(),
        ];
    }

    /**
     * Retorna ISS acumulado e faturamento por mês nos últimos N meses.
     *
     * Retorna array indexado por 'YYYY-MM':
     *   ['total_faturado' => float, 'total_iss' => float, 'retido' => float, 'proprio' => float]
     */
    public function issPorMes(int $meses = 12): array
    {
        $inicio = date('Y-m-d', strtotime("-{$meses} months"));

        $rows = Capsule::table(self::TABLE)
            ->select(
                Capsule::raw("DATE_FORMAT(data_autorizacao, '%Y-%m') as mes"),
                Capsule::raw('SUM(total) as total_faturado'),
                Capsule::raw('SUM(valor_iss) as total_iss'),
                Capsule::raw('SUM(CASE WHEN retido_iss = 1 THEN valor_iss ELSE 0 END) as retido'),
                Capsule::raw('SUM(CASE WHEN retido_iss = 0 OR retido_iss IS NULL THEN valor_iss ELSE 0 END) as proprio'),
            )
            ->where('ambiente', $this->guard->value())
            ->where('status', NfseStatus::AUTORIZADA->value)
            ->where('data_autorizacao', '>=', $inicio . ' 00:00:00')
            ->groupBy('mes')
            ->orderBy('mes', 'asc')
            ->get();

        $mapa = [];
        foreach ($rows as $row) {
            if (!empty($row->mes)) {
                $mapa[$row->mes] = [
                    'total_faturado' => (float) ($row->total_faturado ?? 0),
                    'total_iss'      => (float) ($row->total_iss ?? 0),
                    'retido'         => (float) ($row->retido ?? 0),
                    'proprio'        => (float) ($row->proprio ?? 0),
                ];
            }
        }

        $resultado = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $mes = date('Y-m', strtotime("-{$i} months"));
            $resultado[$mes] = $mapa[$mes] ?? ['total_faturado' => 0, 'total_iss' => 0, 'retido' => 0, 'proprio' => 0];
        }

        return $resultado;
    }

    /**
     * Retorna os erros mais frequentes (ambiente ativo, últimos 90 dias).
     *
     * @return array [['erro' => string, 'total' => int], ...]
     */
    public function errosFrequentes(int $limit = 5): array
    {
        $inicio = date('Y-m-d', strtotime('-90 days'));

        $rows = Capsule::table(self::TABLE)
            ->select(
                Capsule::raw('TRIM(SUBSTRING_INDEX(erro, ":", 2)) as codigo_erro'),
                Capsule::raw('COUNT(*) as total'),
            )
            ->where('ambiente', $this->guard->value())
            ->where('status', NfseStatus::ERRO->value)
            ->whereNotNull('erro')
            ->where('updated_at', '>=', $inicio)
            ->groupBy('codigo_erro')
            ->orderBy('total', 'desc')
            ->limit($limit)
            ->get();

        return array_map(fn ($r) => [
            'erro'  => $r->codigo_erro ?? '—',
            'total' => (int) $r->total,
        ], $rows->all());
    }

    /**
     * Conta faturas pagas nos últimos N dias sem NFS-e autorizada (ambiente ativo).
     */
    public function countFaturasSemNfse(int $dias = 30): int
    {
        $inicio = date('Y-m-d', strtotime("-{$dias} days"));

        return Capsule::table('tblinvoices as i')
            ->leftJoin(self::TABLE . ' as n', function ($join) {
                $join->on('n.id_invoice', '=', 'i.id')
                     ->where('n.ambiente', '=', $this->guard->value())
                     ->where('n.status', '=', NfseStatus::AUTORIZADA->value);
            })
            ->where('i.status', 'Paid')
            ->where('i.datepaid', '>=', $inicio)
            ->whereNull('n.id')
            ->count();
    }

    /**
     * Retorna contagens mensais por status nos últimos N meses (ambiente ativo).
     *
     * Retorna array indexado por 'YYYY-MM', cada entrada com chaves:
     *   autorizadas, processando, erros, canceladas
     */
    public function statsPorMes(int $meses = 12): array
    {
        $inicio = date('Y-m-d', strtotime("-{$meses} months"));

        $rows = Capsule::table(self::TABLE)
            ->select(
                Capsule::raw("DATE_FORMAT(updated_at, '%Y-%m') as mes"),
                'status',
                Capsule::raw('COUNT(*) as total'),
            )
            ->where('ambiente', $this->guard->value())
            ->where('updated_at', '>=', $inicio . ' 00:00:00')
            ->groupBy('mes', 'status')
            ->orderBy('mes', 'asc')
            ->get();

        // Montar mapa mes → status → total
        $mapa = [];
        foreach ($rows as $row) {
            $mapa[$row->mes][$row->status] = (int) $row->total;
        }

        // Garantir todos os meses do intervalo, mesmo sem dados
        $resultado = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $mes = date('Y-m', strtotime("-{$i} months"));
            $resultado[$mes] = [
                'autorizadas' => $mapa[$mes][NfseStatus::AUTORIZADA->value]  ?? 0,
                'processando' => $mapa[$mes][NfseStatus::PROCESSANDO->value] ?? 0,
                'erros'       => $mapa[$mes][NfseStatus::ERRO->value]        ?? 0,
                'canceladas'  => $mapa[$mes][NfseStatus::CANCELADA->value]   ?? 0,
            ];
        }

        return $resultado;
    }

    /**
     * Pesquisa NFS-e com filtros.
     */
    public function search(
        array $filters = [],
        string $orderBy = 'id',
        string $direction = 'desc',
        int $limit = 25,
        int $offset = 0,
    ): array {
        $query = Capsule::table(self::TABLE);

        // SEMPRE filtrar pelo ambiente ativo — nunca exibir notas de outro ambiente
        $query->where('ambiente', $this->guard->value());

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        // Nota: filtro 'ambiente' dos $filters e ignorado — sempre usa o ambiente ativo
        if (!empty($filters['id_invoice'])) {
            $query->where('id_invoice', $filters['id_invoice']);
        }
        if (!empty($filters['id_client'])) {
            $query->where('id_client', $filters['id_client']);
        }
        if (!empty($filters['chave_acesso'])) {
            $query->where('chave_acesso', 'like', '%' . $filters['chave_acesso'] . '%');
        }
        if (!empty($filters['client_name'])) {
            $query->where('client_name', 'like', '%' . $filters['client_name'] . '%');
        }
        if (!empty($filters['data_inicio'])) {
            $query->where('created_at', '>=', $filters['data_inicio']);
        }
        if (!empty($filters['data_fim'])) {
            $query->where('created_at', '<=', $filters['data_fim'] . ' 23:59:59');
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderBy($orderBy, $direction)
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'total' => $total,
            'data' => array_map(fn ($row) => Nfse::fromRow($row), $rows->all()),
        ];
    }

    /**
     * Exclui um registro por ID.
     */
    public function delete(int $id): bool
    {
        return Capsule::table(self::TABLE)->where('id', $id)->delete() > 0;
    }
}
