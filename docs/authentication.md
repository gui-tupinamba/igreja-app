# Autenticação e sessões — fase 4

A API implementa autenticação. A administração de usuários, os Voters e as regras
por ministério pertencem às fases seguintes. Não há cadastro público, senha padrão
ou conta criada automaticamente.

## Configurar e executar

```powershell
php scripts/setup.php
php scripts/setup-auth.php
docker compose up --build -d --wait
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:schema:validate
```

O segundo script cria um par RSA de 3072 bits, preservando e verificando qualquer
par existente. Não altera `.env` nem mostra chaves. Os arquivos em `.secrets` são
montados somente para leitura no PHP e não entram na imagem ou no Git. Em Unix,
o diretório é privado (0700); os arquivos individuais precisam ser legíveis pelo
usuário PHP dentro do container. No Windows, mantenha o diretório sob as permissões
da sua conta. Faça backup protegido das configurações e chaves.

O padrão exige HTTPS para login, refresh e logout. Para desenvolver exclusivamente
no loopback HTTP, adicione `AUTH_ALLOW_INSECURE_LOCAL=1` ao `.env` local e recrie o
PHP com `docker compose up -d --wait`. Essa exceção só funciona para Host
`localhost`, `127.0.0.1` ou `::1` e usa outro nome de cookie. A porta Docker continua
publicada apenas em `127.0.0.1`. Não use essa opção na implantação externa.

`AUTH_ALLOWED_ORIGINS` contém origens exatas separadas por vírgulas, sem barra final.
Os padrões locais são `http://localhost:5173,http://127.0.0.1:5173`. Origens HTTP são
aceitas apenas para loopback; as demais precisam de HTTPS. Configure a origem Web
real antes da implantação. `JWT_ISSUER` e `JWT_AUDIENCE` são identificadores fixos,
respectivamente `igreja-api` e `igreja-clients`; alterá-los invalida tokens anteriores.

## Primeiro administrador

Execute no terminal interativo:

```sh
docker compose exec php php bin/console app:admin:create
```

O comando solicita nome, email, senha e confirmação. A senha fica oculta e precisa
ter entre 12 e 72 bytes, sem NUL. Não é recebida por argumento ou variável de
ambiente. O limite evita truncamento pelo bcrypt quando selecionado por `auto`.
Nome, email e senha são validados a cada pergunta, com mensagem específica e até
três tentativas. Espaços da senha são preservados; apenas o Enter de envio é removido.
Uma trava transacional impede duas criações iniciais concorrentes. Se qualquer
ADMIN já existir, inclusive inativo, a criação é recusada. Recuperação administrativa
posterior terá procedimento próprio; este comando não promove usuários existentes.

## Contratos HTTP

Todas as requisições POST usam `Content-Type: application/json`. Objetos têm campos
permitidos explícitos; campos extras, tipos errados e corpos acima de 8192 bytes
são rejeitados. Senhas são usadas exatamente como recebidas, sem `trim`. Emails
são ASCII, validados, aparados e normalizados para minúsculas.

| Ação | Web | Mobile |
| --- | --- | --- |
| Login | `POST /api/auth/login` | `POST /api/auth/mobile/login` |
| Renovar | `POST /api/auth/refresh` | `POST /api/auth/mobile/refresh` |
| Sair | `POST /api/auth/logout` | `POST /api/auth/mobile/logout` |
| Usuário atual | `GET /api/auth/me` | `GET /api/auth/me` |

Login recebe `{"email":"pessoa@example.org","password":"SUA_SENHA"}`. A resposta
inclui `access_token`, `token_type: "Bearer"`, `expires_in: 600` e `user` com apenas
`id`, `name`, `email`, `role`, `status`. `/me` retorna `{"user": {...}}` e exige
`Authorization: Bearer SEU_ACCESS_TOKEN`. Cargo vem do banco atual, sem usar cargo
embutido no JWT. Cookie e query string não autenticam `/me`.

### Web

Envie a origem permitida em `Origin` e `X-Auth-Client: web`. No navegador, use
`credentials: 'include'`. Esses requisitos também valem para login e logout;
ausência de Origin, origem inesperada e envio de formulário são recusados.

O refresh fica exclusivamente no cookie `__Host-refresh`, com HttpOnly, Secure,
SameSite=Lax, Path=/ e sem Domain. No loopback HTTP explicitamente habilitado, o
nome é `igreja_refresh_dev`, sem Secure. O access token deve permanecer em memória.
O corpo de refresh e logout é `{}`. A API nunca retorna o refresh Web em JSON e
recusa `refresh_token` no corpo Web. Logout retorna 204, revoga a sessão e expira o
cookie; sem cookie, continua sendo uma operação idempotente.

CORS permite somente as origens configuradas, credenciais e os headers
Authorization, Content-Type e X-Auth-Client. Preflight permite GET/POST. Não há
wildcard nem reflexão de headers arbitrários. As respostas variam por Origin.

### Mobile

Envie `X-Auth-Client: mobile`. Origin, Cookie e qualquer header Sec-Fetch-* são
recusados nessa família de rotas. O aplicativo nativo usa esse contrato explícito;
o navegador não pode trocar para ele mantendo cookies. Login/refresh acrescentam
`refresh_token` ao JSON. Renovar e sair recebem `{"refresh_token":"SEU_REFRESH"}`.
Logout com token desconhecido retorna 204. Token ausente ou tipo inválido é erro
de validação. O aplicativo guardará refresh no Expo SecureStore e access em memória
na fase mobile. Nenhuma dependência ou tela Expo foi antecipada nesta etapa.

### Erros e limites

Credenciais incorretas, conta inexistente, inativa ou bloqueada retornam o mesmo
401 genérico. JWT inválido, expirado ou de sessão revogada também retorna 401.
Transporte/origem proibidos retornam 403; JSON malformado, 400; campos inválidos,
422. As respostas de autenticação e erros usam `Cache-Control: no-store`.

O login consome contadores compartilhados no PostgreSQL: dez tentativas por email
normalizado e cem por IP a cada janela fixa de quinze minutos. Tentativas válidas
também contam; tentativas recusadas não reiniciam a janela. O limite é compartilhado
entre Web e mobile e retorna 429 com Retry-After. Os identificadores persistidos
são HMACs, sem guardar email ou IP em texto. O IP é o observado diretamente pelo
Nginx; headers de proxy enviados pelo cliente não são confiáveis nesta fase.
Ao configurar Tunnel/proxies na fase de implantação, ajustar a cadeia confiável
antes de usar os limites em produção. Limites de aplicação não substituem limites
de tráfego do proxy. Contadores antigos podem ser removidos pela rotina de
manutenção `LoginThrottle::pruneExpired`; agendamento fica para a operação.

## Sessões e revogação

O JWT usa RS256 com algoritmo fixo e valida assinatura, emissor, audiência, sujeito,
sessão, emissão e expiração. Dura dez minutos. A implementação usa
[firebase/php-jwt](https://github.com/googleapis/php-jwt) e o
[autenticador Symfony](https://symfony.com/doc/7.4/security/custom_authenticator.html).
Senhas usam o [hasher auto do Symfony](https://symfony.com/doc/7.4/security/passwords.html).

Cada sessão tem validade absoluta de trinta dias. O refresh contém 32 bytes
aleatórios em base64url; só SHA-256 é persistido. A renovação bloqueia usuário,
sessão e token nessa ordem. Consumo e criação do sucessor acontecem na mesma
transação, e a resposta só é construída depois do commit. Renovar não estende o
prazo absoluto. WEB e MOBILE são tipos distintos de sessão.

Reutilizar um refresh consumido revoga toda a família, inclusive o sucessor já
emitido. Essa revogação é confirmada no banco antes do 401. Tokens consumidos
devem ser retidos até a expiração absoluta para detectar replay; uma rotina futura
de limpeza não poderá apagá-los antes desse prazo.

O cliente deve serializar refresh e coordenar abas. Com a política estrita, duas
renovações simultâneas ou repetição após perda de resposta exigem novo login.
Cada dispositivo tem sua sessão; logout revoga a família correspondente.

Toda requisição autenticada consulta sessão e usuário atuais. Um trigger PostgreSQL
revoga sessões quando a senha muda ou o status passa a INACTIVE/BLOCKED, dentro da
mesma transação da alteração, inclusive em atualizações ORM/SQL. Reativar a conta
não restaura sessões. Um upgrade de hash no login também revoga sessões anteriores;
a nova sessão é criada depois do upgrade. A mudança de cargo é lida imediatamente.

A fase 6 acrescenta troca própria com senha atual e limite de tentativas, além de
redefinição administrativa autorizada; ambas revogam todas as sessões. O contrato
está em [users.md](users.md). Recuperação pública por email permanece pendente.
Não existe envio automático de senha por email, reset público ou sessão padrão.

## Persistência e validação

`Version20260910200000` adiciona `auth_sessions`, `refresh_tokens` e
`auth_login_limits`, índices, FKs, CHECKs e trigger de revogação. A migration inicial
permanece preservada. Reverter a migration de autenticação apaga sessões e limites;
o downgrade é validado somente no banco isolado de testes.

```sh
docker compose --profile tools run --build --rm api-test
docker compose exec php php bin/console lint:container
docker compose exec php php bin/console lint:yaml config
```

Os testes geram chaves próprias no container, não recebem chaves do runtime e
verificam o nome real do banco antes de escrever fixtures. Evidência da última
execução e limitações estão em [validation.md](validation.md).
