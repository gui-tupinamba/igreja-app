# Notificações

A fase 14 adiciona caixa de entrada autenticada, leitura, preferências, registro de
dispositivos por sessão e uma fila transacional para Expo Push. Avisos podem ser
direcionados à igreja (`CHURCH`), a um ministério (`MINISTRY`) ou a uma pessoa
(`USER`). Líderes só enviam ao ministério em que mantêm liderança ativa.

O aplicativo pede permissão quando a pessoa ativa o push na tela Notificações. O
projeto precisa estar vinculado ao EAS por `extra.eas.projectId`; `eas init` inclui
esse UUID no `app.json`. Push Android não funciona no Expo Go atual.

Para Android, também é obrigatório registrar `dev.guitupinamba.igreja` no Firebase,
salvar o `google-services.json` em `apps/mobile` e definir
`android.googleServicesFile` como `./google-services.json`. A chave privada da conta
de serviço FCM V1 deve ser enviada ao EAS por `eas credentials` e nunca versionada.

Depois da configuração, gere o APK interno com:

```powershell
cd apps/mobile
npx eas-cli@latest build --platform android --profile preview
```

O `eas.json` mantém `preview` como APK instalável e `production` como Android App
Bundle para distribuição. Mudanças no project ID ou no Firebase exigem novo build;
elas não corrigem APKs já instalados.

Configuração Android ativa em 24/09/2026:

- projeto EAS `@gui-tupinamba/ieqcv-app`, ID
  `5d0e0425-7b4f-4577-80bc-17831021cc63`;
- projeto Firebase `ieqcv-app-5d0e0425`;
- pacote `dev.guitupinamba.igreja` registrado no Firebase;
- credencial FCM V1 associada no EAS;
- build interno EAS `3db62cf8-f590-4047-b330-02f491626ca5` concluído.

No servidor, o envio externo fica desligado por padrão. Depois de configurar as
credenciais do projeto Expo, defina `EXPO_PUSH_ENABLED=1` e, caso a segurança de
acesso do Expo esteja ativa, `EXPO_PUSH_ACCESS_TOKEN`. Inicie o worker recorrente:

```sh
docker compose --profile workers up -d notification-worker
```

Para diagnóstico ou uma execução imediata, use
`docker compose exec -T php php bin/console app:notifications:deliver --limit=100`.

O payload visível na tela bloqueada é sempre genérico. O aplicativo usa somente o
ID recebido e consulta novamente a API antes de exibir ou navegar. Tokens pertencem
à sessão atual; sessão revogada, usuário inativo e participação removida são
revalidados no momento da entrega.
