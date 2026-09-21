import { Pressable, StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { Card, Empty, ErrorState, Heading, Loading, Screen } from "@/components";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Ministry, Page } from "@/types";

export default function Ministries() {
  const resource = useResource<Page<Ministry>>("/ministries?page=1&limit=100");
  return <Screen>
    <Heading title="Ministérios" subtitle="Diferentes dons. Uma mesma comunidade." />
    {resource.loading ? <Loading /> : resource.error ? <ErrorState error={resource.error} retry={resource.reload} /> : resource.data?.items.length ? resource.data.items.map((item) => <Pressable key={item.id} onPress={() => router.push({ pathname: "/(app)/ministry/[id]", params: { id: item.id } })}><Card><Text style={styles.title}>{item.name}</Text><Text numberOfLines={4} style={styles.body}>{item.description || "Conheça este ministério e sua missão."}</Text><Text style={styles.link}>Conhecer ministério →</Text></Card></Pressable>) : <Empty>Os ministérios ativos aparecerão aqui.</Empty>}
  </Screen>;
}

const styles = StyleSheet.create({ title: { color: colors.ink, fontSize: 20, fontWeight: "800" }, body: { color: colors.muted, lineHeight: 21, marginTop: 7 }, link: { color: colors.green, fontWeight: "800", marginTop: 13 } });
