import { useEffect, useState } from "react";
import { ContentImagePicker } from "../ContentImagePicker";
import { useSearchParams } from "react-router-dom";
import {
  Plus,
  MessageCircle,
  CalendarDays,
  LockKeyhole,
  Globe,
  Pencil,
  Archive,
  Check,
  X,
} from "lucide-react";
import { api, apiBlob, apiForm, ApiError } from "../api";
import { useSession } from "../session";
import {
  Badge,
  Choice,
  Confirm,
  date,
  Empty,
  ErrorBox,
  Form,
  Heading,
  instant,
  Loading,
  localDate,
  Modal,
  nullable,
  Pager,
  query,
  text,
  useData,
} from "../ui";
import type { Comment, Content, ContentImage, Page } from "../types";

type Kind = "posts" | "events" | "schedules";
const configs = {
  posts: {
    title: "Publicações",
    subtitle: "Notícias, palavras e momentos da nossa comunidade.",
    singular: "publicação",
    field: "post",
  },
  events: {
    title: "Eventos",
    subtitle: "Encontros que aproximam e fortalecem nossa caminhada.",
    singular: "evento",
    field: "event",
  },
  schedules: {
    title: "Atividades",
    subtitle: "Organize os compromissos da igreja e dos ministérios.",
    singular: "atividade",
    field: "schedule",
  },
};
export function ContentPage({ kind }: { kind: Kind }) {
  const { permissions, ministries, canManage } = useSession();
  const canCreate =
    permissions.manage_ministries || permissions.led_ministries.length > 0;
  const config = configs[kind];
  const [params, setParams] = useSearchParams();
  const [manage, setManage] = useState(false);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({
    q: "",
    ministry_id: "",
    visibility: "",
    status: "",
    from: "",
    to: "",
  });
  const [editor, setEditor] = useState<Content | "new" | null>(null);
  const [selected, setSelected] = useState<number | null>(null);
  const [error, setError] = useState<unknown>();
  const administrative = manage && canCreate;
  const path = `${administrative ? "/admin" : ""}/${kind}?${query({ ...filters, status: administrative ? filters.status : kind === "posts" ? "" : filters.status, from: filters.from ? instant(filters.from)! : "", to: filters.to ? instant(filters.to)! : "", page, limit: 12 })}`;
  // Posts do not accept temporal filters.
  const list = useData<Page<Content>>(
    kind === "posts" ? path.replace(/&?(?:from|to)=[^&]*/g, "") : path,
  );
  useEffect(() => {
    if (params.get("create") === "1" && canCreate) {
      setEditor("new");
      setParams({}, { replace: true });
    }
    if (params.get("post") && kind === "posts") {
      setSelected(Number(params.get("post")));
      setParams({}, { replace: true });
    }
  }, [params, canCreate, kind, setParams]);
  function filter(key: string, value: string) {
    setPage(1);
    setFilters((f) => ({ ...f, [key]: value }));
  }
  return (
    <>
      <Heading title={config.title} subtitle={config.subtitle}>
        {canCreate && (
          <button className="primary" onClick={() => setEditor("new")}>
            <Plus size={18} />{" "}
            {kind === "events"
              ? "Novo evento"
              : kind === "schedules"
                ? "Nova atividade"
                : "Nova publicação"}
          </button>
        )}
      </Heading>
      {canCreate && (
        <div className="tabs">
          <button
            aria-pressed={!administrative}
            className={!administrative ? "active" : ""}
            onClick={() => {
              setManage(false);
              setPage(1);
            }}
          >
            Comunidade
          </button>
          <button
            aria-pressed={administrative}
            className={administrative ? "active" : ""}
            onClick={() => {
              setManage(true);
              setPage(1);
            }}
          >
            Gerenciar
          </button>
        </div>
      )}
      <div className="filters">
        <label>
          Buscar
          <input
            type="search"
            value={filters.q}
            placeholder="Título ou conteúdo"
            maxLength={120}
            onChange={(e) => filter("q", e.target.value)}
          />
        </label>
        <label>
          Ministério
          <select
            value={filters.ministry_id}
            onChange={(e) => filter("ministry_id", e.target.value)}
          >
            <option value="">Todos os ministérios</option>
            <option value="null">Igreja em geral</option>
            {ministries.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          Visibilidade
          <select
            value={filters.visibility}
            onChange={(e) => filter("visibility", e.target.value)}
          >
            <option value="">Todas</option>
            <option value="PUBLIC">Toda a igreja</option>
            <option value="MINISTRY_MEMBERS">Integrantes</option>
          </select>
        </label>
        {(administrative || kind !== "posts") && (
          <label>
            Estado
            <select
              value={filters.status}
              onChange={(e) => filter("status", e.target.value)}
            >
              <option value="">Todos</option>
              {administrative && <option value="DRAFT">Rascunhos</option>}

              {administrative && kind === "posts" && (
                <option value="PENDING_REVIEW">Aguardando revisão</option>
              )}

              <option value="PUBLISHED">Publicados</option>

              {kind !== "posts" && (
                <option value="CANCELLED">Cancelados</option>
              )}
              {administrative && <option value="ARCHIVED">Arquivados</option>}
            </select>
          </label>
        )}
        {kind !== "posts" && (
          <>
            <label>
              A partir de
              <input
                type="datetime-local"
                value={filters.from}
                onChange={(e) => filter("from", e.target.value)}
              />
            </label>
            <label>
              Até
              <input
                type="datetime-local"
                value={filters.to}
                onChange={(e) => filter("to", e.target.value)}
              />
            </label>
          </>
        )}
      </div>
      <ErrorBox error={list.error || error} />
      {list.loading ? (
        <Loading />
      ) : list.data?.items.length ? (
        <div className={kind === "posts" ? "content-grid" : "event-list"}>
          {list.data.items.map((item) => (
            <article className="content-card" key={item.id}>
              {(kind === "posts" || kind === "events") && (
                <ProtectedPostImage image={item.images?.[0]} alt={item.title} />
              )}

              <div className="row-meta">
                <span className="ministry-tag">
                  {item.ministry_id
                    ? ministries.find((m) => m.id === item.ministry_id)?.name ||
                      "Ministério"
                    : "Nossa igreja"}
                </span>
                <Badge value={item.status} />
              </div>

              <button
                className="card-title"
                onClick={() => setSelected(item.id)}
              >
                <h2>{item.title}</h2>
              </button>
              {kind !== "posts" && (
                <div className="time-line">
                  <CalendarDays size={17} />
                  {date(item.starts_at)}
                </div>
              )}
              <p className="excerpt">
                {(
                  item.content ||
                  item.description ||
                  "Confira os detalhes deste encontro."
                ).slice(0, 220)}
              </p>
              <div className="card-footer">
                <span>
                  {item.visibility === "PUBLIC" ? (
                    <Globe size={15} />
                  ) : (
                    <LockKeyhole size={15} />
                  )}{" "}
                  {item.visibility === "PUBLIC"
                    ? "Toda a igreja"
                    : "Integrantes"}
                </span>
                <button
                  className="link-button"
                  onClick={() => setSelected(item.id)}
                >
                  Ver detalhes
                </button>
              </div>
            </article>
          ))}
        </div>
      ) : (
        !list.error && (
          <Empty
            title={
              administrative
                ? "Nenhum registro nesta seleção"
                : "Ainda não há novidades por aqui"
            }
          >
            {administrative
              ? "Crie um registro ou ajuste os filtros."
              : "Acompanhe este espaço para as próximas novidades."}
          </Empty>
        )
      )}
      {list.data && (
        <Pager pagination={list.data.pagination} onPage={setPage} />
      )}{" "}
      {editor && (
        <ContentEditor
          kind={kind}
          item={editor === "new" ? undefined : editor}
          onClose={() => setEditor(null)}
          onSaved={() => {
            setEditor(null);
            setManage(true);
            list.reload();
          }}
        />
      )}
      {selected && (
        <ContentDetail
          kind={kind}
          id={selected}
          administrative={administrative}
          onClose={() => setSelected(null)}
          onChange={() => list.reload()}
          onEdit={(item) => {
            setSelected(null);
            setEditor(item);
          }}
        />
      )}
    </>
  );
}
export function ContentEditor({
  kind,
  item,
  onClose,
  onSaved,
}: {
  kind: Kind;
  item?: Content;
  onClose: () => void;
  onSaved: (published: boolean) => void;
}) {
  const { user, ministries, permissions } = useSession();
  const config = configs[kind];

  const canPublishPost = user.role === "ADMIN" || user.role === "PASTOR";

  const isNewPost = !item && kind === "posts";
  
  const [imageFiles, setImageFiles] = useState<File[]>([]);

  let destinations = permissions.manage_ministries
    ? ministries
    : ministries.filter((m) =>
        permissions.led_ministries.some((l) => l.id === m.id),
      );
  const choices = [
    ...(permissions.manage_ministries
      ? [{ id: "", name: "Igreja em geral" }]
      : []),
    ...destinations.map((m) => ({ id: m.id, name: m.name })),
  ];
  if (
    item?.ministry_id &&
    !choices.some((c) => String(c.id) === String(item.ministry_id))
  )
    choices.push({
      id: item.ministry_id,
      name: "Ministério de origem (histórico)",
    });
  return (
    <Modal
      title={`${item ? "Editar" : kind === "events" ? "Novo" : "Nova"} ${config.singular}`}
      onClose={onClose}
    >
      <Form
        // onClose={onClose}
        // afterSave={onSaved}
        // onSave={async (f) => {
        //   const ministry = text(f, "ministry_id");
        //   const body = {
        //     title: text(f, "title"),
        //     ministry_id: ministry ? Number(ministry) : null,
        //     visibility: text(f, "visibility"),
        //     ...(kind === "posts"
        //       ? {
        //           content: text(f, "content"),
        //           comments_enabled: f.get("comments_enabled") === "on",
        //         }
        //       : {
        //           description: nullable(f, "description"),
        //           starts_at: instant(text(f, "starts_at")),
        //           ends_at: instant(text(f, "ends_at")),
        //           ...(kind === "events"
        //             ? {
        //                 location: nullable(f, "location"),
        //                 address: nullable(f, "address"),
        //               }
        //             : {}),
        //         }),
        //   };
        //   await api(
        //     `/${kind}${item ? `/${item.id}` : ""}`,
        //     item ? "PATCH" : "POST",
        //     body,
        //   );
        // }}

        onClose={onClose}

        submit={
          isNewPost
            ? canPublishPost
              ? "Salvar e publicar"
              : "Enviar para revisão"
            : "Salvar"
        }

        secondarySubmit={isNewPost ? "Salvar rascunho" : undefined}

        onSecondarySave={
          isNewPost
            ? async (f) => {
                await saveContentWithImages(kind, item, f, imageFiles);
              }
            : undefined
        }

        onSave={async (f) => {
          const result = await saveContentWithImages(kind, item, f, imageFiles);

          if (isNewPost) {
            const post = result.post;

            const action = canPublishPost ? "publish" : "submit-review";

            await api(`/posts/${post.id}/${action}`, "POST", {});
          }
        }}

        afterSave={(action) => {
          onSaved(!item && kind === "posts" && action === "primary");
        }}
      >
        <label>
          Título
          <input
            name="title"
            required
            maxLength={180}
            defaultValue={item?.title}
          />
        </label>
        <div className="form-grid">
          <Choice
            name="ministry_id"
            label="Ministério"
            options={choices}
            defaultValue={
              item?.ministry_id ??
              (permissions.manage_ministries ? "" : choices[0]?.id)
            }
          />
          <Choice
            name="visibility"
            label="Quem pode ver"
            defaultValue={item?.visibility || "PUBLIC"}
            options={[
              { id: "PUBLIC", name: "Toda a igreja" },
              { id: "MINISTRY_MEMBERS", name: "Integrantes do ministério" },
            ]}
          />
        </div>
        <label>
          {kind === "posts" ? "Conteúdo" : "Descrição"}
          <textarea
            name={kind === "posts" ? "content" : "description"}
            rows={7}
            required={kind === "posts"}
            maxLength={50000}
            defaultValue={item?.content || item?.description || ""}
          />
        </label>

        {(kind === "posts" || kind === "events") && !item && (
          <div className="content-images-field">
            <div className="content-images-title">
              <strong>Imagens</strong>
              <span>Opcional</span>
            </div>

            <ContentImagePicker value={imageFiles} onChange={setImageFiles} />
          </div>
        )}

        {kind === "posts" ? (
          <label className="checkbox">
            <input
              type="checkbox"
              name="comments_enabled"
              defaultChecked={item?.comments_enabled ?? true}
            />{" "}
            Permitir novos comentários
          </label>
        ) : (
          <>
            <div className="form-grid">
              <label>
                Início
                <input
                  type="datetime-local"
                  step="1"
                  name="starts_at"
                  required
                  defaultValue={localDate(item?.starts_at)}
                />
              </label>
              <label>
                Fim (opcional)
                <input
                  type="datetime-local"
                  step="1"
                  name="ends_at"
                  defaultValue={localDate(item?.ends_at)}
                />
              </label>
            </div>
            <p className="hint">
              Horários no seu fuso:{" "}
              {Intl.DateTimeFormat().resolvedOptions().timeZone}.
            </p>
            {kind === "events" && (
              <>
                <label>
                  Local
                  <input
                    name="location"
                    maxLength={180}
                    defaultValue={item?.location || ""}
                  />
                </label>
                <label>
                  Endereço
                  <input
                    name="address"
                    maxLength={500}
                    defaultValue={item?.address || ""}
                  />
                </label>
              </>
            )}
          </>
        )}

        {!item && kind === "posts" && (
          <p className="hint">
            {canPublishPost
              ? "Você pode salvar como rascunho ou publicar imediatamente."
              : "Ao enviar, a publicação ficará aguardando revisão de um Pastor ou Administrador."}
          </p>
        )}

      </Form>
    </Modal>
  );
}
function ContentDetail({
  kind,
  id,
  administrative,
  onClose,
  onChange,
  onEdit,
}: {
  kind: Kind;
  id: number;
  administrative: boolean;
  onClose: () => void;
  onChange: () => void;
  onEdit: (item: Content) => void;
}) {
  const { user, canManage, ministries, permissions } = useSession();
  const canPublishPost = user.role === "ADMIN" || user.role === "PASTOR";
  const config = configs[kind];
  const detail = useData<Record<string, Content>>(
    `${administrative ? "/admin" : ""}/${kind}/${id}`,
  );
  const item = detail.data?.[config.field];
  const [action, setAction] = useState<string | null>(null);
  return (
    <Modal title={item?.title || "Detalhes"} onClose={onClose}>
      <ErrorBox error={detail.error} />
      {detail.loading ? (
        <Loading />
      ) : (
        item && (
          <>
            {(kind === "posts" || kind === "events") && (
              <ProtectedPostImage
                image={item.images?.[0]}
                alt={item.title}
                variant="detail"
              />
            )}

            <div className="detail-meta">
              <Badge value={item.status} />
              <Badge value={item.visibility} />
              <span>
                {item.ministry_id
                  ? ministries.find((m) => m.id === item.ministry_id)?.name
                  : "Igreja em geral"}
              </span>
            </div>
            {kind !== "posts" && (
              <div className="date-summary">
                <CalendarDays />
                <div>
                  <strong>{date(item.starts_at)}</strong>
                  {item.ends_at && <p>Até {date(item.ends_at)}</p>}
                  {item.location && <p>{item.location}</p>}
                  {item.address && <p>{item.address}</p>}
                </div>
              </div>
            )}
            <p className="prose">
              {item.content || item.description || "Sem descrição adicional."}
            </p>
            {canManage(item.ministry_id) && (
              <div className="detail-actions">
                <button onClick={() => onEdit(item)}>
                  <Pencil size={16} /> Editar
                </button>

                {item.status !== "PUBLISHED" &&
                  item.status !== "ARCHIVED" &&
                  (kind !== "posts" || canPublishPost) && (
                    <button onClick={() => setAction("publish")}>
                      <Check size={16} />
                      Publicar
                    </button>
                  )}

                {kind === "posts" &&
                  user.role === "LEADER" &&
                  item.status === "DRAFT" && (
                    <button onClick={() => setAction("submit-review")}>
                      <Check size={16} />
                      Enviar para revisão
                    </button>
                  )}

                {kind === "posts" &&
                  user.role === "LEADER" &&
                  item.status === "PENDING_REVIEW" && (
                    <span className="review-status">Aguardando revisão</span>
                  )}

                {item.status === "PUBLISHED" && (
                  <button onClick={() => setAction("unpublish")}>
                    Retirar de publicação
                  </button>
                )}
                {kind !== "posts" && item.status === "PUBLISHED" && (
                  <button onClick={() => setAction("cancel")}>
                    <X size={16} /> Cancelar encontro
                  </button>
                )}
                {item.status !== "ARCHIVED" && (
                  <button onClick={() => setAction("archive")}>
                    <Archive size={16} /> Arquivar
                  </button>
                )}
              </div>
            )}
            {kind === "posts" &&
              (item.status === "PUBLISHED" ||
                permissions.manage_ministries) && (
                <Comments post={item} administrative={administrative} />
              )}
          </>
        )
      )}
      {action && (
        <Confirm
          title={
            {
              publish: "Publicar",
              "submit-review": "Enviar para revisão",
              unpublish: "Retirar de publicação",
              cancel: "Cancelar encontro",
              archive: "Arquivar",
            }[action] || "Confirmar"
          }
          text={
            action === "publish"
              ? "A publicação ficará disponível para o público selecionado."
              : action === "submit-review"
                ? "A publicação será enviada para revisão de um Pastor ou Administrador."
                : action === "cancel"
                  ? "O encontro continuará visível com a indicação de cancelamento."
                  : "O registro ficará disponível apenas na gestão."
          }
          onClose={() => setAction(null)}
          onConfirm={async () => {
            await api(
              `/${kind}/${id}${action === "archive" ? "" : `/${action}`}`,
              action === "archive" ? "DELETE" : "POST",
              action === "archive" ? undefined : {},
            );
            onChange();
            onClose();
          }}
        />
      )}
    </Modal>
  );
}

function Comments({
  post,
  administrative,
}: {
  post: Content;
  administrative: boolean;
}) {
  const { user, permissions } = useSession();
  const [moderate, setModerate] = useState(false);
  const admin = permissions.manage_ministries && (moderate || administrative);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Comment | null>(null);
  const [action, setAction] = useState<{
    comment: Comment;
    status: string;
  } | null>(null);
  const [error, setError] = useState<unknown>();
  const comments = useData<Page<Comment>>(
    `${admin ? "/admin" : ""}/posts/${post.id}/comments?page=${page}&limit=10`,
  );
  return (
    <section className="comments">
      <div className="section-heading">
        <h3>
          <MessageCircle size={18} /> Comentários
        </h3>
        {permissions.manage_ministries && (
          <label className="checkbox">
            <input
              type="checkbox"
              checked={admin}
              onChange={(e) => {
                setModerate(e.target.checked);
                setPage(1);
              }}
            />{" "}
            Moderação
          </label>
        )}
      </div>
      <ErrorBox error={comments.error || error} />
      {comments.loading ? (
        <Loading />
      ) : (
        comments.data?.items.map((c) => (
          <article className="comment" key={c.id}>
            <div className="row-meta">
              <strong>
                {c.user_name ||
                  (c.user_id === user.id ? user.name : `Membro #${c.user_id}`)}
              </strong>
              <span>{date(c.created_at)}</span>
              {c.status === "HIDDEN" && <Badge value={c.status} />}
            </div>

            <p className="prose">{c.content}</p>
            <div className="comment-actions">
              {c.user_id === user.id &&
                c.status === "VISIBLE" &&
                post.status === "PUBLISHED" && (
                  <>
                    <button
                      className="link-button"
                      onClick={() => setEditing(c)}
                    >
                      Editar
                    </button>
                    <button
                      className="link-button"
                      onClick={() =>
                        setAction({ comment: c, status: "DELETE_OWN" })
                      }
                    >
                      Remover
                    </button>
                  </>
                )}
              {admin && (
                <>
                  <button
                    className="link-button"
                    onClick={() =>
                      setAction({
                        comment: c,
                        status: c.status === "HIDDEN" ? "VISIBLE" : "HIDDEN",
                      })
                    }
                  >
                    {c.status === "HIDDEN" ? "Restaurar" : "Ocultar"}
                  </button>
                  <button
                    className="link-button danger-text"
                    onClick={() => setAction({ comment: c, status: "DELETED" })}
                  >
                    Excluir
                  </button>
                </>
              )}
            </div>
          </article>
        ))
      )}
      {comments.data && !comments.data.items.length && (
        <p className="hint">Nenhum comentário nesta página.</p>
      )}
      {comments.data && comments.data.pagination.total > 10 && (
        <Pager pagination={comments.data.pagination} onPage={setPage} />
      )}{" "}
      {post.status === "PUBLISHED" &&
        post.comments_enabled &&
        !administrative && (
          <Form
            submit="Comentar"
            afterSave={comments.reload}
            onSave={async (f) => {
              await api(`/posts/${post.id}/comments`, "POST", {
                content: text(f, "content"),
              });
            }}
          >
            <label>
              Deixe seu comentário
              <textarea name="content" required maxLength={5000} rows={3} />
            </label>
          </Form>
        )}
      {post.status === "PUBLISHED" && !post.comments_enabled && (
        <p className="hint">Novos comentários estão fechados.</p>
      )}
      {editing && (
        <Modal title="Editar comentário" onClose={() => setEditing(null)}>
          <Form
            onClose={() => setEditing(null)}
            afterSave={() => {
              setEditing(null);
              comments.reload();
            }}
            onSave={async (f) => {
              await api(`/posts/${post.id}/comments/${editing.id}`, "PATCH", {
                content: text(f, "content"),
              });
            }}
          >
            <label>
              Comentário
              <textarea
                name="content"
                defaultValue={editing.content}
                required
                maxLength={5000}
                rows={4}
              />
            </label>
          </Form>
        </Modal>
      )}
      {action && (
        <Confirm
          title={
            action.status === "HIDDEN"
              ? "Ocultar comentário"
              : action.status === "VISIBLE"
                ? "Restaurar comentário"
                : "Remover comentário"
          }
          text={
            action.status === "HIDDEN"
              ? "O comentário ficará disponível somente à moderação."
              : action.status === "VISIBLE"
                ? "O comentário voltará a aparecer para quem pode ler a publicação."
                : "O comentário não poderá ser restaurado pela interface."
          }
          onClose={() => setAction(null)}
          onConfirm={async () => {
            if (action.status === "DELETE_OWN")
              await api(
                `/posts/${post.id}/comments/${action.comment.id}`,
                "DELETE",
              );
            else
              await api(
                `/admin/posts/${post.id}/comments/${action.comment.id}/status`,
                "PATCH",
                { status: action.status },
              );
            comments.reload();
          }}
        />
      )}
    </section>
  );
}

function getContentImages(f: FormData): File[] {
  return f
    .getAll("images")
    .filter((value): value is File => value instanceof File && value.size > 0);
}

function validateContentImages(files: File[]) {
  if (files.length > 5) {
    throw new ApiError(422, "Selecione no máximo 5 imagens.");
  }

  const allowed = ["image/jpeg", "image/png", "image/webp"];

  for (const file of files) {
    if (!allowed.includes(file.type)) {
      throw new ApiError(422, "Utilize apenas imagens JPEG, PNG ou WebP.");
    }

    if (file.size > 5 * 1024 * 1024) {
      throw new ApiError(422, `A imagem "${file.name}" possui mais de 5 MB.`);
    }
  }
}

async function uploadContentImages(
  kind: "posts" | "events",
  contentId: number,
  files: File[],
) {
  for (const file of files) {
    const body = new FormData();

    body.append("file", file);

    await apiForm(`/${kind}/${contentId}/images`, body);
  }
}

async function saveContentWithImages(
  kind: Kind,
  item: Content | undefined,
  f: FormData,
  imageFiles: File[],
) {
  const supportsImages = (kind === "posts" || kind === "events") && !item;

  const files = supportsImages ? imageFiles : [];

  validateContentImages(files);

  const result = await saveContent(kind, item, f);

  if (supportsImages && files.length > 0) {
    const content = kind === "posts" ? result.post : result.event;

    await uploadContentImages(kind, content.id, files);
  }

  return result;
}

async function saveContent(kind: Kind, item: Content | undefined, f: FormData) {
  const ministry = text(f, "ministry_id");

  const body = {
    title: text(f, "title"),
    ministry_id: ministry ? Number(ministry) : null,
    visibility: text(f, "visibility"),
    ...(kind === "posts"
      ? {
          content: text(f, "content"),
          comments_enabled: f.get("comments_enabled") === "on",
        }
      : {
          description: nullable(f, "description"),
          starts_at: instant(text(f, "starts_at")),
          ends_at: instant(text(f, "ends_at")),
          ...(kind === "events"
            ? {
                location: nullable(f, "location"),
                address: nullable(f, "address"),
              }
            : {}),
        }),
  };

  return api<Record<string, Content>>(
    `/${kind}${item ? `/${item.id}` : ""}`,
    item ? "PATCH" : "POST",
    body,
  );
}

function ProtectedPostImage({
  image,
  alt,
  variant = "card",
}: {
  image?: ContentImage;
  alt: string;
  variant?: "card" | "detail";
}) {
  const [src, setSrc] = useState<string>(image ? "" : "/post-default.jpg");

  useEffect(() => {
    if (!image) {
      setSrc("/post-default.jpg");
      return;
    }

    let active = true;
    let objectUrl: string | null = null;

    const imageUrl =
      variant === "detail"
        ? image.detail_url || image.url
        : image.feed_url || image.url;

    apiBlob(imageUrl)
      .then((blob) => {
        if (!active) {
          return;
        }

        objectUrl = URL.createObjectURL(blob);

        setSrc(objectUrl);
      })
      .catch((error) => {
        console.error("Erro ao carregar imagem:", error);

        if (active) {
          setSrc("/post-default.jpg");
        }
      });

    return () => {
      active = false;

      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [image, variant]);

  if (!src) {
    return (
      <div
        className={
          variant === "detail"
            ? "post-detail-image-loading"
            : "post-card-image-loading"
        }
      />
    );
  }

  return (
    <img
      className={variant === "detail" ? "post-detail-image" : "post-card-image"}
      src={src}
      alt={alt}
    />
  );
}