import { ActivityIndicator, StyleSheet, View } from "react-native";
import { Stack } from "expo-router";
import { useEffect } from "react";
import { StatusBar } from "expo-status-bar";
import { SafeAreaProvider } from "react-native-safe-area-context";

import { SessionProvider, useSession } from "@/session";
import {
  listenForNotificationNavigation,
  registerForPushNotifications,
} from "@/push";
import { colors } from "@/theme";

function Routes() {
  const { ready, user } = useSession();

  useEffect(() => {
    if (!ready || !user) {
      return;
    }

    registerForPushNotifications();
  }, [ready, user?.id]);

  if (!ready) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator size="large" color={colors.green} />
      </View>
    );
  }

  return (
    <Stack
      screenOptions={{
        headerStyle: {
          backgroundColor: colors.cream,
        },
        headerTintColor: colors.ink,
        headerShadowVisible: false,
      }}
    >
      <Stack.Protected guard={!!user}>
        <Stack.Screen
          name="(app)"
          options={{
            headerShown: false,
          }}
        />
      </Stack.Protected>

      <Stack.Protected guard={!user}>
        <Stack.Screen
          name="sign-in"
          options={{
            headerShown: false,
          }}
        />
      </Stack.Protected>
    </Stack>
  );
}

export default function RootLayout() {
  useEffect(() => {
    return listenForNotificationNavigation();
  }, []);

  return (
    <SafeAreaProvider>
      <SessionProvider>
        <StatusBar style="dark" />
        <Routes />
      </SessionProvider>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  loading: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.cream,
  },
});
