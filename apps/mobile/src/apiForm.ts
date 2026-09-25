import { API_URL, ApiError, authorizationHeaders, restoreSession } from "./api";

async function decode<T>(response: Response): Promise<T> {
  if (response.status === 204) {
    return undefined as T;
  }

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

    throw new ApiError(
      response.status,
      messages[response.status] || "Não foi possível concluir.",
      payload?.error?.fields || {},
    );
  }

  return payload as T;
}

export async function apiForm<T>(
  path: string,
  form: FormData,
  method = "POST",
): Promise<T> {
  const send = () =>
    fetch(`${API_URL}${path}`, {
      method,

      /*
       * NÃO definir Content-Type aqui.
       *
       * O React Native adicionará automaticamente
       * o multipart/form-data com o boundary correto.
       */
      headers: {
        ...authorizationHeaders(),
      },

      body: form,
    });

  try {
    let response = await send();

    if (response.status === 401) {
      await restoreSession();

      response = await send();
    }

    return await decode<T>(response);
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }

    throw new ApiError(
      0,
      "Sem conexão com o servidor. Verifique sua internet.",
    );
  }
}
