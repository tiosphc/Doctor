import { spawn } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import path from "node:path";

const root = process.cwd();
const output = path.join(root, "outputs/experience-visible-scroll");
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
    for (const [width,height] of [[1440,900],[1366,768],[390,844]]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        const url='http://127.0.0.1:8080/?experience-visible='+width+'-'+Date.now();
        await send('Page.navigate',{url});
        for(let attempt=0;attempt<100;attempt++) {
            if(await evaluate(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete' && !!document.querySelector('.junie-exp__grid') && Object.keys(document.querySelector('.junie-exp__grid')).some(k=>k.startsWith('__reactFiber'))`).catch(()=>false)) break;
            await delay(200);
        }
        await evaluate('document.fonts.ready.then(()=>true)');
        await delay(500);
        for (const index of [3,2,1,0]) {
            const top=await evaluate(`(() => {
                const step=document.querySelectorAll('.junie-exp__step')[${index}];
                if(innerWidth>=768) return step.getBoundingClientRect().top+scrollY+step.offsetHeight/2-innerHeight/2;
                const track=document.querySelector('.junie-exp__grid'),stage=document.querySelector('.junie-exp__sticky');
                return track.getBoundingClientRect().top+scrollY-parseFloat(getComputedStyle(stage).top)+(track.offsetHeight-stage.offsetHeight)*((${index}+.5)/4);
            })()`);
            await evaluate(`window.scrollTo({top:${top},behavior:'instant'})`);
            await delay(1600);
            const state=await snapshot();
            const hit=await evaluate(`(() => {
                const heading=document.querySelector('.junie-exp__heading');
                const visible=(el,owner) => {const r=el.getBoundingClientRect();return r.width>0&&r.height>0&&owner.contains(document.elementFromPoint(r.left+r.width/2,r.top+r.height/2));};
                const caption=document.querySelector('.junie-exp__caption');
                const panel=document.querySelector('.junie-exp__mobile-panel[data-current="true"]');
                return {label:visible(heading.querySelector('.junie-label span:last-child'),heading),title:visible(heading.querySelector('h2'),heading),caption:innerWidth>=768?visible(caption,caption):visible(panel.querySelector('h3'),panel)};
            })()`);
            if(!hit.label||!hit.title||!hit.caption||state.active!==index||state.visibleImage!==index||state.picture.top<state.heading.bottom-1||state.title.top<Math.max(0,state.header.bottom)) throw new Error('Covered text or wrong slide: '+JSON.stringify({width,index,hit,state}));
            const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            await writeFile(path.join(output,width+'-step-'+(index+1)+'-scroll-up.png'),Buffer.from(shot.data,'base64'));
            reports.push({width,height,index,hit,state});
        }
        const exit=await evaluate(`(() => {const s=document.querySelector('#khong-gian');return s.getBoundingClientRect().bottom+scrollY+50;})()`);
        await evaluate(`window.scrollTo({top:${exit},behavior:'instant'})`);
        await delay(600);
        if(!await evaluate("document.querySelector('.junie-exp__heading').getBoundingClientRect().bottom<=0")) throw new Error('Experience heading extends over next section');
        console.log(width+'x'+height+': all titles/captions stay visible while scrolling up through all four photos; no overlay on next section.');
    }
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    await delay(300);
    await evaluate("window.scrollTo({top:document.querySelector('#khong-gian').getBoundingClientRect().top+scrollY-80,behavior:'instant'})");
    await delay(500);
    const reduced=await evaluate(`(() => {
        const stage=document.querySelector('.junie-exp__sticky');
        return getComputedStyle(stage).position==='static' && Number.isFinite(Number(document.querySelector('.junie-exp__grid').dataset.active)) && document.querySelectorAll('.junie-exp__picture img[aria-hidden="false"]').length===1 && [...document.querySelectorAll('.junie-exp__step')].every(step=>step.getBoundingClientRect().height>0);
    })()`);
    if(!reduced) throw new Error('Reduced-motion experience regressed');
    const baseline=await readFile(path.join(process.env.TEMP,'junie-experience-sticky-check/Sections.before.tsx'),'utf8');
    const current=await readFile('frontend/src/components/landing/Sections.tsx','utf8');
    const outsideExperience=(source)=>source.slice(0,source.indexOf('export function Experience() {'))+source.slice(source.indexOf('/* 05 — Invitation */'));
    if(outsideExperience(baseline)!==outsideExperience(current)) throw new Error('Another section changed');
    await writeFile(path.join(output,'report.json'),JSON.stringify({otherSectionsUnchanged:true,reducedMotion:true,checks:reports},null,2));
    console.log('PASS: all other sections unchanged; reduced-motion fallback preserved.');
    await send('Browser.close').catch(()=>undefined);
} finally {socket?.close();browser.kill();}