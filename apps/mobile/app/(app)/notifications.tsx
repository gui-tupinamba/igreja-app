import { useCallback, useEffect, useMemo, useState } from "react";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import {
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
} from "react-native";

import { router, useLocalSearchParams } from "expo-router";

import { Ionicons } from "@expo/vector-icons";

import { api, ApiError } from "@/api";

import {
  Card,
  Empty,
  ErrorState,
  Heading,
  Loading,
  Screen,
  common,
  formatDate,
} from "@/components";

import { enablePushNotifications } from "@/push";
import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type {
  Ministry,
  NotificationItem,
  NotificationPage,
  NotificationPreferences,
  Page,
  Permissions,
} from "@/types";

type NoticeScope = "CHURCH" | "MINISTRY";

export default function NotificationsScreen() {
  const insets = useSafeAreaInsets();
  const params = useLocalSearchParams<{
    id?: string;
  }>();

  const { user } = useSession();

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const ministries = useResource<Page<Ministry>>(
    "/ministries?limit=100&page=1",
  );

  const [page, setPage] = useState<NotificationPage | null>(null);

  const [preferences, setPreferences] =
    useState<NotificationPreferences | null>(null);

  const [error, setError] = useState<unknown>();

  const [busy, setBusy] = useState(false);

  const [message, setMessage] = useState("");

  const [composerOpen, setComposerOpen] = useState(false);

  const [sending, setSending] = useState(false);

  const [sendError, setSendError] = useState<unknown>();

  const [sendSuccess, setSendSuccess] = useState("");

  const [scope, setScope] = useState<NoticeScope>(
    user?.role === "LEADER" ? "MINISTRY" : "CHURCH",
  );

  const [ministryId, setMinistryId] = useState<number | null>(null);

  const [ministryPickerOpen, setMinistryPickerOpen] = useState(false);

  const [title, setTitle] = useState("");

  const [body, setBody] = useState("");

  const [route, setRoute] = useState("");

  const permissions = access.data?.permissions;

  const canSend =
    user?.role === "ADMIN" ||
    user?.role === "PASTOR" ||
    (user?.role === "LEADER" && !!permissions?.led_ministries.length);

  const availableMinistries = useMemo(() => {
    if (!permissions) {
      return [];
    }

    if (permissions.manage_ministries) {
      return ministries.data?.items || [];
    }

    return permissions.led_ministries;
  }, [permissions, ministries.data]);

  const selectedMinistry = availableMinistries.find(
    (ministry) => ministry.id === ministryId,
  );

  const load = useCallback(async () => {
    setError(undefined);

    try {
      const [items, prefs] = await Promise.all([
        api<NotificationPage>("/notifications?limit=50&page=1"),

        api<{
          preferences: NotificationPreferences;
        }>("/notifications/preferences"),
      ]);

      setPage(items);

      setPreferences(prefs.preferences);
    } catch (reason) {
      setError(reason);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    const item = page?.items.find(
      (notification) => notification.id === Number(params.id),
    );

    if (item && !item.read_at) {
      void open(item);
    }
  }, [params.id, page]);

  useEffect(() => {
    if (
      scope === "MINISTRY" &&
      ministryId === null &&
      availableMinistries.length > 0
    ) {
      setMinistryId(availableMinistries[0].id);
    }
  }, [scope, ministryId, availableMinistries]);

  async function open(item: NotificationItem) {
    try {
      await api(`/notifications/${item.id}/read`, "POST", {});

      setPage((current) =>
        current
          ? {
              ...current,

              unread_count: Math.max(
                0,
                current.unread_count - (item.read_at ? 0 : 1),
              ),

              items: current.items.map((currentItem) =>
                currentItem.id === item.id
                  ? {
                      ...currentItem,

                      read_at: new Date().toISOString(),
                    }
                  : currentItem,
              ),
            }
          : current,
      );

      navigate(item.route);
    } catch (reason) {
      setError(reason);
    }
  }

  function navigate(targetRoute: string | null) {
    if (!targetRoute) {
      return;
    }

    let match;

    if ((match = targetRoute.match(/^\/posts\/(\d+)$/))) {
      router.push({
        pathname: "/(app)/post/[id]",

        params: {
          id: match[1],
        },
      });

      return;
    }

    if ((match = targetRoute.match(/^\/events\/(\d+)$/))) {
      router.push({
        pathname: "/(app)/event/[id]",

        params: {
          id: match[1],
        },
      });

      return;
    }

    if ((match = targetRoute.match(/^\/ministries\/(\d+)$/))) {
      router.push({
        pathname: "/(app)/ministry/[id]",

        params: {
          id: match[1],
        },
      });

      return;
    }

    if (targetRoute === "/agenda") {
      router.push("/(app)/(tabs)/agenda");
    }
  }

  async function togglePush(value: boolean) {
    if (!preferences) {
      return;
    }

    setBusy(true);
    setMessage("");

    try {
      if (value) {
        await enablePushNotifications();
      }

      const response = await api<{
        preferences: NotificationPreferences;
      }>("/notifications/preferences", "PATCH", {
        push_enabled: value,
      });

      setPreferences(response.preferences);

      setMessage(value ? "Notificações ativadas." : "Notificações pausadas.");
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason
          : new ApiError(0, "Não foi possível alterar."),
      );
    } finally {
      setBusy(false);
    }
  }

  function resetComposer() {
    setTitle("");
    setBody("");
    setRoute("");

    setScope(user?.role === "LEADER" ? "MINISTRY" : "CHURCH");

    setMinistryId(
      user?.role === "LEADER"
        ? (permissions?.led_ministries[0]?.id ?? null)
        : null,
    );

    setSendError(undefined);
  }

  function createIdempotencyKey() {
    return [
      "mobile",
      Date.now().toString(36),
      Math.random().toString(36).slice(2),
    ].join("_");
  }

  async function sendNotice() {
    const cleanTitle = title.trim();

    const cleanBody = body.trim();

    const cleanRoute = route.trim();

    if (!cleanTitle) {
      setSendError(new Error("Informe o título do aviso."));

      return;
    }

    if (!cleanBody) {
      setSendError(new Error("Informe a mensagem do aviso."));

      return;
    }

    if (scope === "MINISTRY" && !ministryId) {
      setSendError(new Error("Selecione o ministério que receberá o aviso."));

      return;
    }

    setSending(true);
    setSendError(undefined);
    setSendSuccess("");

    try {
      await api("/admin/notifications", "POST", {
        scope,

        ...(scope === "MINISTRY"
          ? {
              ministry_id: ministryId,
            }
          : {}),

        title: cleanTitle,

        body: cleanBody,

        ...(cleanRoute
          ? {
              route: cleanRoute,
            }
          : {}),

        idempotency_key: createIdempotencyKey(),
      });

      setComposerOpen(false);

      setSendSuccess("Aviso enviado com sucesso.");

      resetComposer();

      await load();
    } catch (reason) {
      setSendError(reason);
    } finally {
      setSending(false);
    }
  }

  if (!page && !error) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  return (
    <Screen>
      {/* CABEÇALHO */}

      <View style={styles.headingRow}>
        <View style={styles.headingGrow}>
          <Heading
            eyebrow="FIQUE POR DENTRO"
            title="Avisos"
            subtitle={
              page
                ? `${page.unread_count} não lida${page.unread_count === 1 ? "" : "s"}`
                : undefined
            }
          />
        </View>

        {canSend ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Criar novo aviso"
            onPress={() => {
              setSendSuccess("");
              setComposerOpen(true);
            }}
            style={({ pressed }) => [
              styles.addButton,

              pressed && styles.pressed,
            ]}
          >
            <Ionicons name="add" size={27} color={colors.white} />
          </Pressable>
        ) : null}
      </View>

      {error ? <ErrorState error={error} retry={load} /> : null}

      {sendSuccess ? (
        <View style={styles.successBox}>
          <Ionicons
            name="checkmark-circle-outline"
            size={20}
            color={colors.green}
          />

          <Text style={styles.successText}>{sendSuccess}</Text>
        </View>
      ) : null}

      {/* BOTÃO DE NOVO AVISO */}

      {canSend ? (
        <Pressable
          accessibilityRole="button"
          onPress={() => {
            setSendSuccess("");
            setComposerOpen(true);
          }}
          style={({ pressed }) => [
            styles.newNoticeButton,

            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="megaphone-outline" size={20} color={colors.white} />

          <Text style={styles.newNoticeText}>Novo aviso</Text>
        </Pressable>
      ) : null}

      {/* PUSH */}

      {preferences ? (
        <Card>
          <View style={styles.row}>
            <View style={styles.grow}>
              <Text style={styles.setting}>Receber notificações push</Text>

              <Text style={styles.muted}>
                O conteúdo protegido só aparece após abrir o aplicativo.
              </Text>
            </View>

            <Switch
              disabled={busy}
              value={preferences.push_enabled}
              onValueChange={togglePush}
              trackColor={{
                false: colors.border,
                true: colors.green,
              }}
            />
          </View>

          {message ? (
            <Text style={styles.preferenceMessage}>{message}</Text>
          ) : null}
        </Card>
      ) : null}

      {/* AVISOS */}

      {page?.items.length ? (
        page.items.map((item) => (
          <Pressable key={item.id} onPress={() => open(item)}>
            <Card>
              <View style={styles.noticeHeader}>
                <View style={styles.noticeIcon}>
                  <Ionicons
                    name={
                      item.scope === "MINISTRY"
                        ? "people-outline"
                        : "megaphone-outline"
                    }
                    size={17}
                    color={colors.green}
                  />
                </View>

                <View style={styles.noticeHeaderText}>
                  <Text style={[styles.title, !item.read_at && styles.unread]}>
                    {item.title}
                  </Text>

                  <Text style={styles.scope}>
                    {item.scope === "MINISTRY"
                      ? "Ministério"
                      : item.scope === "CHURCH"
                        ? "Toda a igreja"
                        : "Aviso pessoal"}
                  </Text>
                </View>

                {!item.read_at ? <View style={styles.dot} /> : null}
              </View>

              <Text style={styles.body}>{item.body}</Text>

              <Text style={styles.date}>{formatDate(item.created_at)}</Text>
            </Card>
          </Pressable>
        ))
      ) : (
        <Empty>Os avisos da igreja aparecerão aqui.</Empty>
      )}

      {/* MODAL NOVO AVISO */}

      <Modal
        visible={composerOpen}
        transparent
        animationType="slide"
        onRequestClose={() => !sending && setComposerOpen(false)}
      >
        <View style={styles.modalContainer}>
          <Pressable
            style={styles.modalBackdrop}
            onPress={() => {
              if (!sending) {
                setComposerOpen(false);
              }
            }}
          />

          <View
            style={[
              styles.modalCard,
              {
                paddingBottom: Math.max(insets.bottom + 18, 32),
              },
            ]}
          >
            <View style={styles.modalHeader}>
              <View>
                <Text style={styles.modalEyebrow}>COMUNICAÇÃO</Text>

                <Text style={styles.modalTitle}>Novo aviso</Text>
              </View>

              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Fechar"
                disabled={sending}
                onPress={() => setComposerOpen(false)}
                style={styles.closeButton}
              >
                <Ionicons name="close" size={24} color={colors.ink} />
              </Pressable>
            </View>

            <ScrollView
              showsVerticalScrollIndicator={false}
              keyboardShouldPersistTaps="handled"
            >
              {sendError ? <ErrorState error={sendError} /> : null}

              {/* PÚBLICO */}

              <Text style={common.label}>Público</Text>

              <View style={styles.scopeChoices}>
                {user?.role !== "LEADER" ? (
                  <Choice
                    label="Toda a igreja"
                    icon="earth-outline"
                    selected={scope === "CHURCH"}
                    onPress={() => {
                      setScope("CHURCH");

                      setMinistryId(null);
                    }}
                  />
                ) : null}

                <Choice
                  label="Ministério"
                  icon="people-outline"
                  selected={scope === "MINISTRY"}
                  onPress={() => {
                    setScope("MINISTRY");

                    setMinistryId(
                      (current) =>
                        current ?? availableMinistries[0]?.id ?? null,
                    );
                  }}
                />
              </View>

              {/* MINISTÉRIO */}

              {scope === "MINISTRY" ? (
                <>
                  <Text style={common.label}>Ministério</Text>

                  <Pressable
                    accessibilityRole="button"
                    onPress={() => setMinistryPickerOpen(true)}
                    style={styles.selectButton}
                  >
                    <View style={styles.selectLeft}>
                      <Ionicons
                        name="people-circle-outline"
                        size={20}
                        color={colors.green}
                      />

                      <Text numberOfLines={1} style={styles.selectText}>
                        {selectedMinistry?.name || "Selecione o ministério"}
                      </Text>
                    </View>

                    <Ionicons
                      name="chevron-forward"
                      size={18}
                      color={colors.muted}
                    />
                  </Pressable>
                </>
              ) : null}

              {/* TÍTULO */}

              <Text style={common.label}>Título</Text>

              <TextInput
                value={title}
                onChangeText={setTitle}
                style={common.input}
                maxLength={120}
                placeholder="Título do aviso"
                placeholderTextColor={colors.muted}
              />

              {/* MENSAGEM */}

              <Text style={common.label}>Mensagem</Text>

              <TextInput
                value={body}
                onChangeText={setBody}
                multiline
                maxLength={1000}
                style={[common.input, common.textarea, styles.messageInput]}
                textAlignVertical="top"
                placeholder="Digite a mensagem do aviso"
                placeholderTextColor={colors.muted}
              />

              <Text style={styles.counter}>{body.length} / 1.000</Text>

              {/* DESTINO */}

              <Text style={common.label}>Destino no aplicativo (opcional)</Text>

              <TextInput
                value={route}
                onChangeText={setRoute}
                style={common.input}
                maxLength={255}
                autoCapitalize="none"
                autoCorrect={false}
                placeholder="/agenda ou /posts/123"
                placeholderTextColor={colors.muted}
              />

              <Text style={styles.routeHelp}>
                Exemplos: /agenda, /posts/12, /events/4 ou /ministries/3.
              </Text>

              {/* ENVIAR */}

              <Pressable
                accessibilityRole="button"
                disabled={sending}
                onPress={sendNotice}
                style={({ pressed }) => [
                  common.button,
                  styles.sendButton,

                  sending && styles.disabled,

                  pressed && !sending && styles.pressed,
                ]}
              >
                <Ionicons name="send-outline" size={19} color={colors.white} />

                <Text style={[common.buttonText, styles.sendButtonText]}>
                  {sending ? "Enviando…" : "Enviar aviso"}
                </Text>
              </Pressable>
            </ScrollView>
          </View>
        </View>
      </Modal>

      {/* MODAL MINISTÉRIOS */}

      <Modal
        visible={ministryPickerOpen}
        transparent
        animationType="fade"
        onRequestClose={() => setMinistryPickerOpen(false)}
      >
        <View style={styles.pickerContainer}>
          <Pressable
            style={styles.modalBackdrop}
            onPress={() => setMinistryPickerOpen(false)}
          />

          <View
            style={[
              styles.pickerCard,
              {
                paddingBottom: Math.max(insets.bottom + 18, 32),
              },
            ]}
          >
            <View style={styles.modalHeader}>
              <Text style={styles.pickerTitle}>Selecionar ministério</Text>

              <Pressable
                onPress={() => setMinistryPickerOpen(false)}
                style={styles.closeButton}
              >
                <Ionicons name="close" size={24} color={colors.ink} />
              </Pressable>
            </View>

            <ScrollView showsVerticalScrollIndicator={false}>
              {availableMinistries.map((ministry) => {
                const selected = ministry.id === ministryId;

                return (
                  <Pressable
                    key={ministry.id}
                    onPress={() => {
                      setMinistryId(ministry.id);

                      setMinistryPickerOpen(false);
                    }}
                    style={[
                      styles.ministryOption,

                      selected && styles.ministryOptionSelected,
                    ]}
                  >
                    <View style={styles.selectLeft}>
                      <Ionicons
                        name="people-outline"
                        size={19}
                        color={selected ? colors.green : colors.muted}
                      />

                      <Text
                        style={[
                          styles.ministryOptionText,

                          selected && styles.ministryOptionTextSelected,
                        ]}
                      >
                        {ministry.name}
                      </Text>
                    </View>

                    {selected ? (
                      <Ionicons
                        name="checkmark-circle"
                        size={22}
                        color={colors.green}
                      />
                    ) : null}
                  </Pressable>
                );
              })}
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

  icon: "earth-outline" | "people-outline";

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
        size={18}
        color={selected ? colors.greenDark : colors.muted}
      />

      <Text style={[styles.choiceText, selected && styles.choiceTextSelected]}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  headingRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 10,
  },

  headingGrow: {
    flex: 1,
  },

  addButton: {
    width: 46,
    height: 46,
    borderRadius: 23,
    backgroundColor: colors.green,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 4,
  },

  newNoticeButton: {
    minHeight: 50,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: colors.green,
    borderRadius: 12,
    marginBottom: 14,
  },

  newNoticeText: {
    color: colors.white,
    fontSize: 16,
    fontWeight: "800",
  },

  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
  },

  grow: {
    flex: 1,
  },

  setting: {
    color: colors.ink,
    fontWeight: "800",
    fontSize: 16,
  },

  muted: {
    color: colors.muted,
    lineHeight: 19,
    marginTop: 4,
  },

  preferenceMessage: {
    color: colors.green,
    fontWeight: "700",
    marginTop: 10,
  },

  successBox: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    padding: 12,
    borderRadius: 12,
    backgroundColor: colors.greenSoft,
    marginBottom: 14,
  },

  successText: {
    flex: 1,
    color: colors.greenDark,
    fontWeight: "700",
  },

  noticeHeader: {
    flexDirection: "row",
    alignItems: "center",
    gap: 9,
  },

  noticeIcon: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: colors.greenSoft,
    alignItems: "center",
    justifyContent: "center",
  },

  noticeHeaderText: {
    flex: 1,
  },

  title: {
    color: colors.ink,
    fontSize: 17,
    fontWeight: "600",
  },

  unread: {
    fontWeight: "900",
  },

  scope: {
    color: colors.muted,
    fontSize: 11,
    marginTop: 2,
    fontWeight: "700",
  },

  dot: {
    width: 9,
    height: 9,
    borderRadius: 5,
    backgroundColor: colors.gold,
  },

  body: {
    color: colors.muted,
    lineHeight: 21,
    marginTop: 10,
  },

  date: {
    color: colors.green,
    fontSize: 12,
    fontWeight: "700",
    marginTop: 12,
  },

  modalContainer: {
    flex: 1,
    justifyContent: "flex-end",
  },

  modalBackdrop: {
    position: "absolute",
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    backgroundColor: "rgba(0, 0, 0, 0.35)",
  },

  modalCard: {
    maxHeight: "88%",
    backgroundColor: colors.cream,

    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,

    paddingHorizontal: 20,
    paddingTop: 18,
  },

  modalHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginBottom: 18,
  },

  modalEyebrow: {
    color: colors.green,
    fontSize: 10,
    fontWeight: "800",
    letterSpacing: 1.3,
    marginBottom: 3,
  },

  modalTitle: {
    color: colors.ink,
    fontSize: 23,
    fontWeight: "900",
  },

  closeButton: {
    width: 42,
    height: 42,
    alignItems: "center",
    justifyContent: "center",
  },

  scopeChoices: {
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
    paddingHorizontal: 14,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
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
    flex: 1,
    color: colors.ink,
    fontWeight: "700",
  },

  messageInput: {
    minHeight: 130,
  },

  counter: {
    color: colors.muted,
    fontSize: 11,
    textAlign: "right",
    marginTop: -10,
    marginBottom: 16,
  },

  routeHelp: {
    color: colors.muted,
    fontSize: 11,
    lineHeight: 16,
    marginTop: -10,
    marginBottom: 18,
  },

  sendButton: {
    flexDirection: "row",
    gap: 7,
    marginBottom: 10,
  },

  sendButtonText: {
    marginLeft: 2,
  },

  pickerContainer: {
    flex: 1,
    justifyContent: "flex-end",
  },

  pickerCard: {
    maxHeight: "65%",
    backgroundColor: colors.cream,

    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,

    paddingHorizontal: 18,
    paddingTop: 18,
  },

  pickerTitle: {
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

  ministryOptionSelected: {
    backgroundColor: colors.greenSoft,
  },

  ministryOptionText: {
    flex: 1,
    color: colors.ink,
    fontWeight: "700",
  },

  ministryOptionTextSelected: {
    color: colors.greenDark,
    fontWeight: "900",
  },

  pressed: {
    opacity: 0.7,
  },

  disabled: {
    opacity: 0.5,
  },
});
