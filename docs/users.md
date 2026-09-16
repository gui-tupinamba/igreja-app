# Usuários e perfil — fase 6

Todas as rotas exigem Bearer e respeitam o transporte HTTPS (ou a exceção local
explícita). As respostas usam `Cache-Control: no-store`. Não existe cadastro público.

| Método e rota | Operação |
| --- | --- |
| `POST /api/admin/users` | ADMIN/PASTOR cadastra uma conta; somente ADMIN cria ADMIN |
| `GET /api/admin/users` | Lista paginada, filtros `role`/`status`, sem telefone/nascimento |
| `GET /api/admin/users/{id}` | Perfil completo de uma conta que o gestor pode administrar |
| `PATCH /api/admin/users/{id}` | Edita nome, email, telefone e nascimento |
| `PATCH /api/admin/users/{id}/access` | Altera cargo/status com as salvaguardas da fase 5 |
| `POST /api/admin/users/{id}/password` | Define nova senha e revoga todas as sessões do alvo |
| `GET /api/profile` | Perfil próprio, ministérios participantes e liderados separados |
| `PATCH /api/profile` | Edita somente nome, telefone e nascimento próprios |
| `POST /api/profile/password` | Troca a própria senha mediante confirmação da senha atual |

## Cadastro e edição

Cadastro recebe `name`, `email`, `password`; opcionais: `role` (MEMBER), `status`
(ACTIVE), `phone` e `birth_date`. Não cria participação nem liderança automaticamente.
Retorna 201, cabeçalho `Location` e `user` com ID, nome, email, telefone, nascimento,
cargo, status, `created_at` e `updated_at`. Hashes e credenciais nunca são retornados.

Exemplo de corpo, usando uma senha escolhida e transmitida fora dos logs:

```json
{"name":"Nome do membro","email":"membro@example.org","password":"SENHA_ESCOLHIDA_PELO_RESPONSAVEL","role":"MEMBER"}
```

A senha inicial é definida pelo gestor, sem envio automático de email. O usuário
pode trocá-la após entrar. Convites, recuperação por email e troca obrigatória no
primeiro login continuam pendentes; não há senha padrão do sistema.

Edição administrativa aceita somente `name`, `email`, `phone`, `birth_date`.
Edição própria aceita somente `name`, `phone`, `birth_date`: email de acesso é
alterado pela gestão. Campos omitidos são preservados; `null` remove somente
telefone/nascimento. Corpo vazio, campos extras e tipos incorretos retornam 422.
Cargo/status e senha têm operações separadas; nenhum ID enviado no corpo muda o alvo.

Validação: nome até 120 caracteres, email ASCII válido até 180, telefone até 30,
nascimento opcional em `AAAA-MM-DD` válido e não futuro. Campos textuais não aceitam
caracteres de controle. Senhas têm 12–72 bytes, preservam espaços e não aceitam NUL
ou conteúdo formado apenas por espaços. Erros de campo conhecidos acrescentam
`error.fields`, com mensagens fixas sem ecoar dados enviados. Colisões de email
normalizado retornam 409; a unicidade permanece protegida no PostgreSQL.

## Permissões e sessões

PASTOR não altera perfil nem redefine senha de ADMIN, mesmo em uma operação sem
mudança efetiva. MEMBER/LEADER não administram usuários. Perfil próprio não aceita
um ID alternativo ou retorna perfis de terceiros. Ministérios do perfil exigem
ministério e participação ativos; liderança é mostrada separadamente e exige cargo
e vínculo elegíveis. Foto será adicionada com o módulo de arquivos.

Mudanças administrativas e próprias validam novamente cargo, status e sessão
depois das travas no banco. Usam a mesma ordem de bloqueio da administração de
acesso. Duas criações concorrentes do mesmo email resultam em apenas uma conta.

Troca própria recebe `current_password` e `new_password`; redefinição administrativa
recebe apenas `new_password`. Senha atual incorreta retorna 403; senha nova igual à
atual retorna 422. A troca própria limita a verificação a dez tentativas por usuário
em quinze minutos, compartilhando o limite de cem tentativas por IP com login.
Sucesso retorna 204 e revoga todas as sessões, inclusive a utilizada na troca.
O cliente deve descartar seu access token e entrar novamente. O cookie antigo de
refresh também fica inválido no servidor.

Alteração efetiva do email normalizado revoga todas as sessões da conta; uma mudança
apenas de maiúsculas/minúsculas preserva a identidade normalizada. Mudanças de nome,
telefone e nascimento não encerram sessões.

Criação, edição efetiva e senha geram auditoria na mesma transação. São registrados
ator/alvo, ação, instante e somente cargo/status inicial ou nomes dos campos
alterados. Não são copiados nome, email, telefone, nascimento, senha ou hashes para
a auditoria. Falha no registro desfaz a operação, incluindo eventual revogação.
Repetir os mesmos dados de perfil não cria auditoria adicional.

## Arquivos e execução

- `src/Administration/UserManagementService.php`: transações, autorização e auditoria.
- `src/Http/UserInput.php`, `InputValidationException.php`, `UserView.php`: entrada e saída.
- `src/Controller/AdminUserController.php`, `ProfileController.php`: rotas.
- `src/Repository/AdminUserReader.php`, `ProfileReader.php`: leituras autorizadas.
- `src/EventSubscriber/CorsSubscriber.php`, `JsonExceptionSubscriber.php`: CORS e erros.

Não há nova migration: utiliza as onze entidades e três migrations existentes.

```sh
docker compose --profile tools run --build --rm api-test
docker compose up --build -d --wait
docker compose exec -T php php bin/console doctrine:schema:validate
```

Resultados executados em [validation.md](validation.md). Ministérios, participação
e liderança foram acrescentados na [fase 7](ministries.md). Interfaces Web/mobile
e publicação externa seguem o plano.
