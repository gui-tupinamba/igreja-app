import * as SecureStore from "expo-secure-store";
import type { User } from "./types";

export const API_URL = (process.env.EXPO_PUBLIC_API_URL || "https://api.guitupinamba.dev/api").replace(/\/$/, "");
const REFRESH_KEY = "igreja.mobile.refresh";
const PUSH_TOKEN_KEY = "igreja.mobile.expoPushToken";

export class ApiError extends Error {
  constructor(public status: number, message: string, public fields: Record<string, string> = {}) {
    super(message);
  }
}

type AuthResult = { access_token: string; refresh_token: string; user: User };
let accessToken: string | null = null;
let refreshing: Promise<User> | null = null;
let generation = 0;
const listeners = new Set<(user: User | null) => void>();

export function subscribeSession(listener: (user: User | null) => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

const emit = (user: User | null) => listeners.forEach((listener) => listener(user));

async function decode<T>(response: Response): Promise<T> {
  if (response.status === 204) return undefined as T;
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const messages: Record<number, string> = {
      401: "Sua sessão expirou. Entre novamente.",
      403: "Você não tem permissão para esta ação.",
      404: "Este conteúdo não está disponível.",
      409: "O registro mudou. Atualize a tela e tente novamente.",
      422: "Revise os dados informados.",
      429: "Muitas tentativas. Aguarde alguns minutos.",
    };
    throw new ApiError(response.status, messages[response.status] || "Não foi possível concluir.", payload?.error?.fields || {});
  }
  return payload as T;
}

async function auth(path: string, body: object): Promise<AuthResult> {
  try {
    return await decode<AuthResult>(await fetch(`${API_URL}/auth/mobile/${path}`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Auth-Client": "mobile" },
      body: JSON.stringify(body),
    }));
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(0, "Sem conexão com o servidor. Verifique sua internet.");
  }
}

async function accept(result: AuthResult, expected: number) {
  if (expected !== generation) throw new ApiError(401, "A sessão mudou.");
  await SecureStore.setItemAsync(REFRESH_KEY, result.refresh_token);
  if (expected !== generation) throw new ApiError(401, "A sessão mudou.");
  accessToken = result.access_token;
  emit(result.user);
  return result.user;
}

export async function signIn(email: string, password: string) {
  const expected = ++generation;
  return accept(await auth("login", { email, password }), expected);
}

export function restoreSession(): Promise<User> {
  if (refreshing) return refreshing;
  const expected = generation;
  refreshing = (async () => {
    const refreshToken = await SecureStore.getItemAsync(REFRESH_KEY);
    if (!refreshToken) throw new ApiError(401, "Entre para continuar.");
    return accept(await auth("refresh", { refresh_token: refreshToken }), expected);
  })().catch(async (error) => {
    if (expected === generation) await clearSession();
    throw error;
  }).finally(() => { refreshing = null; });
  return refreshing;
}

export async function clearSession() {
  generation++;
  accessToken = null;
  await SecureStore.deleteItemAsync(REFRESH_KEY);
  emit(null);
}

export async function signOut() {
  const refreshToken = await SecureStore.getItemAsync(REFRESH_KEY);
  try {
    const pushToken = await SecureStore.getItemAsync(PUSH_TOKEN_KEY);
    if (pushToken && accessToken) {
      await fetch(`${API_URL}/notifications/devices/unregister`, { method: "POST", headers: { ...authorizationHeaders(), "Content-Type": "application/json" }, body: JSON.stringify({ expo_push_token: pushToken }) }).catch(() => undefined);
      await SecureStore.deleteItemAsync(PUSH_TOKEN_KEY);
    }
    if (refreshToken) await auth("logout", { refresh_token: refreshToken });
  } finally {
    await clearSession();
  }
}

export async function changePasswordLocallyComplete() {
  await clearSession();
}

export function authorizationHeaders(): Record<string, string> {
  return accessToken ? { Authorization: `Bearer ${accessToken}` } : {};
}

export async function api<T>(path: string, method = "GET", body?: object): Promise<T> {
  const send = () => fetch(`${API_URL}${path}`, {
    method,
    headers: { ...authorizationHeaders(), ...(body === undefined ? {} : { "Content-Type": "application/json" }) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  try {
    let response = await send();
    if (response.status === 401) {
      await restoreSession();
      response = await send();
    }
    return await decode<T>(response);
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(0, "Sem conexão com o servidor. Verifique sua internet.");
  }
}

export function publishUser(user: User) {
  emit(user);
}
