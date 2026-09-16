# Plano incremental de implementação

A execução atual implementou infraestrutura, banco, autenticação, autorização, usuários, ministérios e publicações. A próxima fase é comentários. As decisões em [architecture.md](architecture.md) guiam todas as fases; contratos em [authentication.md](authentication.md), [authorization.md](authorization.md), [users.md](users.md), [ministries.md](ministries.md) e [posts.md](posts.md); resultados executados em [validation.md](validation.md).

## Estado atual

| Fase | Estado deste marco |
| --- | --- |
| 0 — Análise | Repositório inspecionado: apenas `teste` na origem; preservar esse arquivo e registrar ferramentas disponíveis no relato/README. |
| 1 — Arquitetura | Modelo, autenticação proposta, autorização, infraestrutura, riscos e fases documentados. |
| 2 — Infraestrutura | Concluída: containers saudáveis, health/ready HTTP 200 e persistência verificada após reinício. |
| 3 — Banco e entidades | Concluída: sete entidades, nove enums, sete repositórios e migration inicial; schema validado e 75 testes/601 verificações aprovados. |
| 4 — Autenticação | Concluída: API local atualizada, hashing, bootstrap de ADMIN, JWT, refresh, logout, `/me`, CORS/CSRF e limites; 237 testes/1.437 verificações, incluindo concorrência. |
| 5 — Autorização | Implementada: Voters, políticas atuais, leituras filtradas, administração de acesso, proteção do último ADMIN e auditoria; onze entidades e três migrations. Suíte completa aprovada com 299 testes/2.027 verificações em PostgreSQL isolado. |
| 6 — Usuários e perfil | Implementada: cadastro, edição administrativa/própria, troca/redefinição de senha, auditoria e revogação. Sem nova migration; resultados em validation.md. |
| 7 — Ministérios e vínculos | Implementada: cadastro/edição/desativação, leitura filtrada, participação e liderança com promoção explícita, auditoria transacional; sem migration. |
| 8 — Publicações e feed | Implementada: posts de texto, estados explícitos, gestão por ministério, busca/filtros autorizados e auditoria; sem migration. |
| 9–15 | Planejadas; Web na fase 12, mobile na fase 13 e exposição externa na fase 15. |

Um arquivo ou comando documentado não é evidência de execução. Os resultados históricos das fases 2–4 e a suíte completa da fase 5 estão no registro de validação, que distingue testes em PostgreSQL isolado de implantação no banco local. As fases seguintes mantêm seus critérios próprios de aceite.

## Fase 0 — Análise

**Escopo:** inspecionar conteúdo do repositório, instruções locais, estado de Git, arquivos importantes e ferramentas instaladas. Ler a especificação integralmente e apresentar o estado atual antes de editar.

**Aceite:** inventário relatado, diferenças entre ferramenta presente e runtime funcional identificadas, código existente preservado e ausência de aplicação inicial confirmada quando aplicável.

**Limite:** não instalar ou substituir aplicações globais sem necessidade de implementação.

## Fase 1 — Arquitetura

**Escopo:** definir monorepo, API Symfony, Web React/Vite, Mobile Expo, banco, rede, autenticação por plataforma, permissões, entidades, cardinalidades, constraints, índices e retenção.

**Aceite:** os quatro conceitos — cargo, participação, liderança e visibilidade — estão separados; liderança é por ministério; conteúdo privado exige ministério; autenticação considera rotação e revogação; implantação e recuperação estão descritas; ambiguidades têm direção inicial e momento de decisão.

**Entregáveis:** este plano, documento de arquitetura e resumo ao usuário antes de uma implementação ampla.

## Fase 2 — Infraestrutura e primeiro marco executável

**Escopo:** configurar Compose, PHP-FPM, Symfony mínimo, Nginx, PostgreSQL, redes, variáveis de ambiente, health checks e documentação de execução. O endpoint `GET /api/health` responde JSON sem expor informações internas. Deixar Cloudflare para a fase 15.

**Aceite verificável:**

1. Arquivos de ambiente têm exemplos úteis e nenhum segredo real versionado.
2. Dependências do backend são instaláveis com Composer e versões resolvidas ficam em lock quando gerado.
3. `docker compose config` valida a configuração com o ambiente preparado.
4. `docker compose up --build` inicia os serviços; health checks ficam saudáveis.
5. `GET /api/health` pelo Nginx responde HTTP 200 e `{"status":"ok"}`.
6. PostgreSQL aceita conexão do serviço PHP; o banco não tem porta publicada no host.
7. Falha de dependência coberta pelo health é relatada com 503 e sem detalhes sensíveis.
8. README explica preparação do ambiente, execução, diagnóstico, testes e limites efetivamente verificados.

**Validação proporcional:** lint/configuração, teste do comportamento do health e verificação integrada de Docker quando disponível. Validar com PHP do host auxilia o diagnóstico, mas não comprova funcionamento do PHP-FPM ou PostgreSQL no container. Se Docker estiver indisponível, registrar o bloqueio exato e os comandos ainda não executados; não marcar a execução como aprovada.

**Fora desta fase:** migrations de negócio, usuários fictícios permanentes, JWT improvisado, telas e exposição pública.

## Fase 3 — Banco e entidades

**Escopo:** implementar `User`, `Ministry`, `UserMinistry`, `Post`, `Comment`, `Event`, `MinistrySchedule`, enums e repositórios iniciais. Criar migrations explícitas com FKs, constraints e índices. Preparar as tabelas auxiliares apenas quando necessárias às fases seguintes.

**Aceite:** migrations aplicam em PostgreSQL vazio e o schema corresponde aos mapeamentos; email e slug duplicados são rejeitados; o par usuário/ministério é único; privado sem ministério e intervalos inválidos falham; exclusões de usuário/ministério não apagam autoria/histórico; UTC e datas sem horário têm representação consistente. Verificar integridade no PostgreSQL real, não somente em SQLite.

**Entrega:** migrations revisadas, fixtures exclusivamente de teste/desenvolvimento quando necessárias e comandos de aplicação/verificação documentados.

## Fase 4 — Autenticação e sessões

**Escopo:** hashing Symfony, criação inicial controlada de ADMIN, login, JWT curto, refresh opaco com hash, rotação transacional, sessões/famílias, logout e `/me`. Implementar transporte Web com cookie host-only HttpOnly/Secure e access em memória; definir o transporte Mobile com SecureStore. Implementar CORS, CSRF aplicável e limite de tentativas.

**Aceite:** credenciais válidas/inválidas, usuário inativo/bloqueado, expiração, assinatura inválida, audiência/emissor incorretos, renovação, revogação e logout têm testes. Reutilização de refresh revoga a família, incluindo concorrência. JWT de sessão revogada é recusado imediatamente. Logs e JSON não expõem segredos. Origens inesperadas e CSRF do fluxo cookie são rejeitados. O primeiro administrador não depende de senha padrão no repositório.

**Decisões anteriores à liberação:** política de recuperação/troca de senha, validade final de sessões e comportamento de múltiplas abas/dispositivos.

## Fase 5 — Autorização

**Escopo:** Voters e serviços de políticas, consultas com filtros de acesso antes da paginação e contagem, proteção de ações administrativas e validação de relações pai/filho.

**Entregue:** `AccessPolicy`, `ContentReadScope` e seis Voters consultam o estado atual
do banco. `GET /api/auth/permissions` informa permissões; as rotas administrativas
listam/detalham usuários autorizados e `PATCH /api/admin/users/{id}/access` altera
cargo/status com proteção do último ADMIN, revogação de liderança e auditoria
transacional. Leituras de posts e detalhes vinculados de eventos/comentários aplicam
escopo antes da resposta, contagem e paginação. `Version20260911050000` cria a tabela
de auditoria. A suíte completa passou com 299 testes e 2.027 verificações, incluindo
concorrência. O [contrato](authorization.md) delimita os endpoints disponíveis;
cadastro/perfil e operações de escrita dos demais módulos continuam nas fases próprias.

**Aceite obrigatório antes de avançar:**

| Cenário | Resultado esperado |
| --- | --- |
| MEMBER lê PUBLIC publicado de outro ministério | Permitido |
| MEMBER lê privado publicado do próprio ministério ativo | Permitido |
| MEMBER lê privado de outro ministério | Negado, sem dados ou contagem revelados |
| LEADER publica no ministério que lidera ativamente | Permitido |
| LEADER publica onde apenas participa | Negado |
| LEADER publica onde não participa | Negado |
| LEADER lê privado de outro ministério sem vínculo | Negado |
| MEMBER com flag de liderança inconsistente tenta administrar | Negado |
| PASTOR/ADMIN administram conteúdo de qualquer ministério | Permitido |
| PASTOR tenta ação exclusiva de ADMIN ou altera ADMIN | Negado |
| Usuário não autenticado acessa endpoint protegido | 401 |
| Rascunho PUBLIC é consultado por membro comum | Negado |
| ID de evento/comentário não corresponde ao pai da rota | Negado |
| Participação, liderança, cargo ou sessão é revogada | Poder correspondente removido imediatamente |
| Duas requisições tentam remover os últimos ADMIN ativos | Pelo menos um permanece ativo |

Testes devem cobrir listagens, detalhe, criação, edição, exclusão/arquivamento, agregações e arquivos conforme os respectivos módulos forem adicionados. Provar a matriz por requisições HTTP e integração com banco, além de testes unitários dos predicados.

## Fase 6 — Usuários e perfil

**Escopo:** cadastro administrativo, edição, perfil, status, cargo, listagem com paginação/filtros e auditoria das mudanças críticas. Bootstrap seguro do primeiro ADMIN já vem da fase 4.

**Base disponível:** listagem/detalhe administrativo e alteração explícita de cargo/status
com auditoria e proteção do último ADMIN foram entregues na fase 5. Esta fase completa
o cadastro e a edição de usuários/perfis, reutilizando essas operações.

**Entregue:** cadastro ADMIN/PASTOR (ADMIN exclusivo para criar ADMIN), perfil próprio
com participação/liderança separadas, edição administrativa de dados pessoais e
troca/redefinição de senha. Email normalizado alterado e senha nova revogam sessões;
as mudanças são auditadas sem copiar dados pessoais ou credenciais. Validação de
campos e autorização transacional são aplicadas no backend. Contrato em [users.md](users.md).

**Aceite:** ADMIN/PASTOR acessam apenas operações permitidas; PASTOR nunca cria ou altera ADMIN; último ADMIN ativo protegido; alteração de senha/status revoga sessões conforme política; dados sensíveis não aparecem na serialização; DTOs impedem escalada de privilégio e mass assignment. Membro edita apenas os campos do próprio perfil que a política permite.

## Fase 7 — Ministérios, participantes e líderes

**Escopo:** gestão de ministérios, status e slug; participação N:N; inclusão/remoção; liderança de múltiplos usuários por ministério e múltiplos ministérios por usuário. Implementar auditoria na mesma transação.

**Aceite:** liderança sempre inclui participação ativa; MEMBER só recebe liderança com promoção explícita autorizada para LEADER na mesma transação; reativar participação não restaura liderança; remover vínculo revoga acesso; LEADER não ganha direito de gerenciar participantes ou promover cargos fora das operações previstas. Listas de participantes expõem somente dados necessários.

**Entregue:** rotas de ministérios ativos e manutenção global, edição descritiva por
líder autorizado, inclusão/remoção de participantes, definição/remoção de liderança
e promoção explícita para LEADER. Vínculos encerrados são reutilizados sem restaurar
liderança. Listas privadas filtram antes de contar/paginar, sem contatos pessoais.
Desativação preserva histórico, e auditoria é confirmada na mesma transação.
Contrato completo e decisões de reativação em [ministries.md](ministries.md).

## Fase 8 — Publicações e feed

**Escopo:** conteúdo geral e de ministério, estados, PUBLIC, MINISTRY_MEMBERS, comentários habilitados/desabilitados, paginação/filtros e publicação/arquivamento. Implementar arquivos de imagem seguros quando a interface de upload for necessária.

**Aceite:** a matriz de leitura vale no feed, detalhe, busca, contagens, imagens e edição; rascunhos não aparecem no feed; privado sem ministério é recusado; alteração de ministério/visibilidade exige autorização de origem e destino; paginação é determinística e não vaza existência de conteúdo privado; índices e queries evitam N+1 significativo.

**Entregue:** criação de rascunhos, edição autorizada, publicação, retirada do feed,
arquivamento e republicação. Feed e gestão filtram ministério/visibilidade/texto
antes da paginação/contagem; a gestão também filtra estado. Autor é fixado pela
sessão; mudança de ministério exige origem e destino autorizados. Auditoria não
copia conteúdo. Imagens aguardam arquivos e interface de upload. Contrato em [posts.md](posts.md).

## Fase 9 — Comentários

**Escopo:** criar, listar, editar comentário próprio quando permitido, remover e moderar por ADMIN/PASTOR.

**Aceite:** todas as operações exigem acesso atual ao post; publicação com comentários desabilitados recusa novas mensagens; edição/exclusão não autoriza acesso apenas pelo ID ou autoria de comentário em post inacessível; moderação tem status e auditoria; paginação e respostas não revelam comentários ocultos a quem não pode vê-los.

**Entregue:** criação, listagem, edição/remoção pelo autor e moderação global,
com estados VISIBLE/HIDDEN/DELETED e auditoria transacional. Rotas regulares exigem
leitura atual; manutenção administrativa exige acesso global de gestão ao post,
inclusive no histórico. DELETED é terminal. Contrato em [comments.md](comments.md)
e resultados efetivamente executados em [validation.md](validation.md).

API local atualizada em 14/09/2026; 378 testes e 2.891 verificações aprovados na
suíte completa com PostgreSQL isolado. Nenhuma migration nova.

## Fase 10 — Eventos

**Escopo:** eventos gerais e por ministério, visibilidade, datas, local, status, filtros por período e administração conforme liderança.

**Aceite:** limites temporais são válidos; leitura e escrita respeitam a matriz; detalhe de evento sob ministério errado é recusado; cancelamento preserva histórico e informa somente destinatários autorizados; instantes UTC são apresentados corretamente em fusos configurados.

**Entregue:** cadastro de rascunhos, edição/remarcação, publicação, cancelamento
e arquivamento, com gestão por ministério, filtros por período e auditoria.
Cancelamento de rascunho/arquivado é recusado; cancelados mantêm a audiência.
Horários são validados com fuso explícito e retornados em UTC. Conversão visual de
fuso e notificações seguem as fases das interfaces e notificações.
Contrato em [events.md](events.md); validação executada em [validation.md](validation.md).

API local atualizada em 14/09/2026; 409 testes e 3.147 verificações aprovados na
suíte completa com PostgreSQL isolado. Nenhuma migration ou dependência nova.

## Fase 11 — Agenda

**Escopo:** atividades gerais e por ministério, PUBLIC/MINISTRY_MEMBERS, próximos compromissos e composição da agenda com eventos. Trabalhar inicialmente com ocorrências explícitas, sem motor de recorrência.

**Aceite:** membros veem atividades permitidas; líderes administram só suas agendas; ADMIN/PASTOR administram todas; agenda geral privada é rejeitada; intervalos e datas são consistentes; os mesmos eventos não são duplicados como atividades persistidas sem necessidade.

**Entregue:** gestão de atividades gerais/por ministério e agenda combinada,
com período, próximos compromissos, audiência, estados e auditoria. Eventos e
atividades são unidos somente na leitura, com contagem/paginação global e identidade
`kind + id`. Contrato em [agenda.md](agenda.md); testes em [validation.md](validation.md).

API local atualizada em 14/09/2026; 442 testes e 3.445 verificações aprovados na
suíte completa com PostgreSQL isolado. Nenhuma migration ou dependência nova.

## Fase 12 — Web

**Escopo:** React, TypeScript e Vite; login, dashboard, usuários, ministérios, publicações, comentários, eventos, agenda e perfil conforme API já protegida. Criar pacotes de contratos/cliente compartilhados apenas quando usados.

**Aceite:** aplicação responsiva, navegação por permissões, filtros/paginação reais, formulários acessíveis, labels, contraste, foco por teclado e estados de loading/vazio/erro. Access token fica em memória; refresh usa cookie apropriado; origem/CORS/CSRF funcionam em ambiente de integração. Recusa da API é tratada claramente mesmo quando a interface apresentou uma ação. Nenhum mock permanente substitui as regras reais.

**Validação:** build, verificações TypeScript e fluxos de login, sessão expirada, gestão e privacidade no navegador com contas de cargos distintos. Refinamento visual vem depois dos fluxos completos e acessíveis.

## Fase 13 — Mobile

A Web da fase 12 está implementada em `apps/web`, com serviço estático Docker,
login e módulos conectados aos contratos reais. Sem migration nova.
Execução/arquivos em [web.md](web.md); resultados em [validation.md](validation.md).

**Escopo:** Expo/React Native, Expo Router, TypeScript, Android e iOS; login, Home, feed, detalhe de publicação, eventos, ministérios, agenda e perfil. Navegação adequada ao uso mobile, distinta da Web administrativa.

**Aceite:** tokens sensíveis em SecureStore; restauração/expiração/logout de sessão corretos; estados sem conexão claros, sem prometer sincronização offline ainda inexistente; alvos de toque e texto acessíveis; vínculo e liderança exibidos separadamente. Compilação e fluxos principais verificados em Android e iOS, com registro dos dispositivos/simuladores utilizados e limitações.

## Fase 14 — Notificações (posterior à estabilidade)

**Escopo:** registro de dispositivos, preferências, Expo Push, destinatários por igreja/ministério/usuário, status de leitura e processamento de entrega. Introduzir fila/worker quando justificado pelo volume e confiabilidade.

**Aceite:** envio idempotente com política de retry; dispositivos inválidos removidos; vínculo atual determina destinatário; logout e troca de usuário não enviam dados ao usuário anterior; conteúdo privado não é revelado na tela bloqueada; navegação da notificação revalida acesso na API. Não tratar push como fonte de verdade.

Esta fase não precisa atrasar um MVP estável sem push; depende de decisão de lançamento após os módulos essenciais.

## Fase 15 — Cloudflare Tunnel e preparação de uso real

**Escopo:** Named Tunnel, hosts `app` e `api`, configuração de Nginx, HTTPS externo, origens permitidas, proxies confiáveis e cookies seguros. Documentar operação no computador pessoal e migração para VPS.

**Aceite:** Web e API acessíveis pelos hosts corretos; banco e FPM sem exposição pública; host desconhecido rejeitado; autenticação funciona com HTTPS e CORS restrito; conteúdo autenticado não tem cache público; tokens e credenciais fora do Git. Validar parada/reinício, diagnóstico, backup e uma restauração em ambiente isolado antes de cadastrar dados reais relevantes.

**Preparação operacional:** responsável, frequência e retenção de backups, RPO/RTO, atualização, logs, monitoramento e recuperação de credenciais definidos. Exposição externa só ocorre quando os módulos liberados e as verificações de segurança anteriores estiverem concluídos.

## Como encerrar cada etapa

Relatar de forma verificável:

- O comportamento entregue, arquivos criados/alterados e decisões relevantes.
- Migrations criadas ou declaração de que não houve migrations.
- Comandos para instalar, executar, testar e diagnosticar.
- Testes/checks realmente executados e seus resultados; separar validação estática de integração.
- Limitações, falhas de ambiente e verificações pendentes com evidência suficiente.
- Próxima etapa e seu critério de aceite.

Não avançar para um novo módulo para encobrir uma base quebrada. Também não declarar uma fase concluída apenas porque seu código foi escrito: os critérios de execução e segurança correspondentes fazem parte da entrega.
