# Aplicativo da igreja

Monorepo para gestão, comunicação e ministérios. Infraestrutura, modelo inicial
do banco, autenticação e autorização estão implementados. A API já oferece leituras
protegidas, cadastro, perfis, ministérios, participantes, líderes, publicações, comentários, eventos e agenda.
A interface Web funcional está em **https://guitupinamba.dev/** e a API em
**https://api.guitupinamba.dev/api**. Entre com o ADMIN criado anteriormente.
O aplicativo mobile da fase 13 está disponível em `apps/mobile` para Android e iOS.

## Estado atual

- Symfony 7.4 LTS, Doctrine configurado, dependências fixadas em `apps/api/composer.lock`.
- Compose com PHP 8.4-FPM, PostgreSQL 17, Nginx 1.30 e serviço de testes opcional.
- `GET /api/health`: HTTP 200 com `{"status":"ok"}`; verifica a API, sem acessar o banco.
- `GET /api/ready`: executa `SELECT 1` via Doctrine; HTTP 200 se disponível, 503 genérico em falha.
- Respostas JSON de erros, autenticação obrigatória e verificações de saúde/prontidão.
- Arquivo original `teste` preservado. Não havia repositório Git inicializado na inspeção.
- **Docker validado:** PostgreSQL, PHP-FPM e Nginx saudáveis; health/ready respondem
  HTTP 200 pelo Nginx, inclusive após reiniciar o PostgreSQL.
- Modelo inicial com sete entidades, nove enums e sete repositórios; a fase 4
  adicionou três entidades auxiliares e o tipo de cliente da sessão. A fase 5
  adicionou `AuditLog`: são onze entidades e três migrations,
  `Version20260910170000`, `Version20260910200000` e `Version20260911050000`.
- Fase 3 validada com **75 testes e 601 verificações** no PostgreSQL real.
- Fase 4: login Web/mobile, JWT de dez minutos, refresh com rotação, logout e `/me`.
  Sessões revogáveis, limites de tentativas e comando seguro de primeiro ADMIN.
  **237 testes e 1.437 verificações aprovados** na imagem final com PostgreSQL real.
  Consulte [o contrato e a configuração](docs/authentication.md) e
  [o registro da validação mais recente](docs/validation.md).
- Fase 5: Voters, escopo de leitura compartilhado, listagem/detalhe administrativo
  de usuários, alteração de cargo/status e consulta das permissões atuais.
  Proteção do último ADMIN, remoção de liderança e auditoria na mesma transação.
  **299 testes e 2.027 verificações aprovados** na suíte completa com PostgreSQL isolado.
  Veja [as rotas e regras de autorização](docs/authorization.md). O registro de
  validação distingue esses testes da implantação no banco local da aplicação.

## Decisões e roteiro

Fase 6 implementada: cadastro administrativo, edição de perfil, troca própria e
redefinição administrativa de senha, com auditoria e revogação de sessões. Não há
nova migration. **321 testes e 2.277 verificações aprovados**, API local atualizada.
[Rotas e campos](docs/users.md); [testes executados](docs/validation.md).

Fase 7 implementada: gestão de ministérios, participação N:N e liderança explícita,
com histórico, auditoria e revogação de acesso. Sem nova migration.
**338 testes e 2.442 verificações aprovados**, API local atualizada.
[Rotas e regras de ministérios](docs/ministries.md).

Fase 8 implementada: publicações de texto, rascunhos, publicação/arquivamento,
feed e busca com filtros de acesso, gestão por ministério e auditoria. Sem nova migration.
**358 testes e 2.639 verificações aprovados**, API local atualizada.
[Rotas de publicações e feed](docs/posts.md).

Fase 9 implementada: criação, listagem, edição/remoção de comentários próprios e
moderação por ADMIN/PASTOR, com privacidade, estados e auditoria. Sem nova migration.
**378 testes e 2.891 verificações aprovados**, API local atualizada em 14/09/2026.
[Rotas e regras de comentários](docs/comments.md).

Fase 10 implementada: eventos gerais e de ministérios, horários com fuso, filtros
por período, publicação/cancelamento/arquivamento e auditoria. Sem nova migration.
**409 testes e 3.147 verificações aprovados**, API local atualizada em 14/09/2026.
[Rotas e regras de eventos](docs/events.md).

Fase 11 implementada: atividades gerais/por ministério e agenda combinada com
eventos, próximos compromissos, filtros e paginação global sem duplicar registros.
**442 testes e 3.445 verificações aprovados**, API local atualizada em 14/09/2026.
Sem nova migration. [Rotas e regras da agenda](docs/agenda.md).
Fase 12: Web React/TypeScript/Vite com login, painel, pessoas, ministérios,
participação/liderança, publicações/comentários, eventos, atividades, agenda e perfil.
Build estático servido pelo serviço Docker `web`, com navegação por permissões e
dados reais da API. [Execução e arquivos](docs/web.md). Sem nova migration.
Fase 13: aplicativo Expo/React Native com login, restauração segura de sessão, Home,
feed, detalhes e comentários, eventos, agenda, ministérios e perfil. Refresh token
fica no SecureStore e o access token somente em memória. [Execução](docs/mobile.md).
Próxima fase: notificações.

Leia [a arquitetura e o modelo inicial](docs/architecture.md),
[o plano com critérios de aceite](docs/implementation-plan.md) e
[a especificação original](docs/specification.md).

O Symfony concentra regras e permissões. `UserMinistry` representa participação N:N
e liderança específica, com vínculo único e status. As entidades já validam
invariantes locais; o banco reforça unicidade, referências e estados. A leitura de conteúdo privado
é filtrada no banco antes da paginação e contagem, com o mesmo escopo no acesso por ID.
PUBLIC significa visível a todos os usuários autenticados. Voters e políticas
consultam cargo, estado, participação e liderança atuais, sem confiar em campos do
cliente ou objetos antigos. A estratégia JWT/refresh está implementada na fase 4;
as primeiras rotas de negócio aplicam a autorização da fase 5.

As rotas disponíveis incluem `GET /api/auth/permissions`, `GET /api/admin/users`,
`GET /api/admin/users/{id}`, `PATCH /api/admin/users/{id}/access`, leitura de posts
e detalhes de eventos/comentários vinculados ao pai da rota. O [contrato](docs/authorization.md)
descreve parâmetros, limites e respostas. Publicações de texto já têm escrita e
feed conforme [posts.md](docs/posts.md); os demais módulos seguem o plano.

```text
apps/
  api/                   Symfony, config, controllers, serviços e testes
  web/                   React + TypeScript + Vite, telas e testes de navegador
  mobile/                React Native + Expo + Expo Router para Android/iOS
packages/
  api-client/            futuro cliente HTTP compartilhado
  types/                 futuros contratos públicos
  validation/            futura validação de entrada dos clientes
  shared/                futuros helpers comuns
docker/
  php/                   imagem, PHP-FPM e verificação do processo
  nginx/                 entrada HTTP e encaminhamento FastCGI
  postgres/              inicialização do usuário restrito
docs/                    especificação, decisões e plano incremental
scripts/                 configuração local e smoke test
docker-compose.yml
.env.example
```

## Requisitos

No Windows: Docker Desktop iniciado, com backend WSL2, containers Linux e Docker
Compose v2.24 ou superior. No Linux/VPS: Docker Engine e plugin Compose. Instalação:
[Windows](https://docs.docker.com/desktop/setup/install/windows-install/) e
[Linux](https://docs.docker.com/engine/install/).

PHP, Composer, Node e PostgreSQL instalados no host não são necessários para executar
os containers. PHP 8.2+ no host é uma alternativa para o script de configuração e
testes locais. O PHP 8.2.12 detectado no XAMPP é apenas auxiliar; a imagem utiliza PHP 8.4.
Reserve a porta local 8080 ou altere `HTTP_PORT`.

## Preparar e executar

Na raiz do projeto, gere `.env` com três segredos aleatórios independentes:

```powershell
php scripts/setup.php
php scripts/setup-auth.php
```

Os scripts preservam `.env` e chaves existentes e não exibem segredos. O primeiro
cria as variáveis básicas; o segundo cria as chaves JWT em `.secrets`. Se não houver PHP
local, com Docker já funcionando, execute no PowerShell:

```powershell
docker run --rm --mount "type=bind,source=$($PWD.Path),target=/workspace" -w /workspace php:8.4-cli-alpine php scripts/setup.php
docker run --rm --mount "type=bind,source=$($PWD.Path),target=/workspace" -w /workspace php:8.4-cli-alpine php scripts/setup-auth.php
```

Em shell Linux:

```sh
docker run --rm --user "$(id -u):$(id -g)" --mount "type=bind,source=$(pwd),target=/workspace" -w /workspace php:8.4-cli-alpine php scripts/setup.php
docker run --rm --user "$(id -u):$(id -g)" --mount "type=bind,source=$(pwd),target=/workspace" -w /workspace php:8.4-cli-alpine php scripts/setup-auth.php
```

Alternativamente copie `.env.example` para `.env` e preencha os três campos vazios
com valores aleatórios fortes. Use senha hexadecimal em `APP_DATABASE_PASSWORD`,
pois ela é incorporada à URL de conexão. Não publique o arquivo.

Login, refresh e logout exigem HTTPS por padrão. Para usar essas rotas no loopback
HTTP desta infraestrutura, acrescente `AUTH_ALLOW_INSECURE_LOCAL=1` ao `.env` local.
Esse modo usa um cookie de desenvolvimento separado. Mantenha `0` na implantação
externa. Consulte [autenticação](docs/authentication.md) para origens e contratos.

Inicie a infraestrutura:

```sh
docker compose config --quiet
docker compose up --build -d --wait
docker compose ps
```

O primeiro build instala as dependências a partir do lock. Depois, `docker compose up`
também inicia a base em primeiro plano. Alterações no código PHP exigem
`docker compose up --build -d --wait`, pois o código está incorporado à imagem.
O início do PHP-FPM prepara cache e proxies Doctrine. Não há instalação Composer
nem migrations automáticas no banco da aplicação a cada início.

Após o primeiro início ou uma atualização com novas migrations:

```sh
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:schema:validate
```

O projeto possui três migrations. O comando de migrate executa apenas as versões
pendentes; o estado de implantação verificado está em [validation.md](docs/validation.md).

Verifique o caminho HTTP completo:

```powershell
.\scripts\smoke.ps1
```

Se a política local bloquear scripts PowerShell, use os comandos abaixo ou execute
com a política permitida pela sua organização. Em PowerShell ou shell Linux:

```sh
curl -f http://127.0.0.1:8080/api/health
curl -f http://127.0.0.1:8080/api/ready
```

No Windows PowerShell 5, use `curl.exe` para evitar o alias de `Invoke-WebRequest`.
Ambos devem responder `{"status":"ok"}`. Para outra porta, passe
`-BaseUrl http://127.0.0.1:SUA_PORTA` ao smoke test.

`http://127.0.0.1:8080/` é a entrada da API e responde 404; abra a Web em
`http://127.0.0.1:5173/`. Os endpoints
health/ready são públicos e não retornam informações internas. As rotas de negócio
exigem autenticação e autorização. A exposição externa continua prevista para a fase 15.

## Variáveis de ambiente

| Variável | Uso |
| --- | --- |
| `COMPOSE_PROJECT_NAME` | Identifica serviços, redes e volumes deste projeto |
| `HTTP_PORT` | Porta Nginx, publicada apenas em `127.0.0.1` |
| `APP_ENV`, `APP_DEBUG` | `prod` e `0` por padrão; testes usam `test` |
| `APP_SECRET` | Segredo Symfony, obrigatório e gerado localmente |
| `POSTGRES_DB` | Banco criado no primeiro início |
| `POSTGRES_USER`, `POSTGRES_PASSWORD` | Superusuário de bootstrap/manutenção, não fornecido à API |
| `APP_DATABASE_USER`, `APP_DATABASE_PASSWORD` | Usuário restrito da API e migrations |
| `DATABASE_URL` | Construída pelo Compose e fornecida ao PHP; não precisa duplicá-la no `.env` |
| `JWT_ISSUER`, `JWT_AUDIENCE` | Identificadores validados em cada access token |
| `JWT_PRIVATE_KEY_PATH`, `JWT_PUBLIC_KEY_PATH` | Caminhos dos secrets montados pelo Compose |
| `AUTH_ALLOWED_ORIGINS` | Origens Web exatas, separadas por vírgulas |
| `AUTH_ALLOW_INSECURE_LOCAL` | `0` padrão; `1` habilita autenticação HTTP somente para Host de loopback |

O runtime Symfony recebe variáveis do processo; não lê `.env` da aplicação. O Compose
lê o `.env` da raiz e injeta somente os valores necessários a cada serviço. Não
execute `docker compose config` sem `--quiet` em logs compartilhados: a saída normal
pode conter segredos interpolados. Variáveis de ambiente não substituem controle de
acesso ao host/Docker. As chaves JWT já usam secrets montados somente para leitura.

Alterar senha no `.env` não altera a senha já armazenada no volume PostgreSQL.
O script de inicialização roda somente em volume vazio. Com dados existentes,
planeje a rotação no banco e na configuração de forma coordenada, sem apagar o volume.

## Rede e persistência

Nginx é o único serviço com porta no host, em loopback. Ele participa da rede
`application`, compartilhada com PHP. PostgreSQL participa somente da rede interna
`database`, compartilhada com PHP. Nginx não acessa o banco diretamente;
as portas 5432 e 9000 não são publicadas. PHP e PostgreSQL não têm saída externa
nessa configuração; serviços futuros de email/push exigirão ajuste explícito de rede.

Os testes usam `postgres-test`, banco `igreja_test` em tmpfs, e rede `test_database`
separada, sem acesso ao banco da aplicação. Não há porta publicada. Parar esse banco
descarta seus dados; o runner aplica as migrations novamente no próximo teste.

O volume `postgres_data` persiste o banco. O usuário da aplicação não é superusuário,
não cria bancos/roles e só recebe conexão e uso/criação no schema da aplicação.
Nesta base ele também executa migrations; separar identidade de migrations e runtime
antes de uma implantação que exija restrições de DDL em produção.

`pg_isready` aguarda o PostgreSQL aceitar conexões TCP; PHP possui ping FastCGI;
Nginx verifica `/api/health`. `/api/ready` confirma a conexão real com as credenciais
da aplicação. A opção `--wait` verifica saúde dos serviços, e o smoke test completa
a verificação do acesso da aplicação ao banco.

```sh
docker compose logs --tail=100 nginx php postgres
docker compose restart php
docker compose down
```

`down` preserva o volume. `down -v` apaga os dados: não utilizar como solução de rotina.
Logs possuem rotação; a aplicação evita registrar mensagem de exceção, credenciais
e parâmetros de conexão. Não há logs de acesso Nginx nesta etapa. Datas usam UTC.

## Testes

No Docker, o serviço opcional instala dependências de desenvolvimento, inicia o
PostgreSQL isolado, aplica migrations, valida o schema e prepara o cache antes dos
testes unitários, funcionais e de integração:

```sh
docker compose --profile tools run --build --rm api-test
docker compose exec php php bin/console lint:container
docker compose exec php php bin/console lint:yaml config
```

Os serviços `api-test` e `postgres-test` não são iniciados por `docker compose up` comum. O build verifica
as extensões PHP via `composer check-platform-reqs`. O runtime não inclui PHPUnit.

Alternativa no host, com PHP 8.2+ e extensões exigidas pelo Composer:

```sh
cd apps/api
composer install
composer validate --strict
composer test
```

`composer test` roda a suíte sem depender de PostgreSQL. Os doubles de conexão são
exclusivos dos testes; o runtime sempre usa Doctrine. `composer test:integration`
exige `RUN_DATABASE_TESTS=1` e `DATABASE_URL` de um banco migrado, com nome terminado
em `_test`; sem a flag os testes integrados são ignorados. A suíte consulta o nome
real do banco antes de inserir fixtures. Testes do domínio usam rollback; testes
de autenticação confirmam transações e removem apenas suas próprias fixtures. Prefira o runner
Docker, que também restringe o host a `postgres-test`. Rodar testes no host não
valida rede Docker, PHP-FPM, Nginx ou persistência.

**Registro desta entrega:** resultados finais em [validation.md](docs/validation.md).

## Banco e migrations

As entidades `User`, `Ministry`, `UserMinistry`, `Post`, `Comment`, `Event` e
`MinistrySchedule`, as três entidades de autenticação e `AuditLog` estão mapeadas
em `apps/api/src/Entity`. Enums e repositórios ficam em `src/Enum` e `src/Repository`.

A migration `apps/api/migrations/Version20260910170000.php` cria sete tabelas com
IDs identity, dez FKs com RESTRICT, índices, unicidade e 31 CHECKs de domínio.
Conteúdo privado exige ministério; publicação publicada exige data; intervalos e
estados inválidos são recusados pelo banco. Email normalizado e slug são únicos,
assim como cada par usuário/ministério. Campos de arquivos serão adicionados
quando Asset existir, com FKs reais, na fase de uploads.

`Version20260910200000` adiciona sessões, refresh tokens e limites de autenticação.
`Version20260911050000` adiciona `audit_logs`, com FK de ator, índices e CHECKs,
preservando as tabelas existentes. Mudanças de cargo/status e seus registros de
auditoria são confirmados juntos pelo serviço administrativo.

O SQL de bootstrap cria somente a identidade restrita e suas permissões.
O banco inicial é criado pela imagem PostgreSQL. Comandos de administração:

```sh
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:schema:validate
```

Não usar `doctrine:schema:update --force` como estratégia de implantação. Faça backup
antes de alterações relevantes. O usuário restrito não possui `CREATEDB` por decisão.

## Backup inicial

Com os nomes padrão do `.env`, um backup manual pode ser gerado dentro do container
e copiado ao host. Isso evita corromper dumps binários por redirecionamento no
Windows PowerShell 5:

```powershell
New-Item -ItemType Directory -Force backups
docker compose exec -T postgres pg_dump -U igreja_admin -d igreja -Fc -f /tmp/igreja.dump
docker compose cp postgres:/tmp/igreja.dump ./backups/igreja.dump
```

Use nome com data para preservar gerações; ajuste usuário/banco se personalizou o
ambiente. Copie o dump para armazenamento externo protegido. O exemplo acima ainda
é uma cópia no mesmo computador, sem automação ou restauração comprovada.

Teste a recuperação em banco isolado, preservando o banco principal:

```sh
docker compose cp ./backups/igreja.dump postgres:/tmp/restore.dump
docker compose exec -T postgres createdb -U igreja_admin igreja_restore_check
docker compose exec -T postgres pg_restore -U igreja_admin -d igreja_restore_check --exit-on-error /tmp/restore.dump
```

O banco de verificação deve ser novo; não reutilize um que contenha dados.
Valide tabelas e registros no banco restaurado, e futuramente os fluxos da aplicação.
O papel `igreja_app` já existe nesse cluster; em outro servidor prepare os mesmos
papéis antes da restauração. Inclua arquivos enviados e configurações no plano de backup.

## Próximas fases

Fases 2–15 implementadas: infraestrutura, domínio, autenticação, autorização,
usuários, ministérios, conteúdo, agenda, Web, aplicativo mobile, notificações e
acesso externo seguro. A fase 14 inclui caixa de entrada Web/mobile, preferências,
dispositivos por sessão e fila Expo; configuração em [notifications.md](docs/notifications.md).
A criação inicial é
controlada por `docker compose exec php php bin/console app:admin:create`, com senha
oculta e sem credenciais padrão. O primeiro ADMIN local foi criado pelo usuário.

Os métodos de entidade protegem invariantes locais; serviços e Voters autorizam
o ator. A API já permite consultar usuários e alterar cargo/status com as salvaguardas
da fase 5. A interface administrativa Web está disponível no domínio principal.

Web (fase 12): [instruções](docs/web.md). Mobile (fase 13):
[instruções](docs/mobile.md). A exposição externa está ativa por Cloudflare Tunnel
nos hosts `guitupinamba.dev` e `api.guitupinamba.dev`, com HTTPS e origens restritas.

API atualizada em 22/09/2026; **448 testes e 3.515 verificações** aprovados na suíte
completa com PostgreSQL isolado. A migration mais recente é
`Version20260922120000`.
