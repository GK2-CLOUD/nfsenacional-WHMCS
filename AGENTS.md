# AGENTS.md — NFS-e Nacional WHMCS Module

Instruções para agentes de IA (Claude Code, Cursor, Copilot, Reasonix, etc.)
que trabalham neste módulo. Siga estas regras **antes** de propor ou escrever
qualquer código.

---

## Regras incontornáveis

### 1. PHP 8.3+ obrigatório

O módulo deve rodar em PHP 8.3+. Use recursos modernos:
- `enum` (não constantes de classe)
- `match` (não `switch`)
- Constructor property promotion
- Typed properties + return types em todos os métodos
- `?Type` para nullable (não `Type|null` em docblocks)

```php
// ✅ Correto
class NfseRepository
{
    public function __construct(
        private AmbienteGuard $guard = new AmbienteGuard(),
    ) {}
    
    public function findByInvoice(int $invoiceId): ?Nfse
    {
        return match ($this->guard->value()) {
            'producao' => $this->queryProducao($invoiceId),
            'homologacao' => $this->queryHomologacao($invoiceId),
        };
    }
}
```

### 2. Namespace e estrutura de diretórios

- Namespace raiz: `GK2\NfseNacional\`
- PSR-4 mapeado: `src/NfseNacional/` → `GK2\NfseNacional\`
- Organização por **responsabilidade**, não por tipo de arquivo:

```
src/NfseNacional/
├── Admin/           Painel administrativo (controller, actions, config fields)
├── ClientArea/      Área do cliente (controller, download proxy)
├── Config/          Leitura/escrita de configurações do addon
├── Domain/          Regras de negócio — NUNCA acoplar a WHMCS aqui
│   ├── Entity/      Dps, Nfse (objetos de domínio puros)
│   ├── Enum/        Ambiente, EmissaoPolitica, NfseStatus
│   └── Service/     Casos de uso: EmissaoService, ConsultaService, CancelamentoService
├── Fiscal/          XML fiscal, assinatura, providers de API
│   ├── Mapper/      WHMCS → estruturas fiscais (Prestador, Tomador, Serviço, Tributo)
│   ├── Payload/     Montagem de XML (DPS, Evento) conforme XSD oficial
│   └── Signer/      Assinatura digital XMLDSIG
├── Hook/            Registro de hooks WHMCS — SOMENTE glue code
├── Persistence/     Acesso a dados (Repository, Migration, Sequence)
├── Security/        Assinatura/validação de tokens
└── Transport/       HTTP client, endpoints, auth strategies
```

### 3. `vendor/` NÃO é commitado

- `.gitignore` ignora `vendor/`
- `composer.lock` é commitado
- `composer install` no setup de desenvolvimento
- Dependências diretas: `guzzlehttp/guzzle` (^7.0), `robrichards/xmlseclibs` (^3.1)

### 4. Comentários e documentação

- **Português** para comentários, docblocks, mensagens de commit
- **Inglês** para nomes de classes, métodos, variáveis
- Docblocks obrigatórios em classes públicas e métodos públicos

### 5. Ambiente isolado — regra crítica

O módulo opera em **produção** e **homologação** com isolamento total.
`AmbienteGuard` é a trava central. Toda feature nova DEVE:

```php
// ✅ Toda query filtra por ambiente
$row = Capsule::table(self::TABLE)
    ->where('ambiente', $this->guard->value())
    ->first();

// ✅ Cross-check após busca sem filtro
$nfse->assertAmbiente($this->guard);

// ❌ NUNCA buscar sem filtrar ou validar ambiente
$row = Capsule::table(self::TABLE)->where('id', $id)->first();
```

---

## Fluxo de desenvolvimento (SDD — Specification-Driven Development)

### Para features novas

Siga esta ordem. Não pule etapas.

**1. Especificação (`spec/`)**

Crie um arquivo `spec/NOME_DA_FEATURE.md` com:

```markdown
# Feature: [Nome]

## Motivação
[1 parágrafo — por que isso existe]

## Comportamento
- [Cenário 1]: dado [input], esperado [output]
- [Cenário 2]: dado [erro], esperado [comportamento]

## Casos de borda
- O que acontece se [condição extrema]?

## Impacto
- Arquivos novos: [lista]
- Arquivos modificados: [lista]
- Quebra compatibilidade? [sim/não]
```

**2. Testes primeiro (`tests/`)**

Antes de implementar, escreva o teste que define o comportamento esperado:

```php
// tests/NfseNacional/Domain/Service/EmissaoServiceTest.php
class EmissaoServiceTest extends TestCase
{
    public function testEmissaoComSucessoRetornaChaveAcesso(): void
    {
        // Arrange
        $service = new EmissaoService(/* mocks */);
        
        // Act
        $resultado = $service->processarEmissao(123);
        
        // Assert
        $this->assertEquals('sucesso', $resultado['status']);
        $this->assertNotEmpty($resultado['chave_acesso']);
    }
}
```

- Framework: PHPUnit 10+ (instalar via `composer require --dev phpunit/phpunit`)
- Testes unitários: `tests/Unit/` (sem dependência do WHMCS)
- Testes de integração: `tests/Integration/` (mock da API via Guzzle MockHandler)
- NÃO testar glue code WHMCS (AdminController, hooks) — precisam do runtime WHMCS

**3. Implementação**

Implemente a feature seguindo as regras de arquitetura acima.

**4. Verificação**

```bash
composer check      # Roda tudo: phpstan + cs-check + audit + test
composer test        # PHPUnit
composer phpstan     # Análise estática (nível 5)
composer cs-check    # PHP CS Fixer (PSR-12)
composer cs-fix      # Corrige estilo automaticamente
composer audit       # Vulnerabilidades nas dependências
```

---

## O que NÃO fazer

| Proibido | Motivo |
| --- | --- |
| Acoplar `Domain/` a classes WHMCS (`Capsule`, `$_SESSION`) | Domínio deve ser testável isoladamente |
| Usar `switch` quando `match` resolve | PHP 8.3+ exige recursos modernos |
| Criar subpastas por tipo de arquivo (`Controllers/`, `Models/`) | Organização é por responsabilidade |
| Commit da pasta `vendor/` | `.gitignore` bloqueia; `composer.lock` garante reprodutibilidade |
| Misturar produção e homologação em queries | Sempre filtrar por `AmbienteGuard` |
| Alterar XSD ou planilhas de anexo | São documentos oficiais externos; referenciar, não modificar |
| Escrever em inglês nos docblocks/comentários | Padrão do projeto é português |
| Ignorar `composer.lock` ao adicionar dependências | Sempre commit o lock atualizado |

---

## Modo econômico — Caveman Mode

Para operações rotineiras (testes, lint, CS fix, comandos composer), use o
formato telegráfico. **Cada token economizado é um token disponível para
raciocínio sobre o código.**

### Formato de resposta para comandos bem-sucedidos

```
[exit 0] comando — resumo 1 linha
```

Exemplos:
```
[exit 0] phpstan — 0 errors
[exit 0] cs-fix — 17 files fixed
[exit 0] composer audit — 0 vulnerabilities
```

### Sempre suprimir

- Barras de progresso (use `--no-progress` quando disponível)
- Tabelas de "Lock file operations: N installs, N updates"
- Listas de "Downloading..." e "Extracting archive"
- Mensagens de "Writing lock file", "Generating autoload files"
- Sugestões do Composer ("N package suggestions were added")
- Qualquer output que não seja erro ou resultado direto

### Quando expandir

- ❌ Comando falhou → mostrar o erro completo
- ❌ Usuário pediu "mostre o output" ou "detalhes"
- ❌ Decisão de design que precisa de contexto
- ✅ Comando passou → 1 linha, próximo passo

### Prosa só quando necessário

Use parágrafos apenas para: explicar arquitetura, justificar decisões de
design, reportar achados de segurança, ou responder perguntas conceituais.
Nunca narre o óbvio ("Agora vou rodar o PHPStan...").

---

## Referências

### Arquivos versionados neste repositório

- **Changelog:** `CHANGELOG.md` (Keep a Changelog + SemVer)
- **Workflow de release:** `.github/workflows/release.yml` (automático no merge para main)

### Referências externas (NÃO versionadas — disponíveis apenas no ambiente local)

Estes arquivos estão em `../` (pasta `nota-fiscal/`) e servem como referência
para agentes de IA durante o desenvolvimento. **Não modifique estes arquivos.**

| Arquivo | Conteúdo |
| --- | --- |
| `../DOCUMENTACAO-REFERENCIA-NFSE.md` | Catálogo consolidado de schemas, APIs, anexos |
| `../esquemas-nfse-rtc-v1-01-*/` | Schemas XSD oficiais (namespace `http://www.sped.fazenda.gov.br/nfse`) |
| `../MANUAL API/v1-API-NFS-e-*.json` | Especificações OpenAPI/Swagger dos endpoints |
| `../anexo_*.xlsx` | Tabelas canônicas (IBGE, NBS, tributações, eventos) |
