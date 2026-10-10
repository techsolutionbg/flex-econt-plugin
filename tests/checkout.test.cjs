const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');
const context={document:{querySelectorAll:()=>[],addEventListener(){},documentElement:{}},MutationObserver:class {observe(){}}};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/checkout.js'),'utf8'),context);
test('office messages require exact trusted origin and the locator frame',()=>{
 const frame={contentWindow:{}};
 const trusted=context.isTrustedOfficeMessage;
 for(const origin of ['https://officelocator.econt.com','https://staging.officelocator.econt.com']){
  assert.equal(trusted({origin,source:frame.contentWindow},frame),true);
  assert.equal(trusted({origin:origin+'.attacker.test',source:frame.contentWindow},frame),false);
  assert.equal(trusted({origin,source:{}},frame),false);
 }
 assert.equal(trusted({origin:'null',source:frame.contentWindow},frame),false);
});
