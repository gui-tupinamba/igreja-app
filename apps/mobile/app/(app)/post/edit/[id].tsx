import { useEffect, useMemo, useRef, useState } from "react";

import {
  Pressable,
  StyleSheet,
  Switch,
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
  ErrorState,
  Heading,
  Loading,
  Screen,
  common,
} from "@/components";

import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content, Ministry, Page, Permissions } from "@/types";

type Visibility = "PUBLIC" | "MINISTRY_MEMBERS";

type Destination = {
  id: number;
  name: string;
};

export default function EditPost() {
  const { id } = useLocalSearchParams<{
    id: string;
  }>();

  /*
   * Usamos a rota administrativa porque
   * uma publicação em edição pode estar como:
   *
   * DRAFT
   * PENDING_REVIEW
   * PUBLISHED
   * ARCHIVED
   */
  const detail = useResource<{
    post: Content;
  }>(`/admin/posts/${id}`);

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const ministries = useResource<Page<Ministry>>(
    "/ministries?page=1&limit=100",
  );

  const initializedPost = useRef<number | null>(null);

  const [title, setTitle] = useState("");

  const [content, setContent] = useState("");

  const [ministryId, setMinistryId] = useState<number | null | undefined>(
    undefined,
  );

  const [visibility, setVisibility] = useState<Visibility>("PUBLIC");

  const [commentsEnabled, setCommentsEnabled] = useState(true);

  const [busy, setBusy] = useState(false);

  const [error, setError] = useState<unknown>();

  const post = detail.data?.post;

  const permissions = access.data?.permissions;

  /*
   * ADMIN / PASTOR:
   * pode gerenciar qualquer publicação.
   *
   * LÍDER:
   * somente publicação vinculada a um
   * ministério que ele lidera.
   */
  const canEdit =
    !!post &&
    !!permissions &&
    (permissions.manage_ministries ||
      (post.ministry_id !== null &&
        permissions.led_ministries.some(
          (ministry) => ministry.id === post.ministry_id,
        )));

  /*
   * Destinos disponíveis para alteração.
   */
  const destinations = useMemo<Destination[]>(() => {
    if (!permissions) {
      return [];
    }

    let available: Destination[] = permissions.manage_ministries
      ? (ministries.data?.items || []).map((ministry) => ({
          id: ministry.id,
          name: ministry.name,
        }))
      : permissions.led_ministries.map((ministry) => ({
          id: ministry.id,
          name: ministry.name,
        }));

    /*
     * Se o post pertence a um ministério
     * antigo/inativo que não está mais
     * na listagem atual, mantemos o destino
     * para não obrigar uma alteração.
     */
    if (
      post?.ministry_id &&
      !available.some((ministry) => ministry.id === post.ministry_id)
    ) {
      available = [
        ...available,
        {
          id: post.ministry_id,
          name: "Ministério de origem (histórico)",
        },
      ];
    }

    return available;
  }, [permissions, ministries.data, post?.ministry_id]);

  /*
   * Carrega os valores atuais do post
   * somente uma vez.
   */
  useEffect(() => {
    if (!post) {
      return;
    }

    if (initializedPost.current === post.id) {
      return;
    }

    initializedPost.current = post.id;

    setTitle(post.title);

    setContent(post.content || "");

    setMinistryId(post.ministry_id);

    setVisibility(
      post.visibility === "MINISTRY_MEMBERS" ? "MINISTRY_MEMBERS" : "PUBLIC",
    );

    setCommentsEnabled(post.comments_enabled ?? true);
  }, [post]);

  /*
   * Publicação geral da igreja não pode
   * ser privada para integrantes de
   * ministério.
   */
  useEffect(() => {
    if (ministryId === null && visibility === "MINISTRY_MEMBERS") {
      setVisibility("PUBLIC");
    }
  }, [ministryId, visibility]);

  async function save() {
    if (busy || !post) {
      return;
    }

    const cleanTitle = title.trim();

    const cleanContent = content.trim();

    if (!cleanTitle) {
      setError(new Error("Informe o título da publicação."));

      return;
    }

    if (!cleanContent) {
      setError(new Error("Informe o conteúdo da publicação."));

      return;
    }

    if (ministryId === undefined) {
      setError(new Error("Escolha onde a publicação será exibida."));

      return;
    }

    if (ministryId === null && visibility === "MINISTRY_MEMBERS") {
      setError(
        new Error(
          "Uma publicação para integrantes precisa estar vinculada a um ministério.",
        ),
      );

      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      /*
       * O PATCH altera apenas os dados.
       *
       * Ele NÃO muda o status da publicação.
       *
       * Exemplo:
       *
       * PUBLISHED continua PUBLISHED.
       * DRAFT continua DRAFT.
       */
      await api<{
        post: Content;
      }>(`/posts/${post.id}`, "PATCH", {
        title: cleanTitle,

        content: cleanContent,

        ministry_id: ministryId,

        visibility,

        comments_enabled: commentsEnabled,
      });

      /*
       * Voltamos ao detalhe administrativo.
       */
      router.replace({
        pathname: "/(app)/post/[id]",

        params: {
          id: post.id,
          manage: "1",
        },
      });
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  if (detail.loading || access.loading || ministries.loading) {
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

  if (access.error) {
    return (
      <Screen>
        <ErrorState error={access.error} retry={access.reload} />
      </Screen>
    );
  }

  if (ministries.error) {
    return (
      <Screen>
        <ErrorState error={ministries.error} retry={ministries.reload} />
      </Screen>
    );
  }

  if (!post) {
    return (
      <Screen>
        <ErrorState error={new Error("Publicação não encontrada.")} />
      </Screen>
    );
  }

  if (!canEdit) {
    return (
      <Screen>
        <ErrorState
          error={
            new Error("Você não possui permissão para editar esta publicação.")
          }
        />
      </Screen>
    );
  }

  return (
    <Screen>
      <Heading
        title="Editar publicação"
        subtitle="Altere as informações da publicação."
      />

      {/* STATUS ATUAL */}

      <View style={styles.statusArea}>
        <Text style={styles.statusLabel}>Estado atual</Text>

        <Badge value={post.status} />
      </View>

      {post.status === "PUBLISHED" ? (
        <View style={styles.warningBox}>
          <Ionicons
            name="information-circle-outline"
            size={21}
            color={colors.green}
          />

          <Text style={styles.warningText}>
            Esta publicação já está publicada. As alterações ficarão visíveis
            após salvar.
          </Text>
        </View>
      ) : null}

      {post.status === "PENDING_REVIEW" ? (
        <View style={styles.warningBox}>
          <Ionicons name="time-outline" size={21} color={colors.green} />

          <Text style={styles.warningText}>
            Esta publicação está aguardando revisão.
          </Text>
        </View>
      ) : null}

      {post.status === "ARCHIVED" ? (
        <View style={styles.warningBox}>
          <Ionicons name="archive-outline" size={21} color={colors.muted} />

          <Text style={styles.warningText}>
            Esta publicação está arquivada. Você ainda pode alterar seus dados.
          </Text>
        </View>
      ) : null}

      {error ? <ErrorState error={error} /> : null}

      <Card>
        {/* TÍTULO */}

        <Text style={common.label}>Título</Text>

        <TextInput
          accessibilityLabel="Título"
          value={title}
          onChangeText={setTitle}
          style={common.input}
          maxLength={180}
          placeholder="Título da publicação"
          placeholderTextColor={colors.muted}
        />

        {/* DESTINO */}

        <Text style={common.label}>Publicar em</Text>

        <View style={styles.choices}>
          {permissions?.manage_ministries ? (
            <Choice
              label="Igreja em geral"
              icon="people-outline"
              selected={ministryId === null}
              onPress={() => {
                setMinistryId(null);
                setVisibility("PUBLIC");
              }}
            />
          ) : null}

          {destinations.map((ministry) => (
            <Choice
              key={ministry.id}
              label={ministry.name}
              icon="people-circle-outline"
              selected={ministryId === ministry.id}
              onPress={() => setMinistryId(ministry.id)}
            />
          ))}
        </View>

        {/* VISIBILIDADE */}

        <Text style={common.label}>Quem pode ver</Text>

        <View style={styles.choices}>
          <Choice
            label="Toda a igreja"
            icon="earth-outline"
            selected={visibility === "PUBLIC"}
            onPress={() => setVisibility("PUBLIC")}
          />

          {ministryId !== null && ministryId !== undefined ? (
            <Choice
              label="Integrantes do ministério"
              icon="lock-closed-outline"
              selected={visibility === "MINISTRY_MEMBERS"}
              onPress={() => setVisibility("MINISTRY_MEMBERS")}
            />
          ) : null}
        </View>

        {/* CONTEÚDO */}

        <Text style={common.label}>Conteúdo</Text>

        <TextInput
          accessibilityLabel="Conteúdo"
          value={content}
          onChangeText={setContent}
          style={[common.input, common.textarea, styles.content]}
          maxLength={50000}
          multiline
          placeholder="Escreva a publicação"
          placeholderTextColor={colors.muted}
          textAlignVertical="top"
        />

        <Text style={styles.characterCount}>
          {content.length.toLocaleString("pt-BR")} / 50.000
        </Text>

        {/* COMENTÁRIOS */}

        <View style={styles.switchRow}>
          <View style={styles.switchText}>
            <Text style={styles.switchTitle}>Permitir comentários</Text>

            <Text style={styles.switchHelp}>
              Os membros poderão comentar enquanto a publicação estiver
              publicada.
            </Text>
          </View>

          <Switch
            value={commentsEnabled}
            onValueChange={setCommentsEnabled}
            trackColor={{
              false: colors.border,
              true: colors.green,
            }}
          />
        </View>

        {/* SALVAR */}

        <Pressable
          accessibilityRole="button"
          disabled={busy}
          onPress={save}
          style={({ pressed }) => [
            common.button,

            busy && styles.disabled,

            pressed && !busy && styles.pressed,
          ]}
        >
          <Ionicons name="save-outline" size={19} color={colors.white} />

          <Text style={[common.buttonText, styles.buttonText]}>
            {busy ? "Salvando…" : "Salvar alterações"}
          </Text>
        </Pressable>

        {/* CANCELAR */}

        <Pressable
          accessibilityRole="button"
          disabled={busy}
          onPress={() => router.back()}
          style={({ pressed }) => [
            styles.cancelButton,

            pressed && !busy && styles.pressed,
          ]}
        >
          <Text style={styles.cancelText}>Cancelar</Text>
        </Pressable>
      </Card>
    </Screen>
  );
}

function Choice({
  label,
  icon,
  selected,
  onPress,
}: {
  label: string;

  icon:
    | "people-outline"
    | "people-circle-outline"
    | "earth-outline"
    | "lock-closed-outline";

  selected: boolean;

  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{
        checked: selected,
      }}
      onPress={onPress}
      style={({ pressed }) => [
        styles.choice,

        selected && styles.choiceSelected,

        pressed && styles.pressed,
      ]}
    >
      <Ionicons
        name={icon}
        size={17}
        color={selected ? colors.greenDark : colors.muted}
      />

      <Text style={[styles.choiceText, selected && styles.choiceTextSelected]}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  statusArea: {
    flexDirection: "row",
    alignItems: "center",
    gap: 9,
    marginBottom: 12,
  },

  statusLabel: {
    color: colors.muted,
    fontSize: 13,
    fontWeight: "700",
  },

  warningBox: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 9,
    padding: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
    marginBottom: 13,
  },

  warningText: {
    flex: 1,
    color: colors.muted,
    fontSize: 12,
    lineHeight: 18,
    fontWeight: "600",
  },

  choices: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
    marginBottom: 18,
  },

  choice: {
    minHeight: 42,
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    justifyContent: "center",
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 999,
    backgroundColor: colors.white,
    paddingHorizontal: 14,
    paddingVertical: 8,
  },

  choiceSelected: {
    borderColor: colors.green,
    backgroundColor: colors.greenSoft,
  },

  choiceText: {
    color: colors.muted,
    fontWeight: "700",
  },

  choiceTextSelected: {
    color: colors.greenDark,
  },

  content: {
    minHeight: 190,
  },

  characterCount: {
    color: colors.muted,
    fontSize: 11,
    textAlign: "right",
    marginTop: -10,
    marginBottom: 18,
  },

  switchRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: 16,
    marginBottom: 20,
  },

  switchText: {
    flex: 1,
  },

  switchTitle: {
    color: colors.ink,
    fontWeight: "800",
    marginBottom: 3,
  },

  switchHelp: {
    color: colors.muted,
    fontSize: 12,
    lineHeight: 17,
  },

  buttonText: {
    marginLeft: 7,
  },

  cancelButton: {
    minHeight: 50,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 7,
  },

  cancelText: {
    color: colors.green,
    fontSize: 16,
    fontWeight: "800",
  },

  disabled: {
    opacity: 0.55,
  },

  pressed: {
    opacity: 0.7,
  },
});
