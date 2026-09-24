import { Stack } from "expo-router";

import { colors } from "@/theme";

export default function AppLayout() {
  return (
    <Stack
      screenOptions={{
        headerStyle: {
          backgroundColor: colors.cream,
        },

        headerTintColor: colors.ink,

        headerShadowVisible: false,

        headerTitleStyle: {
          fontSize: 20,
          fontWeight: "800",
        },

        headerTitleAlign: "left",

        contentStyle: {
          backgroundColor: colors.cream,
        },

        headerBackTitle: "Voltar",
      }}
    >
      <Stack.Screen
        name="(tabs)"
        options={{
          headerShown: false,
        }}
      />

      <Stack.Screen
        name="post/[id]"
        options={{
          title: "Publicação",
        }}
      />

      <Stack.Screen
        name="post/create"
        options={{
          title: "Nova publicação",
        }}
      />

      <Stack.Screen
        name="event/[id]"
        options={{
          title: "Encontro",
        }}
      />

      <Stack.Screen
        name="ministry/[id]"
        options={{
          title: "Ministério",
        }}
      />

      <Stack.Screen
        name="notifications"
        options={{
          title: "Notificações",
        }}
      />
    </Stack>
  );
}
