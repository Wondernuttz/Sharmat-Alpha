import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../config_manager.php',import.meta.url),'utf8');
const section=fs.readFileSync(new URL('../config_section_prompts.php',import.meta.url),'utf8');
const fields={device_actions:'promptDeviceActions',device_release:'promptDeviceRelease'};
const elements={};const saved={};const settings={};const defaults={};
for(const [key,id] of Object.entries(fields)) {
    assert.equal((section.match(new RegExp(`id="${id}"`,'g'))||[]).length,1);
    assert.ok(section.indexOf(`id="${id}"`)>section.indexOf('id="sectionDevicesContent"'));
    assert.ok(section.includes(`sharmatDevicePromptDefaults()['${key}']`));
    assert.ok(source.includes(`${key}: <?php echo json_encode(sharmatDevicePromptDefaults()['${key}']); ?>`));
    elements[id]={value:''};settings[key]=`Saved ${key}`;defaults[key]=`Default ${key}`;
}
const context=vm.createContext({s:settings,setPromptValue:(id,val,key)=>elements[id].value=val??defaults[key],getVal:id=>elements[id].value,formData:{append:(key,val)=>saved[key]=val},resetVal:(id,key)=>elements[id].value=defaults[key]});
for(const [key,id] of Object.entries(fields)) {
    const load=source.match(new RegExp(`setPromptValue\\('${id}', s\\.${key}, '${key}'\\);`));
    const save=source.match(new RegExp(`formData\\.append\\('${key}', getVal\\('${id}'\\)\\);`));
    const reset=source.match(new RegExp(`resetVal\\('${id}', '${key}'\\);`));
    assert.ok(load&&save&&reset,'Existing load/save/reset flow must include each new field');
    vm.runInContext(load[0],context);assert.equal(elements[id].value,settings[key]);
    elements[id].value=`Edited ${key}`;vm.runInContext(save[0],context);assert.equal(saved[key],`Edited ${key}`);
    vm.runInContext(reset[0],context);assert.equal(elements[id].value,defaults[key]);
}
assert.ok(!section.includes('Reserved for a later phase.'));
console.log('Device UI: both editable prompts load, save and reset in Devices & Wearables');
