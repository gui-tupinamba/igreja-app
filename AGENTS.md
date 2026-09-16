# Instruções do projeto

Leia `docs/specification.md`, `docs/architecture.md`, `docs/implementation-plan.md`
e o estado real no `README.md` antes de iniciar a próxima fase.

- Preserve a stack: Symfony/Doctrine/PostgreSQL; React/Vite Web; React Native/Expo mobile.
- Trabalhe por fases. Não antecipe telas e recursos avançados à base e à autorização.
- Cargo global, participação, liderança por ministério e visibilidade são conceitos distintos.
- PUBLIC requer autenticação e é legível por toda a igreja, independentemente de participação.
- MINISTRY_MEMBERS requer participação ativa específica, exceto ADMIN/PASTOR ativos.
- LEADER administra somente ministérios em que possui liderança ativa. MEMBER não administra.
- PASTOR não gerencia ADMIN nem configurações exclusivas; preserve o último ADMIN ativo.
- Todas as entradas são validadas e autorizadas no backend; listas filtram antes da paginação.
- Teste privacidade, IDOR, estados, concorrência crítica e revogação antes de liberar módulos.
- Não versionar segredos, dependências, builds, logs, dumps ou dados locais.
- Preserve alterações existentes; leia arquivos antes de modificá-los.
- Ao entregar uma etapa, relate arquivos, migrations, comandos, testes reais e pendências.
