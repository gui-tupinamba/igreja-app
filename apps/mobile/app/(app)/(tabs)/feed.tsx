import { useMemo, useState } from "react";
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

import {
  Badge,
  Card,
  Empty,
  ErrorState,
  Heading,
  Loading,
  ProtectedImage,
  Screen,
} from "@/components";

import { PostManagementActions } from "@/PostManagementActions";
import { colors } from "@/theme";
import { useResource } from "@/useResource";

import type { Content, Ministry, Page, Permissions } from "@/types";

type ManagementStatus =
  | ""
  | "DRAFT"
  | "PENDING_REVIEW"
  | "PUBLISHED"
  | "ARCHIVED";

type VisibilityFilter = "" | "PUBLIC" | "MINISTRY_MEMBERS";

type FilterModal = "ministry" | "visibility" | "status" | null;

type Option = {
  value: string;
  label: string;
  icon?: keyof typeof Ionicons.glyphMap;
};

export default function Feed() {
  const [page, setPage] = useState(1);

  const [search, setSearch] = useState("");

  const [query, setQuery] = useState("");

  const [manage, setManage] = useState(false);

  const [status, setStatus] = useState<ManagementStatus>("");

  const [ministryFilter, setMinistryFilter] = useState("");

  const [visibilityFilter, setVisibilityFilter] =
    useState<VisibilityFilter>("");

  const [openFilter, setOpenFilter] = useState<FilterModal>(null);

  const access = useResource<{
    permissions: Permissions;
  }>("/auth/permissions");

  const ministries = useResource<Page<Ministry>>(
    "/ministries?page=1&limit=100",
  );

  const canManage =
    !!access.data &&
    (access.data.permissions.manage_ministries ||
      access.data.permissions.led_ministries.length > 0);

  const basePath = manage && canManage ? "/admin/posts" : "/posts";

  const params = [
    `page=${page}`,
    "limit=10",

    query ? `q=${encodeURIComponent(query)}` : "",

    ministryFilter ? `ministry_id=${encodeURIComponent(ministryFilter)}` : "",

    visibilityFilter ? `visibility=${visibilityFilter}` : "",

    manage && status ? `status=${status}` : "",
  ]
    .filter(Boolean)
    .join("&");

  const resource = useResource<Page<Content>>(`${basePath}?${params}`);

  const pages = Math.max(
    1,
    Math.ceil((resource.data?.pagination.total || 0) / 10),
  );

  const ministryOptions = useMemo<Option[]>(
    () => [
      {
        value: "",
        label: "Todos os ministérios",
        icon: "layers-outline",
      },

      {
        value: "null",
        label: "Igreja em geral",
        icon: "home-outline",
      },

      ...(ministries.data?.items || []).map((ministry) => ({
        value: String(ministry.id),
        label: ministry.name,
        icon: "people-outline" as const,
      })),
    ],
    [ministries.data],
  );

  const visibilityOptions = useMemo<Option[]>(
    () => [
      {
        value: "",
        label: "Todas as visibilidades",
        icon: "layers-outline",
      },

      {
        value: "PUBLIC",
        label: "Toda a igreja",
        icon: "earth-outline",
      },

      ...(ministryFilter !== "null"
        ? [
            {
              value: "MINISTRY_MEMBERS",
              label: "Somente integrantes",
              icon: "lock-closed-outline" as const,
            },
          ]
        : []),
    ],
    [ministryFilter],
  );

  const statusOptions = useMemo<Option[]>(
    () => [
      {
        value: "",
        label: "Todos os estados",
      },

      {
        value: "DRAFT",
        label: "Rascunhos",
      },

      {
        value: "PENDING_REVIEW",
        label: "Aguardando revisão",
      },

      {
        value: "PUBLISHED",
        label: "Publicados",
      },

      {
        value: "ARCHIVED",
        label: "Arquivados",
      },
    ],
    [],
  );

  const selectedMinistryLabel =
    ministryOptions.find((option) => option.value === ministryFilter)?.label ||
    "Todos os ministérios";

  const selectedVisibilityLabel =
    visibilityOptions.find((option) => option.value === visibilityFilter)
      ?.label || "Todas as visibilidades";

  const selectedStatusLabel =
    statusOptions.find((option) => option.value === status)?.label ||
    "Todos os estados";

  const hasFilters =
    ministryFilter !== "" ||
    visibilityFilter !== "" ||
    (manage && status !== "");

  function submitSearch() {
    setPage(1);
    setQuery(search.trim());
  }

  function changeMode(administrative: boolean) {
    setManage(administrative);
    setPage(1);
    setStatus("");
  }

  function changeMinistry(value: string) {
    setMinistryFilter(value);
    setPage(1);

    if (value === "null" && visibilityFilter === "MINISTRY_MEMBERS") {
      setVisibilityFilter("");
    }

    setOpenFilter(null);
  }

  function changeVisibility(value: string) {
    setVisibilityFilter(value as VisibilityFilter);

    setPage(1);
    setOpenFilter(null);
  }

  function changeStatus(value: string) {
    setStatus(value as ManagementStatus);

    setPage(1);
    setOpenFilter(null);
  }

  function clearFilters() {
    setMinistryFilter("");
    setVisibilityFilter("");
    setStatus("");
    setPage(1);
  }

  return (
    <Screen>
      {/* CABEÇALHO */}

      <View style={styles.headerRow}>
        <View style={styles.headerContent}>
          <Heading
            title="Publicações"
            subtitle="Notícias, palavras e momentos da nossa comunidade."
          />
        </View>
      </View>

      {/* COMUNIDADE / GERENCIAR */}

      {canManage ? (
        <View style={styles.tabs}>
          <Pressable
            accessibilityRole="button"
            accessibilityState={{
              selected: !manage,
            }}
            onPress={() => changeMode(false)}
            style={[styles.tab, !manage && styles.tabActive]}
          >
            <Ionicons
              name="people-outline"
              size={18}
              color={!manage ? colors.white : colors.muted}
            />

            <Text style={[styles.tabText, !manage && styles.tabTextActive]}>
              Comunidade
            </Text>
          </Pressable>

          <Pressable
            accessibilityRole="button"
            accessibilityState={{
              selected: manage,
            }}
            onPress={() => changeMode(true)}
            style={[styles.tab, manage && styles.tabActive]}
          >
            <Ionicons
              name="settings-outline"
              size={18}
              color={manage ? colors.white : colors.muted}
            />

            <Text style={[styles.tabText, manage && styles.tabTextActive]}>
              Gerenciar
            </Text>
          </Pressable>
        </View>
      ) : null}

      {/* NOVA PUBLICAÇÃO */}

      {canManage ? (
        <Pressable
          accessibilityRole="button"
          onPress={() => router.push("/(app)/post/create")}
          style={({ pressed }) => [
            styles.createButton,

            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="create-outline" size={20} color={colors.white} />

          <Text style={styles.createButtonText}>Nova publicação</Text>
        </Pressable>
      ) : null}

      {/* FILTROS */}

      <View style={styles.filtersCard}>
        <View style={styles.filtersHeader}>
          <View style={styles.filtersTitleRow}>
            <Ionicons name="filter-outline" size={19} color={colors.green} />

            <Text style={styles.filtersTitle}>Filtrar publicações</Text>
          </View>

          {hasFilters ? (
            <Pressable accessibilityRole="button" onPress={clearFilters}>
              <Text style={styles.clearFilters}>Limpar</Text>
            </Pressable>
          ) : null}
        </View>

        <FilterSelect
          label="Ministério"
          value={selectedMinistryLabel}
          icon="people-outline"
          onPress={() => setOpenFilter("ministry")}
        />

        <FilterSelect
          label="Visibilidade"
          value={selectedVisibilityLabel}
          icon="eye-outline"
          onPress={() => setOpenFilter("visibility")}
        />

        {manage && canManage ? (
          <FilterSelect
            label="Estado"
            value={selectedStatusLabel}
            icon="document-text-outline"
            onPress={() => setOpenFilter("status")}
            last
          />
        ) : null}
      </View>

      {/* BUSCA */}

      <View style={styles.search}>
        <TextInput
          accessibilityLabel="Buscar publicações"
          placeholder="Buscar por título ou conteúdo"
          placeholderTextColor={colors.muted}
          value={search}
          onChangeText={setSearch}
          onSubmitEditing={submitSearch}
          style={styles.searchInput}
          returnKeyType="search"
        />

        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Buscar"
          onPress={submitSearch}
          style={({ pressed }) => [
            styles.searchButton,

            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="search" size={20} color={colors.white} />
        </Pressable>
      </View>

      {/* LISTAGEM */}

      {resource.loading ? (
        <Loading />
      ) : resource.error ? (
        <ErrorState error={resource.error} retry={resource.reload} />
      ) : resource.data?.items.length ? (
        resource.data.items.map((item) => (
          <Card key={item.id}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`Abrir publicação ${item.title}`}
              onPress={() =>
                router.push({
                  pathname: "/(app)/post/[id]",

                  params: {
                    id: item.id,

                    manage: manage && canManage ? "1" : "0",
                  },
                })
              }
              style={({ pressed }) => [pressed && styles.cardPressed]}
            >
              <ProtectedImage
                image={item.images?.[0]}
                accessibilityLabel={`Imagem de ${item.title}`}
              />

              <View style={styles.meta}>
                <Badge value={item.visibility} />

                {item.status ? <Badge value={item.status} /> : null}
              </View>

              <Text style={styles.title}>{item.title}</Text>

              <Text numberOfLines={4} style={styles.body}>
                {item.content}
              </Text>

              <View style={styles.readRow}>
                <Text style={styles.open}>
                  {manage ? "Gerenciar publicação" : "Ler publicação"}
                </Text>

                <Ionicons name="arrow-forward" size={17} color={colors.green} />
              </View>
            </Pressable>

            {manage && canManage && item.status !== "ARCHIVED" ? (
              <PostManagementActions
                post={{
                  id: item.id,
                  status: item.status,
                }}
                onChanged={resource.reload}
              />
            ) : null}

            {manage && item.status === "ARCHIVED" ? (
              <View style={styles.archivedInfo}>
                <Ionicons
                  name="archive-outline"
                  size={18}
                  color={colors.muted}
                />

                <Text style={styles.archivedText}>
                  Esta publicação está arquivada.
                </Text>
              </View>
            ) : null}
          </Card>
        ))
      ) : (
        <Empty>
          Nenhuma publicação encontrada com os filtros selecionados.
        </Empty>
      )}

      {/* PAGINAÇÃO */}

      {resource.data && pages > 1 ? (
        <View style={styles.pager}>
          <Pressable
            disabled={page === 1}
            onPress={() => setPage((value) => value - 1)}
            style={styles.pageButton}
          >
            <Ionicons name="chevron-back" size={17} color={colors.green} />

            <Text style={[styles.pageLink, page === 1 && styles.disabled]}>
              Anterior
            </Text>
          </Pressable>

          <Text style={styles.page}>
            Página {page} de {pages}
          </Text>

          <Pressable
            disabled={page === pages}
            onPress={() => setPage((value) => value + 1)}
            style={styles.pageButton}
          >
            <Text style={[styles.pageLink, page === pages && styles.disabled]}>
              Próxima
            </Text>

            <Ionicons name="chevron-forward" size={17} color={colors.green} />
          </Pressable>
        </View>
      ) : null}

      {/* MODAL MINISTÉRIO */}

      <FilterOptionsModal
        visible={openFilter === "ministry"}
        title="Selecionar ministério"
        selected={ministryFilter}
        options={ministryOptions}
        onClose={() => setOpenFilter(null)}
        onSelect={changeMinistry}
      />

      {/* MODAL VISIBILIDADE */}

      <FilterOptionsModal
        visible={openFilter === "visibility"}
        title="Selecionar visibilidade"
        selected={visibilityFilter}
        options={visibilityOptions}
        onClose={() => setOpenFilter(null)}
        onSelect={changeVisibility}
      />

      {/* MODAL STATUS */}

      <FilterOptionsModal
        visible={openFilter === "status"}
        title="Selecionar estado"
        selected={status}
        options={statusOptions}
        onClose={() => setOpenFilter(null)}
        onSelect={changeStatus}
      />
    </Screen>
  );
}

function FilterSelect({
  label,
  value,
  icon,
  onPress,
  last = false,
}: {
  label: string;
  value: string;

  icon: keyof typeof Ionicons.glyphMap;

  onPress: () => void;
  last?: boolean;
}) {
  return (
    <View style={[styles.selectGroup, !last && styles.selectGroupBorder]}>
      <Text style={styles.selectLabel}>{label}</Text>

      <Pressable
        accessibilityRole="button"
        onPress={onPress}
        style={({ pressed }) => [
          styles.selectButton,

          pressed && styles.pressed,
        ]}
      >
        <View style={styles.selectLeft}>
          <Ionicons name={icon} size={19} color={colors.green} />

          <Text numberOfLines={1} style={styles.selectValue}>
            {value}
          </Text>
        </View>

        <Ionicons name="chevron-forward" size={18} color={colors.muted} />
      </Pressable>
    </View>
  );
}

function FilterOptionsModal({
  visible,
  title,
  selected,
  options,
  onClose,
  onSelect,
}: {
  visible: boolean;
  title: string;
  selected: string;
  options: Option[];
  onClose: () => void;
  onSelect: (value: string) => void;
}) {
  const insets = useSafeAreaInsets();
  return (
    <Modal
      visible={visible}
      transparent
      animationType="fade"
      onRequestClose={onClose}
    >
      <View style={styles.modalContainer}>
        <Pressable style={styles.modalBackdrop} onPress={onClose} />

        <View
          style={[
            styles.modalCard,
            {
              paddingBottom: Math.max(insets.bottom + 28, 48),
            },
          ]}
        >
          <View style={styles.modalHeader}>
            <Text style={styles.modalTitle}>{title}</Text>

            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Fechar"
              onPress={onClose}
              style={styles.closeButton}
            >
              <Ionicons name="close" size={23} color={colors.ink} />
            </Pressable>
          </View>

          <ScrollView
            style={styles.optionsList}
            showsVerticalScrollIndicator={false}
          >
            {options.map((option) => {
              const isSelected = selected === option.value;

              return (
                <Pressable
                  key={option.value || "__all"}
                  accessibilityRole="button"
                  onPress={() => onSelect(option.value)}
                  style={({ pressed }) => [
                    styles.option,

                    isSelected && styles.optionSelected,

                    pressed && styles.pressed,
                  ]}
                >
                  <View style={styles.optionLeft}>
                    {option.icon ? (
                      <Ionicons
                        name={option.icon}
                        size={19}
                        color={isSelected ? colors.green : colors.muted}
                      />
                    ) : null}

                    <Text
                      style={[
                        styles.optionText,

                        isSelected && styles.optionTextSelected,
                      ]}
                    >
                      {option.label}
                    </Text>
                  </View>

                  {isSelected ? (
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
  );
}

const styles = StyleSheet.create({
  headerRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 12,
  },

  headerContent: {
    flex: 1,
  },

  addButton: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: colors.green,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 2,
  },

  tabs: {
    flexDirection: "row",
    gap: 8,
    marginBottom: 14,
    padding: 4,
    borderRadius: 14,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },

  tab: {
    flex: 1,
    minHeight: 44,
    borderRadius: 10,
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

  createButton: {
    minHeight: 50,
    borderRadius: 12,
    backgroundColor: colors.green,
    justifyContent: "center",
    alignItems: "center",
    flexDirection: "row",
    gap: 8,
    marginBottom: 14,
  },

  createButtonText: {
    color: colors.white,
    fontSize: 16,
    fontWeight: "800",
  },

  filtersCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 15,
    backgroundColor: colors.white,
    paddingHorizontal: 14,
    paddingTop: 14,
    marginBottom: 14,
  },

  filtersHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginBottom: 8,
  },

  filtersTitleRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 7,
  },

  filtersTitle: {
    color: colors.ink,
    fontSize: 16,
    fontWeight: "900",
  },

  clearFilters: {
    color: colors.green,
    fontWeight: "800",
    fontSize: 12,
  },

  selectGroup: {
    paddingVertical: 11,
  },

  selectGroupBorder: {
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },

  selectLabel: {
    color: colors.muted,
    fontSize: 11,
    fontWeight: "700",
    marginBottom: 6,
  },

  selectButton: {
    minHeight: 42,
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
  },

  selectLeft: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: 9,
  },

  selectValue: {
    flex: 1,
    color: colors.ink,
    fontWeight: "800",
    fontSize: 14,
  },

  search: {
    flexDirection: "row",
    marginBottom: 17,
    gap: 8,
  },

  searchInput: {
    flex: 1,
    minHeight: 48,
    paddingHorizontal: 13,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
    color: colors.ink,
  },

  searchButton: {
    width: 50,
    minHeight: 48,
    justifyContent: "center",
    alignItems: "center",
    borderRadius: 12,
    backgroundColor: colors.green,
  },

  meta: {
    flexDirection: "row",
    alignItems: "center",
    flexWrap: "wrap",
    gap: 7,
  },

  title: {
    color: colors.ink,
    fontSize: 20,
    lineHeight: 26,
    fontWeight: "800",
    marginTop: 10,
  },

  body: {
    color: colors.muted,
    lineHeight: 21,
    marginTop: 7,
  },

  readRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    marginTop: 12,
  },

  open: {
    color: colors.green,
    fontWeight: "800",
  },

  archivedInfo: {
    marginTop: 14,
    flexDirection: "row",
    alignItems: "center",
    gap: 7,
  },

  archivedText: {
    color: colors.muted,
    fontWeight: "700",
    fontSize: 12,
  },

  pager: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginTop: 8,
  },

  pageButton: {
    flexDirection: "row",
    alignItems: "center",
    minHeight: 44,
  },

  pageLink: {
    color: colors.green,
    fontWeight: "800",
  },

  page: {
    color: colors.muted,
    fontSize: 12,
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
    maxHeight: "70%",
    backgroundColor: colors.cream,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    paddingHorizontal: 18,
    paddingTop: 18,
  },

  modalHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginBottom: 13,
  },

  modalTitle: {
    color: colors.ink,
    fontSize: 20,
    fontWeight: "900",
  },

  closeButton: {
    width: 42,
    height: 42,
    alignItems: "center",
    justifyContent: "center",
  },

  optionsList: {
    flexGrow: 0,
  },

  option: {
    minHeight: 55,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingHorizontal: 13,
    borderRadius: 12,
    marginBottom: 6,
  },

  optionSelected: {
    backgroundColor: colors.greenSoft,
  },

  optionLeft: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },

  optionText: {
    flex: 1,
    color: colors.ink,
    fontSize: 15,
    fontWeight: "700",
  },

  optionTextSelected: {
    color: colors.greenDark,
    fontWeight: "900",
  },

  pressed: {
    opacity: 0.7,
  },

  cardPressed: {
    opacity: 0.8,
  },

  disabled: {
    opacity: 0.35,
  },
});
