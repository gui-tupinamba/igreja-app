# Publicações e feed — fase 8

Todas as rotas exigem Bearer e usam `Cache-Control: no-store`. PUBLIC significa
acessível à igreja autenticada. ADMIN/PASTOR gerenciam posts gerais e de qualquer
ministério; LEADER gerencia somente ministérios que lidera ativamente. Autoria não
preserva autoridade depois de perder liderança ou mover o post para outro ministério.

| Método e rota | Operação |
| --- | --- |
| `GET /api/posts` | Feed publicado, filtrado pelo acesso atual |
| `GET /api/posts/{id}` | Detalhe legível; fora do escopo retorna 404 |
| `GET /api/admin/posts` | Posts administráveis, incluindo rascunhos e arquivados |
| `GET /api/admin/posts/{id}` | Detalhe administrativo autorizado |
| `POST /api/posts` | Cria rascunho; 201, `post` e `Location` administrativo |
| `PATCH /api/posts/{id}` | Edita texto, ministério, visibilidade e configuração de comentários |
| `POST /api/posts/{id}/publish` | Publica ou republica; retorna `post` |
| `POST /api/posts/{id}/unpublish` | Retira do feed, voltando a DRAFT; retorna `post` |
| `DELETE /api/posts/{id}` | Arquiva preservando histórico; retorna 204 |

## Cadastro e edição

```json
{
  "title": "Ensaio da equipe",
  "content": "Nosso próximo ensaio será no sábado.",
  "ministry_id": 123,
  "visibility": "MINISTRY_MEMBERS",
  "comments_enabled": true
}
```

Título e conteúdo são obrigatórios na criação, com limites de 180 e 50.000
caracteres. Conteúdo aceita quebras de linha e é texto: clientes devem renderizá-lo
como texto, sem executar HTML. Corpo máximo de cadastro/edição: 512 KiB.

Ministério assume `null` (geral), visibilidade PUBLIC e comentários habilitados.
MINISTRY_MEMBERS exige ministério. IDs no corpo são inteiros positivos; flags são
booleanos. O autor vem da sessão e o estado inicial é sempre DRAFT. Campos extras,
como `author_id`, `status`, `published_at` ou URLs de imagens, são recusados com 422.
Os campos omitidos na edição são preservados; texto e flags não aceitam `null`.

Uma alteração de ministério exige autorização tanto sobre o vínculo atual do post
quanto sobre o destino. A audiência é validada combinando os campos novos e os
preservados; não é possível deixar conteúdo geral privado. Líderes não transformam
posts de ministério em posts gerais. MEMBER não escreve posts nem consulta a gestão.

Publicar, retirar do feed e arquivar aceitam corpo vazio ou `{}`. A primeira
publicação recebe data UTC do banco. Republicar preserva a primeira data de
publicação; não coloca artificialmente o post no início do feed. Repetir estado ou
dados idênticos não duplica auditoria. Não há agendamento por data nesta API.

Posts arquivados continuam disponíveis à gestão e podem ser editados/republicados
por quem mantém autorização. Ministério inativo fica fora do feed e da gestão de
líderes. ADMIN/PASTOR podem manter seu histórico, mas criar, publicar ou mover um
post para ministério inativo retorna 409.

## Feed, busca e paginação

Os dois índices aceitam `page` (1–2147483647), `limit` (1–100, padrão 20), `q`,
`ministry_id` e `visibility`. `ministry_id=null` seleciona posts gerais. Somente
o índice administrativo aceita `status=DRAFT|PUBLISHED|ARCHIVED`.

Exemplos:

```text
GET /api/posts?ministry_id=123&visibility=MINISTRY_MEMBERS
GET /api/posts?q=ensaio&page=1&limit=20
GET /api/admin/posts?status=DRAFT
```

Busca literal no título/conteúdo, sem diferenciar maiúsculas/minúsculas, até 120
caracteres. `%`, `_` e caracteres de SQL não viram operadores da busca. O SQL aplica
permissão, estado e filtros antes de contar/paginar, no mesmo snapshot do banco.
Feed ordena por `published_at DESC, id DESC`; gestão por `created_at DESC, id DESC`.
Página vazia preserva a contagem autorizada, inclusive em páginas altas.

PUBLIC publicado é legível sem participação. Privado exige participação ativa
específica ou cargo global ADMIN/PASTOR ativo. Rascunhos, arquivados, ministérios
inativos e posts com data futura ficam fora do feed, busca e contagem regulares.
Troca de visibilidade, ministério ou participação afeta leituras com o token atual.

Resposta `post` contém ID, título, conteúdo, visibilidade, estado, ministério, autor,
configuração de comentários e datas UTC. As três novas propriedades da leitura são
`comments_enabled`, `created_at` e `updated_at`; os campos anteriores são preservados.
Não há dados de contato do autor nas respostas.

## Auditoria, arquivos e execução

Escritas bloqueiam administração, usuário/sessão, post, ministérios em ordem de ID
e vínculos de liderança. Permissões são revalidadas dentro da transação, após esperas.
Publicações concorrentes não duplicam a transição nem a auditoria. Falha na auditoria
desfaz a mudança, inclusive visibilidade e data de publicação.

Auditoria contém IDs, estados, visibilidade e nomes de campos alterados. Não copia
título ou conteúdo. Arquivos principais: `PostController`, `PostManagementService`,
`PostDirectory`, `PostInput`, `PostView`; ajustes nos leitores anteriores, validação
de entrada e CORS. Testes: `PostManagementHttpTest`, matriz existente de leitura e
cenários concorrentes em `AdminUserHttpTest`/worker isolado.

**Sem nova migration ou dependência.** Imagens/uploads aguardam o módulo de arquivos
e sua interface; esta fase entrega publicações de texto. Escrita e moderação de
comentários estão descritas em [comments.md](comments.md). Web/mobile continuam nas fases próprias.

```sh
docker compose --profile tools build php api-test
docker compose --profile tools run --rm api-test
docker compose up --no-build -d --wait
docker compose exec -T php php bin/console doctrine:schema:validate
```

Resultados efetivamente executados em [validation.md](validation.md).
