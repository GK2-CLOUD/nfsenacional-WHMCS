<?php

namespace GK2\NfseNacional\Domain\Entity;

use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Enum\Ambiente;
use GK2\NfseNacional\Domain\Enum\NfseStatus;

/**
 * Entidade que representa uma NFS-e Nacional persistida no banco.
 *
 * Objeto imutavel criado a partir de um registro da tblnfsenacional.
 */
class Nfse
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $clientId,
        public readonly ?string $invoiceId,
        public readonly ?string $clientName,
        public readonly ?float $total,
        public readonly ?float $valorIss,
        public readonly ?bool $retidoIss,
        public readonly NfseStatus $status,
        public readonly ?int $numeroDps,
        public readonly ?string $serieDps,
        public readonly ?string $chaveAcesso,
        public readonly ?string $numeroNfseNacional,
        public readonly ?string $protocolo,
        public readonly ?string $codigoVerificacao,
        public readonly ?string $danfseUrl,
        public readonly ?string $xmlUrl,
        public readonly ?string $ambiente,
        public readonly ?string $erro,
        public readonly ?string $dataEmissao,
        public readonly ?string $dataAutorizacao,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    /**
     * Cria uma instancia a partir de um registro do banco (stdClass).
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: $row->id ?? null,
            clientId: $row->id_client ?? null,
            invoiceId: $row->id_invoice ?? null,
            clientName: $row->client_name ?? null,
            total: isset($row->total) ? (float) $row->total : null,
            valorIss: isset($row->valor_iss) ? (float) $row->valor_iss : null,
            retidoIss: isset($row->retido_iss) ? (bool) $row->retido_iss : null,
            status: NfseStatus::tryFrom($row->status ?? '') ?? NfseStatus::PENDENTE,
            numeroDps: $row->numero_dps ?? null,
            serieDps: $row->serie_dps ?? null,
            chaveAcesso: $row->chave_acesso ?? null,
            numeroNfseNacional: $row->numero_nfse_nacional ?? null,
            protocolo: $row->protocolo ?? null,
            codigoVerificacao: $row->codigo_verificacao ?? null,
            danfseUrl: $row->danfse_url ?? null,
            xmlUrl: $row->xml_url ?? null,
            ambiente: $row->ambiente ?? null,
            erro: $row->erro ?? null,
            dataEmissao: $row->data_emissao ?? null,
            dataAutorizacao: $row->data_autorizacao ?? null,
            createdAt: $row->created_at ?? null,
            updatedAt: $row->updated_at ?? null,
        );
    }

    /**
     * Converte para array associativo.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'id_client' => $this->clientId,
            'id_invoice' => $this->invoiceId,
            'client_name' => $this->clientName,
            'total' => $this->total,
            'valor_iss' => $this->valorIss,
            'retido_iss' => $this->retidoIss,
            'status' => $this->status->value,
            'numero_dps' => $this->numeroDps,
            'serie_dps' => $this->serieDps,
            'chave_acesso' => $this->chaveAcesso,
            'numero_nfse_nacional' => $this->numeroNfseNacional,
            'protocolo' => $this->protocolo,
            'codigo_verificacao' => $this->codigoVerificacao,
            'danfse_url' => $this->danfseUrl,
            'xml_url' => $this->xmlUrl,
            'ambiente' => $this->ambiente,
            'erro' => $this->erro,
            'data_emissao' => $this->dataEmissao,
            'data_autorizacao' => $this->dataAutorizacao,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Verifica se a nota ja foi autorizada.
     */
    public function isAutorizada(): bool
    {
        return $this->status === NfseStatus::AUTORIZADA;
    }

    /**
     * Verifica se a nota pode ser cancelada.
     */
    public function podeCancelar(): bool
    {
        return $this->status->podeCancelar();
    }

    /**
     * Verifica se a nota esta em processamento.
     */
    public function isProcessando(): bool
    {
        return $this->status === NfseStatus::PROCESSANDO;
    }

    /**
     * Retorna o ambiente da nota como enum tipado.
     */
    public function getAmbiente(): ?Ambiente
    {
        return $this->ambiente ? Ambiente::tryFrom($this->ambiente) : null;
    }

    /**
     * Valida que esta nota pertence ao ambiente ativo.
     *
     * @throws \GK2\NfseNacional\Domain\AmbienteMismatchException
     */
    public function assertAmbiente(AmbienteGuard $guard): void
    {
        $guard->assertMesmoAmbiente($this->ambiente);
    }
}
