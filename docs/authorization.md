# Autorização e operações administrativas — fase 5

O backend aplica permissões usando o estado atual do PostgreSQL. Cargo global,
participação, liderança e visibilidade continuam separados. A sessão precisa estar
ativa em cada requisição; campos de cargo do cliente não concedem autorização.

## Rotas disponíveis

Todas exigem `Authorization: Bearer SEU_ACCESS_TOKEN`, HTTPS ou a exceção explícita
de desenvolvimento no loopback. Respostas usam `Cache-Control: no-store`.

| Método e rota | Resultado e acesso |
| --- | --- |
| `GET /api/auth/permissions` | Indicadores de gestão e ministérios liderados pelo usuário atual |
| `GET /api/admin/users?page=1&limit=20` | Listagem administrativa; filtros opcionais `role` e `status` |
| `GET /api/admin/users/{id}` | Dados administrativos mínimos de uma conta autorizada |
| `PATCH /api/admin/users/{id}/access` | Alteração explícita de cargo/status, com auditoria transacional |
| `GET /api/posts?page=1&limit=20` | Publicações legíveis, com contagem e paginação já filtradas |
| `GET /api/posts/{id}` | Publicação legível no escopo regular |
| `GET /api/ministries/{ministryId}/events/{id}` | Evento legível pertencente ao ministério da rota |
| `GET /api/posts/{postId}/comments/{id}` | Comentário visível pertencente ao post legível da rota |

As consultas de conteúdo são a primeira aplicação das políticas de leitura.
Criação/edição de posts e feed foram implementados na [fase 8](posts.md).
Eventos e comentários permanecem nas fases de seus módulos.
Não há interface visual nesta etapa.

Listas retornam `items` e `pagination: {page, limit, total}`; limite padrão 20 e
máximo 100. A lista administrativa retorna `id`, `name`, `email`, `role` e `status`.
Na fase 6, o detalhe autorizado acrescenta telefone, nascimento e datas, conforme
[users.md](users.md). Hashes, sessões e auditoria não são serializados nessas
respostas. IDs e parâmetros são validados, sem aceitar campos
arbitrários para ordenar, consultar ou alterar o banco.

ADMIN pode consultar todos os usuários. PASTOR consulta contas que pode administrar;
contas ADMIN ficam fora da lista, da contagem e do detalhe administrativo. MEMBER e
LEADER recebem 403 nesse módulo. Detalhe fora do escopo retorna 404.

## Alterar acesso

Envie JSON com `role`, `status` ou ambos. Exemplo de mudança de cargo:

```http
PATCH /api/admin/users/ID_DO_USUARIO/access
Authorization: Bearer SEU_ACCESS_TOKEN
Content-Type: application/json

{"role":"LEADER"}
```

Papéis: ADMIN, PASTOR, LEADER, MEMBER. Estados: ACTIVE, INACTIVE, BLOCKED. Valores
nulos, corpo vazio, tipos inválidos e campos extras são recusados com 422.
O preflight CORS autoriza PATCH somente nessa rota, para origens configuradas.

Somente ADMIN altera uma conta ADMIN ou promove alguém a ADMIN. PASTOR não pode
fazê-lo nem em uma atualização que manteria os mesmos dados. A última conta
ADMIN/ACTIVE não pode ser rebaixada, bloqueada ou inativada: a API retorna 409.
Uma mudança efetiva retorna a conta atualizada. Repetir os mesmos valores é
idempotente e não produz auditoria adicional.

A operação bloqueia primeiro a administração global, depois os usuários em ordem
de ID e finalmente a sessão do ator. Cargo, estado, sessão e número de ADMINs são
verificados novamente após os bloqueios. Duas alterações concorrentes não podem
remover os últimos administradores. O bootstrap inicial usa o mesmo bloqueio.

Rebaixar para MEMBER ou inativar/bloquear remove as lideranças existentes na mesma
transação. A participação não é removida por uma mudança de cargo. Reativar a conta
não restaura lideranças. Bloqueio/inativação revoga sessões pelo trigger existente;
mudança de cargo é lida nas próximas requisições, inclusive com um JWT anterior.

Cada mudança efetiva acrescenta um registro em `audit_logs`, com ator, alvo,
ação, data, identificador e apenas os estados anterior/posterior de cargo/status
e quantidade de lideranças removidas. Nenhuma senha, token ou cópia do perfil entra
nessa auditoria. A mudança e seu registro são confirmados juntos; falha na auditoria
desfaz também a mudança. A API não expõe edição ou exclusão desses registros.

## Conteúdo e ministérios

PUBLIC é legível por toda a igreja autenticada, mesmo sem participação no ministério.
MINISTRY_MEMBERS exige participação ACTIVE no ministério específico, exceto para
ADMIN/PASTOR ativos. O ministério precisa estar ACTIVE na leitura regular.

Posts precisam estar PUBLISHED e ter data de publicação já alcançada. Eventos e
agenda permitem PUBLISHED/CANCELLED. Comentários regulares precisam estar VISIBLE
e herdam o acesso ao post. Rascunhos/arquivados ficam fora dessas rotas regulares,
inclusive para gestores; a gestão de histórico terá rotas próprias nos módulos.

Liderança exige cargo LEADER elegível, usuário/ministério/vínculo ativos e a flag
de liderança naquele vínculo. Ser participante, autor ou líder de outro ministério
não concede administração. Uma flag inconsistente em MEMBER também não concede
poder. As políticas de criação/gestão estão implementadas nos Voters para uso pelos
próximos endpoints de escrita.

O SQL compartilhado `ContentReadScope` aplica esse escopo antes de paginação e
contagem. Detalhe usa o mesmo predicado. IDs incompatíveis com o pai da rota e
conteúdo fora do escopo retornam 404 sem confirmar existência. Mudanças de vínculo
ou liderança entram em vigor sem depender de um novo login.

## Arquivos e execução

Políticas/Voters: `apps/api/src/Security/Authorization` e `src/Security/Voter`.
Leituras: `AdminUserReader`, `AuthorizedContentReader`. Rotas: `AdminUserController`,
`PermissionsController`, `ContentReadController`. Mudanças críticas:
`src/Administration/UserAccessService.php`.

Migration `Version20260911050000` cria somente `audit_logs`, com índices, FK de ator
e CHECKs. Preserva usuários e as migrations anteriores.

```sh
docker compose --profile tools run --build --rm api-test
docker compose up --build -d --wait
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:schema:validate
```

Evidência executada em [validation.md](validation.md). Cadastro e perfis estão
implementados na fase 6, em [users.md](users.md). Gestão de ministérios/participações
está na [fase 7](ministries.md). Interfaces Web/mobile continuam nas fases seguintes.
Indicadores de permissão da interface são apenas
informativos: os endpoints sempre repetem a autorização no servidor.
