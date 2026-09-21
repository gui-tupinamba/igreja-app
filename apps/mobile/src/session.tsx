import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { publishUser, restoreSession, signIn, signOut, subscribeSession } from "./api";
import type { User } from "./types";

type Session = {
  ready: boolean;
  user: User | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  updateUser: (user: User) => void;
};

const Context = createContext<Session | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [user, setUser] = useState<User | null>(null);
  useEffect(() => {
    const unsubscribe = subscribeSession(setUser);
    restoreSession().catch(() => undefined).finally(() => setReady(true));
    return () => { unsubscribe(); };
  }, []);
  const value = useMemo<Session>(() => ({
    ready,
    user,
    login: async (email, password) => { await signIn(email, password); },
    logout: signOut,
    updateUser: publishUser,
  }), [ready, user]);
  return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useSession() {
  const value = useContext(Context);
  if (!value) throw new Error("SessionProvider ausente.");
  return value;
}
