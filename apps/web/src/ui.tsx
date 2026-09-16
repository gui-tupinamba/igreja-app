import {
  useEffect,
  useRef,
  useId,
  useState,
  type FormEvent,
  type ReactNode,
} from "react";
import { ChevronLeft, ChevronRight, Inbox, X } from "lucide-react";
import { api, ApiError } from "./api";
import type { Page } from "./types";

export const labels: Record<string, string> = {
  ADMIN: "Administrador",
  PASTOR: "Pastor",
  LEADER: "Líder",
  MEMBER: "Membro",
  ACTIVE: "Ativo",
  INACTIVE: "Inativo",
  BLOCKED: "Bloqueado",
  DRAFT: "Rascunho",
  PUBLISHED: "Publicado",
  CANCELLED: "Cancelado",
  ARCHIVED: "Arquivado",
  PUBLIC: "Toda a igreja",
  MINISTRY_MEMBERS: "Integrantes do ministério",
  VISIBLE: "Visível",
  HIDDEN: "Oculto",
};
export const date = (value?: string | null) =>
  value
    ? new Date(value).toLocaleString("pt-BR", {
        dateStyle: "medium",
        timeStyle: "short",
      })
    : "Sem data";
export const localDate = (value?: string | null) => {
  if (!value) return "";
  const d = new Date(value);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}T${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}:${String(d.getSeconds()).padStart(2, "0")}`;
};
export const instant = (value: string) =>
  value ? new Date(value).toISOString().replace(".000Z", "Z") : null;
export const query = (values: Record<string, string | number | undefined>) => {
  const p = new URLSearchParams();
  Object.entries(values).forEach(([k, v]) => {
    if (v !== undefined && v !== "") p.set(k, String(v));
  });
  return p.toString();
};
export function useData<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<unknown>();
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(true);
  const reload = () => setRevision((v) => v + 1);
  useEffect(() => {
    const focus = () => {
      if (document.visibilityState === "visible") reload();
    };
    window.addEventListener("focus", focus);
    document.addEventListener("visibilitychange", focus);
    return () => {
      window.removeEventListener("focus", focus);
      document.removeEventListener("visibilitychange", focus);
    };
  }, []);
  useEffect(() => {
    let current = true;
    setData(null);
    setError(undefined);
    setLoading(!!path);
    if (path)
      api<T>(path)
        .then((v) => {
          if (current) setData(v);
        })
        .catch((e) => {
          if (current) setError(e);
        })
        .finally(() => {
          if (current) setLoading(false);
        });
    return () => {
      current = false;
    };
  }, [path, revision]);
  return { data, error, loading, reload };
}
export function ErrorBox({ error }: { error: unknown }) {
  if (!error) return null;
  return (
    <div className="error" role="alert">
      {error instanceof Error ? error.message : "Não foi possível carregar."}
      {error instanceof ApiError &&
        Object.values(error.fields).map((m, i) => <div key={i}>{m}</div>)}
    </div>
  );
}
export function Loading() {
  return (
    <div className="loading" role="status">
      <span className="spinner" />
      Carregando…
    </div>
  );
}
export function Empty({
  title = "Ainda não há registros",
  children,
}: {
  title?: string;
  children?: ReactNode;
}) {
  return (
    <div className="empty">
      <Inbox size={32} />
      <h3>{title}</h3>
      <p>{children || "Os novos registros aparecerão aqui."}</p>
    </div>
  );
}
export function Badge({ value }: { value: string }) {
  return (
    <span className={`badge ${value.toLowerCase()}`}>
      {labels[value] || value}
    </span>
  );
}
export function Pager({
  pagination,
  onPage,
}: {
  pagination: Page<unknown>["pagination"];
  onPage: (p: number) => void;
}) {
  const pages = Math.max(1, Math.ceil(pagination.total / pagination.limit));
  return (
    <div className="pager">
      <span>
        {pagination.total} registro(s) · Página {pagination.page} de {pages}
      </span>
      <div>
        <button
          aria-label="Página anterior"
          disabled={pagination.page <= 1}
          onClick={() => onPage(pagination.page - 1)}
        >
          <ChevronLeft size={18} />
        </button>
        <button
          aria-label="Próxima página"
          disabled={pagination.page >= pages}
          onClick={() => onPage(pagination.page + 1)}
        >
          <ChevronRight size={18} />
        </button>
      </div>
    </div>
  );
}
export function Modal({
  title,
  children,
  onClose,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  useEffect(() => {
    ref.current?.showModal();
    const d = ref.current;
    return () => d?.close();
  }, []);
  return (
    <dialog
      ref={ref}
      onCancel={(e) => {
        e.stopPropagation();
        onClose();
      }}
      aria-labelledby={titleId}
    >
      <div className="dialog-head">
        <h2 id={titleId}>{title}</h2>
        <button className="icon-button" aria-label="Fechar" onClick={onClose}>
          <X size={22} />
        </button>
      </div>
      {children}
    </dialog>
  );
}
export function Form({
  children,
  onSave,
  onClose,
  submit = "Salvar",
  afterSave,
}: {
  children: ReactNode;
  onSave: (data: FormData) => Promise<void>;
  onClose?: () => void;
  submit?: string;
  afterSave?: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>();
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    setBusy(true);
    setError(undefined);
    try {
      await onSave(data);
      if (afterSave) {
        form.reset();
        afterSave();
      }
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form onSubmit={save}>
      <fieldset disabled={busy}>{children}</fieldset>
      <ErrorBox error={error} />
      <div className="form-actions">
        {onClose && (
          <button type="button" onClick={onClose}>
            Cancelar
          </button>
        )}
        <button className="primary" disabled={busy}>
          {busy ? "Salvando…" : submit}
        </button>
      </div>
    </form>
  );
}
export function Confirm({
  title,
  text,
  onConfirm,
  onClose,
}: {
  title: string;
  text: string;
  onConfirm: () => Promise<void>;
  onClose: () => void;
}) {
  return (
    <Modal title={title} onClose={onClose}>
      <p>{text}</p>
      <Form
        onSave={onConfirm}
        afterSave={onClose}
        onClose={onClose}
        submit="Confirmar"
      >
        {null}
      </Form>
    </Modal>
  );
}
export function Heading({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children?: ReactNode;
}) {
  return (
    <header className="page-heading">
      <div>
        <span className="eyebrow green">NOSSA COMUNIDADE</span>
        <h1>{title}</h1>
        <p>{subtitle}</p>
      </div>
      <div className="heading-actions">{children}</div>
    </header>
  );
}
export function Choice({
  name,
  label,
  options,
  defaultValue,
  required = false,
}: {
  name: string;
  label: string;
  options: { id: string | number; name: string }[];
  defaultValue?: string | number;
  required?: boolean;
}) {
  return (
    <label>
      {label}
      <select name={name} defaultValue={defaultValue} required={required}>
        {options.map((o) => (
          <option key={o.id} value={o.id}>
            {o.name}
          </option>
        ))}
      </select>
    </label>
  );
}
export const text = (f: FormData, key: string) => String(f.get(key) || "");
export const nullable = (f: FormData, key: string) => text(f, key) || null;
export async function all<T>(path: string): Promise<T[]> {
  let page = 1;
  const items: T[] = [];
  for (;;) {
    const result = await api<Page<T>>(
      `${path}${path.includes("?") ? "&" : "?"}limit=100&page=${page}`,
    );
    items.push(...result.items);
    if (items.length >= result.pagination.total || !result.items.length)
      return items;
    page++;
  }
}
