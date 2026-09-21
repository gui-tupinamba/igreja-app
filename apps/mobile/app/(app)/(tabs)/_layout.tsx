import { StyleSheet, Text, type ColorValue } from "react-native";
import { Tabs } from "expo-router";
import { colors } from "@/theme";

const icon = (symbol: string, color: ColorValue) => <Text style={[styles.icon, { color }]}>{symbol}</Text>;

export default function TabsLayout() {
  return <Tabs screenOptions={{ headerStyle: { backgroundColor: colors.cream }, headerTintColor: colors.ink, headerShadowVisible: false, tabBarActiveTintColor: colors.green, tabBarInactiveTintColor: colors.muted, tabBarStyle: { height: 66, paddingTop: 6, paddingBottom: 8, borderTopColor: colors.border }, tabBarLabelStyle: { fontSize: 11, fontWeight: "700" } }}>
    <Tabs.Screen name="index" options={{ title: "Início", tabBarIcon: ({ color }) => icon("⌂", color) }} />
    <Tabs.Screen name="feed" options={{ title: "Publicações", tabBarIcon: ({ color }) => icon("▤", color) }} />
    <Tabs.Screen name="agenda" options={{ title: "Agenda", tabBarIcon: ({ color }) => icon("□", color) }} />
    <Tabs.Screen name="ministries" options={{ title: "Ministérios", tabBarIcon: ({ color }) => icon("♡", color) }} />
    <Tabs.Screen name="profile" options={{ title: "Perfil", tabBarIcon: ({ color }) => icon("○", color) }} />
  </Tabs>;
}

const styles = StyleSheet.create({ icon: { fontSize: 23, lineHeight: 25 } });
