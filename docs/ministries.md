# Ministérios, participantes e líderes — fase 7

Todas as rotas exigem autenticação. ADMIN/PASTOR gerenciam ministérios e vínculos;
PASTOR não altera participação ou liderança de ADMIN. Não há exclusão física.

| Método e rota | Comportamento |
| --- | --- |
| `GET /api/ministries` | Ministérios ativos, disponíveis a toda a igreja autenticada |
| `GET /api/ministries/{id}` | Metadados de um ministério ativo |
| `GET /api/admin/ministries` | Gestão global: inclui inativos e aceita filtro `status` |
| `GET /api/admin/ministries/{id}` | Detalhe para gestão global, inclusive inativos |
| `POST /api/ministries` | Cria ministério; retorna 201 e `Location` |
| `PATCH /api/ministries/{id}` | Edita dados permitidos; retorna `ministry` |
| `DELETE /api/ministries/{id}` | Desativa, preservando histórico; retorna 204 |
| `GET /api/ministries/{id}/members` | Lista restrita de participantes, paginada |
| `POST /api/ministries/{id}/members` | Inclui ou reativa participação; retorna `membership` |
| `DELETE /api/ministries/{id}/members/{userId}` | Encerra participação e remove liderança; 204 |
| `GET /api/ministries/{id}/leaders` | Lista restrita de líderes atualmente elegíveis |
| `POST /api/ministries/{id}/leaders` | Define liderança com participação ativa; retorna `membership` |
| `DELETE /api/ministries/{id}/leaders/{userId}` | Remove apenas liderança, preservando participação; 204 |

## Dados e permissões

Cadastro requer `name` e `slug`; `description` é opcional e `status` assume ACTIVE.
Edição aceita esses quatro campos. Nome tem até 120 caracteres; slug até 160,
formado por letras ASCII minúsculas/números separados por hífens simples, único no
banco. Descrição aceita até 10.000 caracteres, quebras de linha e `null` para limpar.
Corpos de cadastro/edição têm limite de 64 KiB, demais entradas mantêm 8 KiB.
Valores de status: ACTIVE e INACTIVE. Campos extras ou inválidos retornam 422;
slug duplicado retorna 409. Campos omitidos são preservados.

LEADER pode editar apenas nome/descrição de ministério que efetivamente lidera,
com usuário, vínculo e ministério ativos. Não altera slug/status, cria ministérios,
adiciona participantes ou define líderes. MEMBER não administra ministérios.

Listas usam `page`/`limit`, padrão 1/20, máximo 100, ordenação por nome e ID.
Contagem e página são calculadas no mesmo comando SQL, com autorização aplicada
antes da paginação. A listagem regular e o detalhe ocultam ministérios inativos
inclusive de gestores; a manutenção usa as rotas `/api/admin/ministries`.

Listar participantes/líderes exige gestão global ou liderança ativa naquele
ministério. A resposta inclui IDs do vínculo, usuário e ministério, nome, cargo,
status da conta/vínculo, flag de liderança e datas do vínculo. Não inclui email,
telefone, nascimento ou credenciais. MEMBER não consulta o diretório de terceiros.
O perfil próprio continua mostrando separadamente participação e liderança.

`members` assume vínculo ACTIVE; gestão global pode usar `status=INACTIVE` para
histórico. Líderes veem somente usuários/vínculos ativos do ministério autorizado.
`leaders` mostra somente lideranças elegíveis em ministério ativo. Um ID que pertence
a outro ministério não autoriza alterar seu vínculo.

## Participação e liderança

Adicionar membro recebe `{"user_id":123}`. IDs no corpo devem ser inteiros positivos.
Adicionar líder recebe `user_id` e, opcionalmente, `promote_to_leader` booleano.
Quando o alvo tem cargo MEMBER, é necessário enviar explicitamente:

```json
{"user_id":123,"promote_to_leader":true}
```

Essa operação promove para LEADER e cria/reativa o vínculo com liderança na mesma
transação. Sem consentimento explícito da promoção, retorna 409 sem alterar nada.
ADMIN/PASTOR/LEADER mantêm seus cargos. Pode haver vários líderes por ministério e
uma pessoa pode liderar vários ministérios. Não existe autoparticipação pública.

Adicionar membro ou líder exige conta e ministério ativos. Remoção também é
permitida em registros inativos para manutenção. O mesmo par usuário/ministério é
reutilizado, sem duplicar vínculos. Repetir uma operação já efetivada não duplica
auditoria. Reativar participação remove qualquer liderança anterior; definir líder
novamente exige a operação de liderança. Remover a última liderança não altera
automaticamente o cargo global.

Desativar o ministério oculta seu conteúdo regular e impede a administração por
líderes, mas preserva seus vínculos e marcações como histórico. Reativar o ministério
torna novamente elegíveis os vínculos preservados, conforme o estado atual de cada
usuário. Isso é diferente de reativar uma participação encerrada, que nunca restaura
liderança. Remova o vínculo/liderança explicitamente quando esse for o objetivo.

## Transações, auditoria e execução

Escritas usam o bloqueio administrativo compartilhado, depois usuários em ordem de
ID, sessão do ator, ministério e vínculo. Cargo/status/sessão e liderança específica
são verificados novamente após as travas. Inclusões concorrentes não duplicam
participação nem auditoria. Revogar sessão/poder enquanto a operação espera impede
sua conclusão. Mudanças de vínculo afetam o acesso privado com o JWT já existente.

Auditoria registra criação/edição de ministério, transições de vínculo e promoção
de cargo, apenas com IDs, estados e nomes de campos. Não copia descrição, nome de
pessoa ou contatos. Falha na auditoria desfaz inclusive a promoção de cargo.

Arquivos: `MinistryController`, `MinistryManagementService`, `MinistryDirectory`,
`MinistryInput`, `MinistryView`; ajustes em `ApiInput`, `InputValidationException` e
`CorsSubscriber`. Testes em `MinistryManagementHttpTest`, casos concorrentes em
`AdminUserHttpTest` e no worker isolado. **Sem nova migration.**

```sh
docker compose --profile tools build php api-test
docker compose --profile tools run --rm api-test
docker compose up --no-build -d --wait
docker compose exec -T php php bin/console doctrine:schema:validate
```

Resultados reais em [validation.md](validation.md). Publicações e feed estão
implementados na [fase 8](posts.md). Imagens/capas dependem de arquivos;
Web e mobile continuam nas fases próprias.
