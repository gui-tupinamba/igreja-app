import { useState } from "react";
import { Plus, Pencil, ShieldCheck } from "lucide-react";
import { api, clearSession, refresh } from "../api";
import { useSession } from "../session";
import {
  Badge,
  Choice,
  Empty,
  ErrorBox,
  Form,
  Heading,
  labels,
  Loading,
  Modal,
  nullable,
  Pager,
  query,
  text,
  useData,
} from "../ui";
import type { Page, Profile, User } from "../types";
export function UsersPage() {
  const { user } = useSession();
  const [page, setPage] = useState(1);
  const [role, setRole] = useState("");
  const [status, setStatus] = useState("");
  const [selected, setSelected] = useState<number | "new" | null>(null);
  const list = useData<Page<User>>(
    `/admin/users?${query({ page, limit: 20, role, status })}`,
  );
  const roles = [
    "MEMBER",
    "LEADER",
    "PASTOR",
    ...(user.role === "ADMIN" ? ["ADMIN"] : []),
  ];
  return (
    <>
      <Heading
        title="Pessoas"
        subtitle="Cuide dos perfis e dos acessos da comunidade."
      >
        <button className="primary" onClick={() => setSelected("new")}>
          <Plus size={18} /> Cadastrar pessoa
        </button>
      </Heading>
      <div className="filters">
        <label>
          Cargo
          <select
            value={role}
            onChange={(e) => {
              setRole(e.target.value);
              setPage(1);
            }}
          >
            <option value="">Todos os cargos</option>
            {roles.map((r) => (
              <option key={r} value={r}>
                {labels[r]}
              </option>
            ))}
          </select>
        </label>
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
            {["ACTIVE", "INACTIVE", "BLOCKED"].map((s) => (
              <option key={s} value={s}>
                {labels[s]}
              </option>
            ))}
          </select>
        </label>
      </div>
      <ErrorBox error={list.error} />
      {list.loading ? (
        <Loading />
      ) : list.data?.items.length ? (
        <div className="panel table-wrap">
          <table>
            <thead>
              <tr>
                <th>Pessoa</th>
                <th>Cargo</th>
                <th>Estado</th>
                <th>
                  <span className="sr-only">Ações</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {list.data.items.map((u) => (
                <tr key={u.id}>
                  <td>
                    <strong>{u.name}</strong>
                    <small>{u.email}</small>
                  </td>
                  <td>
                    <Badge value={u.role} />
                  </td>
                  <td>
                    <Badge value={u.status} />
                  </td>
                  <td>
                    <button onClick={() => setSelected(u.id)}>
                      <Pencil size={15} /> Editar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        !list.error && <Empty />
      )}
      {list.data && (
        <Pager pagination={list.data.pagination} onPage={setPage} />
      )}{" "}
      {selected && (
        <UserEditor
          id={selected}
          onClose={() => setSelected(null)}
          onSaved={() => {
            list.reload();
            setSelected(null);
          }}
        />
      )}
    </>
  );
}
function UserEditor({
  id,
  onClose,
  onSaved,
}: {
  id: number | "new";
  onClose: () => void;
  onSaved: () => void;
}) {
  const { user: actor, refreshInfo } = useSession();
  const record = useData<{ user: User }>(
    id === "new" ? null : `/admin/users/${id}`,
  );
  const user = record.data?.user;
  const roles = [
    "MEMBER",
    "LEADER",
    "PASTOR",
    ...(actor.role === "ADMIN" ? ["ADMIN"] : []),
  ];
  return (
    <Modal
      title={id === "new" ? "Cadastrar pessoa" : user?.name || "Editar pessoa"}
      onClose={onClose}
    >
      <ErrorBox error={record.error} />
      {id !== "new" && record.loading ? (
        <Loading />
      ) : (
        (id === "new" || user) && (
          <>
            <Form
              onClose={onClose}
              afterSave={() => {
                refreshInfo();
                onSaved();
              }}
              onSave={async (f) => {
                await api(
                  id === "new" ? "/admin/users" : `/admin/users/${id}`,
                  id === "new" ? "POST" : "PATCH",
                  {
                    name: text(f, "name"),
                    email: text(f, "email"),
                    phone: nullable(f, "phone"),
                    birth_date: nullable(f, "birth_date"),
                    ...(id === "new"
                      ? {
                          password: text(f, "password"),
                          role: text(f, "role"),
                          status: "ACTIVE",
                        }
                      : {}),
                  },
                );
                if (id === actor.id) await refresh();
              }}
            >
              <ProfileFields user={user} email />
              {id === "new" && (
                <>
                  <Choice
                    name="role"
                    label="Cargo global"
                    defaultValue="MEMBER"
                    options={roles.map((r) => ({ id: r, name: labels[r] }))}
                  />
                  <PasswordField name="password" label="Senha inicial" />
                  <p className="hint">
                    O cargo não inclui participação ou liderança em ministérios.
                    Defina esses vínculos na tela de Ministérios.
                  </p>
                </>
              )}
            </Form>
            {user && (
              <>
                <section className="form-section">
                  <h3>
                    <ShieldCheck size={18} /> Cargo e acesso
                  </h3>
                  <p className="hint">
                    Alterar o estado pode encerrar sessões e lideranças desta
                    pessoa.
                  </p>
                  <Form
                    submit="Atualizar acesso"
                    afterSave={() => {
                      refreshInfo();
                      onSaved();
                    }}
                    onSave={async (f) => {
                      await api(`/admin/users/${id}/access`, "PATCH", {
                        role: text(f, "role"),
                        status: text(f, "status"),
                      });
                      if (id === actor.id) await refresh();
                    }}
                  >
                    <div className="form-grid">
                      <Choice
                        name="role"
                        label="Cargo global"
                        defaultValue={user.role}
                        options={roles.map((r) => ({ id: r, name: labels[r] }))}
                      />
                      <Choice
                        name="status"
                        label="Estado da conta"
                        defaultValue={user.status}
                        options={["ACTIVE", "INACTIVE", "BLOCKED"].map((s) => ({
                          id: s,
                          name: labels[s],
                        }))}
                      />
                    </div>
                  </Form>
                </section>
                <section className="form-section">
                  <h3>Redefinir senha</h3>
                  <p className="hint">
                    Todas as sessões desta pessoa serão encerradas.
                  </p>
                  <Form
                    submit="Redefinir senha"
                    afterSave={onSaved}
                    onSave={async (f) => {
                      await api(`/admin/users/${id}/password`, "POST", {
                        new_password: text(f, "new_password"),
                      });
                      if (id === actor.id) clearSession(true);
                    }}
                  >
                    <PasswordField name="new_password" label="Nova senha" />
                  </Form>
                </section>
              </>
            )}
          </>
        )
      )}
    </Modal>
  );
}
export function ProfilePage() {
  const { refreshInfo } = useSession();
  const profile = useData<Profile>("/profile");
  const [saved, setSaved] = useState("");
  return (
    <>
      <Heading
        title="Meu perfil"
        subtitle="Seus dados e seu lugar na comunidade."
      />
      <ErrorBox error={profile.error} />
      {profile.loading ? (
        <Loading />
      ) : (
        profile.data && (
          <div className="profile-grid">
            <section className="panel">
              <div className="profile-heading">
                <div className="avatar large">{profile.data.user.name[0]}</div>
                <div>
                  <h2>{profile.data.user.name}</h2>
                  <Badge value={profile.data.user.role} />
                </div>
              </div>
              {saved && (
                <p className="success" role="status">
                  {saved}
                </p>
              )}
              <Form
                submit="Salvar perfil"
                onSave={async (f) => {
                  await api("/profile", "PATCH", {
                    name: text(f, "name"),
                    phone: nullable(f, "phone"),
                    birth_date: nullable(f, "birth_date"),
                  });
                  await refresh();
                  refreshInfo();
                  setSaved("Perfil atualizado.");
                }}
              >
                <ProfileFields user={profile.data.user} />
                <label>
                  E-mail de acesso
                  <input value={profile.data.user.email} disabled />
                </label>
                <p className="hint">
                  Para alterar seu e-mail, fale com a administração.
                </p>
              </Form>
              <section className="form-section">
                <h3>Alterar minha senha</h3>
                <Form
                  submit="Alterar senha e sair"
                  onSave={async (f) => {
                    if (text(f, "new_password") !== text(f, "confirm_password"))
                      throw new Error("As novas senhas não coincidem.");
                    await api("/profile/password", "POST", {
                      current_password: text(f, "current_password"),
                      new_password: text(f, "new_password"),
                    });
                    clearSession(true);
                  }}
                >
                  <label>
                    Senha atual
                    <input
                      type="password"
                      name="current_password"
                      required
                      autoComplete="current-password"
                    />
                  </label>
                  <PasswordField name="new_password" label="Nova senha" />
                  <PasswordField
                    name="confirm_password"
                    label="Confirme a nova senha"
                  />
                  <p className="hint">
                    Após a troca, entre novamente com sua nova senha.
                  </p>
                </Form>
              </section>
            </section>
            <aside className="panel profile-links">
              <h2>Meus ministérios</h2>
              <p>Onde você participa</p>
              {profile.data.ministries.length ? (
                profile.data.ministries.map((m) => (
                  <div className="membership-card" key={m.id}>
                    {m.name}
                    <Badge value="MEMBER" />
                  </div>
                ))
              ) : (
                <p className="hint">
                  Você ainda não tem uma participação ativa.
                </p>
              )}
              <h2>Minhas lideranças</h2>
              <p>Onde você ajuda a conduzir</p>
              {profile.data.led_ministries.length ? (
                profile.data.led_ministries.map((m) => (
                  <div className="membership-card" key={m.id}>
                    {m.name}
                    <Badge value="LEADER" />
                  </div>
                ))
              ) : (
                <p className="hint">Nenhuma liderança ativa.</p>
              )}
            </aside>
          </div>
        )
      )}
    </>
  );
}
function ProfileFields({
  user,
  email = false,
}: {
  user?: User;
  email?: boolean;
}) {
  return (
    <>
      <label>
        Nome completo
        <input
          name="name"
          defaultValue={user?.name || ""}
          required
          maxLength={120}
          autoComplete="name"
        />
      </label>
      {email && (
        <label>
          E-mail
          <input
            type="email"
            name="email"
            defaultValue={user?.email || ""}
            required
            maxLength={180}
            autoComplete="off"
          />
        </label>
      )}
      <div className="form-grid">
        <label>
          Telefone
          <input
            name="phone"
            defaultValue={user?.phone || ""}
            maxLength={30}
            autoComplete="tel"
          />
        </label>
        <label>
          Data de nascimento
          <input
            type="date"
            name="birth_date"
            defaultValue={user?.birth_date || ""}
            max={new Date().toLocaleDateString("en-CA")}
          />
        </label>
      </div>
    </>
  );
}
function PasswordField({ name, label }: { name: string; label: string }) {
  return (
    <label>
      {label}
      <input
        type="password"
        name={name}
        required
        minLength={12}
        autoComplete="new-password"
      />
      <span className="hint">
        De 12 a 72 bytes. Letras com acento podem ocupar mais de um byte.
      </span>
    </label>
  );
}
