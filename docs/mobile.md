# Aplicativo mobile — fase 13

O aplicativo em `apps/mobile` usa Expo SDK 57, React Native, Expo Router e
TypeScript. Ele atende Android e iOS e consome, por padrão,
`https://api.guitupinamba.dev/api`.

## Executar

```powershell
cd apps/mobile
npm ci
npm start
```

Para apontar um aparelho a outro ambiente, copie `.env.example` para `.env` e
defina `EXPO_PUBLIC_API_URL`. A URL precisa ser acessível pelo aparelho; o endereço
`127.0.0.1` do computador não representa o computador dentro do celular.

## Recursos

- login, renovação serializada, restauração e logout;
- Home com próximos compromissos;
- feed, busca, paginação, imagens autenticadas e detalhes com comentários;
- agenda e detalhes de eventos;
- ministérios, participantes e líderes exibidos separadamente;
- edição do próprio perfil e troca de senha.

O refresh token é salvo no Expo SecureStore. O access token permanece somente em
memória e a API revalida usuário, sessão, cargo e participação em cada requisição.

## Verificar

```powershell
npm run typecheck
npx expo-doctor
npm run export
```

O último comando gera bundles separados em `dist/android` e `dist/ios`. Esses
artefatos são locais e permanecem fora do Git.
