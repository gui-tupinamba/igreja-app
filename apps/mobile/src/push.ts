import { Platform } from "react-native";
import Constants from "expo-constants";
import * as Notifications from "expo-notifications";
import * as SecureStore from "expo-secure-store";
import { router } from "expo-router";
import { api } from "./api";

const TOKEN_KEY = "igreja.mobile.expoPushToken";

Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: false, shouldSetBadge: true }),
});

export async function enablePushNotifications(): Promise<string> {
  if (Platform.OS === "web") throw new Error("Notificações push estão disponíveis no aplicativo instalado.");
  const projectId = process.env.EXPO_PUBLIC_EAS_PROJECT_ID || Constants.expoConfig?.extra?.eas?.projectId || Constants.easConfig?.projectId;
  if (!projectId) throw new Error("Este APK foi gerado sem vínculo com o projeto EAS. Instale uma versão atualizada do aplicativo.");
  if (Platform.OS === "android") await Notifications.setNotificationChannelAsync("default", { name: "Atualizações", importance: Notifications.AndroidImportance.DEFAULT });
  const current = await Notifications.getPermissionsAsync();
  const permission = current.status === "granted" ? current : await Notifications.requestPermissionsAsync();
  if (permission.status !== "granted") throw new Error("Permissão de notificações não concedida.");
  const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  await api("/notifications/devices", "POST", { expo_push_token: token, platform: Platform.OS === "ios" ? "IOS" : "ANDROID" });
  await SecureStore.setItemAsync(TOKEN_KEY, token);
  return token;
}

export function listenForNotificationNavigation() {
  const open = (response: Notifications.NotificationResponse) => {
    const id = response.notification.request.content.data?.notification_id;
    if (typeof id === "number" || (typeof id === "string" && /^\d+$/.test(id))) router.push({ pathname: "/(app)/notifications", params: { id: String(id) } });
  };
  const subscription = Notifications.addNotificationResponseReceivedListener(open);
  Notifications.getLastNotificationResponseAsync().then((response) => { if (response) open(response); }).catch(() => undefined);
  return () => subscription.remove();
}
