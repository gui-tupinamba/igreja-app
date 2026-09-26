import { useEffect, useMemo, useState } from "react";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import {
  Alert,
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

import { api } from "@/api";

import {
  Badge,
  Card,
  Empty,
  ErrorState,
  Heading,
  Loading,
  Screen,
  common,
} from "@/components";

import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Membership, Ministry, Page, Permissions, User } from "@/types";

type MinistryStatus = "" | "ACTIVE" | "INACTIVE";

export default function Ministries() {
  const insets = useSafeAreaInsets();
  const { user } = useSession();

  const [page, setPage] = useState(1);

  const [status, setStatus] = useState<MinistryStatus>("ACTIVE");

  const [statusModal, setStatusModal] = useState(false);

  const [editor, setEditor] = useState<Ministry | "new" | null>(null);

  const [participants, setParticipants] = useState<Ministry | null>(null);

  const [error, setError] = useState<unknown>();

  const [deactivating, setDeactivating] = useState<number | null>(null);

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const permissions = access.data?.permissions;

  /*
   * Apenas ADMIN/PASTOR possuem
   * manage_ministries.
   */
  const globalManager = !!permissions?.manage_ministries;

  /*
   * A aba Gerenciar é global,
   * exatamente como no Web.
   */

  const path = globalManager
    ? `/admin/ministries?page=${page}&limit=12${
        status ? `&status=${status}` : ""
      }`
    : `/ministries?page=${page}&limit=12`;

  const resource = useResource<Page<Ministry>>(path);

  const pages = Math.max(
    1,
    Math.ceil((resource.data?.pagination.total || 0) / 12),
  );

  function canManage(ministryId: number) {
    return (
      globalManager ||
      !!permissions?.led_ministries.some(
        (ministry) => ministry.id === ministryId,
      )
    );
  }

  async function changed() {
    await Promise.all([resource.reload(), access.reload()]);
  }

  async function deactivate(ministry: Ministry) {
    if (deactivating) {
      return;
    }

    setDeactivating(ministry.id);

    setError(undefined);

    try {
      await api(`/ministries/${ministry.id}`, "DELETE");

      await changed();
    } catch (reason) {
      setError(reason);
    } finally {
      setDeactivating(null);
    }
  }

  const selectedStatusLabel =
    status === "ACTIVE"
      ? "Ativos"
      : status === "INACTIVE"
        ? "Inativos"
        : "Todos";

  if (access.loading && !access.data) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  return (
    <Screen>
      {/* CABEÇALHO */}

      <View style={styles.headerRow}>
        <View style={styles.headerContent}>
          <Heading
            eyebrow="NOSSA COMUNIDADE"
            title="Ministérios"
            subtitle="Diferentes dons. Uma mesma comunidade."
          />
        </View>
      </View>

      {/* NOVO MINISTÉRIO */}

      {globalManager ? (
        <Pressable
          accessibilityRole="button"
          onPress={() => setEditor("new")}
          style={({ pressed }) => [
            styles.createButton,

            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="add" size={20} color={colors.white} />

          <Text style={styles.createButtonText}>Novo ministério</Text>
        </Pressable>
      ) : null}

      {/* COMUNIDADE / GERENCIAR */}

      {/* FILTRO ADMINISTRATIVO */}

      {globalManager ? (
        <View style={styles.filterArea}>
          <Text style={styles.filterLabel}>Estado</Text>

          <Pressable
            onPress={() => setStatusModal(true)}
            style={styles.filterButton}
          >
            <Text style={styles.filterValue}>{selectedStatusLabel}</Text>

            <Ionicons name="chevron-down" size={18} color={colors.muted} />
          </Pressable>
        </View>
      ) : null}

      {error ? <ErrorState error={error} /> : null}

      {/* MINISTÉRIOS */}

      {resource.loading ? (
        <Loading />
      ) : resource.error ? (
        <ErrorState error={resource.error} retry={resource.reload} />
      ) : resource.data?.items.length ? (
        resource.data.items.map((ministry) => (
          <Card key={ministry.id}>
            <Pressable
              accessibilityRole="button"
              onPress={() =>
                router.push({
                  pathname: "/(app)/ministry/[id]",

                  params: {
                    id: ministry.id,
                  },
                })
              }
              style={({ pressed }) => [pressed && styles.pressed]}
            >
              <View style={styles.ministryIcon}>
                <Ionicons name="heart-outline" size={25} color={colors.green} />
              </View>

              <Text style={styles.title}>{ministry.name}</Text>

              <Text numberOfLines={4} style={styles.body}>
                {ministry.description ||
                  "Um espaço para servir e caminhar junto."}
              </Text>

              {ministry.status ? (
                <View style={styles.badgeArea}>
                  <Badge value={ministry.status} />
                </View>
              ) : null}
            </Pressable>

            {/* AÇÕES */}

            {canManage(ministry.id) ? (
              <View style={styles.actions}>
                <Pressable
                  accessibilityRole="button"
                  onPress={() => setEditor(ministry)}
                  style={({ pressed }) => [
                    styles.actionButton,

                    pressed && styles.pressed,
                  ]}
                >
                  <Ionicons
                    name="create-outline"
                    size={17}
                    color={colors.green}
                  />

                  <Text style={styles.actionText}>Editar</Text>
                </Pressable>

                <Pressable
                  accessibilityRole="button"
                  onPress={() => setParticipants(ministry)}
                  style={({ pressed }) => [
                    styles.actionButton,

                    pressed && styles.pressed,
                  ]}
                >
                  <Ionicons
                    name="people-outline"
                    size={17}
                    color={colors.green}
                  />

                  <Text style={styles.actionText}>Participantes</Text>
                </Pressable>

                {globalManager && ministry.status === "ACTIVE" ? (
                  <Pressable
                    accessibilityRole="button"
                    disabled={deactivating === ministry.id}
                    onPress={() =>
                      Alert.alert(
                        "Desativar ministério",
                        `O conteúdo de ${ministry.name} ficará fora da comunidade, preservando o histórico.`,
                        [
                          {
                            text: "Cancelar",
                            style: "cancel",
                          },

                          {
                            text: "Desativar",

                            style: "destructive",

                            onPress: () => void deactivate(ministry),
                          },
                        ],
                      )
                    }
                    style={({ pressed }) => [
                      styles.deactivateButton,

                      pressed && styles.pressed,
                    ]}
                  >
                    <Text style={styles.deactivateText}>
                      {deactivating === ministry.id
                        ? "Desativando…"
                        : "Desativar"}
                    </Text>
                  </Pressable>
                ) : null}
              </View>
            ) : null}
          </Card>
        ))
      ) : (
        <Empty>Os ministérios aparecerão aqui.</Empty>
      )}

      {/* PAGINAÇÃO */}

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

      {/* EDITOR */}

      {editor ? (
        <MinistryEditor
          item={editor === "new" ? undefined : editor}
          permissions={permissions!}
          onClose={() => setEditor(null)}
          onSaved={async () => {
            setEditor(null);
            await changed();
          }}
        />
      ) : null}

      {/* PARTICIPANTES */}

      {participants ? (
        <MembersModal
          ministry={participants}
          permissions={permissions!}
          currentUser={user}
          onClose={() => setParticipants(null)}
          onChanged={changed}
        />
      ) : null}

      {/* FILTRO STATUS */}

      <Modal
        visible={statusModal}
        transparent
        animationType="fade"
        onRequestClose={() => setStatusModal(false)}
      >
        <View style={styles.modalContainer}>
          <Pressable
            style={styles.backdrop}
            onPress={() => setStatusModal(false)}
          />

          <View
            style={[
              styles.smallModal,
              {
                paddingBottom: Math.max(insets.bottom + 28, 50),
              },
            ]}
          >
            <Text style={styles.modalTitle}>Estado</Text>

            <StatusOption
              label="Todos"
              selected={status === ""}
              onPress={() => {
                setStatus("");
                setPage(1);
                setStatusModal(false);
              }}
            />

            <StatusOption
              label="Ativos"
              selected={status === "ACTIVE"}
              onPress={() => {
                setStatus("ACTIVE");
                setPage(1);
                setStatusModal(false);
              }}
            />

            <StatusOption
              label="Inativos"
              selected={status === "INACTIVE"}
              onPress={() => {
                setStatus("INACTIVE");

                setPage(1);
                setStatusModal(false);
              }}
            />
          </View>
        </View>
      </Modal>
    </Screen>
  );
}

/* =========================================
   EDITOR DE MINISTÉRIO
========================================= */

function MinistryEditor({
  item,
  permissions,
  onClose,
  onSaved,
}: {
  item?: Ministry;
  permissions: Permissions;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const insets = useSafeAreaInsets();
  const [name, setName] = useState(item?.name || "");

  const [slug, setSlug] = useState(item?.slug || "");

  const [description, setDescription] = useState(item?.description || "");

  const [status, setStatus] = useState(item?.status || "ACTIVE");

  const [busy, setBusy] = useState(false);

  const [error, setError] = useState<unknown>();

  const global = permissions.manage_ministries;

  async function save() {
    const cleanName = name.trim();

    const cleanDescription = description.trim();

    const cleanSlug = slug.trim();

    if (!cleanName) {
      setError(new Error("Informe o nome do ministério."));

      return;
    }

    if (global && !cleanSlug) {
      setError(new Error("Informe o identificador do ministério."));

      return;
    }

    if (global && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(cleanSlug)) {
      setError(
        new Error(
          "O identificador deve conter apenas letras minúsculas, números e hífens.",
        ),
      );

      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      const body = global
        ? {
            name: cleanName,

            slug: cleanSlug,

            description: cleanDescription || null,

            status,
          }
        : {
            name: cleanName,

            description: cleanDescription || null,
          };

      await api(
        `/ministries${item ? `/${item.id}` : ""}`,
        item ? "PATCH" : "POST",
        body,
      );

      await onSaved();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.modalContainer}>
        <Pressable
          style={styles.backdrop}
          onPress={busy ? undefined : onClose}
        />

        <View
          style={[
            styles.editorModal,
            {
              paddingBottom: Math.max(insets.bottom + 28, 50),
            },
          ]}
        >
          <View style={styles.modalHeader}>
            <Text style={styles.modalTitle}>
              {item ? "Editar ministério" : "Novo ministério"}
            </Text>

            <Pressable disabled={busy} onPress={onClose}>
              <Ionicons name="close" size={24} color={colors.ink} />
            </Pressable>
          </View>

          <ScrollView
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
          >
            {error ? <ErrorState error={error} /> : null}

            <Text style={common.label}>Nome</Text>

            <TextInput
              value={name}
              onChangeText={(value) => {
                setName(value);

                /*
                 * Em um novo ministério,
                 * já sugerimos o slug.
                 */
                if (!item && !slug) {
                  setSlug(slugify(value));
                }
              }}
              style={common.input}
              maxLength={120}
              placeholder="Nome do ministério"
              placeholderTextColor={colors.muted}
            />

            {global ? (
              <>
                <Text style={common.label}>Identificador</Text>

                <TextInput
                  value={slug}
                  onChangeText={(value) =>
                    setSlug(value.toLowerCase().replace(/\s+/g, "-"))
                  }
                  style={common.input}
                  autoCapitalize="none"
                  autoCorrect={false}
                  maxLength={160}
                  placeholder="equipe-de-louvor"
                  placeholderTextColor={colors.muted}
                />

                <Text style={styles.hint}>
                  Letras minúsculas, números e hífens.
                </Text>

                <Text style={common.label}>Estado</Text>

                <View style={styles.stateChoices}>
                  <ChoiceButton
                    label="Ativo"
                    selected={status === "ACTIVE"}
                    onPress={() => setStatus("ACTIVE")}
                  />

                  <ChoiceButton
                    label="Inativo"
                    selected={status === "INACTIVE"}
                    onPress={() => setStatus("INACTIVE")}
                  />
                </View>
              </>
            ) : null}

            <Text style={common.label}>Descrição</Text>

            <TextInput
              value={description}
              onChangeText={setDescription}
              multiline
              maxLength={10000}
              textAlignVertical="top"
              style={[common.input, common.textarea, styles.descriptionInput]}
              placeholder="Descrição do ministério"
              placeholderTextColor={colors.muted}
            />

            <Pressable
              accessibilityRole="button"
              disabled={busy}
              onPress={save}
              style={({ pressed }) => [
                common.button,
                styles.saveButton,

                busy && styles.disabled,

                pressed && !busy && styles.pressed,
              ]}
            >
              <Ionicons name="save-outline" size={19} color={colors.white} />

              <Text style={[common.buttonText, styles.saveText]}>
                {busy ? "Salvando…" : "Salvar"}
              </Text>
            </Pressable>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

/* =========================================
   PARTICIPANTES / LIDERANÇAS
========================================= */

function MembersModal({
  ministry,
  permissions,
  currentUser,
  onClose,
  onChanged,
}: {
  ministry: Ministry;
  permissions: Permissions;
  currentUser: User | null;
  onClose: () => void;
  onChanged: () => Promise<void>;
}) {
  const [leaders, setLeaders] = useState(false);

  const [membershipStatus, setMembershipStatus] = useState<
    "ACTIVE" | "INACTIVE"
  >("ACTIVE");

  const [page, setPage] = useState(1);

  const [error, setError] = useState<unknown>();

  const [busy, setBusy] = useState(false);

  const [users, setUsers] = useState<User[]>([]);

  const [userPicker, setUserPicker] = useState(false);

  const global = permissions.manage_ministries;

  const path = leaders
    ? `/ministries/${ministry.id}/leaders?page=${page}&limit=10`
    : `/ministries/${ministry.id}/members?page=${page}&limit=10&status=${membershipStatus}`;

  const resource = useResource<Page<Membership>>(path);

  const pages = Math.max(
    1,
    Math.ceil((resource.data?.pagination.total || 0) / 10),
  );

  /*
   * Só ADMIN/PASTOR possuem acesso
   * a /admin/users.
   */
  useEffect(() => {
    if (!global) {
      return;
    }

    let active = true;

    api<Page<User>>("/admin/users?status=ACTIVE&page=1&limit=100")
      .then((result) => {
        if (active) {
          setUsers(result.items);
        }
      })
      .catch((reason) => {
        if (active) {
          setError(reason);
        }
      });

    return () => {
      active = false;
    };
  }, [global]);

  async function changed() {
    await resource.reload();
    await onChanged();
  }

  async function addMember(target: User) {
    if (busy) {
      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      await api(`/ministries/${ministry.id}/members`, "POST", {
        user_id: target.id,
      });

      setUserPicker(false);

      await changed();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  async function changeLeadership(member: Membership) {
    if (busy) {
      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      if (member.is_leader) {
        await api(
          `/ministries/${ministry.id}/leaders/${member.user_id}`,
          "DELETE",
        );
      } else {
        await api(`/ministries/${ministry.id}/leaders`, "POST", {
          user_id: member.user_id,

          ...(member.role === "MEMBER"
            ? {
                promote_to_leader: true,
              }
            : {}),
        });
      }

      await changed();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  async function removeMember(member: Membership) {
    if (busy) {
      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      await api(
        `/ministries/${ministry.id}/members/${member.user_id}`,
        "DELETE",
      );

      await changed();
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.modalContainer}>
        <Pressable style={styles.backdrop} onPress={onClose} />

        <View style={styles.membersModal}>
          <View style={styles.modalHeader}>
            <View style={{ flex: 1 }}>
              <Text style={styles.modalTitle}>Participantes</Text>

              <Text style={styles.modalSubtitle}>{ministry.name}</Text>
            </View>

            <Pressable onPress={onClose}>
              <Ionicons name="close" size={24} color={colors.ink} />
            </Pressable>
          </View>

          <View style={styles.tabs}>
            <Pressable
              onPress={() => {
                setLeaders(false);
                setPage(1);
              }}
              style={[styles.tab, !leaders && styles.tabActive]}
            >
              <Text style={[styles.tabText, !leaders && styles.tabTextActive]}>
                Participantes
              </Text>
            </Pressable>

            <Pressable
              onPress={() => {
                setLeaders(true);
                setPage(1);
              }}
              style={[styles.tab, leaders && styles.tabActive]}
            >
              <Text style={[styles.tabText, leaders && styles.tabTextActive]}>
                Lideranças
              </Text>
            </Pressable>
          </View>

          {global && !leaders ? (
            <View style={styles.memberFilter}>
              <ChoiceButton
                label="Ativos"
                selected={membershipStatus === "ACTIVE"}
                onPress={() => {
                  setMembershipStatus("ACTIVE");

                  setPage(1);
                }}
              />

              <ChoiceButton
                label="Histórico"
                selected={membershipStatus === "INACTIVE"}
                onPress={() => {
                  setMembershipStatus("INACTIVE");

                  setPage(1);
                }}
              />
            </View>
          ) : null}

          {error ? <ErrorState error={error} /> : null}

          <ScrollView showsVerticalScrollIndicator={false}>
            {resource.loading ? (
              <Loading />
            ) : resource.error ? (
              <ErrorState error={resource.error} retry={resource.reload} />
            ) : resource.data?.items.length ? (
              resource.data.items.map((member) => {
                const mayChange =
                  global &&
                  (currentUser?.role === "ADMIN" || member.role !== "ADMIN");

                return (
                  <View key={member.id} style={styles.memberRow}>
                    <View style={styles.memberAvatar}>
                      <Ionicons name="person" size={18} color={colors.green} />
                    </View>

                    <View style={styles.memberContent}>
                      <Text style={styles.memberName}>{member.name}</Text>

                      <View style={styles.memberBadges}>
                        <Badge value={member.role} />

                        <Badge value={member.status} />
                      </View>

                      {member.is_leader ? (
                        <Text style={styles.leaderText}>
                          Lidera este ministério
                        </Text>
                      ) : null}

                      {mayChange && member.status === "ACTIVE" ? (
                        <View style={styles.memberActions}>
                          <Pressable
                            disabled={busy}
                            onPress={() =>
                              Alert.alert(
                                member.is_leader
                                  ? "Remover liderança"
                                  : "Definir liderança",

                                member.is_leader
                                  ? "A participação será mantida, mas sem poderes de liderança."
                                  : member.role === "MEMBER"
                                    ? `${member.name} passará a liderar este ministério e será promovido para Líder.`
                                    : `${member.name} passará a liderar este ministério.`,

                                [
                                  {
                                    text: "Cancelar",
                                    style: "cancel",
                                  },

                                  {
                                    text: "Confirmar",

                                    onPress: () =>
                                      void changeLeadership(member),
                                  },
                                ],
                              )
                            }
                          >
                            <Text style={styles.memberActionText}>
                              {member.is_leader
                                ? "Remover liderança"
                                : "Definir liderança"}
                            </Text>
                          </Pressable>

                          <Pressable
                            disabled={busy}
                            onPress={() =>
                              Alert.alert(
                                "Encerrar participação",
                                "O acesso ao conteúdo privado deste ministério será revogado.",
                                [
                                  {
                                    text: "Cancelar",
                                    style: "cancel",
                                  },

                                  {
                                    text: "Encerrar",
                                    style: "destructive",

                                    onPress: () => void removeMember(member),
                                  },
                                ],
                              )
                            }
                          >
                            <Text style={styles.removeText}>
                              Encerrar participação
                            </Text>
                          </Pressable>
                        </View>
                      ) : null}
                    </View>
                  </View>
                );
              })
            ) : (
              <Empty>Nenhum participante nesta seleção.</Empty>
            )}

            {resource.data && pages > 1 ? (
              <View style={styles.pager}>
                <Pressable
                  disabled={page === 1}
                  onPress={() => setPage((value) => value - 1)}
                >
                  <Text style={styles.pageLink}>← Anterior</Text>
                </Pressable>

                <Text style={styles.pageText}>
                  {page}/{pages}
                </Text>

                <Pressable
                  disabled={page === pages}
                  onPress={() => setPage((value) => value + 1)}
                >
                  <Text style={styles.pageLink}>Próxima →</Text>
                </Pressable>
              </View>
            ) : null}

            {global && ministry.status === "ACTIVE" ? (
              <Pressable
                accessibilityRole="button"
                onPress={() => setUserPicker(true)}
                style={styles.addMemberButton}
              >
                <Ionicons
                  name="person-add-outline"
                  size={19}
                  color={colors.white}
                />

                <Text style={styles.addMemberText}>Adicionar participante</Text>
              </Pressable>
            ) : null}
          </ScrollView>
        </View>
      </View>

      {/* ESCOLHER PESSOA */}

      <Modal
        visible={userPicker}
        transparent
        animationType="fade"
        onRequestClose={() => setUserPicker(false)}
      >
        <View style={styles.modalContainer}>
          <Pressable
            style={styles.backdrop}
            onPress={() => setUserPicker(false)}
          />

          <View style={styles.userPickerModal}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Selecionar pessoa</Text>

              <Pressable onPress={() => setUserPicker(false)}>
                <Ionicons name="close" size={24} color={colors.ink} />
              </Pressable>
            </View>

            <ScrollView>
              {users.map((target) => (
                <Pressable
                  key={target.id}
                  disabled={busy}
                  onPress={() => void addMember(target)}
                  style={styles.userOption}
                >
                  <View style={styles.memberAvatar}>
                    <Ionicons
                      name="person-outline"
                      size={18}
                      color={colors.green}
                    />
                  </View>

                  <View style={{ flex: 1 }}>
                    <Text style={styles.memberName}>{target.name}</Text>

                    <Text style={styles.userEmail}>{target.email}</Text>
                  </View>

                  <Ionicons
                    name="add-circle-outline"
                    size={22}
                    color={colors.green}
                  />
                </Pressable>
              ))}
            </ScrollView>
          </View>
        </View>
      </Modal>
    </Modal>
  );
}

/* =========================================
   COMPONENTES AUXILIARES
========================================= */

function StatusOption({
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
      style={[styles.statusOption, selected && styles.statusSelected]}
    >
      <Text
        style={[styles.statusOptionText, selected && styles.statusSelectedText]}
      >
        {label}
      </Text>

      {selected ? (
        <Ionicons name="checkmark" size={20} color={colors.green} />
      ) : null}
    </Pressable>
  );
}

function ChoiceButton({
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
      style={[styles.choiceButton, selected && styles.choiceButtonSelected]}
    >
      <Text
        style={[
          styles.choiceButtonText,

          selected && styles.choiceButtonTextSelected,
        ]}
      >
        {label}
      </Text>
    </Pressable>
  );
}

function slugify(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

/* =========================================
   ESTILOS
========================================= */

const styles = StyleSheet.create({
  headerRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 12,
  },

  headerContent: {
    flex: 1,
  },

  createButton: {
    minHeight: 50,
    backgroundColor: colors.green,
    borderRadius: 12,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 7,
    marginBottom: 14,
  },

  createButtonText: {
    color: colors.white,
    fontWeight: "800",
    fontSize: 16,
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
    minHeight: 43,
    alignItems: "center",
    justifyContent: "center",
    borderRadius: 9,
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

  filterArea: {
    marginBottom: 15,
  },

  filterLabel: {
    color: colors.ink,
    fontSize: 12,
    fontWeight: "800",
    marginBottom: 6,
  },

  filterButton: {
    minHeight: 47,
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    paddingHorizontal: 13,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 11,
    backgroundColor: colors.white,
  },

  filterValue: {
    color: colors.ink,
    fontWeight: "700",
  },

  ministryIcon: {
    width: 48,
    height: 48,
    borderRadius: 13,
    backgroundColor: colors.greenSoft,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 18,
  },

  title: {
    color: colors.ink,
    fontSize: 20,
    fontWeight: "900",
  },

  body: {
    color: colors.muted,
    lineHeight: 21,
    marginTop: 9,
  },

  badgeArea: {
    alignSelf: "flex-start",
    marginTop: 14,
  },

  actions: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 7,
    marginTop: 18,
  },

  actionButton: {
    minHeight: 40,
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 9,
    paddingHorizontal: 11,
  },

  actionText: {
    color: colors.ink,
    fontWeight: "700",
    fontSize: 13,
  },

  deactivateButton: {
    minHeight: 40,
    justifyContent: "center",
    paddingHorizontal: 8,
  },

  deactivateText: {
    color: colors.danger,
    fontWeight: "700",
    fontSize: 13,
  },

  pager: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginTop: 10,
  },

  pageLink: {
    color: colors.green,
    fontWeight: "800",
  },

  pageText: {
    color: colors.muted,
    fontSize: 12,
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
    backgroundColor: "rgba(0,0,0,0.35)",
  },

  smallModal: {
    backgroundColor: colors.cream,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    padding: 18,
  },

  editorModal: {
    maxHeight: "90%",
    backgroundColor: colors.cream,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    paddingHorizontal: 18,
    paddingTop: 18,
  },

  membersModal: {
    height: "88%",
    backgroundColor: colors.cream,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    paddingHorizontal: 18,
    paddingTop: 18,
    paddingBottom: 36,
  },

  userPickerModal: {
    maxHeight: "70%",
    backgroundColor: colors.cream,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    paddingHorizontal: 18,
    paddingTop: 18,
    paddingBottom: 36,
  },

  modalHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    gap: 12,
    marginBottom: 16,
  },

  modalTitle: {
    color: colors.ink,
    fontSize: 21,
    fontWeight: "900",
  },

  modalSubtitle: {
    color: colors.muted,
    marginTop: 2,
  },

  statusOption: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    borderRadius: 11,
    paddingHorizontal: 12,
  },

  statusSelected: {
    backgroundColor: colors.greenSoft,
  },

  statusOptionText: {
    color: colors.ink,
    fontWeight: "700",
  },

  statusSelectedText: {
    color: colors.greenDark,
    fontWeight: "900",
  },

  hint: {
    color: colors.muted,
    fontSize: 11,
    lineHeight: 16,
    marginTop: -10,
    marginBottom: 15,
  },

  stateChoices: {
    flexDirection: "row",
    gap: 8,
    marginBottom: 18,
  },

  choiceButton: {
    minHeight: 40,
    justifyContent: "center",
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 999,
    paddingHorizontal: 14,
    backgroundColor: colors.white,
  },

  choiceButtonSelected: {
    borderColor: colors.green,
    backgroundColor: colors.greenSoft,
  },

  choiceButtonText: {
    color: colors.muted,
    fontWeight: "700",
  },

  choiceButtonTextSelected: {
    color: colors.greenDark,
  },

  descriptionInput: {
    minHeight: 130,
  },

  saveButton: {
    flexDirection: "row",
    gap: 7,
    marginTop: 6,
  },

  saveText: {
    marginLeft: 2,
  },

  memberFilter: {
    flexDirection: "row",
    gap: 8,
    marginBottom: 12,
  },

  memberRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 10,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 13,
    padding: 12,
    marginBottom: 9,
  },

  memberAvatar: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: colors.greenSoft,
    alignItems: "center",
    justifyContent: "center",
  },

  memberContent: {
    flex: 1,
  },

  memberName: {
    color: colors.ink,
    fontWeight: "900",
    fontSize: 14,
  },

  memberBadges: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 5,
    marginTop: 6,
  },

  leaderText: {
    color: colors.green,
    fontSize: 11,
    fontWeight: "800",
    marginTop: 6,
  },

  memberActions: {
    gap: 8,
    marginTop: 12,
  },

  memberActionText: {
    color: colors.green,
    fontWeight: "800",
    fontSize: 12,
  },

  removeText: {
    color: colors.danger,
    fontWeight: "800",
    fontSize: 12,
  },

  addMemberButton: {
    minHeight: 48,
    flexDirection: "row",
    justifyContent: "center",
    alignItems: "center",
    gap: 7,
    backgroundColor: colors.green,
    borderRadius: 11,
    marginTop: 15,
    marginBottom: 10,
  },

  addMemberText: {
    color: colors.white,
    fontWeight: "800",
  },

  userOption: {
    minHeight: 61,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    paddingVertical: 8,
  },

  userEmail: {
    color: colors.muted,
    fontSize: 11,
    marginTop: 3,
  },

  disabled: {
    opacity: 0.45,
  },

  pressed: {
    opacity: 0.7,
  },
});
