import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { randomBytes } from "node:crypto";
import { resolve } from "node:path";
const root = resolve("../..");
const password = "Only-tests-" + randomBytes(16).toString("hex");
const fixture = (input: object) =>
  JSON.parse(
    execFileSync(
      "docker",
      [
        "compose",
        "-f",
        "docker-compose.yml",
        "-f",
        "docker-compose.web-test.yml",
        "--profile",
        "tools",
        "exec",
        "-T",
        "php-web-test",
        "php",
        "tests/web-fixtures.php",
      ],
      {
        cwd: root,
        input: JSON.stringify(input),
        encoding: "utf8",
        stdio: ["pipe", "pipe", "pipe"],
      },
    ).trim(),
  );
let data: {
  users: Record<string, { id: number; email: string }>;
  ministry: number;
  privatePost: number;
  tag: string;
};
test.beforeAll(() => {
  data = fixture({ action: "seed", password });
});
async function login(page: Page, role: string) {
  await page.goto("/");
  await page.getByLabel("E-mail", { exact: true }).fill(data.users[role].email);
  await page.getByLabel("Senha", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Entrar na comunidade" }).click();
  await expect(page.getByRole("heading", { name: /Olá,/ })).toBeVisible();
}
test("login, sessão renovada após JWT expirado, restauração e logout", async ({
  page,
  context,
}) => {
  await login(page, "admin");
  const cookie = (await context.cookies()).find(
    (c) => c.name === "igreja_refresh_dev",
  );
  expect(cookie?.httpOnly).toBe(true);
  const storage = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
  }));
  expect(storage).toEqual({ local: [], session: [] });
  const expired = fixture({
    action: "expired_token",
    user: data.users.admin.id,
  }).token;
  let injected = false;
  await page.route("**/api/posts?**", async (route) => {
    if (!injected) {
      injected = true;
      await route.continue({
        headers: {
          ...route.request().headers(),
          authorization: `Bearer ${expired}`,
        },
      });
    } else await route.continue();
  });
  await page.getByRole("link", { name: "Publicações", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Novidade da comunidade 13" }),
  ).toBeVisible();
  expect(injected).toBe(true);
  expect(
    (await context.cookies()).find((c) => c.name === "igreja_refresh_dev")
      ?.value,
  ).not.toBe(cookie?.value);
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Publicações", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Sair da conta" }).click();
  await expect(
    page.getByRole("heading", { name: "Que bom ter você aqui." }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("button", { name: "Entrar na comunidade" }),
  ).toBeVisible();
});
test("ADMIN cria e publica conteúdo e evento pela interface", async ({
  page,
}) => {
  await login(page, "admin");
  await page.getByRole("link", { name: "Publicações", exact: true }).click();
  await page
    .getByRole("button", { name: "Nova publicação", exact: true })
    .click();
  const title = `Publicação Web ${data.tag}`;
  await page.getByLabel("Título", { exact: true }).fill(title);
  await page
    .getByLabel("Conteúdo", { exact: true })
    .fill("Conteúdo criado pela interface real.");
  await page.getByRole("button", { name: "Salvar rascunho", exact: true }).click();
  await page.getByRole("button", { name: title, exact: true }).click();
  await page.getByRole("button", { name: "Publicar", exact: true }).click();
  await page.getByRole("button", { name: "Confirmar", exact: true }).click();
  await page.getByRole("button", { name: "Comunidade", exact: true }).click();
  await expect(page.getByRole("button", { name: title })).toBeVisible();
  await page.getByRole("link", { name: "Eventos", exact: true }).click();
  await page.getByRole("button", { name: "Novo evento", exact: true }).click();
  await page
    .getByLabel("Título", { exact: true })
    .fill(`Evento Web ${data.tag}`);
  await page.getByLabel("Início", { exact: true }).fill("2099-10-04T19:00");
  await page
    .getByLabel("Fim (opcional)", { exact: true })
    .fill("2099-10-04T20:30");
  await page.getByRole("button", { name: "Salvar", exact: true }).click();
  await page
    .getByRole("button", { name: `Evento Web ${data.tag}`, exact: true })
    .click();
  await page.getByRole("button", { name: "Publicar", exact: true }).click();
  await page.getByRole("button", { name: "Confirmar", exact: true }).click();
  await page.getByRole("link", { name: "Agenda", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: `Evento Web ${data.tag}`, exact: true }),
  ).toBeVisible();
});
test("gestão de pessoa, ministério, participação e liderança", async ({
  page,
}) => {
  await login(page, "admin");
  await page.getByRole("link", { name: "Pessoas", exact: true }).click();
  await page
    .getByRole("button", { name: "Cadastrar pessoa", exact: true })
    .click();
  await page.getByLabel("Nome completo").fill("Pessoa criada na Web");
  await page
    .getByLabel("E-mail", { exact: true })
    .fill(`new-${data.tag}@e2e.test`);
  await page.getByLabel("Senha inicial").fill(password);
  await page.getByRole("button", { name: "Salvar", exact: true }).click();
  await expect(
    page.getByText("Pessoa criada na Web", { exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Ministérios", exact: true }).click();
  await page.getByRole("button", { name: "Novo ministério" }).click();
  const ministryName = `Acolhimento Web ${data.tag}`;
  await page.getByLabel("Nome", { exact: true }).fill(ministryName);
  await page.getByLabel("Identificador").fill(`acolhimento-${data.tag}`);
  await page.getByRole("button", { name: "Salvar", exact: true }).click();
  const card = page.locator("article").filter({
    has: page.getByRole("heading", { name: ministryName, exact: true }),
  });
  await card.getByRole("button", { name: "Participantes" }).click();
  await page
    .getByRole("combobox", { name: "Pessoa", exact: true })
    .selectOption({ label: `Pessoa criada na Web · new-${data.tag}@e2e.test` });
  await page
    .getByRole("button", { name: "Adicionar participante", exact: true })
    .click();
  await expect(
    page.getByText("Pessoa criada na Web", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Definir liderança", exact: true })
    .click();
  await page.getByRole("button", { name: "Confirmar", exact: true }).click();
  await expect(page.getByText("Lidera este ministério")).toBeVisible();
});
test("MEMBER comenta e edita apenas seu comentário; outsider não vê privado", async ({
  browser,
}) => {
  const member = await browser.newContext();
  const page = await member.newPage();
  await login(page, "member");
  await expect(
    page.getByRole("link", { name: "Pessoas", exact: true }),
  ).toHaveCount(0);
  await page.goto(`/publicacoes?post=${data.privatePost}`);
  await expect(
    page
      .getByRole("dialog")
      .getByText("Assunto reservado dos integrantes", { exact: true }),
  ).toBeVisible();
  await page
    .getByLabel("Deixe seu comentário")
    .fill("Comentário do integrante");
  await page.getByRole("button", { name: "Comentar", exact: true }).click();
  await expect(
    page.getByText("Comentário do integrante", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Editar", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Comentário", exact: true })
    .fill("Comentário corrigido");
  await page.getByRole("button", { name: "Salvar", exact: true }).click();
  await expect(
    page.getByText("Comentário corrigido", { exact: true }),
  ).toBeVisible();
  const other = await browser.newContext();
  const outsider = await other.newPage();
  await login(outsider, "outsider");
  await outsider.goto(`/publicacoes?post=${data.privatePost}`);
  await expect(outsider.getByRole("alert")).toContainText(
    "não está disponível",
  );
  await expect(
    outsider.getByText("Assunto reservado dos integrantes", { exact: true }),
  ).toHaveCount(0);
  await other.close();
  await member.close();
});
test("PASTOR não recebe ADMIN; LEADER só cria no ministério liderado", async ({
  page,
}) => {
  await login(page, "pastor");
  await page.getByRole("link", { name: "Pessoas", exact: true }).click();
  await expect(
    page.getByText(data.users.admin.email, { exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Cadastrar pessoa", exact: true })
    .click();
  await expect(
    page
      .getByRole("combobox", { name: "Cargo global", exact: true })
      .locator('option[value="ADMIN"]'),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Fechar", exact: true }).click();
  await page.getByRole("button", { name: "Sair da conta" }).click();
  await login(page, "leader");
  await page.getByRole("link", { name: "Publicações", exact: true }).click();
  await page
    .getByRole("button", { name: "Nova publicação", exact: true })
    .click();
  const select = page
    .getByRole("dialog")
    .getByRole("combobox", { name: "Ministério", exact: true });
  await expect(select.locator("option")).toHaveCount(1);
  await expect(select).toHaveValue(String(data.ministry));
  await page.getByRole("button", { name: "Fechar", exact: true }).click();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({
    path: resolve(root, ".tmp/web-mobile.png"),
    fullPage: true,
  });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
});
test("revogação remove leitura privada e paginação continua real", async ({
  page,
}) => {
  await login(page, "member");
  await page.getByRole("link", { name: "Publicações", exact: true }).click();
  await page.getByRole("button", { name: "Próxima página" }).click();
  await expect(page.getByText(/Página 2 de/)).toBeVisible();
  fixture({
    action: "revoke",
    user: data.users.member.id,
    ministry: data.ministry,
  });
  await page.goto(`/publicacoes?post=${data.privatePost}`);
  await expect(page.getByRole("alert")).toContainText("não está disponível");
  await expect(
    page.getByText("Assunto reservado dos integrantes", { exact: true }),
  ).toHaveCount(0);
});
test("captura do painel e login responsivos", async ({ page }) => {
  await page.goto("/");
  await page.screenshot({
    path: resolve(root, ".tmp/web-login.png"),
    fullPage: true,
  });
  await login(page, "admin");
  await expect(page.getByText("Carregando...", { exact: true })).toHaveCount(0);
  await page.screenshot({
    path: resolve(root, ".tmp/web-dashboard.png"),
    fullPage: true,
  });
});

test("atividade publicada entra na agenda e cancelamento preserva histórico", async ({
  page,
}) => {
  await login(page, "admin");
  await page.getByRole("link", { name: "Agenda", exact: true }).click();
  await page.getByRole("link", { name: "Gerenciar atividades" }).click();
  await page
    .getByRole("button", { name: "Nova atividade", exact: true })
    .click();
  const title = `Atividade Web ${data.tag}`;
  await page.getByLabel("Título", { exact: true }).fill(title);
  await page.getByLabel("Início", { exact: true }).fill("2099-11-04T19:00");
  await page.getByRole("button", { name: "Salvar", exact: true }).click();
  await page.getByRole("button", { name: title, exact: true }).click();
  await page.getByRole("button", { name: "Publicar", exact: true }).click();
  await page.getByRole("button", { name: "Confirmar", exact: true }).click();
  await page.getByRole("link", { name: "Agenda", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: title, exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Gerenciar atividades" }).click();
  await page.getByRole("button", { name: title, exact: true }).click();
  await page
    .getByRole("button", { name: "Cancelar encontro", exact: true })
    .click();
  await page.getByRole("button", { name: "Confirmar", exact: true }).click();
  await expect(
    page
      .locator("article")
      .filter({ has: page.getByRole("heading", { name: title, exact: true }) })
      .getByText("Cancelado", { exact: true }),
  ).toBeVisible();
});

test("perfil persiste alterações e troca de senha encerra a sessão", async ({
  page,
}) => {
  await login(page, "outsider");
  await page.getByRole("link", { name: "Meu perfil", exact: true }).click();
  await page.getByLabel("Nome completo").fill("Perfil atualizado na Web");
  await page.getByRole("button", { name: "Salvar perfil" }).click();
  await expect(
    page.getByRole("heading", {
      name: "Perfil atualizado na Web",
      exact: true,
    }),
  ).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("Nome completo")).toHaveValue(
    "Perfil atualizado na Web",
  );
  const nextPassword = "Changed-tests-" + randomBytes(16).toString("hex");
  await page.getByLabel("Senha atual").fill(password);
  await page
    .getByLabel("Nova senha", { exact: false })
    .first()
    .fill(nextPassword);
  await page.getByLabel("Confirme a nova senha").fill(nextPassword);
  await page.getByRole("button", { name: "Alterar senha e sair" }).click();
  await expect(
    page.getByRole("button", { name: "Entrar na comunidade" }),
  ).toBeVisible();
  await page
    .getByLabel("E-mail", { exact: true })
    .fill(data.users.outsider.email);
  await page.getByLabel("Senha", { exact: true }).fill(nextPassword);
  await page.getByRole("button", { name: "Entrar na comunidade" }).click();
  await expect(
    page.getByRole("heading", { name: "Perfil atualizado na Web", exact: true }),
  ).toBeVisible();
});
