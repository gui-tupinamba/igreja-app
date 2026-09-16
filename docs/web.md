# Web — fase 12

Interface React, TypeScript e Vite conectada à API Symfony existente, com
dependências fixadas em `apps/web/package-lock.json`. Nenhuma migration nova.

## Acesso local

Abra **http://127.0.0.1:5173/** no computador que executa o Docker.
Use o e-mail e a senha definidos ao criar o ADMIN. Não existe senha padrão.
A porta 8080 oferece a API; não é o painel. Use o mesmo hostname durante o login
(não alterne localhost e 127.0.0.1). O ambiente local usa refresh cookie HttpOnly
com exceção HTTP limitada a loopback. HTTPS e domínio público são da fase 15.

Na raiz do projeto:

```powershell
docker compose up -d --build --wait web
docker compose ps
```

API e PostgreSQL precisam estar ativos. O serviço `web` inicia também no Compose
completo e possui política de reinício. O Docker precisa continuar ligado.

Para desenvolvimento, pare apenas `web` (`docker compose stop web`) e, em
`apps/web`, execute `npm ci` e `npm run dev`. A API padrão é
`https://<hostname atual>:8080/api`. `VITE_API_URL` pode substituí-la no build;
qualquer novo domínio também exige configurar CORS, cookies e CSP deliberadamente.

## Funcionalidades

- Login, restauração/renovação de sessão e logout, painel com dados autorizados.
- Pessoas: cadastro, perfil, cargo/status e redefinição administrativa de senha.
- Ministérios: cadastro/edição, desativação, participação e liderança separadas.
- Publicações: rascunho, edição, publicação, retirada e arquivamento; comentários
  próprios e moderação global conforme permissão.
- Eventos e atividades: horários no fuso do navegador, edição, publicação,
  cancelamento, arquivamento e agenda combinada sem duplicar registros.
- Perfil próprio, vínculos, liderança e troca de senha com encerramento de sessões.
- Filtros/paginação da API, estados vazios/erro/carregamento, layout responsivo,
  campos rotulados, diálogo nativo com foco e navegação por teclado.

Access token somente em memória; nenhum token em localStorage/sessionStorage.
Refresh usa cookie HttpOnly e credenciais explícitas. Renovações concorrentes são
coordenadas por Web Locks quando disponível; BroadcastChannel invalida a interface
das outras abas quando a sessão muda. As permissões são buscadas na API e
revalidadas ao voltar à aba. Toda operação continua autorizada no backend.
Recusas são exibidas nos formulários; a interface nunca substitui Voters/escopos.

## Arquivos

`apps/web/src/api.ts` centraliza HTTP/sessão; `session.tsx` fornece permissões;
`App.tsx` e `Shell.tsx` implementam login/navegação/painel; `pages/` contém os
módulos; `ui.tsx`, `types.ts` e `style.css` compartilham componentes/contratos/estilos.
`Dockerfile` e `nginx.conf` entregam build estático com fallback de rotas e CSP.
`docker-compose.yml` inclui o serviço Web, sem publicar o PostgreSQL.

## Validação reproduzível

```powershell
# Raiz: banco temporário, API e Nginx exclusivos dos testes
docker compose -f docker-compose.yml -f docker-compose.web-test.yml --profile tools up -d --wait php-web-test nginx-web-test
# apps/web:
npm ci
npx playwright install chromium
npm run build
npm run test:e2e
# Raiz: encerrar somente o ambiente de teste
docker compose -f docker-compose.yml -f docker-compose.web-test.yml --profile tools stop nginx-web-test php-web-test postgres-test
```

Playwright inicia Vite em 5174 e usa API em 8081. Fixtures recusam ambiente/banco
diferente de `test`/`igreja_test`/`postgres-test`; senhas aleatórias são fornecidas
por stdin e não versionadas. Screenshots e resultados ficam em `.tmp/` ignorado.
Para uma nova execução limpa, pare/reinicie os três serviços de teste: o banco é
tmpfs. Nunca use `down -v` para esse procedimento.

O registro de resultados reais fica em [validation.md](validation.md).
Mobile, uploads, recorrência, notificações e publicação externa são fases futuras.
Não há envio de convites, recuperação automática de senha ou funcionamento offline.
