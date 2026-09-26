import { useState } from "react";

import { Pressable, StyleSheet, Text, View } from "react-native";

import { router, useLocalSearchParams } from "expo-router";

import { Ionicons } from "@expo/vector-icons";

import { api } from "@/api";

import {
  Badge,
  Empty,
  ErrorState,
  Loading,
  Screen,
  formatDate,
} from "@/components";

import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content } from "@/types";

export default function ScheduleDetail() {
  const { id, manage } = useLocalSearchParams<{
    id: string;
    manage?: string;
  }>();

  const { user } = useSession();

  const administrative = manage === "1";

  const path = administrative ? `/admin/schedules/${id}` : `/schedules/${id}`;

  const resource = useResource<{
    schedule: Content;
  }>(path);

  const [busy, setBusy] = useState(false);

  const [error, setError] = useState<unknown>();

  const schedule = resource.data?.schedule;

  const canPublish = user?.role === "ADMIN" || user?.role === "PASTOR";

  const isLeader = user?.role === "LEADER";

  async function action(name: "publish" | "submit-review") {
    if (!schedule || busy) {
      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      await api(`/schedules/${schedule.id}/${name}`, "POST", {});

      await resource.reload();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  if (resource.loading) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  if (resource.error) {
    return (
      <Screen>
        <ErrorState error={resource.error} retry={resource.reload} />
      </Screen>
    );
  }

  if (!schedule) {
    return (
      <Screen>
        <Empty>Atividade indisponível.</Empty>
      </Screen>
    );
  }

  return (
    <Screen>
      {administrative ? (
        <View style={styles.managementBanner}>
          <Ionicons
            name="shield-checkmark-outline"
            size={20}
            color={colors.green}
          />

          <View style={styles.bannerText}>
            <Text style={styles.bannerTitle}>Análise da atividade</Text>

            <Text style={styles.bannerDescription}>
              Confira todas as informações antes de aprovar a publicação.
            </Text>
          </View>
        </View>
      ) : null}
      

      <View style={styles.meta}>
        <Badge value={schedule.status} />
        <Badge value={schedule.visibility} />
      </View>

      <Text style={styles.title}>{schedule.title}</Text>

      <View style={styles.dateBox}>
        <View style={styles.dateRow}>
          <Ionicons name="calendar-outline" size={18} color={colors.green} />

          <Text style={styles.date}>{formatDate(schedule.starts_at)}</Text>
        </View>

        {schedule.ends_at ? (
          <Text style={styles.muted}>Até {formatDate(schedule.ends_at)}</Text>
        ) : null}
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Descrição</Text>

        <Text style={styles.body}>
          {schedule.description || "Sem descrição adicional."}
        </Text>
      </View>

      {administrative &&
      (schedule.status === "DRAFT" || schedule.status === "PENDING_REVIEW") ? (
        <View style={styles.managementArea}>
          <Text style={styles.managementTitle}>Revisão</Text>

          {canPublish && schedule.status === "PENDING_REVIEW" ? (
            <Pressable
              disabled={busy}
              onPress={() => action("publish")}
              style={({ pressed }) => [
                styles.primaryButton,
                busy && styles.disabled,
                pressed && !busy && styles.pressed,
              ]}
            >
              <Ionicons
                name="checkmark-circle-outline"
                size={19}
                color={colors.white}
              />

              <Text style={styles.primaryText}>
                {busy ? "Publicando…" : "Aprovar e publicar"}
              </Text>
            </Pressable>
          ) : null}

          {canPublish && schedule.status === "DRAFT" ? (
            <Pressable
              disabled={busy}
              onPress={() => action("publish")}
              style={({ pressed }) => [
                styles.primaryButton,
                busy && styles.disabled,
                pressed && !busy && styles.pressed,
              ]}
            >
              <Ionicons
                name="checkmark-circle-outline"
                size={19}
                color={colors.white}
              />

              <Text style={styles.primaryText}>Publicar atividade</Text>
            </Pressable>
          ) : null}

          {isLeader && schedule.status === "DRAFT" ? (
            <Pressable
              disabled={busy}
              onPress={() => action("submit-review")}
              style={({ pressed }) => [
                styles.primaryButton,
                busy && styles.disabled,
                pressed && !busy && styles.pressed,
              ]}
            >
              <Ionicons name="send-outline" size={19} color={colors.white} />

              <Text style={styles.primaryText}>Enviar para revisão</Text>
            </Pressable>
          ) : null}

          {isLeader && schedule.status === "PENDING_REVIEW" ? (
            <View style={styles.reviewInfo}>
              <Ionicons name="time-outline" size={19} color={colors.green} />

              <Text style={styles.reviewText}>
                Esta atividade está aguardando aprovação de um Pastor ou
                Administrador.
              </Text>
            </View>
          ) : null}
        </View>
      ) : null}

      {error ? <ErrorState error={error} /> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  managementBanner: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,

    padding: 13,
    marginBottom: 18,

    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 13,

    backgroundColor: colors.white,
  },

  bannerText: {
    flex: 1,
  },

  bannerTitle: {
    color: colors.ink,
    fontWeight: "900",
    marginBottom: 3,
  },

  bannerDescription: {
    color: colors.muted,
    fontSize: 12,
    lineHeight: 18,
  },

  meta: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 7,

    marginBottom: 14,
  },

  title: {
    color: colors.ink,
    fontSize: 29,
    lineHeight: 36,
    fontWeight: "900",
  },

  dateBox: {
    marginTop: 18,
    marginBottom: 20,

    padding: 15,

    borderRadius: 13,

    backgroundColor: colors.greenSoft,
  },

  dateRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 7,
  },

  date: {
    color: colors.greenDark,
    fontWeight: "800",
  },

  muted: {
    color: colors.muted,
    marginTop: 6,
  },

  section: {
    marginBottom: 20,
  },

  sectionTitle: {
    color: colors.ink,
    fontSize: 16,
    fontWeight: "900",
    marginBottom: 8,
  },

  body: {
    color: colors.ink,
    fontSize: 16,
    lineHeight: 25,
  },

  managementArea: {
    marginTop: 5,

    padding: 14,

    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 14,

    backgroundColor: colors.white,
  },

  managementTitle: {
    color: colors.ink,
    fontSize: 16,
    fontWeight: "900",

    marginBottom: 12,
  },

  primaryButton: {
    minHeight: 50,

    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",

    gap: 8,

    borderRadius: 11,

    backgroundColor: colors.green,
  },

  primaryText: {
    color: colors.white,
    fontWeight: "800",
  },

  reviewInfo: {
    flexDirection: "row",
    alignItems: "center",

    gap: 8,

    padding: 12,

    borderRadius: 11,

    backgroundColor: colors.greenSoft,
  },

  reviewText: {
    flex: 1,

    color: colors.muted,

    fontSize: 12,
    lineHeight: 18,

    fontWeight: "700",
  },

  disabled: {
    opacity: 0.45,
  },

  pressed: {
    opacity: 0.7,
  },
});
