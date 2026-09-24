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

Para compilar e instalar o projeto nativo Android em um aparelho ou emulador:

```powershell
npm run android
```

O diretório `android` faz parte do repositório. Mudanças em opções nativas do
`app.json` exigem regenerar o projeto com `npx expo prebuild` e revisar o diff antes
do commit.

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

## APK Android de teste

Em 23/09/2026, `assembleRelease` foi aprovado para `arm64-v8a`, `x86` e `x86_64`.
O APK foi instalado no emulador Pixel 4, iniciou `MainActivity`, restaurou a sessão,
carregou conteúdo da API e não registrou exceção fatal. O artefato local fica em
`artifacts/android/Comunidade-0.1.0-test.apk` e não é versionado.

Esse APK usa a chave de depuração incluída no projeto nativo e serve para testes e
homologação. A publicação na Play Store exige uma chave de assinatura própria e sua
configuração segura no Gradle ou no EAS.

Em 24/09/2026, o projeto foi vinculado ao EAS/Firebase e um novo APK interno foi
gerado com credenciais remotas e suporte a FCM V1. O artefato local é
`artifacts/android/Comunidade-0.1.0-eas-push.apk`, com SHA-256
`fa9440f5b9a892e4deb28ed0da1cdce01f1da345559bcdfdf9665739f6192774`.
O aplicativo registrou o Expo Push Token na API e recebeu uma notificação real no
emulador. Esse APK expira no armazenamento do EAS em 07/10/2026; a cópia local não
é versionada.

No Windows, caminhos extensos podem ultrapassar o limite usado pelo CMake. Se isso
ocorrer, associe temporariamente a raiz do repositório a uma unidade curta antes de
executar o Gradle:

```powershell
subst I: C:\dev\igreja-app
Set-Location I:\apps\mobile\android
$env:NODE_ENV = 'production'
.\gradlew.bat assembleRelease --no-daemon '-PreactNativeArchitectures=arm64-v8a,x86,x86_64'
```
