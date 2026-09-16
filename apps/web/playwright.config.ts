import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./tests",
  testMatch: "**/*.spec.ts",
  fullyParallel: false,
  workers: 1,
  timeout: 45000,
  reporter: "list",
  outputDir: "../../.tmp/web-test-results",
  use: {
    baseURL: "http://127.0.0.1:5174",
    headless: true,
    trace: "off",
    screenshot: "off",
  },
  webServer: {
    command: `${process.platform === "win32" ? "npm.cmd" : "npm"} run dev -- --port 5174`,
    url: "http://127.0.0.1:5174",
    reuseExistingServer: false,
    env: { VITE_API_URL: "http://127.0.0.1:8081/api" },
    timeout: 60000,
  },
});
