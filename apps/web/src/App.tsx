import { useEffect, useState, type FormEvent } from "react";
import { Church, ArrowRight, Eye, EyeOff, LogOut } from "lucide-react";
import { login, logout, refresh, subscribeSession } from "./api";
import type { User } from "./types";
import Shell from "./Shell";

export default function App() {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    const unsubscribe = subscribeSession(setUser);
    refresh()
      .catch(() => {})
      .finally(() => setLoading(false));
    return unsubscribe;
  }, []);
  if (loading)
    return (
      <div className="boot" role="status">
        <Church size={38} />
        <p>Conectando sua comunidade…</p>
      </div>
    );
  if (user) return <Shell key={user.id} user={user} />;
  return <Login />;
}
export function Brand() {
  return (
    <div className="brand">
      <span className="brand-mark">
        <Church size={26} />
      </span>
      <span>
        Comunidade<small>VIDA EM IGREJA</small>
      </span>
    </div>
  );
}
function Login() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [show, setShow] = useState(false);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    setBusy(true);
    setError("");
    try {
      await login(String(data.get("email")), String(data.get("password")));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Não foi possível entrar.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="login-page">
      <section className="welcome">
        <Brand />
        <div className="welcome-copy">
          <span className="eyebrow">PERTENCER. SERVIR. CAMINHAR JUNTOS.</span>
          <h1>
            A vida da igreja,
            <br />
            <em>mais perto de você.</em>
          </h1>
          <p>
            Um lugar para acompanhar sua comunidade, servir nos ministérios e
            fazer parte de cada encontro.
          </p>
          <div className="welcome-line" />
          <span className="welcome-foot">
            Conectados pela fé. Presentes na vida.
          </span>
        </div>
        <div className="welcome-bottom">
          PUBLICAÇÕES <span>•</span> MINISTÉRIOS <span>•</span> AGENDA
        </div>
      </section>
      <section className="login-side">
        <form className="login-form" onSubmit={submit}>
          <span className="eyebrow green">BEM-VINDO À SUA COMUNIDADE</span>
          <h2>Que bom ter você aqui.</h2>
          <p>Entre com sua conta para continuar.</p>
          {error && (
            <div role="alert" className="error">
              {error}
            </div>
          )}
          <label>
            E-mail
            <input
              name="email"
              type="email"
              autoComplete="username"
              placeholder="seu@email.com"
              required
              maxLength={180}
            />
          </label>
          <label>
            Senha
            <div className="password-wrap">
              <input
                name="password"
                type={show ? "text" : "password"}
                autoComplete="current-password"
                required
              />
              <button
                type="button"
                className="icon-button"
                aria-label={show ? "Ocultar senha" : "Mostrar senha"}
                onClick={() => setShow(!show)}
              >
                {show ? <EyeOff size={19} /> : <Eye size={19} />}
              </button>
            </div>
          </label>
          <button className="primary login-submit" disabled={busy}>
            {busy ? "Entrando…" : "Entrar na comunidade"}
            <ArrowRight size={19} />
          </button>
          <div className="login-help">
            Ainda não tem acesso?
            <br />
            Fale com a administração da sua igreja.
          </div>
        </form>
        <small className="login-footer">Um espaço de cuidado e conexão.</small>
      </section>
    </main>
  );
}
