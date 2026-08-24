# REASONIX.md — NFS-e Nacional WHMCS Module

> **Regras completas em `AGENTS.md`** — este arquivo contém apenas instruções
> específicas para o Reasonix Code. Leia `AGENTS.md` primeiro para entender
> arquitetura, namespaces, e fluxo de desenvolvimento (SDD).

## Comandos do projeto

```bash
# Setup inicial (uma vez após clonar)
composer install

# Testes
composer test

# Análise estática + estilo + auditoria (roda no CI)
composer check

# Individualmente:
composer phpstan      # PHPStan nível 5
composer cs-check     # PHP CS Fixer (PSR-12)
composer cs-fix       # Corrige estilo automaticamente
composer audit        # Verifica vulnerabilidades nas dependências

# Rodar um script one-shot
php -f script.php
```

## Arquivos de referência — leia antes de propor mudanças

| Arquivo | Quando ler |
| --- | --- |
| `AGENTS.md` | **Sempre.** Regras de arquitetura, estilo, proibições. |
| `CHANGELOG.md` | Antes de criar uma entrada de changelog para nova versão. |

### Referências externas (não versionadas neste repositório)

Estes arquivos estão disponíveis apenas no ambiente local de desenvolvimento
(pasta `nota-fiscal/` acima deste módulo). Consulte-os se precisar de informações
sobre schemas XSD, endpoints de API ou tabelas de códigos oficiais.

| Arquivo externo | Quando consultar |
| --- | --- |
| `../DOCUMENTACAO-REFERENCIA-NFSE.md` | Dúvidas sobre códigos IBGE, NBS, tributação, schemas XSD, endpoints de API. |
| `../MANUAL API/v1-API-NFS-e-*.json` | Implementar ou depurar chamadas à API da Sefin Nacional. |
| `../esquemas-nfse-rtc-v1-01-*/tiposComplexos_v1.01.xsd` | Montar/alterar XML da DPS ou NFS-e. |
| `../esquemas-nfse-rtc-v1-01-*/tiposSimples_v1.01.xsd` | Validar formatos de campos (regex, tamanho máximo). |
| `../esquemas-nfse-rtc-v1-01-*/tiposEventos_v1.01.xsd` | Implementar cancelamento ou outros eventos. |
| `../anexo_*.xlsx` | Códigos IBGE, NBS, tributações, eventos — tabelas canônicas. |

## Regras Reasonix-específicas

### RTK — Rust Token Killer (compressão de output de terminal)

**Regra de ouro:** se um comando produz mais de 50 linhas, **corte
automaticamente** com pipe. O objetivo é gastar tokens com código, não com log.

#### Flags obrigatórias por ferramenta

```bash
# Composer
composer install --no-interaction --quiet 2>&1 | tail -5
composer update  --no-interaction --quiet 2>&1 | tail -5

# PHPStan
composer phpstan -- --no-progress --error-format=table 2>&1

# PHP CS Fixer (já é conciso por padrão — não precisa filtrar)
composer cs-check
composer cs-fix

# PHPUnit (quando houver testes)
composer test -- --no-progress --testdox 2>&1 | tail -30
```

#### Filtro automático para qualquer comando >50 linhas

```bash
comando 2>&1 | tail -30
# OU
comando 2>&1 | grep -E "error|Error|fail|FAIL|warning|Warning|✗|❌" 
```

#### O que NUNCA mostrar na íntegra

- `composer install`/`update` completo (use `--quiet` + `tail`)
- `git push` / `git fetch` output (use `--quiet` ou `2>&1 | tail -1`)
- PHPStan progress bar (use `--no-progress`)
- Listas de dependências no composer.lock

#### Formato de relatório pós-comando

```
[exit N] ferramenta — resumo
```

Exemplo: `[exit 0] phpstan — 0 errors` ou `[exit 1] phpstan — 3 errors (ver acima)`

### Antes de afirmar que algo não existe

Use `search_content` para confirmar. Exemplo:

```
❌ "O módulo não tem testes."   ← afirmação sem evidência
✅ "Nenhum arquivo *Test*.php encontrado (search_files '*Test*.php' em src/)."
```

### Antes de editar um arquivo

Use `read_file` no arquivo alvo **nesta sessão**. O editor bloqueia edições em
arquivos não lidos.

### Ao propor refactors

- Verifique `search_content` em TODOS os call sites da função/classe que pretende alterar
- `AmbienteGuard` é intocável — qualquer mudança afeta produção e homologação simultaneamente
- Mudanças em `Domain/` exigem testes novos obrigatoriamente (SDD)

### Ao adicionar dependências

```bash
composer require vendor/package
```

Sempre commit o `composer.lock` atualizado. NUNCA commit `vendor/`.

## Fluxo de trabalho com este agente

1. **Explorar:** Peça "analise X" ou "como funciona Y" — usarei `search_content`,
   `read_file`, e `get_symbols` para entender o código antes de responder.

2. **Especificar:** Peça "crie a spec para feature Z" — gerarei `spec/Z.md`
   seguindo o template do AGENTS.md.

3. **Implementar:** Após spec aprovada, peça "implemente a feature Z" —
   seguirei SDD: testes → código → verificação.

4. **Release:** Basta bump na versão + changelog + merge na main. O workflow
   `.github/workflows/release.yml` faz o resto automaticamente.
