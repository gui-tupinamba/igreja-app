import { useState, type ReactNode } from "react";
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View, type ScrollViewProps } from "react-native";
import { Image } from "expo-image";
import { API_URL, ApiError, authorizationHeaders, restoreSession } from "./api";
import { colors } from "./theme";
import type { ContentImage } from "./types";

export function Screen({ children, ...props }: ScrollViewProps & { children: ReactNode }) {
  return <ScrollView contentContainerStyle={styles.screen} keyboardShouldPersistTaps="handled" {...props}>{children}</ScrollView>;
}

export function Heading({ eyebrow = "NOSSA COMUNIDADE", title, subtitle }: { eyebrow?: string; title: string; subtitle?: string }) {
  return <View style={styles.heading}><Text style={styles.eyebrow}>{eyebrow}</Text><Text accessibilityRole="header" style={styles.title}>{title}</Text>{subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}</View>;
}

export function Card({ children }: { children: ReactNode }) { return <View style={styles.card}>{children}</View>; }

export function Loading() { return <View accessibilityRole="progressbar" style={styles.state}><ActivityIndicator color={colors.green} size="large" /><Text style={styles.muted}>Carregando…</Text></View>; }

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
  const message = error instanceof ApiError || error instanceof Error ? error.message : "Não foi possível carregar.";
  return <View accessibilityRole="alert" style={[styles.state, styles.error]}><Text style={styles.errorText}>{message}</Text>{retry ? <Pressable accessibilityRole="button" onPress={retry} style={styles.smallButton}><Text style={styles.smallButtonText}>Tentar novamente</Text></Pressable> : null}</View>;
}

export function Empty({ children }: { children: ReactNode }) { return <View style={styles.state}><Text style={styles.emptyTitle}>Nada por aqui ainda</Text><Text style={styles.muted}>{children}</Text></View>; }

export function Badge({ value }: { value: string }) {
  const labels: Record<string, string> = {
    PUBLIC: "Toda a igreja",
    MINISTRY_MEMBERS: "Integrantes",
    DRAFT: "Rascunho",
    PENDING_REVIEW: "Aguardando revisão",
    PUBLISHED: "Publicado",
    CANCELLED: "Cancelado",
    ARCHIVED: "Arquivado",
    ADMIN: "Administrador",
    PASTOR: "Pastor",
    LEADER: "Líder",
    MEMBER: "Membro",
  };
  return <View style={styles.badge}><Text style={styles.badgeText}>{labels[value] || value}</Text></View>;
}

export function ProtectedImage({ image, detail = false, accessibilityLabel }: { image?: ContentImage; detail?: boolean; accessibilityLabel: string }) {
  const [attempt, setAttempt] = useState(0);
  if (!image) return null;
  const path = detail ? image.detail_url || image.url : image.feed_url || image.url;
  return <Image key={`${path}-${attempt}`} accessibilityLabel={accessibilityLabel} source={{ uri: `${API_URL}${path}`, headers: authorizationHeaders() }} contentFit="cover" style={detail ? styles.detailImage : styles.image} onError={() => { if (attempt === 0) restoreSession().then(() => setAttempt(1)).catch(() => undefined); }} />;
}

export function formatDate(value?: string | null) {
  if (!value) return "";
  return new Intl.DateTimeFormat("pt-BR", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

export const common = StyleSheet.create({
  label: { color: colors.ink, fontSize: 14, fontWeight: "700", marginBottom: 7 },
  input: { minHeight: 50, borderWidth: 1, borderColor: colors.border, borderRadius: 12, backgroundColor: colors.white, paddingHorizontal: 14, color: colors.ink, fontSize: 16, marginBottom: 16 },
  textarea: { minHeight: 110, textAlignVertical: "top", paddingTop: 13 },
  button: { minHeight: 50, borderRadius: 12, backgroundColor: colors.green, justifyContent: "center", alignItems: "center", paddingHorizontal: 18 },
  buttonText: { color: colors.white, fontSize: 16, fontWeight: "800" },
  link: { color: colors.green, fontWeight: "800" },
  row: { flexDirection: "row", alignItems: "center", gap: 9, flexWrap: "wrap" },
});

const styles = StyleSheet.create({
  screen: { padding: 20, paddingBottom: 46, backgroundColor: colors.cream, flexGrow: 1 },
  heading: { marginBottom: 20 },
  eyebrow: { color: colors.green, fontWeight: "800", fontSize: 11, letterSpacing: 1.6, marginBottom: 7 },
  title: { color: colors.ink, fontWeight: "800", fontSize: 30, lineHeight: 36 },
  subtitle: { color: colors.muted, fontSize: 15, lineHeight: 22, marginTop: 6 },
  card: { backgroundColor: colors.white, borderRadius: 17, borderWidth: 1, borderColor: colors.border, padding: 17, marginBottom: 13 },
  state: { minHeight: 150, alignItems: "center", justifyContent: "center", gap: 12, padding: 20 },
  muted: { color: colors.muted, textAlign: "center", lineHeight: 21 },
  emptyTitle: { color: colors.ink, fontSize: 18, fontWeight: "800" },
  error: { minHeight: 100, backgroundColor: "#fff1f1", borderRadius: 14 },
  errorText: { color: colors.danger, textAlign: "center", lineHeight: 21 },
  smallButton: { backgroundColor: colors.green, borderRadius: 10, minHeight: 42, justifyContent: "center", paddingHorizontal: 15 },
  smallButtonText: { color: colors.white, fontWeight: "800" },
  badge: { alignSelf: "flex-start", backgroundColor: colors.greenSoft, borderRadius: 999, paddingHorizontal: 9, paddingVertical: 5 },
  badgeText: { color: colors.greenDark, fontSize: 11, fontWeight: "700" },
  image: { width: "100%", height: 125, borderRadius: 12, marginBottom: 13, backgroundColor: colors.greenSoft },
  detailImage: { width: "100%", aspectRatio: 3.14, borderRadius: 14, marginBottom: 17, backgroundColor: colors.greenSoft },
});
