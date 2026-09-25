import { useEffect, useMemo, useState } from "react";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import {
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";

import { router } from "expo-router";
import { Ionicons } from "@expo/vector-icons";

import DateTimePicker, {
  type DateTimePickerEvent,
} from "@react-native-community/datetimepicker";

import { api } from "@/api";

import {
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

type PickerMode = "start-date" | "start-time" | "end-date" | "end-time" | null;

export default function CreateSchedule() {
  const insets = useSafeAreaInsets();
  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const ministries = useResource<Page<Ministry>>(
    "/ministries?page=1&limit=100",
  );

  const [title, setTitle] = useState("");

  const [description, setDescription] = useState("");

  const [ministryId, setMinistryId] = useState<number | null | undefined>(
    undefined,
  );

  const [visibility, setVisibility] = useState<Visibility>("PUBLIC");

  const [startsAt, setStartsAt] = useState(() => {
    const date = new Date();

    date.setMinutes(date.getMinutes() + 30);

    date.setSeconds(0, 0);

    return date;
  });

  const [endsAt, setEndsAt] = useState<Date | null>(null);

  const [picker, setPicker] = useState<PickerMode>(null);

  const [ministryModal, setMinistryModal] = useState(false);

  const [busy, setBusy] = useState(false);

  const [error, setError] = useState<unknown>();

  const permissions = access.data?.permissions;

  const canCreate =
    !!permissions &&
    (permissions.manage_ministries || permissions.led_ministries.length > 0);

  const destinations = useMemo(() => {
    if (!permissions) {
      return [];
    }

    if (permissions.manage_ministries) {
      return ministries.data?.items || [];
    }

    return permissions.led_ministries;
  }, [permissions, ministries.data]);

  const selectedMinistry = destinations.find(
    (ministry) => ministry.id === ministryId,
  );

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

  function handlePicker(event: DateTimePickerEvent, selected?: Date) {
    const currentPicker = picker;

    setPicker(null);

    if (event.type !== "set" || !selected || !currentPicker) {
      return;
    }

    const isEnd = currentPicker.startsWith("end");

    const current = isEnd ? (endsAt ?? new Date(startsAt)) : startsAt;

    const next = new Date(current);

    if (currentPicker.endsWith("date")) {
      next.setFullYear(
        selected.getFullYear(),
        selected.getMonth(),
        selected.getDate(),
      );
    } else {
      next.setHours(selected.getHours(), selected.getMinutes(), 0, 0);
    }

    if (isEnd) {
      setEndsAt(next);
    } else {
      setStartsAt(next);
    }
  }

  function apiDate(value: Date) {
    /*
     * O backend aceita ISO sem
     * milissegundos.
     */
    return value.toISOString().replace(/\.\d{3}Z$/, "Z");
  }

  async function saveAndPublish() {
    const cleanTitle = title.trim();

    const cleanDescription = description.trim();

    if (!cleanTitle) {
      setError(new Error("Informe o título da atividade."));

      return;
    }

    if (ministryId === undefined) {
      setError(new Error("Escolha onde a atividade será publicada."));

      return;
    }

    if (endsAt && endsAt < startsAt) {
      setError(
        new Error("O horário de término não pode ser anterior ao início."),
      );

      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      /*
       * Primeiro criamos a atividade
       * como DRAFT.
       */
      const result = await api<{
        schedule: Content;
      }>("/schedules", "POST", {
        title: cleanTitle,

        description: cleanDescription || null,

        starts_at: apiDate(startsAt),

        ends_at: endsAt ? apiDate(endsAt) : null,

        ministry_id: ministryId,

        visibility,
      });

      /*
       * Depois publicamos.
       *
       * ADMIN, PASTOR e LÍDER podem
       * publicar atividades que têm
       * permissão para administrar.
       */
      await api(`/schedules/${result.schedule.id}/publish`, "POST", {});

      router.replace("/(app)/(tabs)/agenda");
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
          error={new Error("Você não possui permissão para criar atividades.")}
        />
      </Screen>
    );
  }

  return (
    <Screen>
      <Heading
        title="Nova atividade"
        subtitle="Adicione um compromisso à agenda da igreja."
      />

      {error ? <ErrorState error={error} /> : null}

      <Card>
        {/* TÍTULO */}

        <Text style={common.label}>Título</Text>

        <TextInput
          value={title}
          onChangeText={setTitle}
          style={common.input}
          maxLength={180}
          placeholder="Título da atividade"
          placeholderTextColor={colors.muted}
        />

        {/* MINISTÉRIO */}

        <Text style={common.label}>Ministério</Text>

        <Pressable
          accessibilityRole="button"
          onPress={() => setMinistryModal(true)}
          style={styles.selectButton}
        >
          <View style={styles.selectLeft}>
            <Ionicons name="people-outline" size={20} color={colors.green} />

            <Text style={styles.selectText}>
              {ministryId === null
                ? "Igreja em geral"
                : selectedMinistry?.name || "Selecione"}
            </Text>
          </View>

          <Ionicons name="chevron-forward" size={18} color={colors.muted} />
        </Pressable>

        {/* VISIBILIDADE */}

        <Text style={common.label}>Quem pode ver</Text>

        <View style={styles.choices}>
          <Choice
            label="Toda a igreja"
            icon="earth-outline"
            selected={visibility === "PUBLIC"}
            onPress={() => setVisibility("PUBLIC")}
          />

          {ministryId ? (
            <Choice
              label="Somente integrantes"
              icon="lock-closed-outline"
              selected={visibility === "MINISTRY_MEMBERS"}
              onPress={() => setVisibility("MINISTRY_MEMBERS")}
            />
          ) : null}
        </View>

        {/* DESCRIÇÃO */}

        <Text style={common.label}>Descrição</Text>

        <TextInput
          value={description}
          onChangeText={setDescription}
          multiline
          maxLength={50000}
          textAlignVertical="top"
          style={[common.input, common.textarea, styles.description]}
          placeholder="Descrição da atividade (opcional)"
          placeholderTextColor={colors.muted}
        />

        {/* INÍCIO */}

        <Text style={common.label}>Início</Text>

        <View style={styles.dateRow}>
          <DateButton
            icon="calendar-outline"
            value={formatDateOnly(startsAt)}
            onPress={() => setPicker("start-date")}
          />

          <DateButton
            icon="time-outline"
            value={formatTime(startsAt)}
            onPress={() => setPicker("start-time")}
          />
        </View>

        {/* TÉRMINO */}

        <Text style={common.label}>Término</Text>

        {endsAt ? (
          <>
            <View style={styles.dateRow}>
              <DateButton
                icon="calendar-outline"
                value={formatDateOnly(endsAt)}
                onPress={() => setPicker("end-date")}
              />

              <DateButton
                icon="time-outline"
                value={formatTime(endsAt)}
                onPress={() => setPicker("end-time")}
              />
            </View>

            <Pressable
              onPress={() => setEndsAt(null)}
              style={styles.removeEndButton}
            >
              <Ionicons
                name="close-circle-outline"
                size={18}
                color={colors.muted}
              />

              <Text style={styles.removeEndText}>
                Remover horário de término
              </Text>
            </Pressable>
          </>
        ) : (
          <Pressable
            onPress={() => {
              const end = new Date(startsAt);

              end.setHours(end.getHours() + 1);

              setEndsAt(end);
            }}
            style={styles.addEndButton}
          >
            <Ionicons
              name="add-circle-outline"
              size={19}
              color={colors.green}
            />

            <Text style={styles.addEndText}>Adicionar horário de término</Text>
          </Pressable>
        )}

        {/* PUBLICAR */}

        <Pressable
          accessibilityRole="button"
          disabled={busy}
          onPress={saveAndPublish}
          style={({ pressed }) => [
            common.button,
            styles.publishButton,

            busy && styles.disabled,

            pressed && !busy && styles.pressed,
          ]}
        >
          <Ionicons name="calendar-outline" size={19} color={colors.white} />

          <Text style={[common.buttonText, styles.publishText]}>
            {busy ? "Publicando…" : "Salvar e publicar"}
          </Text>
        </Pressable>
      </Card>

      {/* DATE/TIME PICKER */}

      {picker ? (
        <DateTimePicker
          value={picker.startsWith("end") ? (endsAt ?? startsAt) : startsAt}
          mode={picker.endsWith("date") ? "date" : "time"}
          is24Hour
          onChange={handlePicker}
        />
      ) : null}

      {/* MODAL DE MINISTÉRIO */}

      <Modal
        visible={ministryModal}
        transparent
        animationType="fade"
        onRequestClose={() => setMinistryModal(false)}
      >
        <View style={styles.modalContainer}>
          <Pressable
            style={styles.backdrop}
            onPress={() => setMinistryModal(false)}
          />

          <View
            style={[
              styles.modalCard,
              {
                paddingBottom: Math.max(insets.bottom + 28, 46),
              },
            ]}
          >
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Selecionar ministério</Text>

              <Pressable onPress={() => setMinistryModal(false)}>
                <Ionicons name="close" size={24} color={colors.ink} />
              </Pressable>
            </View>

            <ScrollView>
              {permissions?.manage_ministries ? (
                <MinistryOption
                  label="Igreja em geral"
                  selected={ministryId === null}
                  onPress={() => {
                    setMinistryId(null);

                    setVisibility("PUBLIC");

                    setMinistryModal(false);
                  }}
                />
              ) : null}

              {destinations.map((ministry) => (
                <MinistryOption
                  key={ministry.id}
                  label={ministry.name}
                  selected={ministryId === ministry.id}
                  onPress={() => {
                    setMinistryId(ministry.id);

                    setMinistryModal(false);
                  }}
                />
              ))}
            </ScrollView>
          </View>
        </View>
      </Modal>
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

  icon: "earth-outline" | "lock-closed-outline";

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
      style={[styles.choice, selected && styles.choiceSelected]}
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

function DateButton({
  icon,
  value,
  onPress,
}: {
  icon: "calendar-outline" | "time-outline";

  value: string;

  onPress: () => void;
}) {
  return (
    <Pressable onPress={onPress} style={styles.dateButton}>
      <Ionicons name={icon} size={18} color={colors.green} />

      <Text style={styles.dateText}>{value}</Text>
    </Pressable>
  );
}

function MinistryOption({
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
      style={[styles.ministryOption, selected && styles.ministrySelected]}
    >
      <View style={styles.selectLeft}>
        <Ionicons
          name="people-outline"
          size={19}
          color={selected ? colors.green : colors.muted}
        />

        <Text
          style={[styles.ministryText, selected && styles.ministryTextSelected]}
        >
          {label}
        </Text>
      </View>

      {selected ? (
        <Ionicons name="checkmark-circle" size={22} color={colors.green} />
      ) : null}
    </Pressable>
  );
}

function formatDateOnly(date: Date) {
  return new Intl.DateTimeFormat("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(date);
}

function formatTime(date: Date) {
  return new Intl.DateTimeFormat("pt-BR", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(date);
}

const styles = StyleSheet.create({
  selectButton: {
    minHeight: 50,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    backgroundColor: colors.white,
    paddingHorizontal: 14,
    marginBottom: 16,
  },

  selectLeft: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: 9,
  },

  selectText: {
    color: colors.ink,
    fontWeight: "700",
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
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: 14,
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

  description: {
    minHeight: 130,
  },

  dateRow: {
    flexDirection: "row",
    gap: 8,
    marginBottom: 16,
  },

  dateButton: {
    flex: 1,
    minHeight: 50,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    backgroundColor: colors.white,
  },

  dateText: {
    color: colors.ink,
    fontWeight: "700",
    fontSize: 13,
  },

  addEndButton: {
    minHeight: 46,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    marginBottom: 20,
  },

  addEndText: {
    color: colors.green,
    fontWeight: "800",
  },

  removeEndButton: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    marginTop: -6,
    marginBottom: 20,
  },

  removeEndText: {
    color: colors.muted,
    fontWeight: "700",
    fontSize: 12,
  },

  publishButton: {
    flexDirection: "row",
    gap: 7,
  },

  publishText: {
    marginLeft: 2,
  },

  modalContainer: {
    flex: 1,
    justifyContent: "flex-end",
  },

  backdrop: {
    position: "absolute",
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    backgroundColor: "rgba(0, 0, 0, 0.35)",
  },

  modalCard: {
    maxHeight: "65%",
    backgroundColor: colors.cream,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    paddingHorizontal: 18,
    paddingTop: 18,
  },

  modalHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginBottom: 14,
  },

  modalTitle: {
    color: colors.ink,
    fontSize: 20,
    fontWeight: "900",
  },

  ministryOption: {
    minHeight: 54,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingHorizontal: 12,
    borderRadius: 12,
    marginBottom: 6,
  },

  ministrySelected: {
    backgroundColor: colors.greenSoft,
  },

  ministryText: {
    color: colors.ink,
    fontWeight: "700",
  },

  ministryTextSelected: {
    color: colors.greenDark,
    fontWeight: "900",
  },

  disabled: {
    opacity: 0.5,
  },

  pressed: {
    opacity: 0.7,
  },
});
