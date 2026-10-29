import assert from "node:assert/strict";
import { readFile, readdir, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import path from "node:path";

const readJson = async (file) => JSON.parse((await readFile(file, "utf8")).replace(/^\uFEFF/, ""));
const before = await readJson("outputs/hero-background-before/report.json");
const after = await readJson("outputs/hero-background-after/report.json");
assert.equal(before.length, 7);
assert.equal(after.length, 7);
for (let index = 0; index < 6; index++) {
    for (const property of ["viewport", "hero", "copy", "visual", "links", "images", "font"]) {
        assert.deepEqual(after[index][property], before[index][property], property + " changed at " + before[index].viewport.join("x"));
    }
    assert.deepEqual(after[index].errors, []);
}
assert.equal(after[6].reducedMotion, true);

const baselineDirectory = path.join(process.env.TEMP, "junie-hero-background-check");
const sourceBefore = await readJson(path.join(baselineDirectory, "source-before.json"));
assert.ok(Array.isArray(sourceBefore));
const changed = [];
for (const file of sourceBefore) {
    const hash = createHash("sha256").update(await readFile(file.Path)).digest("hex").toUpperCase();
    if (hash !== file.Hash) changed.push(path.relative(process.cwd(), file.Path).replaceAll("\\", "/"));
}
assert.deepEqual(changed, ["frontend/src/components/landing/Hero.css"]);
const oldPaths = new Set(sourceBefore.map((file) => path.resolve(file.Path)));
const currentPaths = (await readdir("frontend/src", { recursive: true, withFileTypes: true }))
    .filter((entry) => entry.isFile())
    .map((entry) => path.resolve(entry.parentPath, entry.name));
const added = currentPaths.filter((file) => !oldPaths.has(file)).map((file) => path.relative(process.cwd(), file).replaceAll("\\", "/"));
assert.deepEqual(added, ["frontend/src/assets/lovable/hero-background.jpg"]);
const summary = {
    changed,
    added,
    layoutUnchangedAt: after.slice(0, 6).map((report) => report.viewport.join("x")),
    reducedMotion: after[6].reducedMotion,
};
await writeFile("outputs/hero-background-after/verification.json", JSON.stringify(summary, null, 2));
console.log(JSON.stringify(summary, null, 2));
