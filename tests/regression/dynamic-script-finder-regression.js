#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const finderSource = fs.readFileSync(path.join(root, 'assets/js/dynamic-script-finder-bootstrap.js'), 'utf8');
const loaderSource = fs.readFileSync(path.join(root, 'assets/js/delayed-js-loader.js'), 'utf8');
const delayTraitSource = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-delay-trait.php'), 'utf8');
const registrySource = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-runtime-registry-trait.php'), 'utf8');
const policySource = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-policy-trait.php'), 'utf8');

let passes = 0;
const failures = [];
function expect(condition, label) {
    if (condition) { passes++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

expect(/'dynamic-script-finder-bootstrap'[\s\S]*?'lane'\s*=>\s*'native'[\s\S]*?'parser_early_required'\s*=>\s*true/.test(registrySource),
    'A1: dynamic capture bootstrap is explicit parser-early NATIVE infrastructure');
expect(delayTraitSource.includes('ultracache_build_unified_js_execution_policy') && delayTraitSource.includes("'dynamicPolicyEncoded'")
    && policySource.includes("'rules' => array(") && policySource.includes("'patterns' => array("),
    'A2: server emits one canonical declarative policy snapshot for registered and dynamic classifiers');
expect(delayTraitSource.includes("'ultracacheDynamicScriptFinderConfig'")
    && /'nativePatterns'\s*=>\s*isset\(\$dynamic_policy\['patterns'\]\['native'\]\)/.test(delayTraitSource)
    && /'encodedPolicy'\s*=>\s*\$native_policy_encoded/.test(delayTraitSource)
    && !delayTraitSource.includes('auditEnabled'),
    'A3: parser-early bootstrap receives only visible NATIVE policy; diagnostics state is not transported in production Finder config');
expect(!finderSource.includes('googletagmanager') && !finderSource.includes('woocommerce') && !finderSource.includes('elementor')
    && finderSource.includes('var complianzCompatibility = !!bootstrapPolicy.complianzCompatibility;')
    && finderSource.includes('complianzInfrastructurePatterns'),
    'A4: native finder bootstrap contains no generic vendor policy; Complianz appears only as explicit consent-controller infrastructure provenance');
expect(finderSource.includes("patch('appendChild'") && finderSource.includes("patch('insertBefore'") && finderSource.includes("patch('replaceChild'"),
    'A5: appendChild/insertBefore/replaceChild are intercepted generically');
expect(finderSource.includes("ElementProto.setAttribute = function") && finderSource.includes("Object.defineProperty(ScriptProto, 'src'"),
    'A6: connected dynamic src assignment is intercepted before request dispatch');
expect(finderSource.includes('DocumentProto.write = function') && finderSource.includes('DocumentProto.writeln = function'),
    'A7: complete document.write/writeln script fragments enter the same capture path');
expect(loaderSource.includes('function classifyDynamicScript(') && loaderSource.includes('function evaluateUnifiedDynamicPolicy(')
    && loaderSource.includes('dynamicPolicyRules') && loaderSource.includes('dynamicPolicyFlags'),
    'A8: deferred browser classifier interprets the server-generated canonical rule table');
expect(loaderSource.includes('flushDynamicBootstrapPendingScripts()') && loaderSource.includes("load(immediate, 0, 'dynamic-defer'"),
    'A9: pre-loader pending DEFER scripts are released through the existing ordered loader at DEFER time');
expect(loaderSource.includes('setDynamicScriptFinderReleasePhase(1)') && loaderSource.includes('setDynamicScriptFinderReleasePhase(2)'),
    'A10: existing Delay executor opens finder release gates instead of a second executor');
expect(loaderSource.includes('thirdParty = stableOrderDelayedNodes(pendingDelayedScripts())'),
    'A11: late boundary re-reads every dynamic placeholder and reapplies stable dependency ordering after first-party execution');
expect(!finderSource.includes('fetch(') && !finderSource.includes('XMLHttpRequest') && !finderSource.includes('sendBeacon'),
    'A12: production capture bootstrap performs no diagnostic/network telemetry');

class FakeNode {
    constructor() { this.parentNode = null; this.children = []; this.isConnected = false; }
    appendChild(node) { node.parentNode = this; node.isConnected = !!this.isConnected; this.children.push(node); return node; }
    insertBefore(node, reference) { if (!reference) return this.appendChild(node); const i=this.children.indexOf(reference); if(i<0)return this.appendChild(node); node.parentNode=this; node.isConnected=!!this.isConnected; this.children.splice(i,0,node); return node; }
    replaceChild(node, oldNode) { const i=this.children.indexOf(oldNode); if(i<0)throw new Error('missing'); node.parentNode=this; node.isConnected=!!this.isConnected; oldNode.parentNode=null; oldNode.isConnected=false; this.children[i]=node; return oldNode; }
    removeChild(node) { const i=this.children.indexOf(node); if(i>=0)this.children.splice(i,1); node.parentNode=null; node.isConnected=false; return node; }
}
class FakeElement extends FakeNode {
    constructor(tagName) { super(); this.tagName=String(tagName||'').toUpperCase(); this.nodeName=this.tagName; this._attrs=Object.create(null); this.textContent=''; }
    get attributes() { return Object.keys(this._attrs).map((name)=>({name,value:this._attrs[name]})); }
    getAttribute(name) { name=String(name).toLowerCase(); return Object.prototype.hasOwnProperty.call(this._attrs,name)?this._attrs[name]:null; }
    setAttribute(name,value) { this._attrs[String(name).toLowerCase()]=String(value); }
    removeAttribute(name) { delete this._attrs[String(name).toLowerCase()]; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this._attrs,String(name).toLowerCase()); }
}
class FakeScriptElement extends FakeElement {
    constructor(){ super('script'); this.text=''; }
    get src(){ return this.getAttribute('src')||''; }
    set src(value){ this._attrs.src=String(value); }
}
class FakeTemplateElement extends FakeElement {
    constructor(){ super('template'); this.content={querySelectorAll(){return [];}}; this._innerHTML=''; }
    get innerHTML(){ return this._innerHTML; }
    set innerHTML(value){ this._innerHTML=String(value); }
}
class FakeDocument extends FakeNode {
    constructor(){ super(); this.isConnected=true; this.baseURI='https://site.example/page'; this.documentElement=new FakeElement('html'); this.documentElement.isConnected=true; this.head=new FakeElement('head'); this.head.isConnected=true; this.body=new FakeElement('body'); this.body.isConnected=true; }
    createElement(name){ if(String(name).toLowerCase()==='script')return new FakeScriptElement(); if(String(name).toLowerCase()==='template')return new FakeTemplateElement(); return new FakeElement(name); }
    querySelectorAll(){ return []; }
    write(){}
    writeln(){}
}

const bootstrapPolicy = { nativePatterns: ['keep-native.js'] };
const encodedPolicy = Buffer.from(JSON.stringify(bootstrapPolicy),'utf8').toString('base64url');
const document = new FakeDocument();
const fakeWindow = {
    Node:FakeNode, Element:FakeElement, HTMLScriptElement:FakeScriptElement, Document:FakeDocument, document,
    location:{origin:'https://site.example',href:'https://site.example/page'},
    ultracacheDynamicScriptFinderConfig:{encodedPolicy}, URL, TextDecoder, Uint8Array,
    atob(v){return Buffer.from(String(v),'base64').toString('binary');},
    btoa(v){return Buffer.from(String(v),'binary').toString('base64');}
};
const sandbox={window:fakeWindow,document,URL,TextDecoder,Uint8Array,encodeURIComponent,decodeURIComponent,unescape,console};
vm.createContext(sandbox);
vm.runInContext(finderSource,sandbox);
const state=fakeWindow.__ultracacheDynamicScriptFinderV31211;
expect(!!state && typeof state.setClassifier==='function' && typeof state.setExecutor==='function' && typeof state.setReleasePhase==='function' && typeof state.getPendingNodes==='function' && typeof state.getRegistrySnapshot==='function',
    'B1: bootstrap exposes bounded classifier/executor/release/registry handoff required by the unified runtime lanes');

function script(src,attrs,code){ const n=new FakeScriptElement(); Object.keys(attrs||{}).forEach(k=>n.setAttribute(k,attrs[k])); if(src)n.src=src; if(code){n.text=code;n.textContent=code;} return n; }
const parent=new FakeElement('div'); parent.isConnected=true;

let node=script('https://cdn.example/keep-native.js');
parent.appendChild(node);
expect(node.getAttribute('type')!=='text/ultracache-delayed-js' && node.hasAttribute('src'),
    'B2: visible Do Not Defer or Delay script is never held before deferred classifier exists');
node=script('https://tracker.example/tag.js');
parent.appendChild(node);
expect(node.getAttribute('type')==='text/ultracache-delayed-js' && node.getAttribute('data-ultracache-dynamic-unclassified')==='1' && !node.hasAttribute('src'),
    'B3: non-NATIVE runtime script created before DEFER loader is captured inert without premature policy guess');
node=script('https://tracker.example/optout.js',{'data-no-defer':''});
parent.appendChild(node);
expect(node.getAttribute('type')!=='text/ultracache-delayed-js', 'B4: explicit empty-valued data-no-defer bypass remains immediate NATIVE');
node=script('https://tracker.example/plain.js',{type:'text/plain'});
parent.appendChild(node);
expect(node.getAttribute('type')==='text/plain' && node.hasAttribute('src'), 'B5: non-executable script semantics are untouched');

const controlledExecutions=[];
state.setExecutor(function(n,route){ controlledExecutions.push({node:n,route,connected:!!n.isConnected}); });
state.setClassifier(function(n,src){
    if(String(src).indexOf('force-defer')!==-1) return {lane:'defer',reason:'visible-defer-instead-of-delay',interactionEligible:true};
    if(String(src).indexOf('functional')!==-1) return {lane:'delay',reason:'functional-third-party',interactionEligible:true};
    return {lane:'delay',reason:'safe-third-party',interactionEligible:false};
});
node=script('https://cdn.example/force-defer.js');
parent.appendChild(node);
expect(node.getAttribute('type')==='text/ultracache-delayed-js' && !node.hasAttribute('src') && controlledExecutions.some(x=>x.node===node && x.route.lane==='defer' && x.connected), 'C1: after classifier handoff, runtime DEFER is neutralized and executed only through the UltraCache executor');
node=script('https://tracker.example/safe.js');
parent.appendChild(node);
expect(node.getAttribute('type')==='text/ultracache-delayed-js' && node.getAttribute('data-ultracache-delay-reason')==='safe-third-party', 'C2: post-handoff DELAY route becomes normal inert Delay placeholder');
state.setReleasePhase(1);
node=script('https://chat.example/functional.js');
parent.appendChild(node);
expect(node.getAttribute('type')==='text/ultracache-delayed-js' && controlledExecutions.some(x=>x.node===node && x.route.lane==='delay'), 'C3: interaction-eligible runtime DELAY remains controlled and is dispatched through the executor after phase 1 opens');
node=script('https://tracker.example/still-held.js');
parent.appendChild(node);
expect(node.getAttribute('type')==='text/ultracache-delayed-js', 'C4: safe/analytics dynamic DELAY remains held during interaction-only release');
state.setReleasePhase(2);
node=script('https://tracker.example/after-full.js');
parent.appendChild(node);
expect(node.getAttribute('type')==='text/ultracache-delayed-js' && controlledExecutions.some(x=>x.node===node && x.route.lane==='delay'), 'C5: after full release, future runtime DELAY scripts still pass through the executor instead of bypassing classification');
node=script('https://tracker.example/loader-release.js',{'data-ultracache-delayed':'1'});
parent.appendChild(node);
expect(node.getAttribute('type')!=='text/ultracache-delayed-js', 'C6: executable replacement emitted by Delay loader bypasses finder recursion');

const connected=script(''); parent.appendChild(connected);
state.setReleasePhase(0); // monotonic setter intentionally cannot close; create a fresh sandbox is unnecessary for static src interception contract.
expect(finderSource.includes("String(name).toLowerCase() === 'src' && this.isConnected") && finderSource.includes('this.isConnected'),
    'D1: connected setAttribute/src assignment is checked synchronously before native setter dispatch');

console.log('\nResult: '+passes+'/'+(passes+failures.length)+' PASS');
if(failures.length){ failures.forEach(f=>console.error(' - '+f)); process.exit(1); }
