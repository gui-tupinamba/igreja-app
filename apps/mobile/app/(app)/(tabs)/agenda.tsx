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
  ProtectedImage,
  Screen,
  formatDate,
} from "@/components";

import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content, Page, Permissions } from "@/types";

type ViewMode = "EVENTS" | "AGENDA";

export default function EventsAndAgenda() {
  const [mode, setMode] = useState<ViewMode>("EVENTS");

  const [page, setPage] = useState(1);

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const canCreate =
    !!access.data &&
    (access.data.permissions.manage_ministries ||
      access.data.permissions.led_ministries.length > 0);

  const path =
    mode === "EVENTS"
      ? `/events?page=${page}&limit=20`
      : `/calendar?page=${page}&limit=20`;

  const resource = useResource<Page<Content>>(path);

  const pages = Math.max(
    1,
    Math.ceil((resource.data?.pagination.total || 0) / 20),
  );

  function changeMode(value: ViewMode) {
    setMode(value);
    setPage(1);
  }

  function openItem(item: Content) {
    /*
     * Na Agenda também existem ACTIVITY.
     *
     * Neste momento somente EVENT possui
     * tela própria de detalhes no mobile.
     */
    if (mode === "AGENDA" && item.kind !== "EVENT") {
      return;
    }

    router.push({
      pathname: "/(app)/event/[id]",

      params: {
        id: item.id,
      },
    });
  }

  return (
    <Screen>
      <Heading
        eyebrow="NOSSA COMUNIDADE"
        title={mode === "EVENTS" ? "Eventos" : "Nossa agenda"}
        subtitle={
          mode === "EVENTS"
            ? "Encontros que aproximam e fortalecem nossa caminhada."
            : "Eventos e atividades. Todos os encontros, em um só lugar."
        }
      />
      {/* EVENTOS / AGENDA */}
      <View style={styles.tabs}>
        <Pressable
          accessibilityRole="button"
          accessibilityState={{
            selected: mode === "EVENTS",
          }}
          onPress={() => changeMode("EVENTS")}
          style={[styles.tab, mode === "EVENTS" && styles.tabActive]}
        >
          <Ionicons
            name="calendar-outline"
            size={18}
            color={mode === "EVENTS" ? colors.white : colors.muted}
          />

          <Text
            style={[styles.tabText, mode === "EVENTS" && styles.tabTextActive]}
          >
            Eventos
          </Text>
        </Pressable>

        <Pressable
          accessibilityRole="button"
          accessibilityState={{
            selected: mode === "AGENDA",
          }}
          onPress={() => changeMode("AGENDA")}
          style={[styles.tab, mode === "AGENDA" && styles.tabActive]}
        >
          <Ionicons
            name="list-outline"
            size={18}
            color={mode === "AGENDA" ? colors.white : colors.muted}
          />

          <Text
            style={[styles.tabText, mode === "AGENDA" && styles.tabTextActive]}
          >
            Agenda
          </Text>
        </Pressable>
      </View>
      {/* EXPLICAÇÃO DA VISUALIZAÇÃO */}
      <View style={styles.info}>
        <Ionicons
          name={mode === "EVENTS" ? "calendar-outline" : "time-outline"}
          size={19}
          color={colors.green}
        />

        <Text style={styles.infoText}>
          {mode === "EVENTS"
            ? "Veja os eventos disponíveis para você."
            : "Veja seus próximos eventos e atividades organizados por data."}
        </Text>
      </View>

      {/* GERENCIAR ATIVIDADES */}

      {mode === "AGENDA" && canCreate ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Gerenciar atividades"
          onPress={() => router.push("/(app)/schedule/manage")}
          style={({ pressed }) => [
            styles.manageButton,
            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="options-outline" size={19} color={colors.green} />

          <Text style={styles.manageButtonText}>Gerenciar atividades</Text>
        </Pressable>
      ) : null}

      {/* NOVA ATIVIDADE */}

      {mode === "AGENDA" && canCreate ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Criar nova atividade"
          onPress={() => router.push("/(app)/schedule/create")}
          style={({ pressed }) => [
            styles.createButton,
            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="add-circle-outline" size={20} color={colors.white} />

          <Text style={styles.createButtonText}>Nova atividade</Text>
        </Pressable>
      ) : null}

      {/* CONTEÚDO */}
      {resource.loading ? (
        <Loading />
      ) : resource.error ? (
        <ErrorState error={resource.error} retry={resource.reload} />
      ) : resource.data?.items.length ? (
        resource.data.items.map((item) => {
          const isActivity = mode === "AGENDA" && item.kind === "ACTIVITY";

          return (
            <Pressable
              key={`${item.kind || "EVENT"}-${item.id}`}
              disabled={isActivity}
              onPress={() => openItem(item)}
              style={({ pressed }) => [pressed && styles.pressed]}
            >
              <Card>
                {/* IMAGEM SOMENTE PARA EVENTOS */}

                {!isActivity ? (
                  <ProtectedImage
                    image={item.images?.[0]}
                    accessibilityLabel={`Imagem de ${item.title}`}
                  />
                ) : null}

                <View style={styles.row}>
                  <Badge value={isActivity ? "Atividade" : "Evento"} />

                  <Badge value={item.status} />

                  <Badge value={item.visibility} />
                </View>

                <View style={styles.dateRow}>
                  <Ionicons
                    name="calendar-outline"
                    size={16}
                    color={colors.green}
                  />

                  <Text style={styles.date}>{formatDate(item.starts_at)}</Text>
                </View>

                <Text style={styles.title}>{item.title}</Text>

                {item.description ? (
                  <Text numberOfLines={3} style={styles.body}>
                    {item.description}
                  </Text>
                ) : null}

                {item.location ? (
                  <View style={styles.locationRow}>
                    <Ionicons
                      name="location-outline"
                      size={16}
                      color={colors.green}
                    />

                    <Text style={styles.location}>{item.location}</Text>
                  </View>
                ) : null}

                {!isActivity ? (
                  <View style={styles.openRow}>
                    <Text style={styles.openText}>Ver evento</Text>

                    <Ionicons
                      name="arrow-forward"
                      size={17}
                      color={colors.green}
                    />
                  </View>
                ) : null}
              </Card>
            </Pressable>
          );
        })
      ) : (
        <Empty>
          {mode === "EVENTS"
            ? "Nenhum evento disponível."
            : "Nenhum compromisso próximo."}
        </Empty>
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

  manageButton: {
  minHeight: 48,

  flexDirection: "row",
  alignItems: "center",
  justifyContent: "center",

  gap: 7,

  borderWidth: 1,
  borderColor: colors.green,

  borderRadius: 12,

  backgroundColor:
    colors.white,

  marginBottom: 10,
},

manageButtonText: {
  color: colors.green,
  fontWeight: "800",
},

  tabs: {
    flexDirection: "row",
    padding: 4,
    borderRadius: 13,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
    marginBottom: 14,
  },

  tab: {
    flex: 1,
    minHeight: 44,
    borderRadius: 9,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
  },

  tabActive: {
    backgroundColor: colors.green,
  },

  tabText: {
    color: colors.muted,
    fontWeight: "800",
  },

  tabTextActive: {
    color: colors.white,
  },

  info: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    padding: 11,
    borderRadius: 12,
    backgroundColor: colors.greenSoft,
    marginBottom: 14,
  },

  infoText: {
    flex: 1,
    color: colors.muted,
    fontSize: 12,
    lineHeight: 18,
    fontWeight: "700",
  },

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

  dateRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    marginTop: 13,
  },

  date: {
    color: colors.green,
    fontWeight: "800",
  },

  title: {
    color: colors.ink,
    fontSize: 20,
    lineHeight: 27,
    fontWeight: "800",
    marginTop: 8,
  },

  body: {
    color: colors.muted,
    lineHeight: 21,
    marginTop: 6,
  },

  locationRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 5,
    marginTop: 10,
  },

  location: {
    flex: 1,
    color: colors.ink,
    fontWeight: "700",
  },

  openRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    marginTop: 13,
  },

  openText: {
    color: colors.green,
    fontWeight: "800",
  },

  pager: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginTop: 8,
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
