import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Badge, Card, Empty, ErrorState, Heading, Loading, ProtectedImage, Screen, formatDate } from "@/components";
import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Content, Page } from "@/types";

export default function Home() {
  const { user } = useSession();
  const posts = useResource<Page<Content>>("/posts?limit=3&page=1");
  const agenda = useResource<Page<Content>>("/calendar?limit=4&page=1");
  const loading = posts.loading || agenda.loading;
  return <Screen refreshControl={undefined}>
    <View style={styles.headingRow}><View style={styles.headingGrow}><Heading title={`Olá, ${user?.name.split(" ")[0]}.`} subtitle="Um novo dia para estar perto e caminhar juntos." /></View><Pressable accessibilityLabel="Abrir notificações" onPress={() => router.push("/(app)/notifications")} style={styles.bell}><Text style={styles.bellText}>●</Text></Pressable></View>
    <View style={styles.hero}><Text style={styles.heroEyebrow}>A IGREJA ACONTECE COM VOCÊ</Text><Text style={styles.heroTitle}>Encontre seu lugar. Faça parte do próximo encontro.</Text><Pressable onPress={() => router.push("/(app)/(tabs)/agenda")} style={styles.heroButton}><Text style={styles.heroButtonText}>Ver nossa agenda</Text></Pressable></View>
    {loading ? <Loading /> : null}
    {posts.error ? <ErrorState error={posts.error} retry={posts.reload} /> : null}
    {!posts.loading && !posts.error ? <><Text style={styles.section}>Na vida da comunidade</Text>{posts.data?.items.length ? posts.data.items.map((item) => <Pressable key={item.id} onPress={() => router.push({ pathname: "/(app)/post/[id]", params: { id: item.id } })}><Card><ProtectedImage image={item.images?.[0]} accessibilityLabel={`Imagem de ${item.title}`} /><Badge value={item.visibility} /><Text style={styles.cardTitle}>{item.title}</Text><Text numberOfLines={3} style={styles.body}>{item.content}</Text></Card></Pressable>) : <Empty>As próximas publicações aparecerão aqui.</Empty>}</> : null}
    {agenda.error ? <ErrorState error={agenda.error} retry={agenda.reload} /> : null}
    {!agenda.loading && !agenda.error ? <><Text style={styles.section}>Próximos encontros</Text>{agenda.data?.items.length ? agenda.data.items.map((item) => <Pressable key={`${item.kind}-${item.id}`} onPress={() => item.kind === "EVENT" && router.push({ pathname: "/(app)/event/[id]", params: { id: item.id } })}><Card><Text style={styles.date}>{formatDate(item.starts_at)}</Text><Text style={styles.cardTitle}>{item.title}</Text><Text numberOfLines={2} style={styles.body}>{item.description || "Confira os detalhes deste encontro."}</Text></Card></Pressable>) : <Empty>Nenhum compromisso próximo.</Empty>}</> : null}
  </Screen>;
}

const styles = StyleSheet.create({
  headingRow: { flexDirection: "row", alignItems: "flex-start", gap: 10 }, headingGrow: { flex: 1 }, bell: { width: 44, height: 44, borderRadius: 22, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" }, bellText: { color: colors.green, fontSize: 18 },
  hero: { backgroundColor: colors.greenDark, borderRadius: 19, padding: 22, marginBottom: 25 }, heroEyebrow: { color: "#bcd6cc", fontWeight: "800", fontSize: 10, letterSpacing: 1.4 }, heroTitle: { color: colors.white, fontSize: 24, lineHeight: 31, fontWeight: "800", marginVertical: 14 }, heroButton: { alignSelf: "flex-start", backgroundColor: colors.cream, borderRadius: 11, paddingHorizontal: 15, paddingVertical: 12 }, heroButtonText: { color: colors.greenDark, fontWeight: "800" },
  section: { color: colors.ink, fontSize: 20, fontWeight: "800", marginTop: 10, marginBottom: 12 }, cardTitle: { color: colors.ink, fontSize: 18, lineHeight: 24, fontWeight: "800", marginTop: 10 }, body: { color: colors.muted, lineHeight: 21, marginTop: 6 }, date: { color: colors.green, fontWeight: "700", fontSize: 13 },
});
