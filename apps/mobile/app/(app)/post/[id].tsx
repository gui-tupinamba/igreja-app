import { useState } from "react";

import {
  Alert,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";

import { router, useLocalSearchParams } from "expo-router";

import { Ionicons } from "@expo/vector-icons";

import { api } from "@/api";

import {
  Badge,
  Card,
  Empty,
  ErrorState,
  Loading,
  ProtectedImage,
  Screen,
  common,
  formatDate,
} from "@/components";

import { PostManagementActions } from "@/PostManagementActions";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Comment, Content, Page, Permissions } from "@/types";

export default function PostDetail() {
  const { id, manage } = useLocalSearchParams<{
    id: string;
    manage?: string;
  }>();

  /*
   * Se o post foi aberto através da área
   * Gerenciar, usamos os endpoints administrativos.
   */
  const administrative = manage === "1";

  const detailPath = administrative ? `/admin/posts/${id}` : `/posts/${id}`;

  const commentsPath = administrative
    ? `/admin/posts/${id}/comments?page=1&limit=50`
    : `/posts/${id}/comments?page=1&limit=50`;

  const detail = useResource<{
    post: Content;
  }>(detailPath);

  const comments = useResource<Page<Comment>>(commentsPath);

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const [text, setText] = useState("");

  const [error, setError] = useState<unknown>();

  const [busy, setBusy] = useState(false);

  const [archiving, setArchiving] = useState(false);

  const post = detail.data?.post;

  /*
   * ADMIN / PASTOR:
   * pode administrar qualquer publicação.
   *
   * LÍDER:
   * somente publicações dos ministérios
   * que lidera.
   */
  const canManagePost =
    !!post &&
    !!access.data &&
    (access.data.permissions.manage_ministries ||
      (post.ministry_id !== null &&
        access.data.permissions.led_ministries.some(
          (ministry) => ministry.id === post.ministry_id,
        )));

  /*
   * Moderação de comentários pertence aos
   * gestores globais.
   */
  const canModerateComments =
    !!access.data?.permissions.manage_ministries && administrative;

  async function send() {
    const cleanText = text.trim();

    if (!cleanText || busy) {
      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      await api(`/posts/${id}/comments`, "POST", {
        content: cleanText,
      });

      setText("");

      await comments.reload();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  async function archivePost() {
    if (!post || archiving) {
      return;
    }

    setArchiving(true);
    setError(undefined);

    try {
      /*
       * O DELETE do backend arquiva.
       * Ele não remove fisicamente o registro.
       */
      await api(`/posts/${post.id}`, "DELETE");

      router.replace("/(app)/(tabs)/feed");
    } catch (reason) {
      setError(reason);
    } finally {
      setArchiving(false);
    }
  }

  async function moderateComment(
    comment: Comment,
    status: "VISIBLE" | "HIDDEN" | "DELETED",
  ) {
    setError(undefined);

    try {
      await api(`/admin/posts/${id}/comments/${comment.id}/status`, "PATCH", {
        status,
      });

      await comments.reload();
    } catch (reason) {
      setError(reason);
    }
  }

  if (detail.loading) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  if (detail.error) {
    return (
      <Screen>
        <ErrorState error={detail.error} retry={detail.reload} />
      </Screen>
    );
  }

  if (!post) {
    return (
      <Screen>
        <Empty>Publicação indisponível.</Empty>
      </Screen>
    );
  }

  return (
    <Screen>
      {/* MODO ADMINISTRATIVO */}

      {administrative ? (
        <View style={styles.managementBanner}>
          <Ionicons
            name="shield-checkmark-outline"
            size={19}
            color={colors.green}
          />

          <View style={styles.managementBannerText}>
            <Text style={styles.managementTitle}>Gerenciamento</Text>

            <Text style={styles.managementDescription}>
              Você está visualizando a versão administrativa desta publicação.
            </Text>
          </View>
        </View>
      ) : null}

      {/* IMAGEM */}

      <ProtectedImage
        image={post.images?.[0]}
        detail
        accessibilityLabel={`Imagem de ${post.title}`}
      />

      {/* STATUS */}

      <View style={styles.meta}>
        <Badge value={post.visibility} />

        <Badge value={post.status} />
      </View>

      {/* TÍTULO */}

      <Text accessibilityRole="header" style={styles.title}>
        {post.title}
      </Text>

      {/* CONTEÚDO */}

      <Text style={styles.body}>{post.content}</Text>

      {/* GERENCIAMENTO */}

      {canManagePost ? (
        <View style={styles.managementArea}>
          <Text style={styles.managementSectionTitle}>
            Gerenciar publicação
          </Text>

          {/* EDITAR */}

          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Editar publicação"
            onPress={() =>
              router.push({
                pathname: "/(app)/post/edit/[id]",
                params: {
                  id: String(post.id),
                },
              })
            }
            style={({ pressed }) => [
              styles.editButton,

              pressed && styles.pressed,
            ]}
          >
            <Ionicons name="create-outline" size={19} color={colors.green} />

            <Text style={styles.editButtonText}>Editar publicação</Text>
          </Pressable>

          {/*
           * DRAFT:
           * ADMIN/PASTOR -> Publicar
           * LÍDER -> Enviar para revisão
           *
           * PENDING_REVIEW:
           * ADMIN/PASTOR -> Aprovar e publicar
           *
           * PUBLISHED:
           * ADMIN/PASTOR -> Retirar publicação
           */}
          <PostManagementActions
            post={{
              id: post.id,
              status: post.status,
            }}
            onChanged={async () => {
              await detail.reload();
            }}
          />

          {/* ARQUIVAR */}

          {post.status !== "ARCHIVED" ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Arquivar publicação"
              disabled={archiving}
              onPress={() =>
                Alert.alert(
                  "Arquivar publicação",
                  "A publicação ficará disponível apenas na área de gerenciamento.",
                  [
                    {
                      text: "Cancelar",
                      style: "cancel",
                    },

                    {
                      text: "Arquivar",
                      style: "destructive",

                      onPress: archivePost,
                    },
                  ],
                )
              }
              style={({ pressed }) => [
                styles.archiveButton,

                archiving && styles.disabled,

                pressed && !archiving && styles.pressed,
              ]}
            >
              <Ionicons name="archive-outline" size={19} color={colors.muted} />

              <Text style={styles.archiveButtonText}>
                {archiving ? "Arquivando…" : "Arquivar publicação"}
              </Text>
            </Pressable>
          ) : (
            <View style={styles.archivedBox}>
              <Ionicons name="archive" size={20} color={colors.muted} />

              <Text style={styles.archivedText}>
                Esta publicação está arquivada.
              </Text>
            </View>
          )}
        </View>
      ) : null}

      {/* ERROS */}

      {error ? <ErrorState error={error} /> : null}

      {/* DIVISOR */}

      <View style={styles.divider} />

      {/* COMENTÁRIOS */}

      <View style={styles.sectionHeader}>
        <Ionicons name="chatbubble-outline" size={21} color={colors.ink} />

        <Text style={styles.section}>Comentários</Text>
      </View>

      {administrative && canModerateComments ? (
        <View style={styles.moderationInfo}>
          <Ionicons name="shield-outline" size={17} color={colors.green} />

          <Text style={styles.moderationInfoText}>
            Moderação de comentários ativa.
          </Text>
        </View>
      ) : null}

      {comments.loading ? (
        <Loading />
      ) : comments.error ? (
        <ErrorState error={comments.error} retry={comments.reload} />
      ) : comments.data?.items.length ? (
        comments.data.items.map((comment) => (
          <Card key={comment.id}>
            <View style={styles.commentHeader}>
              <View style={styles.commentAvatar}>
                <Ionicons name="person" size={15} color={colors.green} />
              </View>

              <View style={styles.commentHeaderText}>
                <Text style={styles.commentAuthor}>
                  {comment.user_name || `Membro #${comment.user_id}`}
                </Text>

                <Text style={styles.commentDate}>
                  {formatDate(comment.created_at)}
                </Text>
              </View>

              {comment.status !== "VISIBLE" ? (
                <Badge value={comment.status} />
              ) : null}
            </View>

            <Text style={styles.comment}>{comment.content}</Text>

            {/* MODERAÇÃO */}

            {canModerateComments ? (
              <View style={styles.moderationActions}>
                <Pressable
                  accessibilityRole="button"
                  onPress={() =>
                    moderateComment(
                      comment,

                      comment.status === "HIDDEN" ? "VISIBLE" : "HIDDEN",
                    )
                  }
                  style={({ pressed }) => [
                    styles.moderationButton,

                    pressed && styles.pressed,
                  ]}
                >
                  <Ionicons
                    name={
                      comment.status === "HIDDEN"
                        ? "eye-outline"
                        : "eye-off-outline"
                    }
                    size={17}
                    color={colors.green}
                  />

                  <Text style={styles.moderationButtonText}>
                    {comment.status === "HIDDEN" ? "Restaurar" : "Ocultar"}
                  </Text>
                </Pressable>

                <Pressable
                  accessibilityRole="button"
                  onPress={() =>
                    Alert.alert(
                      "Excluir comentário",
                      "O comentário não poderá ser restaurado pela interface.",
                      [
                        {
                          text: "Cancelar",
                          style: "cancel",
                        },

                        {
                          text: "Excluir",

                          style: "destructive",

                          onPress: () => moderateComment(comment, "DELETED"),
                        },
                      ],
                    )
                  }
                  style={({ pressed }) => [
                    styles.deleteCommentButton,

                    pressed && styles.pressed,
                  ]}
                >
                  <Ionicons
                    name="trash-outline"
                    size={17}
                    color={colors.danger}
                  />

                  <Text style={styles.deleteCommentText}>Excluir</Text>
                </Pressable>
              </View>
            ) : null}
          </Card>
        ))
      ) : (
        <Empty>Nenhum comentário.</Empty>
      )}

      {/* NOVO COMENTÁRIO */}

      {!administrative &&
      post.status === "PUBLISHED" &&
      post.comments_enabled ? (
        <View style={styles.commentForm}>
          <Text style={common.label}>Deixe seu comentário</Text>

          <TextInput
            accessibilityLabel="Deixe seu comentário"
            multiline
            value={text}
            onChangeText={setText}
            style={[common.input, common.textarea, styles.commentInput]}
            maxLength={5000}
            placeholder="Escreva seu comentário..."
            placeholderTextColor={colors.muted}
            textAlignVertical="top"
          />

          <View style={styles.commentCounter}>
            <Text style={styles.commentCounterText}>{text.length} / 5.000</Text>
          </View>

          <Pressable
            accessibilityRole="button"
            disabled={busy || !text.trim()}
            onPress={send}
            style={({ pressed }) => [
              common.button,

              (busy || !text.trim()) && styles.disabled,

              pressed && !busy && !!text.trim() && styles.pressed,
            ]}
          >
            <Ionicons name="send-outline" size={18} color={colors.white} />

            <Text style={[common.buttonText, styles.buttonText]}>
              {busy ? "Enviando…" : "Comentar"}
            </Text>
          </Pressable>
        </View>
      ) : null}

      {!administrative &&
      post.status === "PUBLISHED" &&
      !post.comments_enabled ? (
        <View style={styles.commentsClosed}>
          <Ionicons name="lock-closed-outline" size={20} color={colors.muted} />

          <Text style={styles.closed}>Novos comentários estão fechados.</Text>
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  managementBanner: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    padding: 12,
    marginBottom: 16,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
  },

  managementBannerText: {
    flex: 1,
  },

  managementTitle: {
    color: colors.ink,
    fontWeight: "800",
    marginBottom: 2,
  },

  managementDescription: {
    color: colors.muted,
    fontSize: 12,
    lineHeight: 17,
  },

  meta: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 7,
    marginBottom: 13,
  },

  title: {
    color: colors.ink,
    fontSize: 29,
    lineHeight: 36,
    fontWeight: "900",
  },

  body: {
    color: colors.ink,
    fontSize: 16,
    lineHeight: 25,
    marginTop: 15,
    marginBottom: 10,
  },

  managementArea: {
    marginTop: 18,
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
  },

  managementSectionTitle: {
    color: colors.ink,
    fontSize: 16,
    fontWeight: "900",
    marginBottom: 12,
  },

  editButton: {
    minHeight: 48,
    borderRadius: 11,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    borderWidth: 1,
    borderColor: colors.green,
    backgroundColor: colors.white,
    marginBottom: 2,
  },

  editButtonText: {
    color: colors.green,
    fontWeight: "800",
  },

  archiveButton: {
    minHeight: 48,
    marginTop: 9,
    borderRadius: 11,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
  },

  archiveButtonText: {
    color: colors.muted,
    fontWeight: "800",
  },

  archivedBox: {
    minHeight: 48,
    marginTop: 9,
    paddingHorizontal: 12,
    borderRadius: 11,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    backgroundColor: colors.greenSoft,
  },

  archivedText: {
    color: colors.muted,
    fontWeight: "700",
  },

  divider: {
    height: 1,
    backgroundColor: colors.border,
    marginTop: 28,
    marginBottom: 24,
  },

  sectionHeader: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    marginBottom: 12,
  },

  section: {
    color: colors.ink,
    fontSize: 20,
    fontWeight: "800",
  },

  moderationInfo: {
    flexDirection: "row",
    alignItems: "center",
    gap: 7,
    marginBottom: 12,
    padding: 10,
    borderRadius: 10,
    backgroundColor: colors.greenSoft,
  },

  moderationInfoText: {
    color: colors.greenDark,
    fontSize: 12,
    fontWeight: "700",
  },

  commentHeader: {
    flexDirection: "row",
    alignItems: "center",
    gap: 7,
  },

  commentHeaderText: {
    flex: 1,
  },

  commentAvatar: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: colors.greenSoft,
    alignItems: "center",
    justifyContent: "center",
  },

  commentAuthor: {
    color: colors.ink,
    fontSize: 13,
    fontWeight: "800",
  },

  commentDate: {
    color: colors.muted,
    fontSize: 11,
    marginTop: 2,
  },

  comment: {
    color: colors.ink,
    lineHeight: 22,
    marginTop: 9,
  },

  moderationActions: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
    marginTop: 14,
  },

  moderationButton: {
    minHeight: 38,
    paddingHorizontal: 12,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.border,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
  },

  moderationButtonText: {
    color: colors.green,
    fontWeight: "800",
    fontSize: 13,
  },

  deleteCommentButton: {
    minHeight: 38,
    paddingHorizontal: 12,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: colors.border,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
  },

  deleteCommentText: {
    color: colors.danger,
    fontWeight: "800",
    fontSize: 13,
  },

  commentForm: {
    marginTop: 8,
    marginBottom: 20,
  },

  commentInput: {
    minHeight: 120,
  },

  commentCounter: {
    alignItems: "flex-end",
    marginTop: -10,
    marginBottom: 12,
  },

  commentCounterText: {
    color: colors.muted,
    fontSize: 11,
  },

  commentsClosed: {
    flexDirection: "row",
    justifyContent: "center",
    alignItems: "center",
    gap: 7,
    backgroundColor: colors.greenSoft,
    borderRadius: 12,
    padding: 15,
  },

  closed: {
    color: colors.muted,
    textAlign: "center",
    fontWeight: "700",
  },

  buttonText: {
    marginLeft: 7,
  },

  pressed: {
    opacity: 0.7,
  },

  disabled: {
    opacity: 0.45,
  },
});
