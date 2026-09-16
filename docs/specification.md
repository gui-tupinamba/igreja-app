# PROJETO: APLICATIVO DE GESTÃO, COMUNICAÇÃO E MINISTÉRIOS DA IGREJA

Quero desenvolver um aplicativo completo para gestão, comunicação e organização de uma igreja.

Atue como um engenheiro de software sênior responsável pela arquitetura e implementação do projeto.

Não trate este projeto como uma demonstração simples.

Quero uma aplicação real, organizada, segura, modular, documentada e preparada para crescer futuramente.

O projeto deverá atender:

- Android
- iOS
- Web
- Painel administrativo
- API centralizada

O aplicativo começará como um MVP, porém sua arquitetura deverá ser preparada para expansão sem exigir uma reconstrução completa posteriormente.

---

# 1. STACK TECNOLÓGICO DEFINIDO

Utilize esta stack como padrão do projeto.

## MOBILE

Utilizar:

- React Native
- Expo
- Expo Router
- TypeScript

O mesmo projeto mobile deverá atender:

- Android
- iOS

Não utilizar React Native Web como aplicação web principal.

---

# 2. WEB

Utilizar:

- React
- TypeScript
- Vite

A aplicação Web será utilizada tanto pelos membros quanto principalmente para funcionalidades administrativas mais complexas.

Exemplos:

- Dashboard
- Gestão de usuários
- Gestão de ministérios
- Gestão de líderes
- Publicações
- Eventos
- Agenda
- Comentários
- Configurações
- Relatórios futuramente

A interface Web deverá ser responsiva.

---

# 3. BACKEND

Utilizar:

- PHP
- Symfony
- Doctrine ORM
- API REST
- JSON
- PostgreSQL

O Symfony será o núcleo central de regras de negócio.

O frontend NUNCA deverá ser responsável sozinho pela autorização de operações.

Todas as permissões deverão ser verificadas novamente pelo backend.

---

# 4. BANCO DE DADOS

Utilizar:

PostgreSQL.

Utilizar:

- Foreign Keys
- Índices adequados
- Unique Constraints
- Migrations do Doctrine
- Relacionamentos normalizados

Nunca armazenar senha em texto puro.

Utilizar o mecanismo de hashing recomendado pelo Symfony.

---

# 5. INFRAESTRUTURA INICIAL

Inicialmente o servidor ficará hospedado no meu computador pessoal.

Utilizar:

- Docker
- Docker Compose
- Containers Linux
- Nginx
- PHP-FPM
- PostgreSQL
- cloudflared

Caso o computador seja Windows, considerar:

- Docker Desktop
- WSL2

O objetivo é permitir que o ambiente seja posteriormente migrado para um VPS Linux praticamente sem alterações na aplicação.

---

# 6. CLOUDFLARE TUNNEL

O acesso externo acontecerá inicialmente utilizando Cloudflare Tunnel.

Não será necessário abrir portas diretamente no roteador.

Pretendo utilizar algo semelhante a:

app.dominio.com.br

para a aplicação React Web.

E:

api.dominio.com.br

para a API Symfony.

Fluxo esperado:

Internet
→ Cloudflare
→ Cloudflare Tunnel
→ Nginx
→ Symfony
→ PostgreSQL

Para o Web:

Internet
→ Cloudflare
→ Cloudflare Tunnel
→ Nginx
→ React

O PostgreSQL NÃO deverá ficar publicamente exposto.

O banco deverá ser acessível somente pelos serviços internos necessários.

Preferir Named Tunnel em vez de Quick Tunnel para o ambiente utilizado pelos primeiros usuários.

---

# 7. ARQUITETURA GERAL

A arquitetura deverá funcionar aproximadamente assim:

                     INTERNET
                        |
                        v
                   CLOUDFLARE
                        |
                        v
                CLOUDFLARE TUNNEL
                        |
                        v
                       NGINX
                  /             \
                 v               v
          REACT WEB          SYMFONY API
                                |
                                v
                           POSTGRESQL


MOBILE:

React Native
Android/iOS
      |
      |
      v
https://api.dominio.com.br
      |
      v
Cloudflare
      |
      v
Symfony API
      |
      v
PostgreSQL

Toda regra de negócio deverá estar centralizada no backend.

---

# 8. ORGANIZAÇÃO DO PROJETO

Preferencialmente utilizar uma estrutura organizada como:

igreja-app/
|
├── apps/
│   |
│   ├── mobile/
│   │   ├── React Native
│   │   ├── Expo
│   │   └── TypeScript
│   |
│   ├── web/
│   │   ├── React
│   │   ├── Vite
│   │   └── TypeScript
│   |
│   └── api/
│       ├── Symfony
│       ├── Doctrine
│       └── PHP
│
├── packages/
│   |
│   ├── api-client/
│   ├── types/
│   ├── validation/
│   └── shared/
│
├── docker/
│   ├── nginx/
│   ├── php/
│   └── outros arquivos necessários
│
├── docker-compose.yml
│
├── .env.example
│
└── README.md

Não compartilhar componentes visuais do React Native com React Web apenas para tentar economizar código.

Priorizar compartilhamento de:

- Tipos
- Interfaces
- Schemas
- Cliente de API
- Validações
- Constantes
- Helpers

---

# 9. TIPOS DE USUÁRIOS

Inicialmente existirão quatro níveis principais:

1. ADMIN
2. PASTOR
3. LEADER
4. MEMBER

Na interface em português:

ADMIN
Administrador

PASTOR
Pastor/Pastora

LEADER
Líder

MEMBER
Membro/Fiel

Hierarquia conceitual:

ADMIN
↓
PASTOR
↓
LEADER
↓
MEMBER

Porém, existe uma regra extremamente importante:

LIDERANÇA DE MINISTÉRIO NÃO DEVE SER DETERMINADA APENAS PELO ROLE DO USUÁRIO.

Devemos separar:

- Cargo global
- Participação em ministérios
- Liderança em ministérios

---

# 10. ADMINISTRADOR

ADMIN representa o maior nível de autoridade do sistema.

Possui acesso total.

Poderá:

- Criar usuários
- Editar usuários
- Desativar usuários
- Reativar usuários
- Excluir usuários quando permitido
- Alterar cargos
- Criar ministérios
- Editar ministérios
- Desativar ministérios
- Cadastrar membros em ministérios
- Remover membros dos ministérios
- Definir líderes
- Remover liderança
- Criar publicações
- Editar qualquer publicação
- Excluir qualquer publicação
- Criar eventos
- Editar qualquer evento
- Excluir eventos
- Gerenciar agendas
- Moderar comentários
- Visualizar conteúdos privados
- Gerenciar Pastor/Pastora
- Gerenciar outros administradores quando permitido
- Configurar parâmetros gerais da igreja
- Acessar logs administrativos

ADMIN deverá possuir algumas permissões exclusivas.

Exemplos:

- Criar/remover outros ADMIN
- Configurações técnicas
- Configurações globais
- Configurações críticas
- Segurança
- Permissões globais

Pastor não deverá poder retirar a autoridade do ADMIN principal.

---

# 11. PASTOR / PASTORA

PASTOR possui praticamente as mesmas permissões operacionais do ADMIN.

Poderá:

- Gerenciar usuários
- Gerenciar ministérios
- Gerenciar líderes
- Adicionar pessoas aos ministérios
- Criar eventos
- Editar eventos
- Criar publicações
- Editar publicações
- Excluir publicações
- Visualizar conteúdos privados
- Moderar comentários
- Gerenciar agendas
- Acessar dashboard administrativo

Porém:

ADMIN continua hierarquicamente acima de PASTOR.

PASTOR não deverá alterar configurações exclusivas do ADMIN.

Pastor/Pastora também poderá:

- Participar de ministérios
- Liderar ministérios

---

# 12. LÍDER

Um usuário com cargo LEADER poderá possuir liderança sobre um ou vários ministérios.

Porém:

SER LÍDER NÃO SIGNIFICA LIDERAR TODOS OS MINISTÉRIOS DOS QUAIS PARTICIPA.

Exemplo:

Usuário João.

Role:

LEADER

Participa de:

- Ministério de Mídia
- Ministério de Dança
- Ministério de Jovens

Lidera:

- Ministério de Dança

João poderá visualizar conteúdos privados dos três ministérios porque participa deles.

Entretanto, somente poderá administrar:

Ministério de Dança.

João NÃO poderá administrar:

- Mídia
- Jovens

apenas porque participa deles.

---

# 13. PERMISSÕES DO LÍDER

Dentro dos ministérios que efetivamente lidera, poderá:

- Criar publicações
- Criar publicações públicas
- Criar publicações privadas
- Editar publicações do ministério
- Criar eventos
- Editar eventos do ministério
- Administrar agenda
- Criar avisos
- Criar comunicados
- Visualizar membros
- Gerenciar determinadas informações do ministério

Um líder NÃO poderá realizar essas operações em outro ministério que não lidera.

A validação deverá acontecer obrigatoriamente no backend.

---

# 14. MEMBRO / FIEL

O MEMBER será o usuário comum.

Poderá:

- Visualizar o feed
- Visualizar publicações públicas
- Visualizar publicações públicas de TODOS os ministérios
- Visualizar eventos públicos
- Comentar em publicações quando permitido
- Visualizar seus ministérios
- Visualizar seu perfil
- Visualizar agendas permitidas
- Receber comunicados
- Visualizar conteúdos privados somente dos ministérios aos quais pertence

Não poderá:

- Administrar usuários
- Criar ministérios
- Alterar ministérios
- Definir líderes
- Criar publicações administrativas
- Alterar cargos
- Criar eventos administrativos
- Gerenciar configurações

---

# 15. PARTICIPAÇÃO EM MINISTÉRIOS

Um usuário poderá participar de:

- Nenhum ministério
- Um ministério
- Vários ministérios

Exemplo:

Maria:

Role:
MEMBER

Participa:

- Louvor
- Mulheres
- Intercessão

Deverá existir uma relação many-to-many entre usuários e ministérios.

Nunca utilizar apenas:

user.ministry_id

porque isso permitiria somente um ministério.

---

# 16. PARTICIPAÇÃO E LIDERANÇA

Precisamos saber individualmente se determinado usuário lidera determinado ministério.

Uma estrutura inicial possível:

USER_MINISTRIES

- id
- user_id
- ministry_id
- is_leader
- joined_at
- status

Exemplo:

user_id = 20
ministry_id = 3
is_leader = false

user_id = 20
ministry_id = 7
is_leader = true

Significa:

O usuário participa do ministério 3.

O usuário participa e lidera o ministério 7.

Essa regra é fundamental.

---

# 17. MINISTÉRIOS

O sistema deverá permitir criação dinâmica de ministérios.

Não colocar ministérios diretamente no código.

Exemplos:

- Mídia
- Louvor
- Dança
- Jovens
- Infantil
- Homens
- Mulheres
- Intercessão
- Recepção

Cada ministério poderá possuir:

- ID
- Nome
- Descrição
- Imagem
- Capa
- Status
- Líderes
- Participantes
- Publicações
- Eventos
- Agenda
- Avisos
- Data de criação
- Data de atualização

Um ministério poderá possuir mais de um líder.

---

# 18. PUBLICAÇÕES

O aplicativo deverá possuir um sistema de feed.

Uma publicação poderá ser:

- Publicação geral da igreja
- Publicação relacionada a um ministério

Uma publicação poderá conter:

- Autor
- Título
- Conteúdo
- Imagem
- Ministério
- Visibilidade
- Comentários habilitados/desabilitados
- Status
- Data de criação
- Data de atualização

---

# 19. PUBLICAÇÕES PÚBLICAS E PRIVADAS

Esta regra é extremamente importante.

Inicialmente existirão pelo menos dois níveis de visibilidade:

PUBLIC

e

MINISTRY_MEMBERS

---

# 20. PUBLIC

Uma publicação PUBLIC relacionada a um ministério poderá ser visualizada por TODOS os membros autenticados da igreja.

O usuário NÃO precisa fazer parte daquele ministério.

Exemplo:

Maria participa somente do Louvor.

O Ministério de Mídia publica:

"Confira as fotos do culto de domingo."

visibility:

PUBLIC

Maria deverá conseguir visualizar normalmente.

---

# 21. MINISTRY_MEMBERS

Uma publicação MINISTRY_MEMBERS será privada daquele ministério.

Poderão visualizar:

- ADMIN
- PASTOR
- Membros daquele ministério
- Líderes daquele ministério

Exemplo:

O Ministério de Mídia publica:

"Escala interna da equipe para domingo."

visibility:

MINISTRY_MEMBERS

Maria participa apenas do Louvor.

Maria NÃO poderá visualizar essa publicação.

---

# 22. LÍDER DE OUTRO MINISTÉRIO

Ser líder de outro ministério NÃO concede acesso a uma publicação privada.

Exemplo:

Pedro lidera Louvor.

Pedro não participa de Mídia.

Publicação:

Ministério:
Mídia

Visibilidade:
MINISTRY_MEMBERS

Pedro NÃO poderá visualizar.

O role LEADER sozinho não concede acesso.

É necessário existir relacionamento do usuário com o ministério.

ADMIN e PASTOR são exceções globais.

---

# 23. FEED DO USUÁRIO

O feed deverá ser calculado pelo backend.

Exemplo:

João participa de:

- Mídia
- Jovens

Seu feed poderá conter:

✓ Publicação pública de Louvor

✓ Publicação pública de Dança

✓ Publicação pública de Mídia

✓ Publicação pública de Jovens

✓ Publicação privada de Mídia

✓ Publicação privada de Jovens

Não poderá conter:

✗ Publicação privada de Louvor

✗ Publicação privada de Dança

O backend não deverá sequer enviar os dados privados aos quais João não possui acesso.

---

# 24. REGRA DE AUTORIZAÇÃO DE PUBLICAÇÕES

A lógica conceitual deverá ser aproximadamente:

Se:

post.visibility == PUBLIC

permitir usuário autenticado.

Caso usuário.role == ADMIN:

permitir.

Caso usuário.role == PASTOR:

permitir.

Caso:

post.visibility == MINISTRY_MEMBERS

verificar se existe relacionamento entre:

user_id
+
post.ministry_id

em USER_MINISTRIES.

Se existir:

permitir.

Caso contrário:

negar.

Nunca confiar apenas na interface para esconder conteúdo.

---

# 25. CRIAÇÃO DE PUBLICAÇÕES

ADMIN poderá publicar:

- Conteúdo geral
- Para qualquer ministério
- Público
- Privado

PASTOR poderá publicar:

- Conteúdo geral
- Para qualquer ministério
- Público
- Privado

LEADER poderá publicar:

Somente nos ministérios que lidera.

Ao criar uma publicação de ministério, permitir escolher:

Visibilidade:

○ Público para toda a igreja

○ Somente integrantes deste ministério

---

# 26. COMENTÁRIOS

Usuários poderão comentar quando:

- Possuírem acesso à publicação
- A publicação permitir comentários

Uma pessoa que não possui acesso à publicação privada também não poderá:

- Visualizar comentários
- Criar comentário
- Consultar comentários via API

Comentários deverão possuir pelo menos:

COMMENTS

- id
- post_id
- user_id
- content
- status
- created_at
- updated_at

ADMIN e PASTOR poderão moderar comentários.

Preparar arquitetura para futuramente permitir que líderes moderem comentários das publicações dos ministérios que lideram.

---

# 27. EVENTOS

Eventos poderão pertencer:

- À igreja
- A determinado ministério

Um evento poderá futuramente utilizar a mesma lógica de visibilidade:

PUBLIC

MINISTRY_MEMBERS

Um evento PUBLIC poderá ser visualizado por qualquer membro.

Um evento MINISTRY_MEMBERS será acessível somente a:

- ADMIN
- PASTOR
- Membros daquele ministério

Líderes poderão criar e administrar eventos apenas nos ministérios que lideram.

ADMIN e PASTOR poderão administrar qualquer evento.

---

# 28. DADOS DO EVENTO

Inicialmente considerar:

EVENTS

- id
- ministry_id nullable
- created_by
- title
- description
- image_url
- visibility
- location
- address
- starts_at
- ends_at
- status
- created_at
- updated_at

---

# 29. AGENDA

Cada ministério deverá possuir uma agenda.

Exemplo:

Ministério de Mídia

Sexta-feira
19:00
Reunião da equipe

Domingo
17:30
Preparação para o culto

Domingo
19:00
Culto

Integrantes daquele ministério poderão visualizar atividades internas.

Líderes daquele ministério poderão administrar a agenda.

ADMIN e PASTOR poderão administrar qualquer agenda.

Preparar a agenda para possuir futuramente:

PUBLIC

MINISTRY_MEMBERS

---

# 30. TELA INICIAL DO MOBILE

A experiência mobile deverá ser simples e acolhedora.

Após login, mostrar algo semelhante a:

Olá, João 👋

Próximo culto

Domingo
19:00

Seus ministérios:

Mídia
Jovens
Dança

Próximas atividades:

Reunião de Mídia
Sexta 19:00

Ensaio da Dança
Sábado 16:00

Também exibir:

- Feed
- Comunicados
- Próximos eventos
- Atividades dos ministérios
- Notificações

Navegação inferior sugerida:

Home
Agenda
Ministérios
Notificações
Perfil

---

# 31. PERFIL

Cada usuário deverá possuir perfil.

Inicialmente:

- Foto
- Nome
- E-mail
- Telefone
- Data de nascimento opcional
- Cargo
- Status

Também exibir separadamente:

Participa de:

- Mídia
- Louvor

Lidera:

- Mídia

Nunca misturar visualmente participação com liderança.

---

# 32. DASHBOARD WEB

ADMIN e PASTOR possuirão dashboard administrativo.

Exemplo:

Total de membros

Total de ministérios

Total de líderes

Eventos do mês

Próximos eventos

Publicações recentes

Usuários recentes

Comentários pendentes de moderação futuramente

Menu administrativo:

Dashboard

Usuários

Ministérios

Publicações

Eventos

Agenda

Comentários

Igreja

Configurações

Auditoria

Algumas opções dentro de Configurações deverão aparecer somente para ADMIN.

---

# 33. CADASTRO DE USUÁRIO

ADMIN ou PASTOR deverá conseguir cadastrar um usuário.

Campos:

Nome

E-mail

Telefone

Senha inicial

Foto

Status

Cargo:

ADMIN
PASTOR
LEADER
MEMBER

Ministérios dos quais participa:

[✓] Mídia
[✓] Dança
[ ] Louvor
[ ] Jovens

Separadamente:

Ministérios que lidera:

[ ] Mídia
[✓] Dança
[ ] Louvor
[ ] Jovens

O sistema deverá garantir:

Um usuário somente pode liderar um ministério do qual participa.

Caso o administrador marque liderança, o sistema pode automaticamente adicionar participação caso ainda não exista.

---

# 34. AUTENTICAÇÃO

Utilizar arquitetura segura de autenticação.

Preferencialmente:

- JWT de curta duração
- Refresh Token
- Rotação de Refresh Token quando adequado
- Revogação de sessões
- Hash seguro das senhas

Nunca colocar segredo JWT diretamente no código.

Utilizar variáveis de ambiente.

Implementar endpoints como:

POST /api/auth/login

POST /api/auth/refresh

POST /api/auth/logout

GET /api/auth/me

---

# 35. ARMAZENAMENTO DE TOKEN

Considerar diferenças entre plataformas.

No React Native:

Utilizar armazenamento seguro apropriado, como mecanismo seguro do Expo.

Não guardar tokens sensíveis de forma insegura.

No Web:

Evitar soluções vulneráveis desnecessariamente.

Analisar utilização de cookie HttpOnly/Secure quando adequado à arquitetura.

Explique a decisão antes da implementação.

---

# 36. AUTORIZAÇÃO NO BACKEND

Não basta fazer:

if (user.role === "LEADER")

Para ações de ministério é necessário verificar também:

O usuário lidera especificamente este ministério?

Exemplo:

PUT /api/ministries/10/events/25

O backend deverá verificar:

1. Usuário está autenticado?
2. É ADMIN?
3. É PASTOR?
4. Caso seja LEADER, lidera ministry_id 10?
5. O evento realmente pertence ao ministry_id 10?
6. Possui autorização para aquela operação?

Somente então executar.

---

# 37. PROTEÇÃO CONTRA IDOR

Evitar vulnerabilidades de acesso por alteração de IDs.

Exemplo:

Um usuário autorizado para:

/api/posts/100

não poderá simplesmente alterar para:

/api/posts/101

e visualizar uma publicação privada de outro ministério.

Toda consulta deverá validar autorização sobre o objeto acessado.

Isso vale para:

- Posts
- Comentários
- Eventos
- Agendas
- Ministérios
- Imagens privadas
- Documentos futuros
- Anexos futuros

---

# 38. API

Manter rotas REST previsíveis.

Exemplo:

AUTH

POST /api/auth/login
POST /api/auth/refresh
POST /api/auth/logout
GET /api/auth/me

USERS

GET /api/users
GET /api/users/{id}
POST /api/users
PUT /api/users/{id}
PATCH /api/users/{id}
DELETE /api/users/{id}

MINISTRIES

GET /api/ministries
GET /api/ministries/{id}
POST /api/ministries
PUT /api/ministries/{id}
DELETE /api/ministries/{id}

GET /api/ministries/{id}/members
POST /api/ministries/{id}/members
DELETE /api/ministries/{id}/members/{userId}

POST /api/ministries/{id}/leaders/{userId}
DELETE /api/ministries/{id}/leaders/{userId}

POSTS

GET /api/posts
GET /api/posts/{id}
POST /api/posts
PUT /api/posts/{id}
DELETE /api/posts/{id}

COMMENTS

GET /api/posts/{postId}/comments
POST /api/posts/{postId}/comments
PUT /api/comments/{id}
DELETE /api/comments/{id}

EVENTS

GET /api/events
GET /api/events/{id}
POST /api/events
PUT /api/events/{id}
DELETE /api/events/{id}

SCHEDULE

GET /api/ministries/{id}/schedule
POST /api/ministries/{id}/schedule
PUT /api/schedules/{id}
DELETE /api/schedules/{id}

Não é obrigatório seguir exatamente esses endpoints caso exista uma organização REST melhor.

Explique mudanças importantes.

---

# 39. ENTIDADES INICIAIS

Criar arquitetura considerando pelo menos:

USERS

- id
- name
- email
- password_hash
- phone
- avatar_url
- role
- status
- created_at
- updated_at

MINISTRIES

- id
- name
- slug
- description
- image_url
- cover_url
- status
- created_at
- updated_at

USER_MINISTRIES

- id
- user_id
- ministry_id
- is_leader
- status
- joined_at
- created_at
- updated_at

POSTS

- id
- author_id
- ministry_id nullable
- title
- content
- image_url nullable
- visibility
- comments_enabled
- status
- published_at
- created_at
- updated_at

COMMENTS

- id
- user_id
- post_id
- content
- status
- created_at
- updated_at

EVENTS

- id
- ministry_id nullable
- created_by
- title
- description
- image_url
- visibility
- location
- address
- starts_at
- ends_at
- status
- created_at
- updated_at

MINISTRY_SCHEDULES

- id
- ministry_id
- created_by
- title
- description
- starts_at
- ends_at
- visibility
- created_at
- updated_at

NOTIFICATIONS

Preparar estrutura.

AUDIT_LOGS

Preparar estrutura.

CHURCH_SETTINGS

Preparar estrutura.

Analise os campos e normalize quando necessário.

Não considere esta lista imutável.

---

# 40. ENUMS E CONSTANTES

Evitar strings aleatórias espalhadas pelo código.

Criar Enums ou estruturas equivalentes para:

UserRole:

ADMIN
PASTOR
LEADER
MEMBER

ContentVisibility:

PUBLIC
MINISTRY_MEMBERS

UserStatus:

ACTIVE
INACTIVE
BLOCKED

PostStatus, por exemplo:

DRAFT
PUBLISHED
ARCHIVED

Utilizar os recursos adequados de PHP e TypeScript.

---

# 41. AUDITORIA

Registrar operações administrativas importantes.

Exemplo:

João alterou o evento "Culto de Jovens".

Registrar:

- Usuário
- Ação
- Entidade
- ID da entidade
- Dados necessários
- Data/hora

Inicialmente preparar para ações como:

- Criação de usuário
- Alteração de cargo
- Exclusão/desativação
- Inclusão em ministério
- Remoção de ministério
- Alteração de liderança
- Exclusão de publicação
- Alteração de configurações

---

# 42. NOTIFICAÇÕES

Preparar arquitetura para push notifications.

No futuro deverá ser possível enviar:

Para toda a igreja:

"Novo comunicado da igreja."

Para um ministério:

"Novo evento do Ministério de Mídia."

Para um usuário:

"Você foi adicionado à escala."

Para líderes:

"Há uma nova solicitação pendente."

Inicialmente considerar integração mobile compatível com Expo Push Notifications.

Não é necessário implementar toda essa funcionalidade imediatamente.

---

# 43. IMAGENS E ARQUIVOS

Não salvar grandes arquivos diretamente no banco.

O banco deverá guardar referência/URL.

Durante desenvolvimento poderá existir estratégia local.

Entretanto, a arquitetura deverá permitir migração para armazenamento compatível com S3 futuramente.

Conteúdo privado deverá ser tratado com cuidado.

Não presumir que uma URL obscura é suficiente para proteger arquivo privado.

---

# 44. DESIGN

Quero uma interface:

- Moderna
- Limpa
- Bonita
- Acolhedora
- Profissional
- Responsiva
- Fácil de utilizar
- Acessível para usuários com pouca experiência tecnológica

O aplicativo representa uma igreja.

A identidade deve transmitir:

- Fé
- Comunidade
- União
- Acolhimento
- Organização

Evitar excesso de símbolos religiosos ou aparência visual ultrapassada.

Evitar também aparência excessivamente corporativa.

---

# 45. MOBILE VS WEB

Não tentar criar exatamente a mesma interface nas duas plataformas.

Mobile deverá priorizar:

- Feed
- Agenda
- Ministérios
- Eventos
- Comunicados
- Perfil
- Notificações

Web deverá aproveitar melhor telas grandes para:

- Tabelas
- Filtros
- Dashboards
- Gestão
- Formulários
- Relatórios
- Administração

A regra de negócio, entretanto, deverá ser exatamente a mesma porque vem da API Symfony.

---

# 46. VALIDAÇÃO

Não confiar nos dados enviados pelo frontend.

Validar no Symfony:

- Campos obrigatórios
- Tipos
- Comprimentos
- IDs
- Relacionamentos
- Permissões
- Arquivos
- E-mails
- Datas
- Estados possíveis

Exemplo:

Frontend envia:

is_leader = true

Isso NÃO deverá automaticamente tornar alguém líder.

A operação só poderá acontecer se quem realizou a requisição possuir autorização.

---

# 47. PAGINAÇÃO

Endpoints que podem crescer devem possuir paginação desde cedo.

Exemplo:

GET /api/posts?page=1&limit=20

GET /api/users?page=1&limit=20

GET /api/events?page=1&limit=20

GET /api/ministries/1/members?page=1&limit=20

Preparar também filtros quando adequados.

---

# 48. PESQUISA E FILTROS

Preparar APIs administrativas para filtros.

Exemplos:

Usuários:

- Nome
- E-mail
- Role
- Ministério
- Status

Posts:

- Ministério
- Autor
- Visibilidade
- Data
- Status

Eventos:

- Ministério
- Período
- Visibilidade
- Status

---

# 49. LOGS

Configurar logs adequados no backend.

Não registrar:

- Senhas
- Refresh Tokens
- Secrets
- Dados sensíveis desnecessários

Registrar erros e informações suficientes para diagnóstico.

---

# 50. VARIÁVEIS DE AMBIENTE

Nunca colocar credenciais diretamente no Git.

Utilizar:

.env

E versionar somente:

.env.example

Exemplos:

DATABASE_URL

APP_ENV

APP_SECRET

JWT_PRIVATE_KEY

JWT_PUBLIC_KEY

CLOUDFLARE_TUNNEL_TOKEN

Nunca enviar valores reais de produção para o repositório.

---

# 51. DOCKER

Quero iniciar o projeto utilizando Docker Compose.

Considerar serviços como:

nginx

php

postgres

cloudflared

Posteriormente:

redis

worker

Os serviços deverão utilizar networks internas quando apropriado.

PostgreSQL não deverá possuir exposição pública desnecessária.

---

# 52. MIGRAÇÃO FUTURA PARA VPS

A infraestrutura deverá ser pensada para que futuramente seja possível fazer:

Computador pessoal
↓
VPS Linux

mantendo:

- Docker
- Docker Compose
- Symfony
- PostgreSQL
- Nginx
- Cloudflare Tunnel

Evitar dependências específicas da máquina de desenvolvimento.

---

# 53. BACKUP

Embora inicialmente seja ambiente local, preparar documentação sobre backup.

Especialmente:

PostgreSQL.

O banco conterá dados importantes da igreja.

Posteriormente quero possibilidade de:

- Backup automático
- Backup externo
- Restauração testada

Não implementar automação complexa agora sem necessidade, mas não ignorar esse requisito arquitetural.

---

# 54. SEGURANÇA

Considerar desde o início:

- HTTPS
- Password hashing
- JWT seguro
- Refresh Tokens
- Controle de acesso
- Rate limiting
- CORS
- CSRF quando aplicável
- Validação
- SQL Injection
- XSS
- IDOR
- Upload seguro
- Logs
- Secrets
- Brute Force
- Revogação de sessão

Não inventar mecanismos criptográficos próprios.

Utilizar mecanismos maduros do ecossistema.

---

# 55. FUNCIONALIDADES FUTURAS

A arquitetura deverá permitir futuramente:

- Escala de voluntários
- Confirmação de presença
- Check-in
- QR Code
- Células
- Pequenos grupos
- Pedidos de oração
- Devocionais
- Estudos bíblicos
- Escola bíblica
- Cursos
- Biblioteca
- Documentos
- Chat
- Chat de ministérios
- Reações
- Curtidas
- Push Notifications
- Transmissões ao vivo
- Integração com YouTube
- Doações
- Ofertas
- Gestão financeira
- Histórico de participação
- Relatórios
- Dashboard avançado

Essas funcionalidades NÃO fazem parte obrigatoriamente do primeiro MVP.

Não complique o MVP tentando implementá-las agora.

Apenas mantenha uma arquitetura que não dificulte sua inclusão futuramente.

---

# 56. PRINCÍPIOS FUNDAMENTAIS

Nunca confundir:

ROLE GLOBAL

com

PARTICIPAÇÃO EM MINISTÉRIO

com

LIDERANÇA DO MINISTÉRIO

com

VISIBILIDADE DE CONTEÚDO.

Exemplo:

João:

role:
LEADER

Participa:

Mídia
Dança

Lidera:

Dança

João poderá:

Ver PUBLIC de todos os ministérios.

Ver MINISTRY_MEMBERS de Mídia.

Ver MINISTRY_MEMBERS de Dança.

Administrar Dança.

João NÃO poderá:

Administrar Mídia.

Ver conteúdo privado de Louvor caso não participe.

---

# 57. MATRIZ CONCEITUAL DE PERMISSÕES

ADMIN:

Visualização:
Tudo.

Administração:
Tudo.

Configurações críticas:
Sim.

---

PASTOR:

Visualização:
Tudo.

Administração:
Praticamente tudo.

Configurações exclusivas ADMIN:
Não.

---

LEADER:

Visualização:
Publicações públicas de todos os ministérios.

Publicações privadas dos ministérios dos quais participa.

Administração:
Somente ministérios que lidera.

---

MEMBER:

Visualização:
Publicações públicas de todos os ministérios.

Publicações privadas dos ministérios dos quais participa.

Administração:
Não.

---

# 58. TESTES

Não desenvolver funcionalidades críticas sem considerar testes.

No backend criar testes especialmente para autorização.

Exemplos obrigatórios:

MEMBER consegue visualizar post PUBLIC.

MEMBER consegue visualizar post MINISTRY_MEMBERS do próprio ministério.

MEMBER NÃO consegue visualizar post privado de outro ministério.

LEADER consegue publicar no ministério que lidera.

LEADER NÃO consegue publicar em ministério do qual apenas participa.

LEADER NÃO consegue publicar em ministério de que não participa.

PASTOR consegue administrar qualquer ministério.

ADMIN consegue administrar qualquer ministério.

PASTOR não consegue executar operação exclusiva de ADMIN.

Usuário não autenticado não acessa endpoints privados.

Também criar testes para regras importantes de negócio.

---

# 59. DOCUMENTAÇÃO

Manter README atualizado.

O README deverá futuramente explicar pelo menos:

- Requisitos
- Como instalar
- Como subir Docker
- Variáveis de ambiente
- Como executar migrations
- Como criar banco
- Como executar testes
- Como iniciar Web
- Como iniciar Mobile
- Como configurar Cloudflare Tunnel
- Estrutura do projeto

Não deixar conhecimento importante apenas no código.

---

# 60. PADRÃO DE IMPLEMENTAÇÃO

Não criar controllers gigantes.

Não colocar regra de negócio complexa diretamente no controller.

Separar adequadamente:

- Controllers
- DTOs
- Entities
- Repositories
- Services
- Security
- Authorization
- Validators
- Exceptions
- Serializers
- Mappers quando necessários

No frontend separar:

- Pages/Screens
- Components
- Hooks
- Services
- API Client
- Stores
- Types
- Schemas
- Utils

Evitar overengineering.

Utilizar arquitetura proporcional ao projeto.

---

# 61. TRATAMENTO DE ERROS

API deverá responder erros de maneira consistente.

Exemplo conceitual:

{
  "error": {
    "code": "FORBIDDEN",
    "message": "Você não possui permissão para acessar este conteúdo."
  }
}

Não retornar stack traces em produção.

Diferenciar corretamente:

400 Bad Request

401 Unauthorized

403 Forbidden

404 Not Found

409 Conflict

422 Unprocessable Entity

500 Internal Server Error

---

# 62. DATAS

Armazenar datas de forma consistente.

Preferencialmente armazenar timestamps em UTC no backend/banco e converter para timezone apropriado na apresentação.

A aplicação inicialmente será utilizada no Brasil.

Não espalhar regras de timezone pelo código.

Centralizar tratamento.

---

# 63. SOFT DELETE

Antes de utilizar DELETE físico indiscriminadamente, avaliar quais registros precisam ser preservados.

Para entidades importantes, considerar:

status

deleted_at

ou estratégia equivalente.

Especialmente:

- Usuários
- Ministérios
- Publicações importantes
- Eventos

Explique a decisão tomada.

---

# 64. SLUGS

Ministérios poderão possuir slug.

Exemplo:

Nome:

Ministério de Mídia

Slug:

ministerio-de-midia

Não utilizar nome como identificador interno.

IDs continuam sendo identificadores reais.

---

# 65. PERFORMANCE

Evitar problemas comuns do ORM como N+1 queries.

Criar índices principalmente em campos utilizados frequentemente em:

- Relacionamentos
- Filtros
- Autorização
- Ordenação

Exemplos:

user_id

ministry_id

visibility

created_at

status

Não criar índices aleatórios sem necessidade.

---

# 66. ACESSIBILIDADE

Interfaces deverão considerar:

- Contraste
- Tamanho dos textos
- Botões fáceis de tocar
- Labels de formulários
- Navegação clara
- Feedback de erro
- Loading
- Estados vazios

Lembrar que usuários poderão possuir diferentes níveis de familiaridade com tecnologia.

---

# 67. DESENVOLVIMENTO POR FASES

NÃO tente implementar todo o projeto de uma vez.

Quero desenvolvimento incremental.

## FASE 0 — ANÁLISE

Antes de modificar qualquer arquivo:

1. Inspecione o repositório existente.
2. Identifique tecnologias já instaladas.
3. Identifique arquivos importantes.
4. Identifique o que já existe.
5. Não destrua código existente funcional sem necessidade.
6. Apresente um resumo do estado atual.

Se o repositório estiver vazio, informe e prossiga com a arquitetura inicial.

---

## FASE 1 — ARQUITETURA

Definir:

- Estrutura do repositório
- Backend
- Web
- Mobile
- Docker
- Banco
- Autenticação
- Estratégia de autorização
- Variáveis de ambiente
- Estrutura inicial das entidades

Antes de escrever muita implementação, apresente o plano.

---

## FASE 2 — INFRAESTRUTURA

Configurar:

- Docker Compose
- PHP
- Symfony
- Nginx
- PostgreSQL
- Rede interna
- Variáveis de ambiente
- Health checks quando apropriados

Cloudflare poderá ser configurado após a aplicação local estar funcionando corretamente.

---

## FASE 3 — BANCO DE DADOS

Implementar entidades principais:

- User
- Ministry
- UserMinistry
- Post
- Comment
- Event
- MinistrySchedule

Criar migrations.

Verificar integridade dos relacionamentos.

---

## FASE 4 — AUTENTICAÇÃO

Implementar:

- Login
- Token
- Refresh
- Logout
- /me
- Segurança das senhas

Criar testes.

---

## FASE 5 — AUTORIZAÇÃO

Implementar:

- Roles
- Voters/Policies ou mecanismo adequado do Symfony
- Permissões globais
- Permissões por ministério

Criar testes completos antes de avançar.

Esta é uma fase crítica.

---

## FASE 6 — USUÁRIOS

Implementar:

- Cadastro
- Edição
- Status
- Role
- Perfil

---

## FASE 7 — MINISTÉRIOS

Implementar:

- CRUD
- Participantes
- Líderes
- Regras de participação/liderança

---

## FASE 8 — PUBLICAÇÕES

Implementar:

- Publicações gerais
- Publicações de ministério
- PUBLIC
- MINISTRY_MEMBERS
- Feed autorizado

Criar testes rigorosos de privacidade.

---

## FASE 9 — COMENTÁRIOS

Implementar:

- Criar
- Listar
- Editar quando permitido
- Moderar
- Excluir
- Controle de acesso baseado no post

---

## FASE 10 — EVENTOS

Implementar:

- Eventos gerais
- Eventos de ministério
- Visibilidade
- Autorização

---

## FASE 11 — AGENDA

Implementar agenda geral e por ministério.

---

## FASE 12 — WEB

Construir inicialmente:

- Login
- Dashboard
- Usuários
- Ministérios
- Publicações
- Eventos
- Agenda

Priorizar funcionalidade antes de refinamento visual extremo.

---

## FASE 13 — MOBILE

Construir:

- Login
- Home
- Feed
- Publicações
- Eventos
- Ministérios
- Agenda
- Perfil

---

## FASE 14 — NOTIFICAÇÕES

Implementar posteriormente quando as funcionalidades principais estiverem estáveis.

---

## FASE 15 — CLOUDLFARE TUNNEL

Após funcionamento local:

Configurar Cloudflare Tunnel.

Separar:

app.dominio.com.br

api.dominio.com.br

Validar HTTPS e funcionamento externo.

---

# 68. COMO O CODEX DEVE TRABALHAR

Ao receber este prompt:

NÃO gere todo o sistema imediatamente.

Primeiro inspecione o projeto.

Depois apresente:

1. Situação atual
2. Arquitetura proposta
3. Estrutura de diretórios
4. Entidades
5. Relacionamentos
6. Estratégia de autenticação
7. Estratégia de autorização
8. Docker
9. Plano de implementação

Depois comece pela primeira fase necessária.

Ao concluir cada etapa, informe:

- O que foi criado
- O que foi alterado
- Arquivos envolvidos
- Migrations criadas
- Comandos necessários
- Como executar
- Como testar
- Testes executados
- Resultado dos testes
- Próxima etapa sugerida

---

# 69. REGRAS IMPORTANTES PARA ALTERAÇÃO DE CÓDIGO

Antes de modificar um arquivo existente:

Leia o arquivo completo ou contexto suficiente para compreender sua função.

Não sobrescreva arquivos importantes cegamente.

Não remova funcionalidades existentes sem motivo.

Quando encontrar um problema arquitetural, explique resumidamente e corrija.

Não criar múltiplas implementações concorrentes da mesma funcionalidade.

Evitar arquivos duplicados como:

authService.ts
authServiceNew.ts
authServiceFinal.ts
authServiceFinal2.ts

Modificar corretamente a implementação existente.

---

# 70. DEPENDÊNCIAS

Antes de adicionar uma biblioteca:

Verifique se realmente é necessária.

Prefira bibliotecas:

- Mantidas
- Conhecidas
- Compatíveis com as versões utilizadas
- Com boa documentação

Não adicionar dependências simplesmente para resolver algo trivial.

---

# 71. CÓDIGO

Quero:

- TypeScript tipado corretamente
- PHP tipado corretamente
- Código legível
- Métodos pequenos quando possível
- Nomes descritivos
- Sem comentários redundantes
- Sem código morto
- Sem credenciais
- Sem mocks permanentes dentro da lógica real

Não utilizar `any` indiscriminadamente no TypeScript.

---

# 72. GIT

Preparar .gitignore corretamente.

Nunca versionar:

- .env real
- Secrets
- Tokens
- Banco local
- node_modules
- vendor quando não apropriado
- Arquivos temporários
- Logs
- Builds desnecessários

---

# 73. PRIMEIRO OBJETIVO EXECUTÁVEL

O primeiro marco do projeto deverá ser conseguir executar localmente:

docker compose up

e possuir:

PostgreSQL funcionando.

Symfony funcionando.

Nginx funcionando.

Endpoint:

GET /api/health

respondendo algo como:

{
  "status": "ok"
}

Depois disso avançar para banco e autenticação.

---

# 74. MVP

Considere como MVP inicial:

1. Autenticação
2. Usuários
3. Roles
4. Ministérios
5. Participação em ministérios
6. Liderança específica por ministério
7. Feed
8. Publicações públicas
9. Publicações privadas por ministério
10. Comentários
11. Eventos
12. Agenda
13. Painel administrativo Web
14. Aplicativo Android/iOS
15. Cloudflare Tunnel

Não implementar recursos secundários antes de essas bases estarem funcionando corretamente.

---

# 75. CRITÉRIO PRINCIPAL DE SEGURANÇA

Considere sempre que um usuário poderá manipular manualmente as requisições HTTP.

Portanto:

Nunca confie que um botão escondido significa que uma operação está protegida.

O backend deve assumir que o usuário pode tentar executar qualquer endpoint manualmente.

Toda ação protegida deve ser validada no Symfony.

---

# 76. REGRA DE NEGÓCIO CENTRAL

Esta regra nunca deverá ser quebrada durante o desenvolvimento:

MEMBRO:

Pode visualizar conteúdos PUBLIC de TODOS os ministérios.

Pode visualizar conteúdos MINISTRY_MEMBERS somente dos ministérios dos quais participa.

LÍDER:

Possui as mesmas regras de leitura do membro.

Pode administrar somente ministérios que efetivamente lidera.

PASTOR:

Pode visualizar e administrar praticamente todo o conteúdo.

ADMIN:

Possui autoridade máxima e configurações exclusivas.

---

# 77. RESULTADO ESPERADO DESTE PROMPT

Neste primeiro momento NÃO quero que você tente concluir o aplicativo inteiro.

Primeiro:

1. Analise o repositório.
2. Apresente a arquitetura que será utilizada.
3. Mostre a estrutura de diretórios proposta.
4. Apresente o modelo inicial do banco e relacionamentos.
5. Explique como as permissões serão implementadas no Symfony.
6. Explique a estratégia Docker.
7. Identifique possíveis problemas ou melhorias nesta especificação.
8. Crie um plano de implementação por fases.

Depois disso, inicie somente a primeira fase necessária para colocar a base do projeto funcionando.

Não pule diretamente para telas ou funcionalidades avançadas antes que infraestrutura, banco, autenticação e autorização estejam corretamente estruturados.

Prioridades gerais:

SEGURANÇA
→ ARQUITETURA
→ REGRAS DE NEGÓCIO
→ TESTES
→ FUNCIONALIDADE
→ EXPERIÊNCIA DO USUÁRIO
→ REFINAMENTO VISUAL

Sempre preserve as decisões arquiteturais e regras de negócio estabelecidas neste documento durante as próximas etapas do desenvolvimento.