const fs = require("fs");
const path = require("path");

function loadPlaywright() {
  const candidates = [
    "playwright",
    "playwright-core",
    "/usr/lib/node_modules/playwright",
    "/usr/lib/node_modules/playwright-core",
  ];

  for (const candidate of candidates) {
    try {
      return require(candidate);
    } catch (_) {}
  }

  throw new Error("playwright_module_missing");
}

async function run() {
  const { chromium } = loadPlaywright();
  const targetUrl = process.env.ECB_TARGET_URL || "http://wordpress";
  const screenshotPath =
    process.env.ECB_SCREENSHOT_PATH || "/workspace/storage/playwright/chatbot-fullpage.png";

  fs.mkdirSync(path.dirname(screenshotPath), { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
  await page.route("http://localhost:18080/**", async (route) => {
    const url = route.request().url().replace("http://localhost:18080", "http://host.docker.internal:18080");
    await route.continue({ url });
  });
  await page.goto(targetUrl, { waitUntil: "networkidle", timeout: 60000 });
  await page.waitForTimeout(1500);

  const result = await page.evaluate(() => {
    const shortcode = document.querySelector('.estandar-chatbot-shortcode[data-ecb-shortcode="1"]');
    const host = document.querySelector("#estandar-chatbot-host");
    const title = document.querySelector(".entry-title, .wp-block-post-title, .page-header, .entry-header");
    const root = host && host.shadowRoot ? host.shadowRoot.querySelector("#estandar-chatbot") : null;
    const panel = root ? root.querySelector(".ecb-panel") : null;
    const rect = panel ? panel.getBoundingClientRect() : null;
    return {
      hasShortcode: !!shortcode,
      hasHost: !!host,
      hasRoot: !!root,
      hasPanel: !!panel,
      panelWidth: rect ? Math.round(rect.width) : 0,
      viewportWidth: Math.round(window.innerWidth),
      titleVisible: !!(title && title.offsetParent !== null),
      fullpageClass: !!(root && root.classList.contains("ecb-inline-fullpage")),
    };
  });

  await page.screenshot({ path: screenshotPath, fullPage: true });
  await browser.close();

  if (!result.hasShortcode || !result.hasHost || !result.hasRoot || !result.hasPanel) {
    throw new Error(`chatbot_mount_invalid:${JSON.stringify(result)}`);
  }
  if (result.titleVisible) {
    throw new Error(`page_title_still_visible:${JSON.stringify(result)}`);
  }
  if (!result.fullpageClass) {
    throw new Error(`fullpage_class_missing:${JSON.stringify(result)}`);
  }
  if (result.panelWidth < Math.round(result.viewportWidth * 0.9)) {
    throw new Error(`panel_not_fullwidth:${JSON.stringify(result)}`);
  }

  process.stdout.write(`PLAYWRIGHT_OK ${JSON.stringify(result)}\n`);
}

run().catch((err) => {
  process.stderr.write(String(err && err.stack ? err.stack : err) + "\n");
  process.exit(1);
});
