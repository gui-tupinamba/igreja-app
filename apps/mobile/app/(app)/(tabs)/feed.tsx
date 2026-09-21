import { useState } from "react";
import { Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { router } from "expo-router";
import { Badge, Card, Empty, ErrorState, Heading, Loading, ProtectedImage, Screen } from "@/components";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Content, Page } from "@/types";

export default function Feed() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const resource = useResource<Page<Content>>(`/posts?page=${page}&limit=10${query ? `&q=${encodeURIComponent(query)}` : ""}`);
  const pages = Math.max(1, Math.ceil((resource.data?.pagination.total || 0) / 10));
  return <Screen>
    <Heading title="Publicações" subtitle="Notícias, palavras e momentos da nossa comunidade." />
    <View style={styles.search}><TextInput accessibilityLabel="Buscar publicações" placeholder="Buscar por título ou conteúdo" value={search} onChangeText={setSearch} onSubmitEditing={() => { setPage(1); setQuery(search.trim()); }} style={styles.searchInput} returnKeyType="search" /><Pressable accessibilityRole="button" onPress={() => { setPage(1); setQuery(search.trim()); }} style={styles.searchButton}><Text style={styles.searchButtonText}>Buscar</Text></Pressable></View>
    {resource.loading ? <Loading /> : resource.error ? <ErrorState error={resource.error} retry={resource.reload} /> : resource.data?.items.length ? resource.data.items.map((item) => <Pressable key={item.id} onPress={() => router.push({ pathname: "/(app)/post/[id]", params: { id: item.id } })}><Card><ProtectedImage image={item.images?.[0]} accessibilityLabel={`Imagem de ${item.title}`} /><View style={styles.meta}><Badge value={item.visibility} /></View><Text style={styles.title}>{item.title}</Text><Text numberOfLines={4} style={styles.body}>{item.content}</Text><Text style={styles.open}>Ler publicação →</Text></Card></Pressable>) : <Empty>Nenhuma publicação encontrada.</Empty>}
    {resource.data && pages > 1 ? <View style={styles.pager}><Pressable disabled={page === 1} onPress={() => setPage((value) => value - 1)}><Text style={[styles.open, page === 1 && styles.disabled]}>← Anterior</Text></Pressable><Text style={styles.page}>Página {page} de {pages}</Text><Pressable disabled={page === pages} onPress={() => setPage((value) => value + 1)}><Text style={[styles.open, page === pages && styles.disabled]}>Próxima →</Text></Pressable></View> : null}
  </Screen>;
}

const styles = StyleSheet.create({
  search: { flexDirection: "row", marginBottom: 17, gap: 8 }, searchInput: { flex: 1, minHeight: 48, paddingHorizontal: 13, borderRadius: 12, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.white, color: colors.ink }, searchButton: { minWidth: 72, justifyContent: "center", alignItems: "center", borderRadius: 12, backgroundColor: colors.green }, searchButtonText: { color: colors.white, fontWeight: "800" }, meta: { flexDirection: "row" }, title: { color: colors.ink, fontSize: 20, lineHeight: 26, fontWeight: "800", marginTop: 10 }, body: { color: colors.muted, lineHeight: 21, marginTop: 7 }, open: { color: colors.green, fontWeight: "800", marginTop: 12 }, pager: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", marginTop: 8 }, page: { color: colors.muted, fontSize: 12 }, disabled: { opacity: 0.35 },
});
