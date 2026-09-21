import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Badge, Empty, ErrorState, Loading, ProtectedImage, Screen, formatDate } from "@/components";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Content } from "@/types";

export default function EventDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const resource = useResource<{ event: Content }>(`/events/${id}`);
  if (resource.loading) return <Screen><Loading /></Screen>;
  if (resource.error) return <Screen><ErrorState error={resource.error} retry={resource.reload} /></Screen>;
  const event = resource.data?.event;
  if (!event) return <Screen><Empty>Evento indisponível.</Empty></Screen>;
  return <Screen><ProtectedImage image={event.images?.[0]} detail accessibilityLabel={`Imagem de ${event.title}`} /><View style={styles.meta}><Badge value={event.visibility} /><Badge value={event.status} /></View><Text accessibilityRole="header" style={styles.title}>{event.title}</Text><View style={styles.dateBox}><Text style={styles.date}>{formatDate(event.starts_at)}</Text>{event.ends_at ? <Text style={styles.muted}>Até {formatDate(event.ends_at)}</Text> : null}{event.location ? <Text style={styles.location}>{event.location}</Text> : null}{event.address ? <Text style={styles.muted}>{event.address}</Text> : null}</View><Text style={styles.body}>{event.description || "Sem descrição adicional."}</Text></Screen>;
}

const styles = StyleSheet.create({ meta: { flexDirection: "row", gap: 7, marginBottom: 13 }, title: { color: colors.ink, fontSize: 29, lineHeight: 36, fontWeight: "900" }, dateBox: { backgroundColor: colors.greenSoft, borderRadius: 14, padding: 16, marginVertical: 18 }, date: { color: colors.greenDark, fontWeight: "800", fontSize: 16 }, location: { color: colors.ink, fontWeight: "800", marginTop: 10 }, muted: { color: colors.muted, marginTop: 4 }, body: { color: colors.ink, fontSize: 16, lineHeight: 25 } });
