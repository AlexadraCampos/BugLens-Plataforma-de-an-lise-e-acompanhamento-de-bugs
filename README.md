# Portal BI

Sistema de login próprio que centraliza o acesso a relatórios do Power BI, sem depender de licença individual do Power BI para cada usuário. Feito em PHP puro (sem framework), com MySQL/MariaDB e integração direta com a Power BI REST API + Microsoft Entra ID (Azure AD).

## Funcionalidades

- Login com sessão própria (independe de SSO/AD da empresa)
- Dois perfis de acesso: **Administrador** e **Usuário**
- Dashboard com relatórios do Power BI incorporados (iframe)
- **API própria de exportação para Excel** — gera `.xlsx` a partir dos dados reais do dataset, sem depender do usuário ter licença Power BI
- Painel de administração: cadastro, ativação/desativação, troca de perfil, exclusão e redefinição de senha de usuários
- Log de acessos (login, logout, falhas de login, geração de relatório)
- Cabeçalhos de segurança (CSP com nonce, X-Frame-Options, Referrer-Policy, Permissions-Policy)
- Proteção CSRF em todos os formulários
- Bloqueio temporário após tentativas de login inválidas (rate limiting por e-mail + IP)

Card:
Link: https://github.com/AlexadraCampos/BugLens-Plataforma-de-an-lise-e-acompanhamento-de-bugs/issues/2#issue-5146482466

## Stack

PHP 7.4+ (sem framework) · MySQL/MariaDB via PDO · JavaScript vanilla · Power BI REST API · Microsoft Entra ID (OAuth2 client credentials)

## Estrutura de pastas

```
login_bi/
├── index.php              # tela de login
├── valida_login.php       # processa o formulário de login
├── logout.php             # encerra a sessão
├── dashboard.php          # tela principal com os relatórios incorporados
├── admin/
│   ├── index.php          # hub da administração + criação do 1º admin
│   ├── usuarios.php       # cadastro, edição de perfil, exclusão, redefinição de senha
│   └── acessos.php        # visualização dos logs de acesso
├── api/
│   └── exportar_excel.php # API de exportação para Excel (ver seção abaixo)
├── assets/
│   ├── asset.php          # serve CSS/imagem via PHP (ver nota técnica)
│   ├── css/style.css
│   └── img/favicon-150x150.png
└── config/
    ├── database.php       # conexão, sessão, cabeçalhos de segurança, helpers gerais
    └── power_bi.php       # credenciais do Power BI/Azure (não versionar com valores reais)
```

## Perfis de acesso

| | Usuário | Administrador |
|---|---|---|
| Ver dashboard e relatórios | ✅ | ✅ |
| Exportar Excel | ✅ | ✅ |
| Painel de administração | ❌ | ✅ |
| Ver logs de acesso | ❌ | ✅ |
| Cadastrar/editar/excluir usuários | ❌ | ✅ |
| Redefinir senha de qualquer conta | ❌ | ✅ |

A restrição é aplicada **no servidor** (`exigir_admin()`), não apenas escondendo botões na tela — tentar acessar qualquer página de `/admin/` sem ser administrador retorna `403 Acesso negado`.

## Autenticação e sessão

- Senhas com `password_hash()` / `password_verify()` (bcrypt)
- Sessão nomeada (`portal_bi_session`), cookie `HttpOnly`, `SameSite=Lax`, `Secure` quando em HTTPS
- Token CSRF por sessão, validado em todo `POST`
- Bloqueio temporário (5 tentativas inválidas / 15 minutos, por e-mail + IP)
- `session_regenerate_id()` após login bem-sucedido

## A API — `api/exportar_excel.php`

Único endpoint do sistema: `GET /api/exportar_excel.php?relatorio=...&opcao=...`. Exige sessão ativa (usa o mesmo login do site — não é uma API pública). Fluxo completo:

**1. Autenticação server-to-server no Power BI**
OAuth2 *client credentials flow* direto contra o Microsoft Entra ID (`POST /oauth2/v2.0/token`), usando o Client ID/Secret de um App Registration configurado em `config/power_bi.php`. Nenhum usuário final precisa de licença ou login próprio no Power BI — a aplicação se autentica como "ela mesma".

**2. Descoberta do dataset**
Consulta a Power BI REST API (`GET /reports/{id}`) para obter o `datasetId` por trás do relatório configurado.

**3. Execução da consulta DAX**
Cada combinação de relatório + opção (ex: "VGV Total", "Leads → SDR") tem sua própria query DAX (`SUMMARIZECOLUMNS` / `SELECTCOLUMNS`), executada via `POST /datasets/{id}/executeQueries`. Os dados vêm direto do modelo semântico — não é um "print" do relatório visual, é a mesma fonte de dados, mas via consulta programática.

**4. Geração do `.xlsx` do zero**
Em vez de depender de uma biblioteca como PhpSpreadsheet, o arquivo Excel é montado manualmente: o XML do formato OOXML (`[Content_Types].xml`, `workbook.xml`, `styles.xml`, `worksheet.xml`) é escrito diretamente e compactado com `ZipArchive`. Isso evita uma dependência externa pesada só para gerar planilhas simples. Inclui:
- Autoajuste de largura de coluna, calculado a partir do conteúdo
- Formatação automática de número, moeda ou porcentagem, detectada pelo nome da coluna
- Sanitização do nome da aba (remove caracteres inválidos no Excel: `\ / : * ? [ ]`)

**5. Download**
Retorna o arquivo como anexo (`Content-Disposition: attachment`). Usa um arquivo temporário que é apagado logo após o envio — nada fica salvo em disco.

Todas as chamadas (sucesso ou falha) são gravadas na tabela de logs, junto com a mensagem de erro quando aplicável — o que facilita muito o diagnóstico quando algo quebra (credencial expirada, permissão faltando no workspace, etc.).

## Configuração inicial

1. Crie o banco de dados (as tabelas `usuarios` e `logs_acesso` são criadas automaticamente na primeira conexão).
2. Preencha `config/database.php` com as credenciais reais do banco e defina um `SETUP_TOKEN` novo e único.
3. No Azure/Entra: registre um App, gere um Client Secret e conceda as permissões de API `Report.Read.All` + `Dataset.Read.All` (do tipo **Application permissions**, com consentimento de administrador).
4. No Power BI Service: adicione esse App (pelo Client ID) como **Membro** do workspace que contém os relatórios.
5. Preencha `config/power_bi.php` com os IDs reais (Tenant, Client, Secret, Workspace, Reports).
6. Acesse `/admin/index.php` pela primeira vez para criar o primeiro administrador, usando o `SETUP_TOKEN` definido no passo 2.

## Nota técnica: `assets/asset.php`

Em vez de servir `style.css` e a logo como arquivos estáticos diretos, eles passam por um pequeno script PHP (`asset.php?f=...`). Isso existe porque, no ambiente onde o projeto rodou originalmente (hospedagem compartilhada com outro sistema no mesmo domínio), requisições estáticas dentro dessa pasta eram interceptadas por uma regra de reescrita de URL de terceiros — arquivos `.php` não sofriam esse problema. Em uma hospedagem dedicada/isolada isso não seria necessário, mas foi mantido aqui para preservar a solução real implementada.

## Segurança

- Content-Security-Policy com nonce por requisição (sem `unsafe-inline`)
- `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`
- Todas as consultas ao banco usam PDO com *prepared statements*
- Nenhuma credencial fica exposta em HTML/JS — permanecem só em arquivos PHP no servidor, fora do diretório publicamente acessível de forma direta
