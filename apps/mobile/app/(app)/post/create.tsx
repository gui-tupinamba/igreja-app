import { useEffect, useMemo, useState } from "react";

import {
  Pressable,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
} from "react-native";

import { router } from "expo-router";

import { Ionicons } from "@expo/vector-icons";

import { api } from "@/api";
import { apiForm } from "@/apiForm";

import {
  Card,
  ErrorState,
  Heading,
  Loading,
  Screen,
  common,
} from "@/components";

import {
  PostImagePicker,
  postImageFileName,
  postImageMimeType,
  type SelectedPostImage,
} from "@/PostImagePicker";

import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content, Ministry, Page, Permissions } from "@/types";

type SaveAction = "draft" | "publish" | "review";

type Visibility = "PUBLIC" | "MINISTRY_MEMBERS";

export default function CreatePost() {
  const { user } = useSession();

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const ministries = useResource<Page<Ministry>>(
    "/ministries?page=1&limit=100",
  );

  const [title, setTitle] = useState("");

  const [content, setContent] = useState("");

  const [ministryId, setMinistryId] = useState<number | null | undefined>();

  const [visibility, setVisibility] = useState<Visibility>("PUBLIC");

  const [commentsEnabled, setCommentsEnabled] = useState(true);

  const [images, setImages] = useState<SelectedPostImage[]>([]);

  const [busy, setBusy] = useState(false);

  const [error, setError] = useState<unknown>();

  const [saved, setSaved] = useState<SaveAction>();

  const permissions = access.data?.permissions;

  const canCreate =
    !!permissions &&
    (permissions.manage_ministries || permissions.led_ministries.length > 0);

  const canPublish = user?.role === "ADMIN" || user?.role === "PASTOR";

  const destinations = useMemo(() => {
    if (!permissions) {
      return [];
    }

    if (permissions.manage_ministries) {
      return ministries.data?.items || [];
    }

    return permissions.led_ministries;
  }, [permissions, ministries.data]);

  useEffect(() => {
    if (ministryId === undefined && permissions) {
      setMinistryId(
        permissions.manage_ministries
          ? null
          : permissions.led_ministries[0]?.id,
      );
    }
  }, [ministryId, permissions]);

  useEffect(() => {
    if (ministryId === null && visibility === "MINISTRY_MEMBERS") {
      setVisibility("PUBLIC");
    }
  }, [ministryId, visibility]);

  async function uploadImages(postId: number) {
    for (let index = 0; index < images.length; index++) {
      const image = images[index];

      const mimeType = postImageMimeType(image);

      if (!mimeType) {
        throw new Error("Uma das imagens possui formato inválido.");
      }

      const form = new FormData();

      /*
       * O React Native entende este objeto
       * como um arquivo multipart.
       */
      form.append("file", {
        uri: image.uri,

        name: postImageFileName(image, index),

        type: mimeType,
      } as unknown as Blob);

      await apiForm(`/posts/${postId}/images`, form);
    }
  }

  async function save(action: SaveAction) {
    const cleanTitle = title.trim();

    const cleanContent = content.trim();

    if (!cleanTitle || !cleanContent) {
      setError(new Error("Informe o título e o conteúdo da publicação."));

      return;
    }

    if (ministryId === undefined) {
      setError(new Error("Escolha onde a publicação será criada."));

      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      /*
       * Primeiro criamos como DRAFT.
       */
      const result = await api<{
        post: Content;
      }>("/posts", "POST", {
        title: cleanTitle,

        content: cleanContent,

        ministry_id: ministryId,

        visibility,

        comments_enabled: commentsEnabled,
      });

      /*
       * Depois enviamos as imagens.
       *
       * Assim o backend já possui o ID
       * da publicação.
       */
      if (images.length > 0) {
        await uploadImages(result.post.id);
      }

      /*
       * Só alteramos o status depois que
       * as imagens foram enviadas.
       */
      if (action === "publish") {
        await api(`/posts/${result.post.id}/publish`, "POST");
      }

      if (action === "review") {
        await api(`/posts/${result.post.id}/submit-review`, "POST");
      }

      if (action === "publish") {
        router.replace({
          pathname: "/(app)/post/[id]",

          params: {
            id: String(result.post.id),

            manage: "1",
          },
        });

        return;
      }

      setSaved(action);
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  if (access.loading || ministries.loading) {
    return (
      <Screen>
        <Loading />
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

  if (!canCreate) {
    return (
      <Screen>
        <ErrorState
          error={new Error("Você não possui permissão para criar publicações.")}
        />
      </Screen>
    );
  }

  if (saved) {
    return (
      <Screen>
        <Heading
          title="Publicação salva"
          subtitle={
            saved === "review"
              ? "A publicação foi enviada para revisão."
              : "O rascunho foi salvo."
          }
        />

        <Card>
          <View style={styles.savedIcon}>
            <Ionicons
              name={saved === "review" ? "time-outline" : "save-outline"}
              size={30}
              color={colors.green}
            />
          </View>

          <Text style={styles.savedText}>
            {saved === "review"
              ? "Um administrador ou pastor poderá revisar e publicar o conteúdo."
              : "Você poderá continuar o gerenciamento pela área administrativa."}
          </Text>

          <Pressable
            onPress={() => router.replace("/(app)/(tabs)/feed")}
            style={common.button}
          >
            <Text style={common.buttonText}>Voltar às publicações</Text>
          </Pressable>
        </Card>
      </Screen>
    );
  }

  return (
    <Screen>
      <Heading
        title="Nova publicação"
        subtitle={
          canPublish
            ? "Crie um rascunho ou publique agora."
            : "Crie para seu ministério e envie para revisão."
        }
      />

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
            selected={visibility === "PUBLIC"}
            onPress={() => setVisibility("PUBLIC")}
          />

          {ministryId ? (
            <Choice
              label="Integrantes do ministério"
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

        {/* IMAGENS */}

        <PostImagePicker value={images} onChange={setImages} disabled={busy} />

        {/* COMENTÁRIOS */}

        <View style={styles.switchRow}>
          <View style={styles.switchText}>
            <Text style={styles.switchTitle}>Permitir comentários</Text>

            <Text style={styles.switchHelp}>
              Os membros poderão comentar após a publicação.
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

        {/* PRINCIPAL */}

        <Pressable
          disabled={busy}
          onPress={() => save(canPublish ? "publish" : "review")}
          style={({ pressed }) => [
            common.button,

            busy && styles.disabled,

            pressed && !busy && styles.pressed,
          ]}
        >
          <Ionicons
            name={canPublish ? "cloud-upload-outline" : "send-outline"}
            size={19}
            color={colors.white}
          />

          <Text style={[common.buttonText, styles.buttonText]}>
            {busy
              ? images.length > 0
                ? "Salvando e enviando imagens…"
                : "Salvando…"
              : canPublish
                ? "Salvar e publicar"
                : "Enviar para revisão"}
          </Text>
        </Pressable>

        {/* RASCUNHO */}

        <Pressable
          disabled={busy}
          onPress={() => save("draft")}
          style={({ pressed }) => [
            styles.secondaryButton,

            busy && styles.disabled,

            pressed && !busy && styles.pressed,
          ]}
        >
          <Ionicons name="save-outline" size={19} color={colors.green} />

          <Text style={styles.secondaryText}>Salvar rascunho</Text>
        </Pressable>
      </Card>
    </Screen>
  );
}

function Choice({
  label,
  selected,
  onPress,
}: {
  label: string;
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
      <Text style={[styles.choiceText, selected && styles.choiceTextSelected]}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  choices: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
    marginBottom: 18,
  },

  choice: {
    minHeight: 42,
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
    minHeight: 180,
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

  secondaryButton: {
    minHeight: 50,
    justifyContent: "center",
    alignItems: "center",
    flexDirection: "row",
    gap: 7,
    marginTop: 8,
  },

  secondaryText: {
    color: colors.green,
    fontSize: 16,
    fontWeight: "800",
  },

  savedIcon: {
    alignItems: "center",
    marginBottom: 14,
  },

  savedText: {
    color: colors.muted,
    lineHeight: 22,
    marginBottom: 20,
    textAlign: "center",
  },

  disabled: {
    opacity: 0.55,
  },

  pressed: {
    opacity: 0.7,
  },
});
