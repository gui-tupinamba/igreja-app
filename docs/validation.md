# Validação do projeto — fases 2 a 15

## Atualização de 22/09/2026

- Fase 15 operacional concluída: backup real do PostgreSQL e uploads com SHA-256,
  retenção de 14 dias e restauração comprovada em banco isolado com 19 tabelas e
  9 migrations. O banco temporário foi removido após o teste.
- `operations-check.ps1` aprovou health/ready públicos, quatro serviços essenciais,
  migrations, idade do backup e espaço em disco. As duas rotinas diárias foram
  instaladas e executadas pelo Agendador de Tarefas com resultado `0`; RPO de 24h
  e RTO de 4h estão documentados.
- A cópia externa configurável foi validada com publicação atômica, SHA-256 e
  retenção própria. Nesta instalação, o destino OneDrive `IgrejaApp-Backups` foi
  preenchido e a tarefa diária das 03:15 executou com resultado `0`. As tarefas
  preservam logs locais de sucesso e falha.
- Fase 14 implementada: caixa de entrada autenticada na Web e no mobile, leitura,
  preferências, registro Expo vinculado à sessão e audiências por igreja,
  ministério ou usuário.
- Worker `app:notifications:deliver`: lotes com lock, idempotência, retry
  exponencial, invalidação de `DeviceNotRegistered` e payload de tela bloqueada
  sem conteúdo privado. Sessão, usuário, função e participação são revalidados.
- Migration `Version20260922120000` cria cinco tabelas, FKs, CHECKs, índices e
  unicidade. Mapeamento Doctrine e banco isolado estão sincronizados.
- API: **448 testes / 3.515 assertions aprovados**. Testes novos cobrem IDOR,
  revogação de participação, escopo de líder, preferências, leitura,
  idempotência e troca do token entre usuários.
- Web: typecheck e build Vite aprovados. Mobile: typecheck e exports Android/iOS
  aprovados com `expo-notifications ~57.0.20`.
- Push externo permanece desativado até vincular o app ao EAS e definir as
  credenciais descritas em [notifications.md](notifications.md); a caixa de
  entrada funciona sem essas credenciais.

## Atualização de 21/09/2026

- Fase 13 implementada em `apps/mobile`: login, restauração/rotação de sessão,
  Home, feed, imagens protegidas, publicação e comentários, eventos, agenda,
  ministérios e perfil. O refresh token usa Expo SecureStore; access token fica
  apenas em memória.
- `npm run typecheck`, Expo Doctor (21/21) e export dos bundles Android e iOS aprovados.
- API: **445 testes / 3.490 assertions aprovados** em PostgreSQL isolado. A suíte
  cobre aprovação de posts por líderes, privacidade/IDOR e revogação de acesso às
  imagens de posts e eventos.
- Web: build Vite aprovado e 9 fluxos Playwright executados contra a API isolada.
- Migration `Version20260921120000` aplicada: normaliza posições existentes e cria
  unicidade `(post_id, position)` para uploads concorrentes. Schema Doctrine sincronizado.
- Produção local atualizada; `https://guitupinamba.dev/` e
  `https://api.guitupinamba.dev/api/ready` respondem HTTP 200 pelo Cloudflare Tunnel.
  Host desconhecido no Nginx responde 404.

Comandos principais: `docker compose --profile tools run --rm api-test`,
`npm run build`, `npm run test:e2e`, `npm run typecheck`, `npx expo-doctor` e
`npm run export`.

Atualização local: 14/09/2026 (America/Manaus). Fases 2–11 implementadas e verificadas em
Docker. A API local atende em `http://127.0.0.1:8080`. O modelo agora contém onze
entidades e três migrations. O usuário criou uma conta ADMIN real, preservada
durante esta etapa. A interface Web ainda não foi implementada.

## Resultado atual

- **442 testes / 3.445 assertions aprovados**, sem testes ignorados, com PHP 8.4.25
  e PostgreSQL isolado. Sem migration ou dependência nova; onze entidades e três
  migrations existentes continuam sincronizadas.
- Atividades: cadastro, edição/remarcação, publicação, cancelamento e arquivamento,
  com gestão por ministério, audiência de origem/destino, validação de datas,
  histórico inativo, revogação e auditoria. Cancelamento concorrente produz uma
  auditoria; sessão revogada durante a espera não consegue efetuar a operação.
- Agenda combinada: próximos compromissos e itens em andamento, períodos passados
  explícitos, ordenação/paginação global, filtros e contagens autorizadas. Eventos
  e atividades com o mesmo ID aparecem como fontes distintas, sem cópias persistidas.
  Revogar participação remove ambas as fontes privadas com o JWT existente;
  ministério inativo fica fora da agenda regular inclusive para ADMIN.
- Falhas forçadas na auditoria desfazem criação, edição, audiência, cancelamento
  e arquivamento. Logs 500 sanitizados nesses testes são esperados.
- API local atualizada e PostgreSQL/PHP/Nginx saudáveis. Schema, container Symfony
  e sete YAML aprovados. Pelo Nginx: health/ready 200; agenda GET, atividades
  POST/PATCH/DELETE e cancelamento POST sem credenciais 401; preflight PATCH 204.
- Consulta agregada confirmou **um ADMIN/ACTIVE**, zero eventos, zero atividades
  e zero auditorias no banco local. Fixtures ficaram no banco temporário isolado.
- Arquivos/contrato: [agenda.md](agenda.md). Próxima fase: interface Web. Mobile,
  imagens/uploads, recorrência e notificações permanecem nas etapas posteriores.

Comandos executados: `docker compose --profile tools build php api-test`,
`docker compose --profile tools run --rm api-test`, `docker compose up --no-build -d --wait`,
`doctrine:schema:validate`, `lint:container`, `lint:yaml config` e consulta agregada
com `dbal:run-sql` no serviço PHP; smoke HTTP com `curl.exe`.

## Histórico: conclusão da fase 10

- **409 testes / 3.147 assertions aprovados**, sem testes ignorados, com PHP 8.4.25
  e PostgreSQL isolado. Sem migration ou dependência nova; onze entidades e três
  migrations existentes continuam sincronizadas.
- Eventos: rascunho, publicação, cancelamento, arquivamento, edição e remarcação;
  gestão por ministério, autorização de origem/destino, revogação com JWT existente,
  acesso por pai correto, privacidade nas listas e contagens, estados e validação.
- Horários com offsets são normalizados para UTC; offsets equivalentes não geram
  escrita/auditoria. Datas inválidas, ausência de fuso e intervalos invertidos são
  recusados. Filtros incluem eventos em andamento e respeitam limites do período,
  ordenação e páginas vazias. Cancelar rascunho/arquivado não publica conteúdo.
- Concorrência real confirmou uma auditoria para cancelamentos iguais, recusa após
  revogação de sessão durante a espera e revalidação de fim alterado enquanto duas
  remarcações aguardavam. Falhas forçadas na auditoria desfazem criação, horários,
  audiência, cancelamento e arquivamento. Logs 500 sanitizados desses casos são esperados.
- API local atualizada e PostgreSQL/PHP/Nginx saudáveis. Schema, container Symfony
  e sete YAML aprovados. Pelo Nginx: health/ready 200, eventos GET/POST/PATCH/DELETE
  e cancelamento POST sem credenciais 401; preflight PATCH 204.
- Consulta agregada no banco local confirmou **um ADMIN/ACTIVE**, zero eventos
  e zero auditorias. Fixtures ficaram exclusivamente no banco temporário isolado.
- Arquivos/contrato: [events.md](events.md). Próxima fase: agenda. Interfaces Web/mobile,
  imagens/uploads e notificações push/email continuam pendentes nas etapas próprias.

Comandos executados: `docker compose --profile tools build php api-test`,
`docker compose --profile tools run --rm api-test`, `docker compose up --no-build -d --wait`,
`doctrine:schema:validate`, `lint:container`, `lint:yaml config` e consulta agregada
com `dbal:run-sql` no serviço PHP; smoke HTTP com `curl.exe`.

## Histórico: conclusão da fase 9

- **378 testes / 2.891 assertions aprovados**, sem testes ignorados, com PHP 8.4.25
  e PostgreSQL isolado. Sem migration ou dependência nova; onze entidades e três
  migrations existentes continuam sincronizadas.
- Comentários: criação, paginação, consulta, correção e remoção pelo autor,
  moderação ADMIN/PASTOR, ocultação/restauração e DELETED terminal. Validados
  limites de texto, autoria fixada pelo servidor, pai incorreto, acesso privado
  revogado com o token existente e bloqueio de moderação por líderes.
- Listas regulares excluem ocultos da página e da contagem, inclusive para ADMIN.
  A manutenção administrativa global trata histórico de posts sem liberar leitura regular.
- Concorrência em processos separados confirma recusa após revogação de sessão,
  recusa de duas criações após fechamento dos comentários durante a espera e
  uma única auditoria para duas moderações iguais. Falhas forçadas na auditoria
  desfazem criação, edição, moderação e exclusão; seus logs 500 sanitizados são esperados.
- API local atualizada e PostgreSQL/PHP/Nginx saudáveis. Schema, container Symfony
  e sete arquivos YAML aprovados. Pelo Nginx: health/ready 200, GET/POST/PATCH/DELETE
  de comentários e PATCH de moderação sem credenciais 401; preflight PATCH 204.
- Consulta agregada no banco local confirmou **um ADMIN/ACTIVE**, zero posts,
  zero comentários e zero auditorias. As fixtures ficaram no banco isolado.
- Arquivos e contrato: [comments.md](comments.md). Eventos são a próxima fase;
  interfaces Web/mobile e imagens/uploads permanecem pendentes nas etapas próprias.

Comandos executados: `docker compose --profile tools build php api-test`,
`docker compose --profile tools run --rm api-test`, `docker compose up --no-build -d --wait`,
`doctrine:schema:validate`, `lint:container`, `lint:yaml config` e consulta agregada
com `dbal:run-sql` no serviço PHP; smoke HTTP com `curl.exe`.

## Histórico: conclusão da fase 8

- **358 testes / 2.639 assertions aprovados**, sem testes ignorados, com PHP 8.4.25
  e PostgreSQL isolado na imagem final. Nenhuma migration ou dependência nova.
- Verificados rascunho/publicação/retirada/arquivamento/republicação, busca e filtros
  sem vazamento de conteúdo privado, gestão por ministério, origem/destino na mudança
  de audiência, autor fixado pelo servidor, validação e configuração de comentários.
- A matriz de leitura anterior continua passando. O intervalo de páginas do feed
  foi preservado, inclusive páginas grandes vazias com contagem autorizada.
- Concorrência com duas conexões/processos confirma uma única transição/auditoria
  na publicação repetida e recusa da sessão revogada durante espera. Falha forçada
  na auditoria desfaz publicação/data e mudança de audiência. Logs 500 desses casos
  são esperados e sanitizados; não representam falhas da suíte.
- API local atualizada, serviços saudáveis, schema sincronizado, container Symfony
  e sete YAML aprovados. Pelo Nginx: health/ready 200, novas operações sem credencial
  401 e preflight PATCH 204. Um ADMIN/ACTIVE preservado; zero posts e auditorias no
  banco local. As fixtures ficaram exclusivamente no banco temporário de testes,
  encerrado após a validação.
- Contratos e arquivos em [posts.md](posts.md). Publicações são de texto; imagens
  aguardam arquivos/upload. Na conclusão da fase 8, interface Web e escrita de comentários eram pendentes.

Comandos executados: `docker compose --profile tools build php api-test`,
`docker compose --profile tools run --rm api-test`, `docker compose up --no-build -d --wait`,
`doctrine:schema:validate`, `lint:container`, `lint:yaml config` no serviço PHP,
e smoke HTTP com `curl.exe`.

## Histórico: conclusão da fase 7

- **338 testes / 2.442 assertions aprovados**, sem testes ignorados, na imagem final
  de testes com PHP 8.4.25 e PostgreSQL isolado. O schema permanece sincronizado
  com as onze entidades e três migrations existentes.
- Gestão de ministérios, participação e liderança implementadas sem nova migration.
  Contratos, arquivos e comandos em [ministries.md](ministries.md).
- Casos HTTP verificam criação/edição/desativação, slug único, campos inválidos,
  promoção explícita, múltiplas lideranças, privilégio por ministério, diretório
  sem contatos pessoais, escopo antes de paginação/contagem e acesso privado
  revogado após remover participação. Reativação de vínculo não restaura liderança.
- Concorrência real verifica duas inclusões do mesmo vínculo sem duplicar relação
  ou auditoria e sessão revogada durante espera de atribuição de liderança.
  Falha forçada na auditoria desfaz promoção de cargo e participação juntas.
- O cenário de publicação privada usa data truncada ao segundo, consistente com
  o schema; isso elimina arredondamento da fixture para um instante futuro.
- API local atualizada; PostgreSQL, PHP-FPM e Nginx saudáveis. Schema, container
  Symfony e sete YAML aprovados. Health/ready retornaram 200 pelo Nginx; as novas
  operações sem autenticação retornaram 401; preflight de remoção de vínculo 204.
- Consulta agregada confirmou um ADMIN/ACTIVE preservado, zero ministérios e zero
  auditorias no banco local. Não houve criação de dados reais nem fixtures nesse
  banco. O PostgreSQL temporário de testes foi encerrado.

Comandos executados: `docker compose --profile tools build php api-test`,
`docker compose --profile tools run --rm api-test`, `docker compose up --no-build -d --wait`,
`doctrine:schema:validate`, `lint:container` e `lint:yaml config` no serviço PHP;
verificação HTTP com `curl.exe`. O segundo build de `api-test` incluiu a correção
da data da fixture, sem mudança adicional no runtime.

## Histórico: conclusão da fase 6

- **321 testes / 2.277 assertions aprovados**, sem testes ignorados, na imagem final
  com PHP 8.4.25 e PostgreSQL isolado. As três migrations foram aplicadas em banco
  de testes vazio e o schema validado. Nenhuma nova migration nesta etapa.
- Cadastro com login real, campos opcionais, status inicial, email normalizado único,
  validação de senha/nascimento, mensagens de campo sem dados pessoais e proteção
  contra alteração de cargo/IDs pelo formulário de perfil verificados.
- Perfil próprio separa participação/liderança e não expõe vínculos inativos ou de
  terceiros. PASTOR não cria nem altera perfil/senha de ADMIN. Mudança de cargo em
  sessão existente remove acesso aos novos endpoints administrativos.
- Troca própria exige senha atual e limite de tentativas; redefinição administrativa
  e mudança de email revogam sessões e refresh tokens. A senha antiga deixa de
  funcionar; nenhum segredo ou dado pessoal é copiado à auditoria.
- Concorrência real: duas criações com mesmo email confirmam uma conta e uma
  auditoria; edição de perfil com sessão revogada durante espera não é confirmada.
  Falha forçada na auditoria desfaz mudança de email e revogação de sessões.
- Arquivos, contrato e limitações em [users.md](users.md). As contas e fixtures dos
  testes ficam exclusivamente em `igreja_test`.
- API local atualizada, serviços saudáveis, schema sincronizado, container Symfony
  e sete YAML aprovados. Pelo Nginx, health/ready retornaram 200; as seis novas
  operações sem credencial retornaram 401; preflight de perfil retornou 204.
  Consulta agregada confirmou um ADMIN/ACTIVE e zero auditorias no banco local:
  o administrador real foi preservado. Banco temporário de testes encerrado.

Comandos: `docker compose --profile tools build php api-test`,
`docker compose --profile tools run --rm api-test`,
`docker compose up --no-build -d --wait`; validações com `doctrine:schema:validate`,
`lint:container` e `lint:yaml config` no serviço PHP.

## Histórico: conclusão da fase 5

- **299 testes / 2.027 assertions aprovados**, sem testes ignorados, com PHP 8.4.25
  e PostgreSQL isolado, na imagem final reconstruída com todo o código e testes.
  Incluem as suítes anteriores e os novos casos de autorização.
- Imagem de runtime atualizada e migration `Version20260911050000` aplicada no
  banco local: uma migration e oito comandos SQL, acrescentando somente auditoria.
  As três migrations estão aplicadas; schema, container Symfony, sete YAML e
  configuração Nginx validados. PostgreSQL, PHP-FPM e Nginx ficaram saudáveis.
- HTTP pelo Nginx: health/ready 200; usuários administrativos, permissões e posts
  sem credencial retornaram 401; preflight PATCH autorizado retornou 204.
  Consulta agregada confirmou um ADMIN/ACTIVE e nenhum registro de auditoria:
  nenhuma conta real foi alterada ou usada como fixture.
- Implementados Voters e políticas com consulta ao estado atual do banco;
  cargo global, vínculo, liderança e visibilidade verificados separadamente.
- Listagem/detalhe administrativo e alteração de cargo/status disponíveis;
  PASTOR não consulta nem administra ADMIN, e MEMBER/LEADER não acessam esse módulo.
- Leitura de posts, eventos por ministério e comentários por post aplica o mesmo
  escopo no SQL antes da paginação, contagem e acesso por ID. Verificados PUBLIC
  autenticado, participação específica, ministério inativo, estados e IDs de pais.
- Verificadas revogação imediata após mudança de cargo/vínculo, retirada de
  lideranças, bloqueio de sessões e reativação sem restaurar acessos antigos.
- Concorrência real com processos PHP e conexões PostgreSQL independentes:
  dois ADMINs não conseguem rebaixar simultaneamente os últimos administradores;
  sessão revogada enquanto aguarda a trava não pode concluir a alteração.
- A migration `Version20260911050000` acrescenta `audit_logs`. Falha forçada na
  auditoria, somente no banco de testes, desfez tanto a mudança de status quanto a
  revogação de sessão. O HTTP 500 sanitizado desse caso é esperado pelo teste.
- Novos arquivos, rotas, contratos e comandos estão em
  [authorization.md](authorization.md). Cadastro e edição de perfil ficam na fase 6.

Comandos executados nesta atualização:

```sh
docker compose --profile tools build php api-test
docker compose --profile tools run --rm api-test
docker compose run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction
docker compose up --no-build -d --wait
docker compose exec -T php php bin/console doctrine:schema:validate
docker compose exec -T php php bin/console lint:container
docker compose exec -T php php bin/console lint:yaml config
docker compose exec -T nginx nginx -t
```

## Histórico: correção do cadastro inicial de ADMIN

Atualização em 11/09/2026: o usuário confirmou que a senha curta causava a recusa
e criou o primeiro ADMIN. Consulta agregada confirmou uma conta ADMIN/ACTIVE,
preservada na atualização. O comando agora explica os erros por campo, permite até
três tentativas e preserva espaços da senha. Validação direcionada no PostgreSQL:
**35 testes / 130 assertions aprovados**, incluindo fluxo interativo, senha oculta,
preservação de espaços e concorrência do bootstrap. Não houve nova migration.
Arquivos: `CreateAdminCommand`, `InitialAdminCreator`, `AdminCreationException`,
`InitialAdminTest` e `AdminCreationInputTest`.

## Histórico: conclusão da fase 4

O registro abaixo corresponde à entrega original de autenticação, antes da criação
manual do administrador.

- Imagens finais de runtime e testes construídas com Symfony Security 7.4.18 e
  firebase/php-jwt 7.1.0. O runtime não inclui dependências de desenvolvimento.
- Migration `Version20260910200000` aplicada no banco da aplicação: três tabelas
  auxiliares, índices, FKs, CHECKs e trigger de revogação por senha/status.
  A migration inicial foi preservada. Schema Doctrine sincronizado nos dois bancos.
- **237 testes / 1.437 assertions aprovados**, sem testes ignorados, na imagem final
  com PHP 8.4.25 e PostgreSQL real, após reversão/reaplicação da migration de autenticação.
- Reversão da nova migration testada somente em `igreja_test`; o banco da aplicação
  recebeu apenas a aplicação normal da migration, sem downgrade ou fixtures.
- Concorrência real verificada com dois processos PHP e duas conexões PostgreSQL:
  as duas conexões esperam na trava antes da liberação; refresh produz um sucessor
  e depois revoga a família por replay; bootstrap cria exatamente um ADMIN.
- Cobertura: login Web/mobile, cookies, CORS/CSRF, JSON/campos inválidos, JWTs
  expirados/adulterados e claims incorretos, isolamento entre usuários e dispositivos,
  prazo absoluto, rotação, replay confirmado por conexão independente, logout,
  revogação por senha/status, reativação sem restaurar sessões e cargo atualizado.
- Limites por conta/IP, contadores persistidos, janela expirada, constraints SQL,
  criação inicial e comando interativo com senha oculta também verificados.
- `composer validate --strict` e sintaxe de 63 arquivos PHP aprovados. A resolução
  de dependências não reportou avisos de vulnerabilidade conhecidos.
- Runtime: `lint:container`, sete YAML, `nginx -t` e schema aprovados.
  PostgreSQL, PHP e Nginx saudáveis após atualizar o container da API.
- HTTP pelo Nginx: health/ready GET 200; health HEAD 200; `/me` sem credencial 401;
  preflight permitido 204; login Web/mobile inválido 401 com mensagem genérica.
- Par RSA real montado no runtime verificado por assinatura e validação sem exibir
  tokens/chaves. JWT assinado sem sessão real foi rejeitado pelo Nginx/API com 401.
- Consulta confirmou **zero usuários no banco da aplicação**. O primeiro ADMIN
  será criado por `docker compose exec php php bin/console app:admin:create`.
- `.env` local recebeu explicitamente `AUTH_ALLOW_INSECURE_LOCAL=1`, para o HTTP
  publicado apenas no loopback. `.env.example` mantém o padrão seguro `0`.
  Chaves em `.secrets` foram geradas e a segunda execução preservou/verificou o par.
- Banco temporário de testes encerrado após a validação; a API local continua ativa.

Comandos e contratos de uso estão em [authentication.md](authentication.md).
O login válido, a rotação e o logout completos foram exercitados via Kernel HTTP
com PostgreSQL isolado. Nenhuma conta real foi criada para o smoke do Nginx.

## Histórico: conclusão das fases 2 e 3

- Docker Desktop 4.90.0, Engine 29.7.2 e Compose 5.5.1 disponíveis com containers Linux.
- Build de runtime e testes concluído; extensões e dependências verificadas no PHP 8.4.25.
- PostgreSQL, PHP-FPM e Nginx saudáveis; `nginx -t`, lint de container e dos seis YAML aprovados.
- `GET /api/health` e `GET /api/ready` retornam HTTP 200 com `{"status":"ok"}` pelo Nginx.
- `Version20260910170000` aplicada em banco vazio: sete tabelas, dez FKs RESTRICT,
  índices, unicidade e 31 CHECKs; Doctrine confirmou schema e mapeamentos consistentes.
- **75 testes / 601 assertions aprovados**, sem testes ignorados, em PostgreSQL real.
- Testes cobrem persistência e reidratação das sete entidades, identidade/UTC/data de
  nascimento, vínculos e liderança, estados, visibilidade e 35 violações SQL por
  unicidade, CHECKs e FKs. As violações são verificadas por SQLSTATE.
- Reversão até versão 0 e reaplicação da migration confirmadas somente em `igreja_test`;
  schema novamente consistente após o ciclo.
- Migration permaneceu aplicada após reiniciar o PostgreSQL da aplicação, e ambos
  os endpoints continuaram respondendo HTTP 200.
- `composer validate --strict` aprovado após declarar a necessidade de mbstring.

O serviço `postgres-test` usa tmpfs e a rede exclusiva `test_database`. `api-test`
aponta somente para `igreja_test`; o runner confere host/ambiente antes de aplicar
migrations, e os testes verificam o nome real do banco antes de inserir fixtures.
Os casos do domínio revertem suas transações. Na fase 4, os testes de autenticação
confirmam transações reais e removem suas próprias fixtures. O banco da aplicação
não recebeu fixtures de teste.

Na fase 3, os métodos de entidades protegiam somente invariantes locais. Voters,
proteção do último ADMIN e autorização de operações foram implementados na fase 5.

A execução direta de `scripts/smoke.ps1` foi bloqueada pela política de scripts
do Windows. Os mesmos endpoints foram verificados com `curl.exe`, sem mudar essa
política. A sintaxe do script já havia sido corrigida e validada.

## Histórico: ambiente encontrado antes da instalação do Docker

- Diretório inicial com um único arquivo vazio, `teste`, preservado.
- Sem repositório Git inicializado.
- Windows, PHP CLI 8.2.12, Composer 2.10.2 e Node.js 24.17.0 disponíveis.
- WSL2 disponível; Docker e Podman não encontrados no Windows ou na distribuição WSL.
- Runtime definido no projeto: Linux/PHP 8.4-FPM, PostgreSQL 17 e Nginx 1.30.
- Docker Compose standalone 5.5.1 baixado do repositório oficial para `.tmp/tools`,
  com SHA-256 comparado ao digest publicado. Utilizado somente para validar a
  configuração, sem instalar um daemon. Essa pasta é ignorada pelo Git/build.

## Histórico: verificações do bootstrap inicial

| Verificação | Resultado e limite |
| --- | --- |
| Composer install/update | Dependências instaladas; `composer.lock` gerado e sincronizado |
| `composer validate --strict` | Aprovado |
| `composer audit --locked` | Sem vulnerabilidades conhecidas reportadas na execução |
| PHPUnit, suíte `application` | **8 testes e 37 assertions aprovados** |
| PHPUnit, suíte `integration` no host | **1 teste ignorado**: ambiente sem conexão PostgreSQL configurada para execução real |
| Sintaxe PHP da API e script de setup | Aprovada |
| Symfony `lint:yaml config` | Aprovado |
| Symfony `lint:container` | Aprovado |
| Cache Symfony em produção | Warmup aprovado |
| Doctrine `schema:validate --skip-sync` | Mapeamentos vazios válidos; não compara banco real |
| Kernel em `prod`, sem doubles | Health 200, ready 503, método inválido 405 e rota ausente 404; JSON e no-store |
| Ambiente do runtime | `APP_ENV` respeitado, inclusive console `--env=test`; padrão prod/debug desligado |
| Compose `config --quiet` | Aprovado pelo validador oficial |
| Configuração resolvida do Compose | PostgreSQL/FPM sem portas no host; Nginx em loopback; redes internas; teste integrado habilitado |
| Compose com `.env.example` sem segredos | Rejeitado como esperado por variáveis obrigatórias vazias |
| Scripts shell de FPM e inicialização PostgreSQL | Sintaxe validada com Bash; não executados em containers |
| Script PowerShell de smoke | Sintaxe corrigida e validada pelo parser; execução HTTP depende dos containers |
| `scripts/setup.php` | `.env` criado com segredos aleatórios sem exibi-los; segunda execução preservou o mesmo conteúdo |
| Revisão independente | Compose, Dockerfile, Nginx, FPM, init PostgreSQL, secrets e exclusões revisados estaticamente |

Os testes da aplicação cobrem health sem consulta ao banco, prontidão com sucesso
e falha, erros 404/405/500 e log da falha de conexão sem sua
mensagem sensível. Doubles de conexão existem somente nos testes. O serviço real
de prontidão utiliza Doctrine.

O smoke do Kernel chama o Symfony no PHP do host; ele não constitui um teste HTTP
através do Nginx/PHP-FPM. O 503 de prontidão nesse ambiente é esperado, sem banco
configurado/driver PostgreSQL habilitado. Não é evidência de uma conexão bem-sucedida.

A revisão identificou que opções fixas no Symfony Runtime ignoravam `APP_ENV`.
Isso foi corrigido: defaults estão no bootstrap e variáveis externas são respeitadas.

## Como reproduzir a validação atual

Com Docker Desktop ou Engine iniciado:

```sh
docker compose config --quiet
php scripts/setup-auth.php
docker compose up --build -d --wait
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:schema:validate
docker compose --profile tools run --build --rm api-test
```

Verificação HTTP, inclusive em Windows com scripts PowerShell bloqueados:

```powershell
curl.exe --fail http://127.0.0.1:8080/api/health
curl.exe --fail http://127.0.0.1:8080/api/ready
```

O `api-test` recebe `RUN_DATABASE_TESTS=1`, prepara o schema no banco isolado e
executa a suíte completa. Health e ready devem retornar HTTP 200 pelo Nginx.

## Pendências fora desta fase

Registro histórico: naquele marco, backup/restauração e fases posteriores ainda
estavam pendentes. Foram concluídos nas atualizações acima. Recuperação pública de
senha continua fora do escopo atual; troca própria e redefinição administrativa
foram implementadas na fase 6.
