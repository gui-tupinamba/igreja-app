import { Stack } from "expo-router";
import { Pressable } from "react-native";
import { router } from "expo-router";
import { Ionicons } from "@expo/vector-icons";
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
        name="schedule/manage"
        options={{
          title: "Gerenciar atividades",

          headerLeft: () => (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Voltar"
              onPress={() => {
                if (router.canGoBack()) {
                  router.back();
                } else {
                  router.replace("/(app)/(tabs)/agenda");
                }
              }}
              style={{
                width: 42,
                height: 42,
                alignItems: "center",
                justifyContent: "center",
                marginRight: 6,
              }}
            >
              <Ionicons name="arrow-back" size={24} color={colors.ink} />
            </Pressable>
          ),
        }}
      />

      <Stack.Screen
        name="schedule/[id]"
        options={{
          title: "Atividade",
        }}
      />

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
        name="schedule/create"
        options={{
          title: "Nova atividade",
        }}
      />

      <Stack.Screen
        name="post/edit/[id]"
        options={{
          title: "Editar publicação",
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
          title: "Avisos",
        }}
      />
    </Stack>
  );
}
