import { useEffect, useState } from "react";
import { Plus, HandHeart, Pencil, Users } from "lucide-react";
import { api } from "../api";
import { useSession } from "../session";
import {
  all,
  Badge,
  Choice,
  Confirm,
  Empty,
  ErrorBox,
  Form,
  Heading,
  Loading,
  Modal,
  nullable,
  Pager,
  query,
  text,
  useData,
} from "../ui";

import type { Membership, Ministry, Page, User } from "../types";
export function MinistriesPage() {
  const { permissions, canManage, refreshInfo } = useSession();
  const [manage, setManage] = useState(false);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const [editor, setEditor] = useState<Ministry | "new" | null>(null);
  const [members, setMembers] = useState<Ministry | null>(null);
  const [archive, setArchive] = useState<Ministry | null>(null);
  const administrative = manage && permissions.manage_ministries;
  const list = useData<Page<Ministry>>(
    `${administrative ? "/admin" : ""}/ministries?${query({ page, limit: 12, ...(administrative ? { status } : {}) })}`,
  );
  function changed() {
    list.reload();
    refreshInfo();
  }
  return (
    <>
      <Heading
        title="Ministérios"
        subtitle="Diferentes dons. Uma mesma comunidade."
      >
        {permissions.manage_ministries && (
          <button className="primary" onClick={() => setEditor("new")}>
            <Plus size={18} /> Novo ministério
          </button>
        )}
      </Heading>
      {permissions.manage_ministries && (
        <div className="tabs">
          <button
            className={!administrative ? "active" : ""}
            onClick={() => {
              setManage(false);
              setPage(1);
            }}
          >
            Comunidade
          </button>
          <button
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
      {administrative && (
        <div className="filters">
          <label>
            Estado
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Todos</option>
              <option value="ACTIVE">Ativos</option>
              <option value="INACTIVE">Inativos</option>
            </select>
          </label>
        </div>
      )}
      <ErrorBox error={list.error} />
      {list.loading ? (
        <Loading />
      ) : list.data?.items.length ? (
        <div className="ministry-grid">
          {list.data.items.map((m) => (
            <article className="panel ministry-card" key={m.id}>
              <div className="ministry-icon">
                <HandHeart size={25} />
              </div>
              <h2>{m.name}</h2>
              <p className="prose">
                {m.description || "Um espaço para servir e caminhar junto."}
              </p>
              <Badge value={m.status} />
              {canManage(m.id) && (
                <div className="detail-actions">
                  <button onClick={() => setEditor(m)}>
                    <Pencil size={16} /> Editar
                  </button>
                  <button onClick={() => setMembers(m)}>
                    <Users size={16} /> Participantes
                  </button>
                  {permissions.manage_ministries && m.status === "ACTIVE" && (
                    <button
                      className="link-button"
                      onClick={() => setArchive(m)}
                    >
                      Desativar
                    </button>
                  )}
                </div>
              )}
            </article>
          ))}
        </div>
      ) : (
        !list.error && (
          <Empty title="Os ministérios aparecerão aqui">
            A administração pode criar os espaços de serviço da igreja.
          </Empty>
        )
      )}
      {list.data && (
        <Pager pagination={list.data.pagination} onPage={setPage} />
      )}{" "}
      {editor && (
        <MinistryEditor
          item={editor === "new" ? undefined : editor}
          onClose={() => setEditor(null)}
          onSaved={() => {
            setEditor(null);
            changed();
          }}
        />
      )}
      {members && (
        <Members
          ministry={members}
          onClose={() => setMembers(null)}
          onChange={changed}
        />
      )}{" "}
      {archive && (
        <Confirm
          title="Desativar ministério"
          text={`O conteúdo de ${archive.name} ficará fora da comunidade, preservando o histórico.`}
          onClose={() => setArchive(null)}
          onConfirm={async () => {
            await api(`/ministries/${archive.id}`, "DELETE");
            changed();
          }}
        />
      )}
    </>
  );
}
function MinistryEditor({
  item,
  onClose,
  onSaved,
}: {
  item?: Ministry;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { permissions } = useSession();
  return (
    <Modal
      title={item ? "Editar ministério" : "Novo ministério"}
      onClose={onClose}
    >
      <Form
        onClose={onClose}
        afterSave={onSaved}
        onSave={async (f) => {
          await api(
            `/ministries${item ? `/${item.id}` : ""}`,
            item ? "PATCH" : "POST",
            {
              name: text(f, "name"),
              description: nullable(f, "description"),
              ...(permissions.manage_ministries
                ? { slug: text(f, "slug"), status: text(f, "status") }
                : {}),
            },
          );
        }}
      >
        <label>
          Nome
          <input
            name="name"
            defaultValue={item?.name}
            required
            maxLength={120}
          />
        </label>
        {permissions.manage_ministries && (
          <>
            <label>
              Identificador
              <input
                name="slug"
                defaultValue={item?.slug}
                required
                maxLength={160}
                pattern="[a-z0-9]+(-[a-z0-9]+)*"
                placeholder="jovens"
              />
              <span className="hint">
                Letras minúsculas, números e hífens. Ex.: equipe-de-louvor.
              </span>
            </label>
            <Choice
              name="status"
              label="Estado"
              defaultValue={item?.status || "ACTIVE"}
              options={[
                { id: "ACTIVE", name: "Ativo" },
                { id: "INACTIVE", name: "Inativo" },
              ]}
            />
          </>
        )}
        <label>
          Descrição
          <textarea
            name="description"
            rows={5}
            maxLength={10000}
            defaultValue={item?.description || ""}
          />
        </label>
      </Form>
    </Modal>
  );
}
function Members({
  ministry,
  onClose,
  onChange,
}: {
  ministry: Ministry;
  onClose: () => void;
  onChange: () => void;
}) {
  const { permissions, user, refreshInfo } = useSession();
  const [page, setPage] = useState(1);
  const [leaders, setLeaders] = useState(false);
  const [state, setState] = useState("ACTIVE");
  const [users, setUsers] = useState<User[]>([]);
  const [pickError, setPickError] = useState<unknown>();
  const [target, setTarget] = useState<{
    member: Membership;
    action: string;
  } | null>(null);
  const list = useData<Page<Membership>>(
    `/ministries/${ministry.id}/${leaders ? "leaders" : "members"}?${query({ page, limit: 10, ...(!leaders ? { status: state } : {}) })}`,
  );
  useEffect(() => {
    let active = true;
    if (permissions.manage_ministries)
      all<User>("/admin/users?status=ACTIVE")
        .then((u) => {
          if (active) setUsers(u);
        })
        .catch((e) => {
          if (active) setPickError(e);
        });
    return () => {
      active = false;
    };
  }, [permissions.manage_ministries]);
  function changed() {
    list.reload();
    refreshInfo();
    onChange();
  }
  return (
    <Modal title={`Participantes · ${ministry.name}`} onClose={onClose}>
      <div className="tabs">
        <button
          className={!leaders ? "active" : ""}
          onClick={() => {
            setLeaders(false);
            setPage(1);
          }}
        >
          Participantes
        </button>
        <button
          className={leaders ? "active" : ""}
          onClick={() => {
            setLeaders(true);
            setPage(1);
          }}
        >
          Lideranças
        </button>
      </div>
      {permissions.manage_ministries && !leaders && (
        <label>
          Vínculo
          <select
            value={state}
            onChange={(e) => {
              setState(e.target.value);
              setPage(1);
            }}
          >
            <option value="ACTIVE">Ativos</option>
            <option value="INACTIVE">Histórico</option>
          </select>
        </label>
      )}
      <ErrorBox error={list.error} />
      {list.loading ? (
        <Loading />
      ) : list.data?.items.length ? (
        list.data.items.map((m) => (
          <div className="member-row" key={m.id}>
            <div>
              <strong>{m.name}</strong>
              <div className="row-meta">
                <Badge value={m.role} />
                <Badge value={m.status} />
                {m.is_leader && <span>Lidera este ministério</span>}
              </div>
            </div>
            {permissions.manage_ministries &&
              (user.role === "ADMIN" || m.role !== "ADMIN") && (
                <div className="member-actions">
                  {m.status === "ACTIVE" && (
                    <>
                      <button
                        className="link-button"
                        onClick={() =>
                          setTarget({
                            member: m,
                            action: m.is_leader ? "unlead" : "lead",
                          })
                        }
                      >
                        {m.is_leader
                          ? "Remover liderança"
                          : "Definir liderança"}
                      </button>
                      <button
                        className="link-button danger-text"
                        onClick={() =>
                          setTarget({ member: m, action: "remove" })
                        }
                      >
                        Encerrar participação
                      </button>
                    </>
                  )}
                </div>
              )}
          </div>
        ))
      ) : (
        !list.error && <Empty title="Nenhum participante nesta seleção" />
      )}
      {list.data && (
        <Pager pagination={list.data.pagination} onPage={setPage} />
      )}{" "}
      {permissions.manage_ministries && ministry.status === "ACTIVE" && (
        <section className="form-section">
          <h3>Adicionar participante</h3>
          <ErrorBox error={pickError} />
          <Form
            submit="Adicionar participante"
            afterSave={changed}
            onSave={async (f) => {
              const id = Number(text(f, "user_id"));
              if (!id) throw new Error("Selecione uma pessoa.");
              await api(`/ministries/${ministry.id}/members`, "POST", {
                user_id: id,
              });
            }}
          >
            <Choice
              label="Pessoa"
              name="user_id"
              required
              options={[
                { id: "", name: "Selecione uma pessoa" },
                ...users.map((u) => ({
                  id: u.id,
                  name: `${u.name} · ${u.email}`,
                })),
              ]}
            />
          </Form>
        </section>
      )}
      {target && (
        <Confirm
          title={
            {
              lead: "Definir liderança",
              unlead: "Remover liderança",
              remove: "Encerrar participação",
            }[target.action] || "Confirmar"
          }
          text={
            target.action === "lead"
              ? `${target.member.name} passará a liderar este ministério.${target.member.role === "MEMBER" ? " O cargo global será promovido para Líder." : ""}`
              : target.action === "unlead"
                ? "A participação será mantida, sem os poderes de liderança."
                : "O acesso ao conteúdo privado deste ministério será revogado."
          }
          onClose={() => setTarget(null)}
          onConfirm={async () => {
            if (target.action === "lead")
              await api(`/ministries/${ministry.id}/leaders`, "POST", {
                user_id: target.member.user_id,
                ...(target.member.role === "MEMBER"
                  ? { promote_to_leader: true }
                  : {}),
              });
            else
              await api(
                `/ministries/${ministry.id}/${target.action === "unlead" ? "leaders" : "members"}/${target.member.user_id}`,
                "DELETE",
              );
            changed();
          }}
        />
      )}
    </Modal>
  );
}
