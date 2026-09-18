import type { User } from "./types";

export const API_URL = (
  import.meta.env.VITE_API_URL ||
  (["localhost", "127.0.0.1", "[::1]"].includes(window.location.hostname)
    ? `http://${window.location.hostname}:8080/api`
    : "https://api.guitupinamba.dev/api")
).replace(/\/$/, "");
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public fields: Record<string, string> = {},
  ) {
    super(message);
  }
}
let token: string | null = null;
let generation = 0;
let refreshing: Promise<User> | null = null;

const imageRequests = new Map<string, Promise<Blob>>();

const listeners = new Set<(user: User | null) => void>();
const channel =
  typeof BroadcastChannel !== "undefined"
    ? new BroadcastChannel("igreja-session")
    : null;
const emit = (user: User | null) => listeners.forEach((fn) => fn(user));
export function subscribeSession(fn: (user: User | null) => void) {
  listeners.add(fn);
  return () => {
    listeners.delete(fn);
  };
}
export function clearSession(broadcast = false) {
  generation++;
  token = null;

  imageRequests.clear();
  
  emit(null);
  if (broadcast) channel?.postMessage("changed");
}
if (channel) channel.onmessage = () => clearSession();
const lock = <T>(operation: () => Promise<T>): Promise<T> =>
  navigator.locks
    ? navigator.locks.request("igreja-auth-cookie", operation)
    : operation();

async function decode<T>(response: Response): Promise<T> {
  if (response.status === 204) return undefined as T;
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const defaults: Record<number, string> = {
      401: "Entre novamente para continuar.",
      403: "Você não tem permissão para esta ação.",
      404: "Este conteúdo não está disponível.",
      409: "A operação conflita com o estado atual. Confira os dados.",
      422: "Revise os campos informados.",
      429: "Muitas tentativas. Aguarde alguns minutos.",
    };
    throw new ApiError(
      response.status,
      defaults[response.status] ||
        "Não foi possível concluir. Tente novamente.",
      payload?.error?.fields || {},
    );
  }
  return payload as T;
}

async function auth(path: string, body: object) {
  return decode<{ access_token: string; user: User }>(
    await fetch(`${API_URL}/auth/${path}`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json", "X-Auth-Client": "web" },
      body: JSON.stringify(body),
    }).catch(() => {
      throw new ApiError(
        0,
        "Sem conexão com o servidor. Verifique sua conexão e tente novamente.",
      );
    }),
  );
}

export async function login(email: string, password: string) {
  const current = ++generation;
  return lock(async () => {
    const result = await auth("login", { email, password });
    if (current !== generation)
      throw new ApiError(401, "A sessão mudou. Entre novamente.");
    token = result.access_token;
    emit(result.user);
    channel?.postMessage("changed");
    return result.user;
  });
}

export function refresh(): Promise<User> {
  if (refreshing) return refreshing;
  const current = generation;
  refreshing = lock(async () => {
    if (current !== generation) throw new ApiError(401, "A sessão mudou.");
    const result = await auth("refresh", {});
    if (current !== generation) throw new ApiError(401, "A sessão mudou.");
    token = result.access_token;
    emit(result.user);
    return result.user;
  })
    .catch((error) => {
      if (current === generation) clearSession();
      throw error;
    })
    .finally(() => {
      refreshing = null;
    });
  return refreshing;
}

export async function logout() {
  await lock(async () => {
    await auth("logout", {});
    clearSession(true);
  });
}

export async function api<T>(
  path: string,
  method = "GET",
  body?: object,
): Promise<T> {
  const current = generation;
  const send = () =>
    fetch(`${API_URL}${path}`, {
      method,
      credentials: "include",
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
  try {
    let response = await send();
    if (response.status === 401 && current === generation) {
      await refresh();
      response = await send();
    }
    if (current !== generation) throw new ApiError(401, "A sessão mudou.");
    const result = await decode<T>(response);
    if (current !== generation) throw new ApiError(401, "A sessão mudou.");
    return result;
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(
      0,
      "Sem conexão com o servidor. Verifique sua conexão e tente novamente.",
    );
  }
}

export async function apiBlob(path: string): Promise<Blob> {
  const existingRequest = imageRequests.get(path);

  if (existingRequest) {
    return existingRequest;
  }

  const request = (async () => {
    const current = generation;

    const send = () =>
      fetch(`${API_URL}${path}`, {
        method: "GET",
        credentials: "include",
        headers: {
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
      });

    try {
      let response = await send();

      if (response.status === 401 && current === generation) {
        await refresh();
        response = await send();
      }

      if (current !== generation) {
        throw new ApiError(401, "A sessão mudou. Entre novamente.");
      }

      if (!response.ok) {
        await decode<never>(response);

        throw new ApiError(
          response.status,
          "Não foi possível carregar a imagem.",
        );
      }

      const blob = await response.blob();

      return blob;
    } catch (error) {
      if (error instanceof ApiError) {
        throw error;
      }

      throw new ApiError(0, "Não foi possível carregar a imagem.");
    }
  })().finally(() => {
    if (imageRequests.get(path) === request) {
      imageRequests.delete(path);
    }
  });

  imageRequests.set(path, request);

  return request;
}

export async function apiForm<T>(
  path: string,
  form: FormData,
  method = "POST",
): Promise<T> {
  const current = generation;

  const send = () =>
    fetch(`${API_URL}${path}`, {
      method,
      credentials: "include",
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: form,
    });

  try {
    let response = await send();

    if (response.status === 401 && current === generation) {
      await refresh();
      response = await send();
    }

    if (current !== generation) {
      throw new ApiError(401, "A sessão mudou. Entre novamente.");
    }

    return await decode<T>(response);
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }

    throw new ApiError(
      0,
      "Sem conexão com o servidor. Verifique sua conexão e tente novamente.",
    );
  }
}