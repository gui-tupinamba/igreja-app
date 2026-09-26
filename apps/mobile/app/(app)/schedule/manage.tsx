import { useState } from "react";
import { router } from "expo-router";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { Ionicons } from "@expo/vector-icons";
import { api } from "@/api";
import {
  Badge,
  Card,
  Empty,
  ErrorState,
  Heading,
  Loading,
  Screen,
  formatDate,
} from "@/components";

import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content, Page } from "@/types";

type StatusFilter =
  | ""
  | "DRAFT"
  | "PENDING_REVIEW"
  | "PUBLISHED"
  | "CANCELLED"
  | "ARCHIVED";

export default function ManageSchedules() {
  const { user } = useSession();

  const [page, setPage] = useState(1);

  const [status, setStatus] = useState<StatusFilter>("");

  const [busyId, setBusyId] = useState<number | null>(null);

  const [error, setError] = useState<unknown>();

  const canPublish = user?.role === "ADMIN" || user?.role === "PASTOR";

  const isLeader = user?.role === "LEADER";

  const path =
    `/admin/schedules?page=${page}&limit=20` +
    (status ? `&status=${status}` : "");

  const resource = useResource<Page<Content>>(path);

  const pages = Math.max(
    1,
    Math.ceil((resource.data?.pagination.total || 0) / 20),
  );

  async function runAction(
    item: Content,
    action: "publish" | "submit-review" | "unpublish" | "cancel" | "archive",
  ) {
    setBusyId(item.id);
    setError(undefined);

    try {
      if (action === "archive") {
        await api(`/schedules/${item.id}`, "DELETE");
      } else {
        await api(`/schedules/${item.id}/${action}`, "POST", {});
      }

      resource.reload();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <Screen>
      <View style={styles.intro}>
        <Text style={styles.eyebrow}>NOSSA COMUNIDADE</Text>

        <Text style={styles.subtitle}>
          Acompanhe rascunhos, revisões e atividades publicadas.
        </Text>
      </View>

      {/* FILTROS */}

      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={styles.filtersScroll}
        contentContainerStyle={styles.filters}
      >
        <Filter
          label="Todos"
          selected={status === ""}
          onPress={() => {
            setStatus("");
            setPage(1);
          }}
        />

        <Filter
          label="Aguardando revisão"
          selected={status === "PENDING_REVIEW"}
          onPress={() => {
            setStatus("PENDING_REVIEW");
            setPage(1);
          }}
        />

        <Filter
          label="Rascunhos"
          selected={status === "DRAFT"}
          onPress={() => {
            setStatus("DRAFT");
            setPage(1);
          }}
        />

        <Filter
          label="Publicados"
          selected={status === "PUBLISHED"}
          onPress={() => {
            setStatus("PUBLISHED");
            setPage(1);
          }}
        />

        <Filter
          label="Cancelados"
          selected={status === "CANCELLED"}
          onPress={() => {
            setStatus("CANCELLED");
            setPage(1);
          }}
        />

        <Filter
          label="Arquivados"
          selected={status === "ARCHIVED"}
          onPress={() => {
            setStatus("ARCHIVED");
            setPage(1);
          }}
        />
      </ScrollView>

      {error ? <ErrorState error={error} /> : null}

      {resource.loading ? (
        <Loading />
      ) : resource.error ? (
        <ErrorState error={resource.error} retry={resource.reload} />
      ) : resource.data?.items.length ? (
        resource.data.items.map((item) => {
          const busy = busyId === item.id;

          return (
            <Card key={item.id}>
              <View style={styles.badges}>
                <Badge value={item.status} />

                <Badge value={item.visibility} />
              </View>

              <Text style={styles.date}>{formatDate(item.starts_at)}</Text>

              <Text style={styles.title}>{item.title}</Text>

              {item.description ? (
                <Text numberOfLines={3} style={styles.description}>
                  {item.description}
                </Text>
              ) : null}

              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`Ver atividade ${item.title}`}
                onPress={() =>
                  router.push({
                    pathname: "/(app)/schedule/[id]",
                    params: {
                      id: String(item.id),
                      manage: "1",
                    },
                  })
                }
                style={({ pressed }) => [
                  styles.viewButton,
                  pressed && styles.pressed,
                ]}
              >
                <Ionicons name="eye-outline" size={18} color={colors.green} />

                <Text style={styles.viewButtonText}>Ver atividade</Text>
              </Pressable>

              {/* ADMIN / PASTOR */}

              {canPublish &&
              (item.status === "DRAFT" || item.status === "PENDING_REVIEW") ? (
                <ActionButton
                  icon="checkmark-circle-outline"
                  label={
                    item.status === "PENDING_REVIEW"
                      ? "Aprovar e publicar"
                      : "Publicar"
                  }
                  disabled={busy}
                  onPress={() => runAction(item, "publish")}
                />
              ) : null}

              {/* LÍDER */}

              {isLeader && item.status === "DRAFT" ? (
                <ActionButton
                  icon="send-outline"
                  label="Enviar para revisão"
                  disabled={busy}
                  onPress={() => runAction(item, "submit-review")}
                />
              ) : null}

              {isLeader && item.status === "PENDING_REVIEW" ? (
                <View style={styles.reviewBox}>
                  <Ionicons
                    name="time-outline"
                    size={18}
                    color={colors.green}
                  />

                  <Text style={styles.reviewText}>
                    Aguardando aprovação de um Pastor ou Administrador.
                  </Text>
                </View>
              ) : null}

              {/* CONTEÚDO PUBLICADO */}

              {canPublish && item.status === "PUBLISHED" ? (
                <>
                  <ActionButton
                    icon="arrow-undo-outline"
                    label="Retirar de publicação"
                    secondary
                    disabled={busy}
                    onPress={() => runAction(item, "unpublish")}
                  />

                  <ActionButton
                    icon="close-circle-outline"
                    label="Cancelar atividade"
                    secondary
                    disabled={busy}
                    onPress={() => runAction(item, "cancel")}
                  />
                </>
              ) : null}

              {/* ARQUIVAR */}

              {canPublish && item.status !== "ARCHIVED" ? (
                <ActionButton
                  icon="archive-outline"
                  label="Arquivar"
                  danger
                  disabled={busy}
                  onPress={() => runAction(item, "archive")}
                />
              ) : null}

              {busy ? <Text style={styles.busyText}>Processando…</Text> : null}
            </Card>
          );
        })
      ) : (
        <Empty>Nenhuma atividade encontrada nesta seleção.</Empty>
      )}

      {resource.data && pages > 1 ? (
        <View style={styles.pager}>
          <Pressable
            disabled={page === 1}
            onPress={() => setPage((value) => value - 1)}
          >
            <Text style={[styles.pageLink, page === 1 && styles.disabled]}>
              ← Anterior
            </Text>
          </Pressable>

          <Text style={styles.pageText}>
            Página {page} de {pages}
          </Text>

          <Pressable
            disabled={page === pages}
            onPress={() => setPage((value) => value + 1)}
          >
            <Text style={[styles.pageLink, page === pages && styles.disabled]}>
              Próxima →
            </Text>
          </Pressable>
        </View>
      ) : null}
    </Screen>
  );
}

function Filter({
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
      onPress={onPress}
      style={[styles.filter, selected && styles.filterSelected]}
    >
      <Text style={[styles.filterText, selected && styles.filterTextSelected]}>
        {label}
      </Text>
    </Pressable>
  );
}

function ActionButton({
  icon,
  label,
  onPress,
  disabled,
  secondary = false,
  danger = false,
}: {
  icon:
    | "checkmark-circle-outline"
    | "send-outline"
    | "arrow-undo-outline"
    | "close-circle-outline"
    | "archive-outline";

  label: string;

  onPress: () => void;

  disabled: boolean;

  secondary?: boolean;

  danger?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.actionButton,

        secondary && styles.secondaryButton,

        danger && styles.dangerButton,

        disabled && styles.disabled,

        pressed && !disabled && styles.pressed,
      ]}
    >
      <Ionicons
        name={icon}
        size={18}
        color={danger ? colors.danger : secondary ? colors.green : colors.white}
      />

      <Text
        style={[
          styles.actionText,

          secondary && styles.secondaryText,

          danger && styles.dangerText,
        ]}
      >
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  viewButton: {
    minHeight: 46,
    borderRadius: 11,

    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",

    gap: 7,

    borderWidth: 1,
    borderColor: colors.green,

    backgroundColor: colors.white,

    marginTop: 14,
  },

  viewButtonText: {
    color: colors.green,
    fontWeight: "800",
  },

  intro: {
    marginBottom: 18,
  },

  eyebrow: {
    color: colors.green,
    fontSize: 11,
    fontWeight: "800",
    letterSpacing: 1.5,
    marginBottom: 5,
  },

  subtitle: {
    color: colors.muted,
    fontSize: 15,
    lineHeight: 22,
  },

  filters: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    paddingRight: 20,
  },

  filtersScroll: {
    flexGrow: 0,
    marginBottom: 16,
  },

  filter: {
    height: 40,
    justifyContent: "center",

    paddingHorizontal: 14,

    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.border,

    backgroundColor: colors.white,
  },

  filterSelected: {
    borderColor: colors.green,
    backgroundColor: colors.greenSoft,
  },

  filterText: {
    color: colors.muted,
    fontWeight: "700",
  },

  filterTextSelected: {
    color: colors.greenDark,
    fontWeight: "800",
  },

  badges: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 7,
    marginBottom: 10,
  },

  date: {
    color: colors.green,
    fontWeight: "800",
    marginBottom: 6,
  },

  title: {
    color: colors.ink,
    fontSize: 20,
    lineHeight: 26,
    fontWeight: "900",
  },

  description: {
    color: colors.muted,
    lineHeight: 21,
    marginTop: 6,
    marginBottom: 14,
  },

  actionButton: {
    minHeight: 46,
    borderRadius: 11,
    backgroundColor: colors.green,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    marginTop: 9,
    paddingHorizontal: 14,
  },

  secondaryButton: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.green,
  },

  dangerButton: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },

  actionText: {
    color: colors.white,
    fontWeight: "800",
  },

  secondaryText: {
    color: colors.green,
  },

  dangerText: {
    color: colors.danger,
  },

  reviewBox: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    backgroundColor: colors.greenSoft,
    borderRadius: 11,
    padding: 11,
    marginTop: 12,
  },

  reviewText: {
    flex: 1,
    color: colors.muted,
    fontSize: 12,
    lineHeight: 18,
    fontWeight: "700",
  },

  busyText: {
    color: colors.muted,
    textAlign: "center",
    marginTop: 8,
    fontSize: 12,
  },

  pager: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginTop: 12,
  },

  pageLink: {
    color: colors.green,
    fontWeight: "800",
  },

  pageText: {
    color: colors.muted,
    fontSize: 12,
  },

  disabled: {
    opacity: 0.4,
  },

  pressed: {
    opacity: 0.7,
  },
});
