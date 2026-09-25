import { useState } from "react";

import { Alert, Pressable, StyleSheet, Text, View } from "react-native";

import { Ionicons } from "@expo/vector-icons";

import { api } from "@/api";
import { ErrorState } from "@/components";
import { useSession } from "@/session";
import { colors } from "@/theme";

type Props = {
  post: {
    id: number;
    status: string;
  };

  onChanged?: () => void | Promise<void>;
};

type Action = "publish" | "unpublish" | "submit-review";

export function PostManagementActions({ post, onChanged }: Props) {
  const { user } = useSession();

  const [busy, setBusy] = useState<Action | null>(null);

  const [error, setError] = useState<unknown>();

  const isGlobalManager = user?.role === "ADMIN" || user?.role === "PASTOR";

  const isLeader = user?.role === "LEADER";

  async function execute(action: Action) {
    if (busy) {
      return;
    }

    setBusy(action);
    setError(undefined);

    try {
      await api(`/posts/${post.id}/${action}`, "POST");

      await onChanged?.();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(null);
    }
  }

  function confirm(action: Action) {
    const content = {
      publish: {
        title:
          post.status === "PENDING_REVIEW" ? "Aprovar publicação" : "Publicar",
        message: "A publicação ficará disponível para o público selecionado.",
        confirm:
          post.status === "PENDING_REVIEW" ? "Aprovar e publicar" : "Publicar",
      },

      "submit-review": {
        title: "Enviar para revisão",
        message:
          "A publicação será enviada para revisão de um Pastor ou Administrador.",
        confirm: "Enviar para revisão",
      },

      unpublish: {
        title: "Retirar de publicação",
        message:
          "A publicação deixará de aparecer para os membros e voltará para rascunho.",
        confirm: "Retirar",
      },
    }[action];

    Alert.alert(content.title, content.message, [
      {
        text: "Cancelar",
        style: "cancel",
      },

      {
        text: content.confirm,

        style: action === "unpublish" ? "destructive" : "default",

        onPress: () => {
          void execute(action);
        },
      },
    ]);
  }

  /*
   * Conteúdo arquivado não possui
   * transição disponível nesta área.
   */
  if (post.status === "ARCHIVED") {
    return null;
  }

  /*
   * ADMIN / PASTOR
   *
   * DRAFT
   * -> publicar
   *
   * PENDING_REVIEW
   * -> aprovar e publicar
   *
   * PUBLISHED
   * -> retirar de publicação
   */
  if (isGlobalManager) {
    return (
      <View style={styles.container}>
        {error ? <ErrorState error={error} /> : null}

        {post.status === "DRAFT" ? (
          <ActionButton
            icon="cloud-upload-outline"
            label="Publicar"
            busy={busy === "publish"}
            disabled={!!busy}
            primary
            onPress={() => confirm("publish")}
          />
        ) : null}

        {post.status === "PENDING_REVIEW" ? (
          <ActionButton
            icon="checkmark-circle-outline"
            label="Aprovar e publicar"
            busy={busy === "publish"}
            disabled={!!busy}
            primary
            onPress={() => confirm("publish")}
          />
        ) : null}

        {post.status === "PUBLISHED" ? (
          <ActionButton
            icon="eye-off-outline"
            label="Retirar de publicação"
            busy={busy === "unpublish"}
            disabled={!!busy}
            onPress={() => confirm("unpublish")}
          />
        ) : null}
      </View>
    );
  }

  /*
   * LÍDER
   *
   * DRAFT
   * -> enviar para revisão
   *
   * PENDING_REVIEW
   * -> aguardando revisão
   *
   * PUBLISHED
   * -> pode retirar de publicação
   *    desde que administre o ministério.
   *
   * A API continua sendo a autoridade
   * final sobre a permissão.
   */
  if (isLeader) {
    return (
      <View style={styles.container}>
        {error ? <ErrorState error={error} /> : null}

        {post.status === "DRAFT" ? (
          <ActionButton
            icon="send-outline"
            label="Enviar para revisão"
            busy={busy === "submit-review"}
            disabled={!!busy}
            primary
            onPress={() => confirm("submit-review")}
          />
        ) : null}

        {post.status === "PENDING_REVIEW" ? (
          <View style={styles.reviewBox}>
            <Ionicons name="time-outline" size={19} color={colors.green} />

            <View style={styles.reviewText}>
              <Text style={styles.reviewTitle}>Aguardando revisão</Text>

              <Text style={styles.reviewDescription}>
                Um Pastor ou Administrador poderá aprovar e publicar este
                conteúdo.
              </Text>
            </View>
          </View>
        ) : null}

        {post.status === "PUBLISHED" ? (
          <ActionButton
            icon="eye-off-outline"
            label="Retirar de publicação"
            busy={busy === "unpublish"}
            disabled={!!busy}
            onPress={() => confirm("unpublish")}
          />
        ) : null}
      </View>
    );
  }

  return null;
}

function ActionButton({
  icon,
  label,
  busy,
  disabled,
  primary = false,
  onPress,
}: {
  icon:
    | "cloud-upload-outline"
    | "checkmark-circle-outline"
    | "send-outline"
    | "eye-off-outline";

  label: string;
  busy: boolean;
  disabled: boolean;
  primary?: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.button,

        primary ? styles.primaryButton : styles.secondaryButton,

        disabled && styles.disabled,

        pressed && !disabled && styles.pressed,
      ]}
    >
      <Ionicons
        name={icon}
        size={19}
        color={primary ? colors.white : colors.green}
      />

      <Text
        style={[
          styles.buttonText,

          primary ? styles.primaryText : styles.secondaryText,
        ]}
      >
        {busy ? "Processando…" : label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: {
    marginTop: 10,
    gap: 8,
  },

  button: {
    minHeight: 48,
    borderRadius: 11,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    paddingHorizontal: 14,
  },

  primaryButton: {
    backgroundColor: colors.green,
  },

  secondaryButton: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },

  buttonText: {
    fontSize: 14,
    fontWeight: "800",
    textAlign: "center",
  },

  primaryText: {
    color: colors.white,
  },

  secondaryText: {
    color: colors.green,
  },

  reviewBox: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 9,
    padding: 12,
    borderRadius: 11,
    backgroundColor: colors.greenSoft,
  },

  reviewText: {
    flex: 1,
  },

  reviewTitle: {
    color: colors.greenDark,
    fontWeight: "800",
    marginBottom: 2,
  },

  reviewDescription: {
    color: colors.muted,
    fontSize: 12,
    lineHeight: 17,
  },

  disabled: {
    opacity: 0.5,
  },

  pressed: {
    opacity: 0.7,
  },
});
