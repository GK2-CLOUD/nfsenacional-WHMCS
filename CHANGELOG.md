# Changelog

Todos as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [1.0.0] - 2026-07-25

### Adicionado
- Emissão de NFS-e via API Sefin Nacional (DPS → NFS-e síncrona)
- Cancelamento de NFS-e com eventos (cancelamento, cancelamento por substituição)
- Consulta de NFS-e por chave de acesso
- Download de DANFS-e (PDF) e XML na área do cliente e admin
- Envio automático de e-mail com DANFS-e/XML após emissão
- Suporte a certificado digital A1 (.pfx/.p12) para mTLS e assinatura XMLDSIG
- Criptografia AES-256-CBC da senha do certificado no banco (com migração automática de plaintext)
- Isolamento total entre ambientes de produção e homologação via `AmbienteGuard`
- Configuração de política de emissão (manual, automática na criação da fatura, automática no pagamento)
- Tabela própria (`tblnfsenacional`) com histórico de status, protocolos e chaves de acesso
- Painel administrativo com dashboard de status, listagem com filtros e página de detalhes
- Injeção de UI nas telas de fatura do admin e área do cliente (botões de ação)
- Cache local de CEP → código IBGE para validação de município
- Assinatura/validação de tokens para links seguros de download
- Fallback de autoloader PSR-4 manual quando `vendor/autoload.php` não está disponível
