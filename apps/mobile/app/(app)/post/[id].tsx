import { useState } from "react";
import { Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { api } from "@/api";
import { Badge, Card, Empty, ErrorState, Loading, ProtectedImage, Screen, common, formatDate } from "@/components";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Comment, Content, Page } from "@/types";

export default function PostDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const detail = useResource<{ post: Content }>(`/posts/${id}`);
  const comments = useResource<Page<Comment>>(`/posts/${id}/comments?page=1&limit=50`);
  const [text, setText] = useState(""); const [error, setError] = useState<unknown>(); const [busy, setBusy] = useState(false);
  async function send() { setBusy(true); setError(undefined); try { await api(`/posts/${id}/comments`, "POST", { content: text }); setText(""); await comments.reload(); } catch (reason) { setError(reason); } finally { setBusy(false); } }
  if (detail.loading) return <Screen><Loading /></Screen>;
  if (detail.error) return <Screen><ErrorState error={detail.error} retry={detail.reload} /></Screen>;
  const post = detail.data?.post;
  if (!post) return <Screen><Empty>Publicação indisponível.</Empty></Screen>;
  return <Screen>
    <ProtectedImage image={post.images?.[0]} detail accessibilityLabel={`Imagem de ${post.title}`} />
    <View style={styles.meta}><Badge value={post.visibility} /><Badge value={post.status} /></View>
    <Text accessibilityRole="header" style={styles.title}>{post.title}</Text><Text style={styles.body}>{post.content}</Text>
    <Text style={styles.section}>Comentários</Text>
    {comments.loading ? <Loading /> : comments.error ? <ErrorState error={comments.error} retry={comments.reload} /> : comments.data?.items.length ? comments.data.items.map((comment) => <Card key={comment.id}><Text style={styles.commentAuthor}>Membro #{comment.user_id} · {formatDate(comment.created_at)}</Text><Text style={styles.comment}>{comment.content}</Text></Card>) : <Empty>Seja o primeiro a comentar.</Empty>}
    {error ? <ErrorState error={error} /> : null}
    {post.comments_enabled ? <View><Text style={common.label}>Deixe seu comentário</Text><TextInput accessibilityLabel="Deixe seu comentário" multiline value={text} onChangeText={setText} style={[common.input, common.textarea]} maxLength={5000} /><Pressable disabled={busy || !text.trim()} onPress={send} style={common.button}><Text style={common.buttonText}>{busy ? "Enviando…" : "Comentar"}</Text></Pressable></View> : <Text style={styles.closed}>Novos comentários estão fechados.</Text>}
  </Screen>;
}

const styles = StyleSheet.create({ meta: { flexDirection: "row", gap: 7, marginBottom: 13 }, title: { color: colors.ink, fontSize: 29, lineHeight: 36, fontWeight: "900" }, body: { color: colors.ink, fontSize: 16, lineHeight: 25, marginTop: 15, marginBottom: 28 }, section: { color: colors.ink, fontSize: 20, fontWeight: "800", marginBottom: 12 }, commentAuthor: { color: colors.muted, fontSize: 12, fontWeight: "700" }, comment: { color: colors.ink, lineHeight: 22, marginTop: 7 }, closed: { color: colors.muted, textAlign: "center", padding: 15 } });
