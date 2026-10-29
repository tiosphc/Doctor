import { spawn } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import path from "node:path";

const root = process.cwd();
const output = path.join(root, "outputs/experience-sticky-qa");
await mkdir(output, { recursive: true });
const browser = spawn("C:/Program Files/Google/Chrome/Application/chrome.exe", [
    "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
    "--remote-debugging-port=9333",
    "--user-data-dir=" + path.join(root, ".tmp/hero-tools/chrome-profile"), "about:blank",
], { windowsHide: true, stdio: "ignore" });
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let socket;
const reports = [];
try {
    let tab;
    for (let attempt = 0; attempt < 40; attempt++) {
        try { tab = (await fetch("http://127.0.0.1:9333/json").then(r => r.json())).find(t => t.type === "page"); } catch {}
        if (tab) break;
        await delay(250);
    }
    if (!tab) throw new Error("Chrome did not start");
    socket = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener("open", resolve, { once: true });
        socket.addEventListener("error", reject, { once: true });
    });
    let id = 0;
    const pending = new Map();
    socket.addEventListener("message", ({data}) => {
        const message = JSON.parse(data);
        const call = pending.get(message.id);
        if (!call) return;
        pending.delete(message.id);
        if (message.error) call.reject(new Error(JSON.stringify(message.error)));
        else call.resolve(message.result);
    });
    function send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const callId = ++id;
            pending.set(callId, { resolve, reject });
            socket.send(JSON.stringify({ id: callId, method, params }));
        });
    }
    async function evaluate(expression) {
        const result = await send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
        return result.result.value;
    }
    const snapshot = () => evaluate(`(() => {
        const bounds = (selector) => {
            const element = typeof selector === 'string' ? document.querySelector(selector) : selector;
            const r = element.getBoundingClientRect();
            return {top:r.top, bottom:r.bottom, left:r.left, right:r.right, width:r.width, height:r.height};
        };
        const section = document.querySelector('#khong-gian');
        const active = Number(section.querySelector('.junie-exp__grid').dataset.active);
        const step = section.querySelectorAll('.junie-exp__step')[active];
        const copy = innerWidth < 768 ? section.querySelector('.junie-exp__mobile-panel[data-current="true"]') : step;
        return {
            viewport:[innerWidth, innerHeight], active,
            section: bounds(section), title: bounds('.junie-exp__title'),
            picture: bounds('.junie-exp__picture'), stage: bounds('.junie-exp__sticky'), heading: bounds('.junie-exp__heading'), label: bounds('.junie-exp__heading .junie-label'), caption: bounds('.junie-exp__caption'),
            header: bounds('header'), activeTitle: bounds(copy.querySelector('h3')),
            description: bounds(copy.querySelector('p')),
            visibleImage: [...section.querySelectorAll('.junie-exp__picture img')].findIndex(img => img.getAttribute('aria-hidden') === 'false'),
            transition: getComputedStyle(section.querySelector('.junie-exp__picture img')).transitionDuration,
            overflow: document.documentElement.scrollWidth > innerWidth,
        };
    })()`);
    await send("Page.enable");
    await send("Runtime.enable");
    await send("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "no-preference" }] });
    for (const [width,height] of [[2560,1080],[1920,1080],[1440,900],[1366,768],[1366,640],[1024,600],[768,1024],[390,844],[375,812]]) {
        await send("Emulation.setDeviceMetricsOverride", { width,height,deviceScaleFactor:1,mobile:false });
        const url = "http://127.0.0.1:8080/?experience-qa=" + width + "-" + height + "-" + Date.now();
        await send("Page.navigate", { url });
        let ready = false;
        for (let attempt = 0; attempt < 100; attempt++) {
            ready = await evaluate(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete' && !!document.querySelector('.junie-exp__grid') && Object.keys(document.querySelector('.junie-exp__grid')).some(key => key.startsWith('__reactFiber'))`).catch(() => false);
            if (ready) break;
            await delay(200);
        }
        if (!ready) throw new Error("Experience did not render");
        await evaluate("Promise.race([document.fonts.ready,new Promise(r=>setTimeout(r,10000))]).then(()=>true)");
        await delay(500);
        let target = await evaluate(`document.querySelector('#khong-gian').getBoundingClientRect().top+scrollY-${width < 768 ? 80 : 112}`);
        await evaluate(`window.scrollTo({top:${target+24},behavior:'instant'})`);
        await delay(100);
        await evaluate(`window.scrollTo({top:${target},behavior:'instant'})`);
        await delay(1400);
        for (let attempt = 0; attempt < 5; attempt++) {
            const sectionTop = await evaluate("document.querySelector('#khong-gian').getBoundingClientRect().top");
            if (Math.abs(sectionTop - (width < 768 ? 80 : 112)) < 2) break;
            target = await evaluate(`document.querySelector('#khong-gian').getBoundingClientRect().top+scrollY-${width < 768 ? 80 : 112}`);
            await evaluate(`window.scrollTo({top:${target+24},behavior:'instant'})`);
            await delay(100);
            await evaluate(`window.scrollTo({top:${target},behavior:'instant'})`);
            await delay(900);
        }
        const initial = await snapshot();
        const errors = [];
        if (initial.overflow) errors.push("Horizontal overflow");
        if (initial.title.top < initial.header.bottom) errors.push("Heading behind navigation");
        if (initial.picture.top < initial.title.bottom+15) errors.push("Photo overlaps section heading");
        if (initial.picture.bottom > height-12) errors.push("Intro photo is taller than available viewport");
        if (initial.description.bottom > height-8) errors.push("Intro copy exceeds viewport");
        const screenshot = await send("Page.captureScreenshot", { format:"png", captureBeyondViewport:false });
        await writeFile(path.join(output,width+"x"+height+".png"),Buffer.from(screenshot.data,"base64"));
        const states = [];
        for (const index of [0,1,2,3,2,1,0]) {
            const scrollTarget = await evaluate(`(() => {
                if (innerWidth < 768) {
                    const track=document.querySelector('.junie-exp__grid');
                    const stage=document.querySelector('.junie-exp__sticky');
                    return track.getBoundingClientRect().top+scrollY-parseFloat(getComputedStyle(stage).top)+(track.offsetHeight-stage.offsetHeight)*((${index}+.5)/4);
                }
                const step=document.querySelectorAll('.junie-exp__step')[${index}];
                return step.getBoundingClientRect().top+scrollY+step.offsetHeight/2-innerHeight/2;
            })()`);
            await evaluate(`window.scrollTo({top:${scrollTarget},behavior:'instant'})`);
            await delay(650);
            const state = await snapshot();
            if (state.title.top < Math.max(0,state.header.bottom) || state.label.top < Math.max(0,state.header.bottom)) errors.push("Section heading disappeared at step "+index);
            if (state.picture.top < state.heading.bottom-1) errors.push("Photo overlaps section heading at step "+index);
            if (width >= 768 && state.caption.bottom > height-8) errors.push("Image caption outside viewport at step "+index);
            if (state.active !== index || state.visibleImage !== index) errors.push("Scroll storytelling failed at step "+index);
            if (state.picture.top < Math.max(0,state.header.bottom)+(width < 768 ? 2 : 12)) errors.push("Sticky photo overlaps navigation at step "+index);
            if (state.picture.bottom > height-12) errors.push("Sticky photo cropped at step "+index);
            if (state.description.bottom > height-8 || state.activeTitle.top < Math.max(0,state.header.bottom)) errors.push("Step copy outside viewport at step "+index);
            states.push(state);
        }
        reports.push({ initial, states, errors });
        console.log(width+"x"+height+": "+(errors.length ? errors.join("; ") : "PASS"));
    }
    const sourceBefore = JSON.parse((await readFile(path.join(process.env.TEMP,"junie-experience-sticky-check/source-before.json"),"utf8")).replace(/^\uFEFF/,""));
    const changed = [];
    for (const file of sourceBefore) {
        const hash=createHash("sha256").update(await readFile(file.Path)).digest("hex").toUpperCase();
        if (hash !== file.Hash) changed.push(path.relative(root,file.Path).replaceAll("\\","/"));
    }
    if (JSON.stringify(changed) !== JSON.stringify(["frontend/src/components/landing/Experience.css","frontend/src/components/landing/Sections.tsx"])) throw new Error("Unexpected source changes: "+changed);
    await writeFile(path.join(output,"report.json"),JSON.stringify({changed,reports},null,2));
    if (reports.some(r=>r.errors.length)) process.exitCode=1;
    await send("Browser.close").catch(()=>undefined);
} finally {
    socket?.close();
    browser.kill();
}
