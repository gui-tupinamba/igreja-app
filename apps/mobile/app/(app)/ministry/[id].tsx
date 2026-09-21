import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Empty, ErrorState, Loading, Screen } from "@/components";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Ministry } from "@/types";

export default function MinistryDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const resource = useResource<{ ministry: Ministry }>(`/ministries/${id}`);
  if (resource.loading) return <Screen><Loading /></Screen>;
  if (resource.error) return <Screen><ErrorState error={resource.error} retry={resource.reload} /></Screen>;
  const ministry = resource.data?.ministry;
  if (!ministry) return <Screen><Empty>Ministério indisponível.</Empty></Screen>;
  return <Screen><Text style={styles.eyebrow}>SERVIR JUNTOS</Text><Text accessibilityRole="header" style={styles.title}>{ministry.name}</Text><Text style={styles.body}>{ministry.description || "Este ministério ainda não possui uma descrição."}</Text></Screen>;
}

const styles = StyleSheet.create({ eyebrow: { color: colors.green, fontWeight: "800", letterSpacing: 1.5, fontSize: 11 }, title: { color: colors.ink, fontWeight: "900", fontSize: 31, lineHeight: 38, marginTop: 9 }, body: { color: colors.ink, fontSize: 17, lineHeight: 27, marginTop: 21 } });
