# Web (fase 12)

Aplicação React + TypeScript + Vite, responsiva, para membros e administração.
Interface funcional conectada à API Symfony. Acesso local: http://127.0.0.1:5173/.
Use o e-mail e a senha cadastrados para o ADMIN; não existe senha padrão.

Na raiz: `docker compose up -d --build --wait web`.
Desenvolvimento: pare somente `web` para liberar a porta, execute `npm ci` e
`npm run dev` nesta pasta. API em 8080 deve continuar ativa.

`npm run build` verifica TypeScript e gera os arquivos estáticos.
Consulte [execução, testes e limites](../../docs/web.md).
