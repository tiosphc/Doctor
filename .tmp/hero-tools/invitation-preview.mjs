import { spawn } from "node:child_process";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";

const root = process.cwd();
const output = path.join(root, "outputs/invitation-brown");
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
    for (const [width,height] of [[1440,900],[390,844]]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        const url='http://127.0.0.1:8080/?invitation-preview='+width+'-'+Date.now();
        await send('Page.navigate',{url});
        for (let attempt=0;attempt<100;attempt++) {
            if (await evaluate(`location.href === ${JSON.stringify(url)} && !!document.querySelector('#lien-he') && Object.keys(document.querySelector('#lien-he')).some(k=>k.startsWith('__reactFiber'))`).catch(()=>false)) break;
            await delay(200);
        }
        await evaluate('document.fonts.ready.then(()=>true)');
        await delay(500);
        const area=await evaluate(`(() => {const s=document.querySelector('#lien-he');return {top:s.getBoundingClientRect().top+scrollY,height:s.offsetHeight};})()`);
        await evaluate(`window.scrollTo({top:${area.top-96},behavior:'instant'})`);
        await delay(600);
        await evaluate(`window.scrollTo({top:${area.top+area.height-height+80},behavior:'instant'})`);
        await delay(600);
        await evaluate(`window.scrollTo({top:${area.top-96},behavior:'instant'})`);
        await delay(2200);
        const colors=await evaluate(`(() => {
            const s=document.querySelector('#lien-he');
            return {background:getComputedStyle(s).backgroundColor,heading:getComputedStyle(s.querySelector('h2')).color,body:getComputedStyle(s.querySelector('p')).color,button:getComputedStyle(s.querySelector('a')).backgroundColor,links:[...s.querySelectorAll('a')].map(a=>a.getAttribute('href')),imageAnimation:getComputedStyle(s.querySelector('img')).animationName,frameAnimation:getComputedStyle(s.querySelector('.junie-invitation__visual-edge')).animationName,horizontalOverflow:document.documentElement.scrollWidth>innerWidth};
        })()`);
        if (JSON.stringify(colors.links)!==JSON.stringify(['/booking','/services']) || colors.horizontalOverflow || colors.imageAnimation!=='invitation-drift' || colors.frameAnimation!=='invitation-float') throw new Error('Invitation behavior changed');
        const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:area.top,width,height:area.height,scale:1}});
        await writeFile(path.join(output,width+'.png'),Buffer.from(shot.data,'base64'));
        console.log(JSON.stringify({width,height,...colors}));
    }
    await send('Browser.close').catch(()=>undefined);
} finally {socket?.close();browser.kill();}