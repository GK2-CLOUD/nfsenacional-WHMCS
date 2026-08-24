<?php

namespace GK2\NfseNacional\Transport;

/**
 * DTO que normaliza respostas da API NFS-e Nacional.
 *
 * Encapsula sucesso/falha, dados parseados, erros e corpo bruto
 * para uso uniforme em todas as camadas do modulo.
 */
class ApiResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly array $data,
        public readonly array $errors,
        public readonly int $httpCode,
        public readonly string $rawBody,
    ) {
    }

    /**
     * Cria um ApiResponse de sucesso.
     */
    public static function success(array $data, int $httpCode = 200, string $rawBody = ''): self
    {
        return new self(
            success: true,
            data: $data,
            errors: [],
            httpCode: $httpCode,
            rawBody: $rawBody,
        );
    }

    /**
     * Cria um ApiResponse de erro.
     */
    public static function error(array $errors, int $httpCode = 0, string $rawBody = '', array $data = []): self
    {
        return new self(
            success: false,
            data: $data,
            errors: $errors,
            httpCode: $httpCode,
            rawBody: $rawBody,
        );
    }

    /**
     * Indica se a resposta representa um processamento em andamento.
     * (retornou protocolo mas ainda sem numero de NFS-e)
     */
    public function isProcessando(): bool
    {
        if (!$this->success) {
            return false;
        }

        return !empty($this->data['protocolo']) && empty($this->data['numero_nfse']);
    }

    /**
     * Retorna o primeiro erro, se houver.
     */
    public function getFirstError(): string
    {
        return $this->errors[0] ?? '';
    }
}
