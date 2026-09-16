# Comentários — fase 9

Todas as rotas exigem Bearer válido e usuário ativo. Conteúdo é texto simples de
1 a 5.000 caracteres, com espaços externos removidos; quebras de linha são aceitas.
Autoria e publicação são fixadas pela sessão e pela rota. Campos extras são recusados.

| Método e rota | Operação |
| --- | --- |
| `GET /api/posts/{postId}/comments` | Lista comentários visíveis, com paginação |
| `GET /api/posts/{postId}/comments/{id}` | Consulta comentário visível |
| `POST /api/posts/{postId}/comments` | Cria com `{"content":"Texto"}`; retorna 201 e Location |
| `PATCH /api/posts/{postId}/comments/{id}` | Autor edita com `{"content":"Correção"}` |
| `DELETE /api/posts/{postId}/comments/{id}` | Autor remove logicamente; retorna 204 |
| `GET /api/admin/posts/{postId}/comments` | ADMIN/PASTOR consultam visíveis e ocultos |
| `PATCH /api/admin/posts/{postId}/comments/{id}/status` | ADMIN/PASTOR moderam com `{"status":"HIDDEN"}`; retorna 204 |

Respostas de criação, edição e detalhe usam `comment`, com `id`, `post_id`,
`user_id`, `content`, `status`, `created_at` e `updated_at`. Datas são UTC.
Listas usam `items` e `pagination: {page, limit, total}`. Padrões: página 1,
20 itens; máximo de 100 por página. Ordem crescente por criação e ID. Não há
filtro de status: a lista regular contém VISIBLE e a administrativa VISIBLE/HIDDEN.
A autorização do pai, o filtro de comentários, a contagem e a página são resolvidos
no mesmo snapshot SQL, inclusive em páginas vazias.

## Acesso e estados

Operações regulares exigem acesso atual ao post publicado. PUBLIC continua exigindo
autenticação; MINISTRY_MEMBERS exige participação ativa específica ou ADMIN/PASTOR
ativo. Autoria não concede acesso a post privado após remoção da participação.
ID de comentário vinculado a outro post retorna 404.

Criação exige `comments_enabled=true`; fechamento retorna 409 para novas mensagens.
O autor ainda pode corrigir/remover comentário VISIBLE existente se puder ler o post.
Comentários HIDDEN não podem ser editados/removidos pela rota do autor. Outro
usuário não pode editar texto ou excluir por essa rota, mesmo sendo ADMIN/PASTOR.

Moderação aceita VISIBLE, HIDDEN ou DELETED. Somente ADMIN/PASTOR ativos usam a
rota administrativa; liderança de ministério não concede moderação. Essa manutenção
global também alcança posts em rascunho, arquivados ou de ministério inativo,
conforme a política de administração existente. Não libera a leitura regular.

DELETED é terminal: fica no banco para histórico, não aparece nas listas/detalhes
e não pode ser restaurado pela API. HIDDEN pode voltar a VISIBLE por moderação.
Repetir uma alteração sem mudança não gera nova auditoria. Exclusão repetida retorna 404.

## Implementação e verificação

Arquivos principais em `apps/api/src`: `Controller/CommentController.php`,
`Administration/CommentManagementService.php`, `Repository/CommentDirectory.php`,
`Http/CommentInput.php` e `Http/CommentView.php`; ajustes em validação e CORS.
O detalhe regular mantém o leitor existente e seu escopo compartilhado.

Escritas usam a trava transacional compartilhada com administração e publicações,
com bloqueios de usuário/sessão, post, ministério/vínculo e comentário. A sessão e
as permissões são reavaliadas após a espera. Auditoria registra IDs, estados e nomes
de campos, sem copiar o texto. Falha na auditoria desfaz a escrita inteira.

Testes HTTP em `CommentManagementHttpTest.php` e concorrência em
`AdminUserHttpTest.php`/`auth-concurrency-worker.php`. Sem migration ou dependência nova.
Comandos e resultados executados ficam em [validation.md](validation.md).
Eventos são a próxima fase; Web e mobile seguem o plano próprio.
