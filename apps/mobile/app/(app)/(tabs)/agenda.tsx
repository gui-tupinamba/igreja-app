import { useState } from "react";

import { Pressable, StyleSheet, Text, View } from "react-native";

import { router } from "expo-router";
import { Ionicons } from "@expo/vector-icons";

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

import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content, Page, Permissions } from "@/types";

export default function Agenda() {
  const [page, setPage] = useState(1);

  const resource = useResource<Page<Content>>(
    `/calendar?page=${page}&limit=20`,
  );

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const canCreate =
    !!access.data &&
    (access.data.permissions.manage_ministries ||
      access.data.permissions.led_ministries.length > 0);

  const pages = Math.max(
    1,
    Math.ceil((resource.data?.pagination.total || 0) / 20),
  );

  return (
    <Screen>
      <Heading
        title="Nossa agenda"
        subtitle="Eventos e atividades, em um só lugar."
      />

      {/* NOVA ATIVIDADE */}

      {canCreate ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Criar nova atividade"
          onPress={() => router.push("/(app)/schedule/create")}
          style={({ pressed }) => [
            styles.createButton,

            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="add-circle-outline" size={21} color={colors.white} />

          <Text style={styles.createButtonText}>Nova atividade</Text>
        </Pressable>
      ) : null}

      {/* AGENDA */}

      {resource.loading ? (
        <Loading />
      ) : resource.error ? (
        <ErrorState error={resource.error} retry={resource.reload} />
      ) : resource.data?.items.length ? (
        resource.data.items.map((item) => (
          <Pressable
            key={`${item.kind}-${item.id}`}
            disabled={item.kind !== "EVENT"}
            onPress={() => {
              if (item.kind === "EVENT") {
                router.push({
                  pathname: "/(app)/event/[id]",

                  params: {
                    id: item.id,
                  },
                });
              }
            }}
            style={({ pressed }) => [pressed && styles.pressed]}
          >
            <Card>
              <View style={styles.row}>
                <Badge value={item.kind === "EVENT" ? "Evento" : "Atividade"} />

                <Badge value={item.status} />
              </View>

              <Text style={styles.date}>{formatDate(item.starts_at)}</Text>

              <Text style={styles.title}>{item.title}</Text>

              {item.description ? (
                <Text numberOfLines={3} style={styles.body}>
                  {item.description}
                </Text>
              ) : null}

              {item.location ? (
                <Text style={styles.location}>{item.location}</Text>
              ) : null}
            </Card>
          </Pressable>
        ))
      ) : (
        <Empty>Nenhum compromisso próximo.</Empty>
      )}

      {/* PAGINAÇÃO */}

      {resource.data && pages > 1 ? (
        <View style={styles.pager}>
          <Pressable
            disabled={page === 1}
            onPress={() => setPage((value) => value - 1)}
          >
            <Text style={[styles.link, page === 1 && styles.disabled]}>
              ← Anterior
            </Text>
          </Pressable>

          <Text style={styles.page}>
            Página {page} de {pages}
          </Text>

          <Pressable
            disabled={page === pages}
            onPress={() => setPage((value) => value + 1)}
          >
            <Text style={[styles.link, page === pages && styles.disabled]}>
              Próxima →
            </Text>
          </Pressable>
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  createButton: {
    minHeight: 50,
    borderRadius: 12,
    backgroundColor: colors.green,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    marginBottom: 18,
    paddingHorizontal: 18,
  },

  createButtonText: {
    color: colors.white,
    fontSize: 16,
    fontWeight: "800",
  },

  row: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 7,
  },

  date: {
    color: colors.green,
    fontWeight: "800",
    marginTop: 13,
  },

  title: {
    color: colors.ink,
    fontSize: 20,
    lineHeight: 27,
    fontWeight: "800",
    marginTop: 6,
  },

  body: {
    color: colors.muted,
    lineHeight: 21,
    marginTop: 6,
  },

  location: {
    color: colors.ink,
    fontWeight: "700",
    marginTop: 10,
  },

  pager: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
  },

  link: {
    color: colors.green,
    fontWeight: "800",
  },

  page: {
    color: colors.muted,
    fontSize: 12,
  },

  pressed: {
    opacity: 0.7,
  },

  disabled: {
    opacity: 0.35,
  },
});
