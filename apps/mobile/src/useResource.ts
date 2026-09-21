import { useCallback, useEffect, useState } from "react";
import { api } from "./api";

export function useResource<T>(path: string) {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<unknown>();
  const [loading, setLoading] = useState(true);
  const load = useCallback(async () => {
    setError(undefined);
    try { setData(await api<T>(path)); }
    catch (reason) { setError(reason); }
    finally { setLoading(false); }
  }, [path]);
  useEffect(() => { setLoading(true); void load(); }, [load]);
  return { data, error, loading, reload: load };
}
