import { spawn } from "node:child_process";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";

const root = process.cwd();
const output = path.join(root, process.env.HERO_QA_OUTPUT || "outputs/hero-motion-qa");
await mkdir(output, { recursive: true });
const browser = spawn("C:/Program Files/Google/Chrome/Application/chrome.exe", [
    "--headless=new",
    "--disable-gpu",
    "--no-first-run",
    "--no-default-browser-check",
    "--remote-debugging-port=9333",
    "--user-data-dir=" + path.join(root, ".tmp/hero-tools/chrome-profile"),
    "about:blank",
], { windowsHide: true, stdio: "ignore" });

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let socket;
const report = [];
try {
    let tab;
    for (let attempt = 0; attempt < 40; attempt++) {
        try {
            const tabs = await fetch("http://127.0.0.1:9333/json").then((r) => r.json());
            tab = tabs.find((t) => t.type === "page");
            if (tab) break;
        } catch {}
        await delay(250);
    }
    if (!tab) throw new Error("Headless Chrome did not start");
    socket = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener("open", resolve, { once: true });
        socket.addEventListener("error", reject, { once: true });
    });
    let next = 0;
    const pending = new Map();
    socket.addEventListener("message", ({ data }) => {
        const message = JSON.parse(data);
        if (!message.id) return;
        const callbacks = pending.get(message.id);
        if (!callbacks) return;
        pending.delete(message.id);
        if (message.error) callbacks.reject(new Error(JSON.stringify(message.error)));
        else callbacks.resolve(message.result);
    });
    function send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++next;
            pending.set(id, { resolve, reject });
            socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async function evaluate(expression) {
        const result = await send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
        return result.result.value;
    }
    await send("Page.enable");
    await send("Runtime.enable");
    for (const [width, height] of [[1920, 1080], [1440, 900], [1366, 768], [768, 1024], [390, 844], [375, 812]]) {
        await send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: false });
        await send("Page.navigate", { url: "http://127.0.0.1:8080/" });
        let ready = false;
        for (let attempt = 0; attempt < 100; attempt++) {
            ready = await evaluate("Boolean(document.querySelector('.junie-hero__heading'))").catch(() => false);
            if (ready) break;
            await delay(300);
        }
        if (!ready) throw new Error("Hero did not render at " + width);
        await evaluate("Promise.race([document.fonts.ready, new Promise(r => setTimeout(r, 12000))]).then(() => true)");
        await delay(1400);
        const result = await evaluate(`(() => {
            const rect = (el) => {
                const r = el.getBoundingClientRect();
                return { x: r.x, y: r.y, width: r.width, height: r.height, right: r.right, bottom: r.bottom };
            };
            const hero = document.querySelector('#trang-chu');
            const heading = document.querySelector('#hero-heading');
            const copy = document.querySelector('.junie-hero__copy');
            const visual = document.querySelector('.junie-hero-visual');
            const main = document.querySelector('.junie-hero-visual__main');
            const detail = document.querySelector('.junie-hero-visual__detail');
            const errors = [];
            const h = rect(hero), c = rect(copy), v = rect(visual), m = rect(main), d = rect(detail);
            if (document.documentElement.scrollWidth > innerWidth) errors.push('Horizontal page overflow');
            for (const el of hero.querySelectorAll('h1, h1 > span, p, a, figure, img')) {
                const r = rect(el);
                if (r.x < -1 || r.right > innerWidth + 1 || r.y < h.y - 1 || r.bottom > h.bottom + 1) errors.push('Element outside hero: ' + el.tagName + '.' + el.className);
            }
            for (const el of heading.children) {
                const range = document.createRange();
                range.selectNodeContents(el);
                const r = range.getBoundingClientRect();
                if (r.right > c.right + 1) errors.push('Heading line overflows text column');
            }
            for (const el of hero.querySelectorAll('a > span:first-child')) {
                const r = rect(el), a = rect(el.parentElement);
                if (el.scrollWidth > el.clientWidth + 1 || r.x < a.x || r.right > a.right) errors.push('CTA text overflow');
            }
            if (innerWidth >= 1280 && h.height > innerHeight + 1) errors.push('Desktop hero exceeds viewport');
            if (innerWidth >= 768 && c.right > v.x) errors.push('Text overlaps photography');
            if (innerWidth < 768 && c.bottom > v.y) errors.push('Mobile photos overlap text');
            if (!(d.x < m.right && d.right > m.x && d.y < m.bottom && d.bottom > m.y)) errors.push('Images do not overlap');
            if (rect(document.querySelector('header')).bottom > c.y) errors.push('Header overlaps text');
            const images = [...hero.querySelectorAll('img')].map((img) => ({ src: img.currentSrc, loaded: img.complete && img.naturalWidth > 0 }));
            if (images.some((img) => !img.loaded)) errors.push('Hero image failed to load');
            const links = [...hero.querySelectorAll('a')].map((a) => a.getAttribute('href'));
            if (JSON.stringify(links) !== JSON.stringify(['/booking', '#dich-vu'])) errors.push('CTA destination changed');
            return { viewport: [innerWidth, innerHeight], hero: h, copy: c, visual: v, images, links, font: getComputedStyle(heading).fontFamily, fontLoaded: document.fonts.check('300 64px "Cormorant Garamond"'), errors };
        })()`);
        report.push(result);
        const shot = await send("Page.captureScreenshot", {
            format: "png",
            captureBeyondViewport: true,
            clip: { x: 0, y: 0, width, height: Math.ceil(result.hero.height), scale: 1 },
        });
        await writeFile(path.join(output, width + "x" + height + ".png"), Buffer.from(shot.data, "base64"));
        console.log(width + "x" + height + ": " + (result.errors.length ? result.errors.join("; ") : "PASS"));
    }
    const activeSlide = () => evaluate("[...document.querySelectorAll('.junie-hero-visual__slide')].findIndex(el => el.dataset.active === 'true')");
    const check = (condition, message) => { if (!condition) throw new Error(message); };
    const firstSlide = await activeSlide();
    check(firstSlide >= 0, 'No active slide');
    await delay(6500);
    const secondSlide = await activeSlide();
    check(firstSlide !== secondSlide, 'Hero did not automatically change photo');
    await delay(5500);
    check(await activeSlide() === firstSlide, 'Slideshow did not loop back');
    const animations = await evaluate(`({
        main: getComputedStyle(document.querySelector('.junie-hero-visual__main img')).animationName,
        secondary: getComputedStyle(document.querySelector('.junie-hero-visual__secondary')).animationName,
        motif: getComputedStyle(document.querySelector('.junie-hero-visual__motif')).animationName,
        opacityTransition: getComputedStyle(document.querySelector('.junie-hero-visual__slide')).transitionDuration,
        activeAccessible: document.querySelectorAll('.junie-hero-visual__slide[aria-hidden="false"]').length,
    })`);
    check(animations.main === 'junie-hero-cinema', 'Photo drift animation missing');
    check(animations.secondary === 'junie-hero-float', 'Skincare float animation missing');
    check(animations.motif === 'junie-hero-line', 'Line art animation missing');
    check(parseFloat(animations.opacityTransition) > 0, 'Photo crossfade missing');
    check(animations.activeAccessible === 1, 'Inactive slides should be hidden from accessibility tree');
    await evaluate("window.scrollTo({top: document.querySelector('#trang-chu').offsetHeight + innerHeight, behavior: 'instant'})");
    await delay(800);
    check(await evaluate("document.querySelector('.junie-hero-visual').dataset.paused === 'true'"), 'Offscreen animation did not pause');
    const offscreenSlide = await activeSlide();
    await delay(5500);
    check(await activeSlide() === offscreenSlide, 'Offscreen slideshow timer kept running');
    await evaluate("window.scrollTo({top: 0, behavior: 'instant'})");
    await delay(800);
    check(await evaluate("document.querySelector('.junie-hero-visual').dataset.paused === 'false'"), 'Animation did not resume on return');
    await delay(5500);
    check(await activeSlide() !== offscreenSlide, 'Slideshow did not resume on return');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await delay(500);
    const target = await evaluate("(() => { const r = document.querySelector('.junie-hero-visual').getBoundingClientRect(); return {x: r.x + r.width * .8, y: r.y + r.height * .7}; })()");
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: target.x, y: target.y });
    await delay(1600);
    check(await evaluate("Math.abs(parseFloat(document.querySelector('.junie-hero-visual').style.getPropertyValue('--pointer-x'))) > .1"), 'Pointer parallax missing');
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 20, y: 140 });
    await delay(100);
    check(await evaluate("parseFloat(document.querySelector('.junie-hero-visual').style.getPropertyValue('--pointer-x')) === 0"), 'Pointer parallax did not reset');
    console.log('PASS: automatic photo changes, looping, crossfade, drift, float, line art, pointer motion, and offscreen pause/resume.');
    report.push({ motion: { automaticPhotoChanges: true, loops: true, crossfade: true, animations, pointerMotion: true, offscreenPauseAndResume: true } });
    await send("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
    await delay(500);
    const reduced = await evaluate(`[...document.querySelectorAll('#trang-chu, #trang-chu *')].every(el => {
        const style = getComputedStyle(el);
        return style.animationName === 'none' || parseFloat(style.animationDuration) <= 0.01;
    })`);
    check(await evaluate("document.querySelector('.junie-hero-visual').dataset.paused === 'true'"), 'Reduced motion did not pause slideshow');
    const reducedSlide = await activeSlide();
    await delay(5500);
    check(await activeSlide() === reducedSlide, 'Reduced motion did not stop photo changes');
    console.log('PASS: reduced motion stops animation and automatic photo changes.');
    report.push({ reducedMotion: reduced });
    if (!reduced) throw new Error("Reduced motion check failed");
    await writeFile(path.join(output, "report.json"), JSON.stringify(report, null, 2));
    if (report.some((r) => r.errors?.length)) process.exitCode = 1;
    await send("Browser.close").catch(() => undefined);
} finally {
    socket?.close();
    browser.kill();
}
