import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { api } from "./api";
import { all, Loading } from "./ui";
import type { Ministry, Permissions, User } from "./types";
const empty: Permissions = {
  manage_users: false,
  manage_ministries: false,
  manage_settings: false,
  led_ministries: [],
};
interface Session {
  user: User;
  permissions: Permissions;
  ministries: Ministry[];
  refreshInfo: () => void;
  canManage: (ministry: number | null) => boolean;
}
const Context = createContext<Session>(null!);
export const useSession = () => useContext(Context);
export function SessionProvider({
  user,
  children,
}: {
  user: User;
  children: ReactNode;
}) {
  const [permissions, setPermissions] = useState(empty);
  const [ministries, setMinistries] = useState<Ministry[]>([]);
  const [revision, setRevision] = useState(0);
  const [ready, setReady] = useState(false);
  const refreshInfo = () => setRevision((v) => v + 1);
  useEffect(() => {
    let active = true;
    Promise.all([
      api<{ permissions: Permissions }>("/auth/permissions"),
      all<Ministry>("/ministries"),
    ])
      .then(([p, m]) => {
        if (active) {
          setPermissions(p.permissions);
          setMinistries(m);
          setReady(true);
        }
      })
      .catch(() => {
        if (active) {
          setPermissions(empty);
          setMinistries([]);
          setReady(true);
        }
      });
    return () => {
      active = false;
    };
  }, [user.id, user.role, revision]);
  useEffect(() => {
    window.addEventListener("focus", refreshInfo);
    return () => window.removeEventListener("focus", refreshInfo);
  }, []);
  return (
    <Context.Provider
      value={{
        user,
        permissions,
        ministries,
        refreshInfo,
        canManage: (id) =>
          permissions.manage_ministries ||
          permissions.led_ministries.some((m) => m.id === id),
      }}
    >
      {ready ? children : <Loading />}
    </Context.Provider>
  );
}
