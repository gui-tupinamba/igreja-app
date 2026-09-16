# Eventos — fase 10

Todas as rotas exigem autenticação Bearer e usuário ativo. ADMIN/PASTOR administram
eventos gerais e de ministérios. LEADER administra apenas eventos dos ministérios
ativos em que tem liderança ativa. Autoria não concede administração.

| Método e rota | Operação |
| --- | --- |
| `GET /api/events` | Lista eventos PUBLISHED/CANCELLED acessíveis |
| `GET /api/events/{id}` | Detalhe regular, incluindo evento geral |
| `GET /api/ministries/{ministryId}/events/{id}` | Detalhe existente; exige que o evento pertença ao ministério informado |
| `GET /api/admin/events` | Lista estados e eventos dentro da gestão do ator |
| `GET /api/admin/events/{id}` | Detalhe administrativo |
| `POST /api/events` | Cria DRAFT; retorna 201 e Location administrativo |
| `PATCH /api/events/{id}` | Edita campos permitidos e remarca horários |
| `POST /api/events/{id}/publish` | Publica/republica |
| `POST /api/events/{id}/unpublish` | Retorna a DRAFT |
| `POST /api/events/{id}/cancel` | Marca CANCELLED mantendo leitura aos destinatários autorizados |
| `DELETE /api/events/{id}` | Arquiva logicamente; retorna 204 |

Criação exige `title` (1–180 caracteres) e `starts_at`. Campos opcionais:
`description` (50.000), `location` (180), `address` (500), `ends_at`, `ministry_id`
e `visibility`. Valores padrão: textos opcionais/fim/ministério null e PUBLIC.
Textos opcionais vazios são normalizados para null. Descrição aceita múltiplas
linhas; localização/endereço/título são de linha única. Conteúdo é texto simples.
PATCH aceita esses mesmos campos, exige ao menos um e valida o resultado combinado
com os valores existentes. Criador e estado não são campos de criação/edição.

```json
{
  "title": "Culto de domingo",
  "starts_at": "2026-10-04T19:00:00-04:00",
  "ends_at": "2026-10-04T20:30:00-04:00",
  "location": "Templo",
  "visibility": "PUBLIC"
}
```

Horários exigem ISO 8601 com segundos e `Z` ou offset explícito, sem frações:
`AAAA-MM-DDTHH:MM:SS±HH:MM`. Datas impossíveis, horários sem fuso e fim anterior ao
início retornam 422. Fim igual ao início é aceito; null representa ausência de fim
informado. Instantes são armazenados/retornados em UTC, com precisão de segundos.
O exemplo inicia às `2026-10-04T23:00:00Z`. A futura interface converte para o fuso
configurado. Dois offsets que representam o mesmo instante não geram alteração.

## Consultas e visibilidade

Listas retornam `items` e `pagination: {page, limit, total}`. Padrões: página 1 e
20 itens, máximo 100 por página. Ordenação regular por início crescente e ID;
administrativa por criação decrescente e ID. Filtros opcionais:

- `ministry_id`: inteiro positivo ou `null` para eventos gerais.
- `visibility`: PUBLIC ou MINISTRY_MEMBERS.
- `q`: busca literal de até 120 caracteres no título/descrição.
- `status`: PUBLISHED/CANCELLED na leitura regular; todos os quatro estados na gestão.
- `from` e `to`: instantes com fuso, seguindo o mesmo formato dos horários.

O período inclui eventos cujo fim (ou início, quando fim ausente) seja maior ou
igual a `from` e cujo início seja estritamente menor que `to`. Assim, eventos
iniciados antes do período, mas ainda em andamento, aparecem. Ambos os limites são
opcionais; quando enviados juntos, `to` deve ser posterior a `from`. Codifique `+`
como `%2B` em parâmetros de URL. Sem período, a lista inclui passado e futuro.

PUBLIC é visível a todos os usuários autenticados, sem exigir participação.
MINISTRY_MEMBERS exige participação ativa específica ou ADMIN/PASTOR ativo.
Evento geral privado é recusado. Cancelamento preserva essa mesma audiência.
Rascunhos, arquivados e conteúdo de ministério inativo ficam fora da leitura regular.
A gestão global pode consultar/editar histórico de ministério inativo; publicar
nele ou criar/mover conteúdo para ele retorna 409.

Todos os filtros e o escopo de acesso são aplicados antes da contagem/paginação,
no mesmo snapshot SQL. Perda de participação/liderança afeta o token atual.
Recurso fora do escopo retorna 404; MEMBER não administra e recebe 403 nas escritas.
Mover evento exige autorização sobre origem e destino; o criador permanece fixo.

## Estados e auditoria

Criação sempre gera DRAFT. Publicação explícita permite PUBLISHED, inclusive após
cancelamento/arquivamento, desde que o destino esteja ativo. Cancelamento aceita
somente PUBLISHED/CANCELLED; tentar cancelar DRAFT/ARCHIVED retorna 409 para evitar
exposição indireta de conteúdo não publicado. Arquivamento não apaga histórico.
Transições aceitam corpo vazio ou `{}`. Repetir o estado atual não duplica auditoria.

Escritas bloqueiam a administração compartilhada, usuário/sessão, evento,
ministérios em ordem de ID e vínculos. Permissões e intervalo combinado são
revalidados após esperas. Auditoria participa da mesma transação, com IDs,
visibilidade, estados e nomes de campos alterados, sem copiar descrição/endereço.
Falha na auditoria desfaz a operação.

Respostas novas de detalhe/escrita usam `event` com ID, criador, ministério,
campos descritivos, horários, visibilidade, estado e datas de criação/atualização.
A rota antiga sob ministério mantém seu contrato de campos existente.

## Arquivos e pendências

Arquivos principais em `apps/api/src`: `Controller/EventController.php`,
`Administration/EventManagementService.php`, `Repository/EventDirectory.php`,
`Http/EventInput.php`, `Http/EventView.php`; ajustes em validação e CORS.
Testes em `EventManagementHttpTest.php`, `AdminUserHttpTest.php` e worker de
concorrência isolado. **Sem migration ou dependência nova.**

Cancelamento é informado pelo estado visível na API aos usuários autorizados.
Notificações push/email aguardam a fase de notificações. Imagens/uploads e interfaces
Web/mobile seguem suas etapas. Próxima fase: agenda, combinando eventos e atividades
sem duplicar os eventos no banco. Comandos e resultados em [validation.md](validation.md).
