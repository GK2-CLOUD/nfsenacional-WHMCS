# NFS-e Nacional — Addon WHMCS

Addon para WHMCS que integra a emissão de faturas com a **NFS-e Nacional (ADN)** —
o padrão nacional de Nota Fiscal de Serviço Eletrônica mantido pela RFB/ENCAT
(https://www.gov.br/nfse). Cobre emissão, consulta, cancelamento, download de
DANFS-e/XML e envio automático por e-mail, com isolamento total entre os
ambientes de **produção** e **homologação**.

> Desenvolvido pela [GK2](https://gk2.com.br).

## Instalação

Guia completo de instalação e configuração (produção e homologação):
**https://oraculo.gk2.cloud/books/whmcs/page/configuracao-nfs-e-nacional-v10-producao-e-homologacao**

Resumo rápido:

1. Baixe o arquivo `nfsenacional-vX.Y.Z.zip` da [página de releases](https://github.com/<org>/<repo>/releases).
2. Extraia o conteúdo em `modules/addons/nfsenacional/` na sua instalação WHMCS.
3. O zip já inclui a pasta `vendor/` com todas as dependências — não é necessário rodar `composer install` no servidor de produção.
4. Em **Configuration Value → System → Activate Modules → Other Addon Modules**, ative o addon "NFS-e Nacional".
5. Preencha as configurações do addon (certificado A1, série DPS, ambiente, política de emissão) — detalhado no guia acima.

> ⚠️ **Não use o código direto do repositório em produção.** Clone o repo apenas para
> desenvolvimento. Para instalação, use sempre o zip de release, que já vem com
> `vendor/` incluso e a pasta renomeada para `nfsenacional`.

## O que o addon faz

- Emite a DPS (Declaração de Prestação de Serviço) a partir de uma fatura paga/gerada no WHMCS e obtém a NFS-e autorizada pelo SEFIN Nacional.
- Consulta, cancela e reenvia por e-mail notas já emitidas.
- Disponibiliza download de DANFS-e (PDF) e XML tanto na área do cliente quanto no admin.
- Mantém histórico completo por fatura (status, protocolo, chave de acesso, valores de ISS) em tabela própria do banco.

## Arquitetura

O código vive em `src/NfseNacional/` sob o namespace `GK2\NfseNacional`, organizado
por responsabilidade (não por tipo de arquivo MVC):

```
nfsenacional.php     Entry point do addon (config/activate/deactivate/output/clientarea)
hooks.php             Registro dos hooks do WHMCS (carregado automaticamente)
src/NfseNacional/
├── Bootstrap.php              Autoload (Composer, com fallback PSR-4 manual)
├── Admin/                     Painel administrativo do addon
│   ├── AdminController.php    Dispatch de páginas (dashboard, list, detail)
│   ├── ConfigFields.php       Definição dos campos de configuração
│   └── Action/                Ações disparadas do admin (Emitir, Cancelar, Excluir, Reenviar e-mail)
├── ClientArea/                 Área do cliente (listagem de notas do usuário logado)
│   ├── ClientAreaController.php
│   └── DownloadController.php  Proxy de download (DANFS-e / XML)
├── Config/
│   └── ModuleConfig.php        Leitura/escrita das configurações do addon (inclui
│                                criptografia AES-256-CBC da senha do certificado)
├── Domain/                     Regras de negócio e modelos de domínio
│   ├── AmbienteGuard.php        Trava central: resolve o ambiente (produção/homologação)
│   │                             uma única vez por requisição e nunca deixa misturar dados
│   │                             ou chamadas de API entre os dois ambientes
│   ├── Entity/                  Dps, Nfse
│   ├── Enum/                    Ambiente, EmissaoPolitica, NfseStatus
│   └── Service/                 Orquestração dos casos de uso:
│       EmissaoService, ConsultaService, CancelamentoService,
│       EmailService, DownloadUrlService, CepIbgeCache
├── Fiscal/                     Tudo relacionado à montagem e assinatura do documento fiscal
│   ├── NacionalProvider.php     Implementação de ProviderInterface para a API ADN
│   ├── Mapper/                  Fatura/cliente WHMCS → estruturas fiscais
│   │                             (Prestador, Tomador, Serviço, Tributo)
│   ├── Payload/                 Monta o XML da DPS/Evento conforme XSD oficial
│   └── Signer/XmlSigner.php     Assinatura digital XML (via robrichards/xmlseclibs)
├── Hook/                        Integração com os hooks do WHMCS
│   ├── HookHandler.php          Registro central de todos os hooks
│   ├── InvoiceHooks.php         Gatilhos de emissão automática (fatura criada/paga)
│   └── AdminInvoiceUI.php, AdminInvoiceListUI.php,
│       ClientInvoiceListUI.php, ClientAreaMenu.php   Injeção de UI nas telas do WHMCS
├── Persistence/                 Acesso a dados (tblnfsenacional e tabelas de apoio)
│   ├── NfseRepository.php, Migration.php, DpsSequence.php
├── Security/TokenSigner.php     Assinatura/validação de tokens usados nos links de download
└── Transport/                   Cliente HTTP e autenticação com a API Nacional
    ├── HttpClient.php, ApiEndpoints.php, ApiResponse.php
    └── Auth/                    CertificateAuth (mTLS via certificado A1) e TokenAuth
```

### Fluxo de emissão (resumo)

1. Um hook (`InvoiceHooks`) ou uma ação manual do admin chama `EmissaoService::processarEmissao()`.
2. `AmbienteGuard` resolve o ambiente ativo (produção/homologação) uma vez e o propaga
   para repositório, provider e endpoints — impedindo qualquer mistura entre os dois.
3. `DpsPayloadBuilder` (usando os `Mapper`s) monta o XML da DPS a partir da fatura e do cliente.
4. `NacionalProvider` compacta o XML (GZip + base64) e envia para o SEFIN Nacional
   (`ApiEndpoints` resolve as URLs por ambiente: `*.nfse.gov.br` em produção,
   `*.producaorestrita.nfse.gov.br` em homologação).
5. A resposta é persistida via `NfseRepository`, e-mail é disparado se habilitado
   (`EmailService`), e o resultado é exibido no admin/área do cliente.

Consulta, cancelamento e obtenção de DANFS-e/XML seguem o mesmo padrão através de
`ConsultaService` e `CancelamentoService`.

## Requisitos

- PHP >= 8.1
- WHMCS >= 8.12
- Certificado digital A1 (.pfx) do prestador de serviços

## Stack

- [`guzzlehttp/guzzle`](https://github.com/guzzle/guzzle) — cliente HTTP (com autenticação mTLS via certificado A1)
- [`robrichards/xmlseclibs`](https://github.com/robrichards/xmlseclibs) — assinatura digital XML

## Desenvolvimento

### Setup inicial

```bash
# Clone o repositório
git clone <repo-url>
cd nota-fiscal

# Instale as dependências do módulo
cd nfsenacional-WHMCS
composer install
```

> **Importante:** A pasta `vendor/` não é commitada no repositório. Após clonar ou trocar
> de branch, execute sempre `composer install` para regenerá-la a partir do `composer.lock`.
> O `composer.lock` garante que todos os ambientes usem exatamente as mesmas versões das
> dependências.

### Estrutura do projeto

```
nota-fiscal/                        ← Raiz do repositório
├── .github/workflows/release.yml   ← Workflow de release automático
├── DOCUMENTACAO-REFERENCIA-NFSE.md ← Catálogo dos arquivos de suporte (XSD, APIs, anexos)
├── esquemas-nfse-rtc-v1-01-*/      ← Schemas XSD oficiais da NFS-e
├── MANUAL API/                     ← Especificações OpenAPI/Swagger das APIs
├── anexo_*.xlsx                    ← Planilhas oficiais (IBGE, NBS, tributações, eventos)
├── nfsenacional-WHMCS/             ← Módulo WHMCS
│   ├── composer.json               ← Dependências PHP (guzzle, xmlseclibs)
│   ├── composer.lock               ← Versões exatas lockadas (commitado)
│   ├── vendor/                     ← ⚠️ Não commitado — gerado via composer install
│   ├── CHANGELOG.md                ← Histórico de versões (Keep a Changelog)
│   └── src/NfseNacional/           ← Código-fonte do módulo
└── guia_*.pdf                      ← Guias e notas técnicas
```

### Fluxo de release

O release é **100% automatizado** via GitHub Actions (`.github/workflows/release.yml`).
Para publicar uma nova versão:

1. **Atualize a versão** no docblock do `nfsenacional-WHMCS/nfsenacional.php`:
   ```php
   /**
    * @version    1.0.1   ← altere aqui
    */
   ```

2. **Registre as mudanças** no `nfsenacional-WHMCS/CHANGELOG.md`:
   ```markdown
   ## [1.0.1] - 2026-08-10

   ### Corrigido
   - Timeout em chamadas de consulta corrigido
   - Encoding UTF-8 no e-mail de notificação

   ### Adicionado
   - Suporte a certificado A3 com cadeia completa
   ```

3. **Abra um PR** com as alterações para a branch `main`.

4. **Ao mergear o PR**, o workflow automaticamente:
   - Extrai a versão do docblock
   - Verifica se a tag já existe (evita duplicatas)
   - Executa `composer install --no-dev`
   - Empacota o módulo em `nfsenacional-vX.Y.Z.zip` (pasta renomeada para `nfsenacional`)
   - Extrai a seção correspondente do `CHANGELOG.md`
   - Cria a tag `vX.Y.Z` no Git
   - Publica o release no GitHub com o zip anexado

### Convenções

| Convenção | Regra |
| --- | --- |
| **Versionamento** | [SemVer](https://semver.org/) — `MAJOR.MINOR.PATCH` |
| **Changelog** | [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) — seções `Adicionado`, `Alterado`, `Corrigido`, `Removido` |
| **PHP mínimo** | 8.3+ (produção); `composer.json` declara `>=8.1` por compatibilidade com WHMCS |
| **Vendor** | Não commitado — `.gitignore` ignora `vendor/`; `composer.lock` é commitado |
| **Release** | Automático no merge de PR para `main`; tag = versão do módulo |
| **Nome do pacote** | `nfsenacional/` (sem sufixo `-WHMCS`) dentro do zip de release |
