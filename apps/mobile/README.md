# Mobile — fase 13

Aplicativo Expo/React Native para Android e iOS, voltado ao uso cotidiano dos
membros. Ele consome a mesma API e respeita os mesmos escopos de leitura.

```powershell
npm ci
npm run typecheck
npm start
```

Por padrão, usa `https://api.guitupinamba.dev/api`. Para desenvolvimento em um
aparelho físico, copie `.env.example` para `.env` e informe uma URL HTTPS ou o IP
LAN acessível pelo aparelho. `127.0.0.1` no celular aponta para o próprio celular.

O refresh token fica no SecureStore; o access token permanece apenas em memória.
O app restaura e renova a sessão, remove a credencial no logout e encerra a sessão
local após troca de senha. Não há senha padrão nem modo offline.
