import { useState } from "react";
import { Link } from "react-router-dom";
import { CalendarDays, Plus, Settings2 } from "lucide-react";
import { useSession } from "../session";
import {
  Badge,
  date,
  Empty,
  ErrorBox,
  Heading,
  instant,
  Loading,
  Pager,
  query,
  useData,
} from "../ui";
import { ContentEditor } from "./Content";
import type { Page, Content } from "../types";
export function CalendarPage() {
  const { ministries, permissions } = useSession();
  const [page, setPage] = useState(1);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [ministry, setMinistry] = useState("");
  const [status, setStatus] = useState("");
  const [create, setCreate] = useState(false);
  const list = useData<Page<Content>>(
    `/calendar?${query({ page, limit: 20, from: from ? instant(from)! : "", to: to ? instant(to)! : "", ministry_id: ministry, status })}`,
  );
  const canManage =
    permissions.manage_ministries || permissions.led_ministries.length > 0;
  return (
    <>
      <Heading
        title="Nossa agenda"
        subtitle="Eventos e atividades. Todos os encontros, em um só lugar."
      >
        {canManage && (
          <>
            <Link className="button" to="/atividades">
              <Settings2 size={17} /> Gerenciar atividades
            </Link>
            <button className="primary" onClick={() => setCreate(true)}>
              <Plus size={17} /> Nova atividade
            </button>
          </>
        )}
      </Heading>
      <div className="filters">
        <label>
          A partir de
          <input
            type="datetime-local"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value);
              setPage(1);
            }}
          />
        </label>
        <label>
          Até
          <input
            type="datetime-local"
            value={to}
            onChange={(e) => {
              setTo(e.target.value);
              setPage(1);
            }}
          />
        </label>
        <label>
          Ministério
          <select
            value={ministry}
            onChange={(e) => {
              setMinistry(e.target.value);
              setPage(1);
            }}
          >
            <option value="">Todos</option>
            <option value="null">Igreja em geral</option>
            {ministries.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
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
            <option value="">Publicados e cancelados</option>
            <option value="PUBLISHED">Publicados</option>
            <option value="CANCELLED">Cancelados</option>
          </select>
        </label>
      </div>
      <p className="hint">
        Sem data inicial, mostramos os próximos compromissos e os que estão em
        andamento. Fuso: {Intl.DateTimeFormat().resolvedOptions().timeZone}.
      </p>
      <ErrorBox error={list.error} />
      {list.loading ? (
        <Loading />
      ) : list.data?.items.length ? (
        <div className="panel calendar-list">
          {list.data.items.map((item) => (
            <article className="calendar-item" key={`${item.kind}-${item.id}`}>
              <div className="date-tile">
                <b>{new Date(item.starts_at!).getDate()}</b>
                <span>
                  {new Date(item.starts_at!).toLocaleDateString("pt-BR", {
                    month: "short",
                  })}
                </span>
              </div>
              <div className="calendar-info">
                <div className="row-meta">
                  <span>{item.kind === "EVENT" ? "Evento" : "Atividade"}</span>
                  <Badge value={item.status} />
                </div>
                <h2>{item.title}</h2>
                <p>
                  <CalendarDays size={15} /> {date(item.starts_at)}
                  {item.ends_at && ` — ${date(item.ends_at)}`}
                </p>
                {item.description && (
                  <p className="prose">{item.description}</p>
                )}
                {item.location && (
                  <p>
                    {item.location}
                    {item.address && ` · ${item.address}`}
                  </p>
                )}
                <span className="ministry-tag">
                  {item.ministry_id
                    ? ministries.find((m) => m.id === item.ministry_id)?.name ||
                      "Ministério"
                    : "Igreja em geral"}
                </span>
              </div>
            </article>
          ))}
        </div>
      ) : (
        !list.error && (
          <Empty title="Espaço para novos encontros">
            Nenhum compromisso encontrado para este período.
          </Empty>
        )
      )}
      {list.data && (
        <Pager pagination={list.data.pagination} onPage={setPage} />
      )}{" "}
      {create && (
        <ContentEditor
          kind="schedules"
          onClose={() => setCreate(false)}
          onSaved={() => {
            setCreate(false);
            list.reload();
          }}
        />
      )}
    </>
  );
}
