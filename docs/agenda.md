# Agenda — fase 11

A agenda combina eventos e atividades de organização. Cada fonte mantém seu
registro original: consultar a agenda não copia eventos para `ministry_schedules`.
Todos os endpoints exigem Bearer válido e usuário ativo.

## Atividades

| Método e rota | Operação |
| --- | --- |
| `GET /api/schedules` | Lista atividades PUBLISHED/CANCELLED acessíveis |
| `GET /api/schedules/{id}` | Detalhe regular |
| `GET /api/admin/schedules` | Lista todos os estados dentro da gestão do ator |
| `GET /api/admin/schedules/{id}` | Detalhe administrativo |
| `POST /api/schedules` | Cria DRAFT, retorna 201 e Location administrativo |
| `PATCH /api/schedules/{id}` | Edita/remarca atividade |
| `POST /api/schedules/{id}/publish` | Publica/republica |
| `POST /api/schedules/{id}/unpublish` | Retorna a DRAFT |
| `POST /api/schedules/{id}/cancel` | Cancela preservando a audiência |
| `DELETE /api/schedules/{id}` | Arquiva logicamente; retorna 204 |

Campos: `title` (obrigatório, até 180 caracteres), `starts_at` (obrigatório),
`description` (opcional, até 50.000), `ends_at`, `ministry_id`, `visibility`.
Opcionais têm padrão null, exceto visibilidade PUBLIC. Não há localização/endereço
em atividades. Autoria vem da sessão; estado inicial DRAFT não pode ser sobrescrito.
Respostas de detalhe/escrita usam `schedule` com esses campos, `id`, `created_by`,
`status`, `created_at` e `updated_at`. Campos extras são recusados.

Horários, intervalos, validação de PATCH combinado com valores atuais e filtros
seguem [eventos](events.md): segundos e offset obrigatório, resposta UTC, fim
opcional e nunca anterior ao início. Listas aceitam `page`, `limit`, `q`,
`ministry_id`, `visibility`, `status`, `from`, `to`; padrão 20 e máximo 100 por página.
Ordem regular: início/ID crescente; administrativa: criação/ID decrescente.

ADMIN/PASTOR gerenciam atividades gerais e por ministério. LEADER gerencia apenas
ministérios ativos com liderança ativa; MEMBER não gerencia. Mudança de ministério
exige autorização sobre origem e destino. Atividade geral privada é recusada.
PUBLIC exige autenticação, mas não participação. MINISTRY_MEMBERS exige participação
ativa específica ou cargo global ativo. Autoria não preserva poderes após revogação.

Rascunhos, arquivados e atividades de ministério inativo não aparecem na leitura
regular. Histórico inativo fica disponível à gestão global. Publicar/criar/mover
para ministério inativo é recusado. Cancelamento somente de PUBLISHED/CANCELLED,
para não expor rascunhos ou arquivos. Estados e auditoria são transacionais;
escritas repetidas sem mudanças não duplicam auditoria.

## Agenda combinada

`GET /api/calendar` retorna `items` e `pagination: {page, limit, total}` com os mesmos
filtros da listagem regular. Cada item contém `kind: EVENT | ACTIVITY`, ID original,
criador, ministério, título, descrição, horários, visibilidade, estado e datas.
`location`/`address` são null para ACTIVITY. A identidade do item é **kind + id**:
um evento e uma atividade podem ter o mesmo ID numérico.

Por padrão, traz próximos compromissos e os que estão em andamento: fim (ou início,
se fim ausente) maior ou igual ao instante atual do banco. Para consultar passado,
envie `from` explicitamente. `to` exclui itens que começam exatamente no limite
final. Com ambos os limites, `to` deve ser posterior a `from`.

Exemplo de agenda de ministério: `/api/calendar?ministry_id=10`.
Para um mês: `/api/calendar?from=2026-10-01T00:00:00Z&to=2026-11-01T00:00:00Z`.
Offsets positivos precisam de `%2B` na URL. Sem filtro de estado, cancelados
continuam aparecendo aos destinatários autorizados; use `status=PUBLISHED` para
listar apenas confirmados.

O SQL aplica o escopo de cada fonte antes de uni-las, filtrar, contar e paginar.
A ordenação global é início, kind e ID. Contagem e página usam o mesmo snapshot;
não há paginação independente por fonte nem duplicação persistida de eventos.
O endpoint é somente leitura. Edições seguem a rota da fonte (`events`/`schedules`).

## Arquivos, validação e pendências

Novos controllers: `ScheduleController`, `CalendarController`; serviço:
`ScheduleManagementService`; consultas: `ScheduleDirectory`, `CalendarDirectory`;
entrada/saída: `ScheduleInput`, `ScheduleView`. Arquivos em `apps/api/src`.
Validação temporal é compartilhada com eventos; as atividades têm whitelist própria.
Testes: `ScheduleManagementHttpTest`, concorrência em `AdminUserHttpTest`/worker.
Sem migration ou dependência nova. Auditoria não copia texto e falhas desfazem
escritas. Resultados e comandos executados em [validation.md](validation.md).

Ocorrências são explícitas; recorrência e notificações ficam para evolução posterior.
Próxima fase: interface Web, usando essas APIs reais.
