/* eslint-disable no-console */
/**
 * Vercel expects an Output Directory (configured as "public").
 * This repo serves static files from the project root, so we generate /public
 * by copying the static site assets into it at build time.
 */

const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");
const OUT_DIR = path.join(ROOT, "public");

const EXCLUDED_ROOT_FILES = new Set([
  "package.json",
  "package-lock.json",
  "vercel.json",
  ".gitignore",
]);

const EXCLUDED_DIRS = new Set([
  ".git",
  "node_modules",
  "api",
  "admin",
  "data",
  "lib",
  "public",
]);

const ALLOWED_EXTENSIONS = new Set([
  ".html",
  ".css",
  ".js",
  ".mjs",
  ".json",
  ".png",
  ".jpg",
  ".jpeg",
  ".gif",
  ".webp",
  ".svg",
  ".ico",
  ".mp4",
  ".txt",
  ".xml",
]);

function rmDirIfExists(dir) {
  if (!fs.existsSync(dir)) return;
  fs.rmSync(dir, { recursive: true, force: true });
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function copyFile(src, dest) {
  ensureDir(path.dirname(dest));
  fs.copyFileSync(src, dest);
}

function copyDirRecursive(srcDir, destDir) {
  ensureDir(destDir);
  for (const entry of fs.readdirSync(srcDir, { withFileTypes: true })) {
    const src = path.join(srcDir, entry.name);
    const dest = path.join(destDir, entry.name);
    if (entry.isDirectory()) {
      copyDirRecursive(src, dest);
    } else if (entry.isFile()) {
      copyFile(src, dest);
    }
  }
}

function isAllowedStaticRootFile(fileName) {
  if (EXCLUDED_ROOT_FILES.has(fileName)) return false;
  const ext = path.extname(fileName);
  if (!ext) return false;
  if (ext === ".php") return false;
  return ALLOWED_EXTENSIONS.has(ext.toLowerCase());
}

function main() {
  rmDirIfExists(OUT_DIR);
  ensureDir(OUT_DIR);

  // Copy eligible root-level static files
  for (const entry of fs.readdirSync(ROOT, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (EXCLUDED_DIRS.has(entry.name)) continue;
      // Only copy the known static sub-site folder(s).
      if (entry.name === "offer-board" || entry.name === "happyearth") {
        copyDirRecursive(path.join(ROOT, entry.name), path.join(OUT_DIR, entry.name));
      }
      continue;
    }

    if (!entry.isFile()) continue;
    if (!isAllowedStaticRootFile(entry.name)) continue;

    copyFile(path.join(ROOT, entry.name), path.join(OUT_DIR, entry.name));
  }

  // Safety: ensure index.html exists for static hosting.
  const indexPath = path.join(OUT_DIR, "index.html");
  if (!fs.existsSync(indexPath)) {
    console.error("Build failed: public/index.html was not generated.");
    process.exit(1);
  }

  console.log(`Static output generated in ${path.relative(ROOT, OUT_DIR)}/`);
}

main();

