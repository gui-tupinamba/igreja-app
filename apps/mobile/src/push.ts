import { Platform } from "react-native";
import { router } from "expo-router";
import Constants from "expo-constants";
import * as Notifications from "expo-notifications";
import * as SecureStore from "expo-secure-store";

import { api } from "./api";

const PUSH_TOKEN_KEY = "igreja.mobile.expoPushToken";

/**
 * Define como a notificação deve aparecer quando o aplicativo
 * estiver ABERTO em primeiro plano.
 */
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

/**
 * Configura o canal usado pelas notificações no Android.
 */
async function configureAndroidNotificationChannel() {
  if (Platform.OS !== "android") {
    return;
  }

  await Notifications.setNotificationChannelAsync("default", {
    name: "Notificações",
    description: "Notificações da Comunidade",
    importance: Notifications.AndroidImportance.MAX,
    vibrationPattern: [0, 250, 250, 250],
    sound: "default",
  });
}

/**
 * Obtém o ID do projeto Expo/EAS.
 */
function getProjectId() {
  return (
    process.env.EXPO_PUBLIC_EAS_PROJECT_ID ??
    Constants.expoConfig?.extra?.eas?.projectId ??
    Constants.easConfig?.projectId
  );
}

/**
 * Solicita autorização do usuário para receber notificações.
 */
async function requestNotificationPermission() {
  const currentPermission = await Notifications.getPermissionsAsync();

  if (currentPermission.status === "granted") {
    return true;
  }

  const requestedPermission = await Notifications.requestPermissionsAsync();

  return requestedPermission.status === "granted";
}

/**
 * Registra este celular para receber Push Notifications.
 *
 * Deve ser chamada depois que o usuário estiver autenticado.
 */
async function registerPushNotifications(): Promise<string> {
    if (Platform.OS === "web") {
      throw new Error("Notificações push estão disponíveis no aplicativo instalado.");
    }

    /*
     * No Android o canal deve ser configurado antes
     * de solicitar a permissão.
     */
    await configureAndroidNotificationChannel();

    const permissionGranted = await requestNotificationPermission();

    if (!permissionGranted) {
      throw new Error("Permissão de notificações não concedida.");
    }

    const projectId = getProjectId();

    if (!projectId) {
      throw new Error("Este APK foi gerado sem vínculo com o projeto EAS. Instale uma versão atualizada do aplicativo.");
    }

    /*
     * Solicita ao Expo o token deste aparelho.
     */
    const result = await Notifications.getExpoPushTokenAsync({
      projectId,
    });

    const expoPushToken = result.data;

    /*
     * Registra o token no backend Symfony.
     */
    await api("/notifications/devices", "POST", {
      expo_push_token: expoPushToken,
      platform: Platform.OS === "ios" ? "IOS" : "ANDROID",
    });

    /*
     * Salva localmente para que o logout consiga
     * remover o dispositivo posteriormente.
     */
    await SecureStore.setItemAsync(PUSH_TOKEN_KEY, expoPushToken);

    return expoPushToken;
}

export async function registerForPushNotifications() {
  try {
    return await registerPushNotifications();
  } catch (error) {
    console.error("[Push] Erro ao registrar notificações:", error);

    return null;
  }
}

export function enablePushNotifications(): Promise<string> {
  return registerPushNotifications();
}

/**
 * Navega dentro do aplicativo quando o usuário
 * toca em uma notificação.
 */
function handleNotificationNavigation(
  response: Notifications.NotificationResponse,
) {
  const data = response.notification.request.content.data;

  const notificationId = data?.notification_id;
  if (
    typeof notificationId === "number" ||
    (typeof notificationId === "string" && /^\d+$/.test(notificationId))
  ) {
    router.push({
      pathname: "/(app)/notifications",
      params: { id: String(notificationId) },
    });
    return;
  }

  /*
   * Se o backend enviar uma rota:
   *
   * data: {
   *   route: "/(app)/notifications"
   * }
   */
  const route = data?.route;

  if (typeof route === "string") {
    let match: RegExpMatchArray | null;
    if ((match = route.match(/^\/posts\/(\d+)$/))) {
      router.push({ pathname: "/(app)/post/[id]", params: { id: match[1] } });
      return;
    }
    if ((match = route.match(/^\/events\/(\d+)$/))) {
      router.push({ pathname: "/(app)/event/[id]", params: { id: match[1] } });
      return;
    }
    if ((match = route.match(/^\/ministries\/(\d+)$/))) {
      router.push({ pathname: "/(app)/ministry/[id]", params: { id: match[1] } });
      return;
    }
    if (route === "/agenda") {
      router.push("/(app)/(tabs)/agenda");
      return;
    }
    if (route.startsWith("/(app)/")) {
      router.push(route as never);
      return;
    }
  }

  /*
   * Caso não exista uma rota específica,
   * abre a tela geral de notificações.
   */
  router.push("/(app)/notifications");
}

/**
 * Ativa o listener responsável por detectar quando
 * o usuário toca em uma notificação.
 *
 * Retorna uma função de limpeza para usar no useEffect.
 */
export function listenForNotificationNavigation() {
  const subscription = Notifications.addNotificationResponseReceivedListener(
    handleNotificationNavigation,
  );

  /*
   * Também verifica se o aplicativo foi aberto
   * diretamente através de uma notificação.
   */
  Notifications.getLastNotificationResponseAsync()
    .then((response) => {
      if (response) {
        handleNotificationNavigation(response);
      }
    })
    .catch((error) => {
      console.error("[Push] Erro ao verificar notificação inicial:", error);
    });

  return () => {
    subscription.remove();
  };
}
