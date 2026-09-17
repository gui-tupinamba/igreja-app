import { useState } from "react";
import {
  BrowserRouter,
  NavLink,
  Navigate,
  Route,
  Routes,
  useLocation,
} from "react-router-dom";
import {
  LayoutDashboard,
  Newspaper,
  Users,
  HandHeart,
  CalendarDays,
  CalendarCheck,
  UserRound,
  LogOut,
  Menu,
  X,
  Plus,
  ArrowUpRight,
} from "lucide-react";
import { Brand } from "./App";
import { logout } from "./api";
import { SessionProvider, useSession } from "./session";
import {
  useData,
  date,
  Empty,
  ErrorBox,
  Heading,
  labels,
  Loading,
  Badge,
} from "./ui";
import type { User, Page, Content } from "./types";
import { ContentPage } from "./pages/Content";
import { MinistriesPage } from "./pages/Ministries";
import { UsersPage, ProfilePage } from "./pages/Users";
import { CalendarPage } from "./pages/Calendar";

export default function Shell({ user }: { user: User }) {
  return (
    <SessionProvider user={user}>
      <BrowserRouter>
        <Layout />
      </BrowserRouter>
    </SessionProvider>
  );
}

function Layout() {
  const { user, permissions } = useSession();
  const [open, setOpen] = useState(false);
  const [logoutError, setLogoutError] = useState<unknown>();
  const [leaving, setLeaving] = useState(false);
  const location = useLocation();
  const entries = [
    ["/", "Visão geral", LayoutDashboard],
    ["/publicacoes", "Publicações", Newspaper],
    ["/ministerios", "Ministérios", HandHeart],
    ["/eventos", "Eventos", CalendarDays],
    ["/agenda", "Agenda", CalendarCheck],
    ...(permissions.manage_users ? [["/pessoas", "Pessoas", Users]] : []),
    ["/perfil", "Meu perfil", UserRound],
  ] as const;
  return (
    <div className="app-shell">
      <a className="skip" href="#main">
        Pular para o conteúdo
      </a>
      {open && (
        <button
          className="sidebar-overlay"
          aria-label="Fechar navegação"
          onClick={() => setOpen(false)}
        />
      )}
      <aside className={`sidebar ${open ? "open" : ""}`}>
        <Brand />
        <button
          className="mobile-close icon-button"
          aria-label="Fechar menu"
          onClick={() => setOpen(false)}
        >
          <X />
        </button>
        <span className="nav-label">ESPAÇO DA IGREJA</span>
        <nav>
          {entries.map(([path, label, Icon]) => (
            <NavLink
              key={String(path)}
              end={path === "/"}
              to={String(path)}
              onClick={() => setOpen(false)}
            >
              <Icon size={19} />
              {String(label)}
            </NavLink>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <div className="avatar">{user.name[0]}</div>
          <div>
            <strong>{user.name}</strong>
            <small>{labels[user.role]}</small>
          </div>
          <button
            className="icon-button"
            title="Sair"
            aria-label="Sair da conta"
            disabled={leaving}
            onClick={() => {
              setLeaving(true);
              setLogoutError(undefined);
              logout()
                .catch(setLogoutError)
                .finally(() => setLeaving(false));
            }}
          >
            <LogOut size={18} />
          </button>
        </div>
      </aside>
      <div className="main-shell">
        <div className="topbar">
          <button
            className="mobile-menu icon-button"
            aria-label="Abrir menu"
            onClick={() => setOpen(true)}
          >
            <Menu />
          </button>
          <span>
            Comunidade <span className="slash">/</span>{" "}
            {(entries.find((e) => e[0] === location.pathname)?.[1] as string) ||
              "Início"}
          </span>
          <span className="top-date">
            {new Date().toLocaleDateString("pt-BR", {
              day: "numeric",
              month: "long",
              year: "numeric",
            })}
          </span>
        </div>
        <main id="main" className="main-content">
          <ErrorBox error={logoutError} />
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route
              path="/publicacoes"
              element={<ContentPage key="posts" kind="posts" />}
            />
            <Route
              path="/eventos"
              element={<ContentPage key="events" kind="events" />}
            />
            <Route
              path="/atividades"
              element={<ContentPage key="schedules" kind="schedules" />}
            />
            <Route path="/agenda" element={<CalendarPage />} />
            <Route path="/ministerios" element={<MinistriesPage />} />
            <Route
              path="/pessoas"
              element={
                permissions.manage_users ? (
                  <UsersPage />
                ) : (
                  <Navigate to="/" replace />
                )
              }
            />
            <Route path="/perfil" element={<ProfilePage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
        <footer className="app-footer">Comunidade · Vida em igreja</footer>
      </div>
    </div>
  );
}

function Dashboard() {
  const { user, ministries, permissions } = useSession();
  const news = useData<Page<Content>>("/posts?limit=3");
  const calendar = useData<Page<Content>>("/calendar?limit=4");
  const canCreate =
    permissions.manage_ministries || permissions.led_ministries.length > 0;
  return (
    <>
      <Heading
        title={`Olá, ${user.name.split(" ")[0]}.`}
        subtitle="Um novo dia para estar perto e caminhar juntos."
      />
      <section className="dashboard-hero">
        <div>
          <span className="eyebrow">A IGREJA ACONTECE COM VOCÊ</span>
          <h2>
            Encontre seu lugar.
            <br />
            Faça parte do próximo encontro.
          </h2>
          <p>Acompanhe o que vem aí na sua comunidade.</p>
          <NavLink className="button light" to="/agenda">
            Ver nossa agenda <ArrowUpRight size={18} />
          </NavLink>
        </div>
        <CalendarDays className="hero-icon" size={135} strokeWidth={1} />
      </section>
      <div className="stats">
        <div>
          <span className="stat-icon">
            <HandHeart />
          </span>
          <div>
            <strong>{ministries.length}</strong>
            <span>Ministérios ativos</span>
          </div>
        </div>
        <div>
          <span className="stat-icon">
            <Newspaper />
          </span>
          <div>
            <strong>{news.data?.pagination.total ?? "—"}</strong>
            <span>Publicações para você</span>
          </div>
        </div>
        <div>
          <span className="stat-icon">
            <CalendarDays />
          </span>
          <div>
            <strong>{calendar.data?.pagination.total ?? "—"}</strong>
            <span>Próximos compromissos</span>
          </div>
        </div>
      </div>
      <div className="dashboard-columns">
        <section className="panel">
          <div className="section-heading">
            <h2>Na vida da comunidade</h2>
            <NavLink to="/publicacoes">Ver todas</NavLink>
          </div>
          <ErrorBox error={news.error} />
          {news.loading ? (
            <Loading />
          ) : !news.data?.items.length ? (
            <Empty title="As novidades começam aqui">
              Quando uma publicação for compartilhada, ela aparecerá neste
              espaço.
            </Empty>
          ) : (
            news.data.items.map((p) => (
              <NavLink
                className="news-preview"
                to={`/publicacoes?post=${p.id}`}
                key={p.id}
              >
                <div className="row-meta">
                  <Badge value={p.visibility} />
                  <span>{date(p.published_at)}</span>
                </div>
                <h3>{p.title}</h3>
                <p>
                  {p.content?.slice(0, 170)}
                  {(p.content?.length || 0) > 170 ? "…" : ""}
                </p>
              </NavLink>
            ))
          )}
          {canCreate && (
            <NavLink className="text-action" to="/publicacoes?create=1">
              <Plus size={17} /> Nova publicação
            </NavLink>
          )}
        </section>
        <section className="panel">
          <div className="section-heading">
            <h2>Próximos encontros</h2>
            <NavLink to="/agenda">Ver agenda</NavLink>
          </div>
          <ErrorBox error={calendar.error} />
          {calendar.loading ? (
            <Loading />
          ) : !calendar.data?.items.length ? (
            <Empty title="Agenda aberta">
              Os próximos eventos e atividades estarão aqui.
            </Empty>
          ) : (
            calendar.data.items.map((item) => (
              <div className="upcoming" key={`${item.kind}-${item.id}`}>
                <div className="date-tile">
                  <b>{new Date(item.starts_at!).getDate()}</b>
                  <span>
                    {new Date(item.starts_at!).toLocaleDateString("pt-BR", {
                      month: "short",
                    })}
                  </span>
                </div>
                <div>
                  <h3>{item.title}</h3>
                  <p>{date(item.starts_at)}</p>
                  <Badge value={item.status} />
                </div>
              </div>
            ))
          )}
        </section>
      </div>
    </>
  );
}
