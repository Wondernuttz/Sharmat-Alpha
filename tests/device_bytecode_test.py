"""Execute the compiled DD adapter against engine/DD fixtures, not the Skyrim VM.

Usage: python device_bytecode_test.py <PapyrusAssembler-disassembly-directory>
"""
from pathlib import Path
import re, json, sys
root=Path(sys.argv[1])
scripts={n.lower():(root/(n+'.disassemble.pas')).read_text(encoding='utf-8-sig') for n in ['AIAgentNSFWDevices','AIAgentNSFW']}
assert not re.search(r'\.(?:local|variable|param)\s+\S+\s+zad\w+|\.return\s+zad\w+', scripts['aiagentnsfw'], re.I), 'DD types leaked into shared quest'
def body(cls,name):
    m=re.search(r'\.function '+re.escape(name)+r'\b.*?\.endFunction',scripts[cls],re.S|re.I)
    assert m,(cls,name)
    return m[0]

class Engine:
    def __init__(self):
        self.present=True; self.version=14; self.child=set(); self.combat=set(); self.dead=set(); self.unloaded=set(); self.distance=100.0
        self.inventory={'Operator':['key'], 'Player':['item'], 'Other':[]}
        self.items={'item':{'kw':'zad_deviouscollar','render':'render','mask':4}, 'render':{'kw':'zad_deviouscollar','mask':4}}
        self.protected={}; self.props={}; self.worn=set(); self.extra_keywords={}; self.delay=0; self.waits=0; self.accept=True; self.apply=True; self.jammed=0
        self.calls=[]; self.logs=[]; self.global_destroy=False; self.pending=None
        self.scene=set();self.framework='ostim'
        self.quest={'self':'quest','hasdeviousdevices':True,'devicesession':'123.456','devicecommandbusy':False,'devicependingactor':None,'devicependingcategory':'','devicependinglock':False}
    def run(self,name,*args,cls='aiagentnsfwdevices'):
        f=body(cls,name); types={n.lower():t.lower() for n,t in re.findall(r'\.(?:local|param) (\S+) (\S+)',f)}
        params=re.findall(r'\.param (\S+) \S+',f)
        v={n:(False if t=='bool' else 0 if t in ['int','float'] else '' if t=='string' else None) for n,t in types.items()}
        v.update(self.quest);v.update({p.lower():a for p,a in zip(params,args)})
        lines=[s.strip().split(';@line')[0].strip() for s in f.split('.code',1)[1].split('.endCode')[0].splitlines() if s.strip()]
        labels={l[:-1]:i for i,l in enumerate(lines) if l.endswith(':')}
        def get(x):
            if x.startswith('"'):return json.loads(x)
            if x.lower()=='none':return None
            if x.lower() in ['true','false']:return x.lower()=='true'
            if re.fullmatch(r'-?\d+(?:\.\d+)?',x):return float(x) if '.' in x else int(x)
            return v[x.lower()]
        def put(x,val):
            v[x.lower()]=val
            if x.lower() in self.quest:self.quest[x.lower()]=val
        pc=0
        for _ in range(100000):
            if pc>=len(lines):return None
            line=lines[pc];pc+=1
            if line.endswith(':'):continue
            op,*a=re.findall(r'"(?:\\.|[^"\\])*"|\S+',line)
            if op=='Assign':put(a[0],get(a[1]))
            elif op=='Cast':
                val=get(a[1]);t=types.get(a[0].lower())
                if t=='bool':val=bool(val)
                elif t=='int':val=int(val or 0)
                elif t=='float':val=float(val or 0)
                elif t=='string':val=str(val) if val is not None else ''
                elif t=='armor' and val not in self.items:val=None
                put(a[0],val)
            elif op=='Not':put(a[0],not get(a[1]))
            elif op.startswith('Compare'):
                x,y=get(a[1]),get(a[2]);kind=op[7:]
                if isinstance(x,str) and isinstance(y,str):x=x.lower();y=y.lower()
                put(a[0],{'EQ':lambda:x==y,'LT':lambda:x<y,'LTE':lambda:x<=y,'GT':lambda:x>y,'GTE':lambda:x>=y}[kind]())
            elif op in ['IAdd','FAdd','ISubtract','FSubtract']:put(a[0],get(a[1])+get(a[2])*(-1 if 'Subtract' in op else 1))
            elif op=='StrCat':put(a[0],str(get(a[1]))+str(get(a[2])))
            elif op=='Jump':pc=labels[a[0]]
            elif op in ['JumpT','JumpF']:
                if bool(get(a[0]))==(op=='JumpT'):pc=labels[a[1]]
            elif op=='Return':return get(a[0])
            elif op=='ArrayLength':put(a[0],len(get(a[1]) or []))
            elif op=='ArrayCreate':put(a[0],[None]*get(a[1]))
            elif op=='ArrayGetElement':put(a[0],get(a[1])[get(a[2])])
            elif op=='ArraySetElement':get(a[0])[get(a[1])]=get(a[2])
            elif op=='PropGet':
                prop,obj,dest=a;obj=get(obj);prop=prop.lower()
                value='config' if prop=='config' else self.global_destroy if prop=='globaldestroykey' else prop
                put(dest,value)
            elif op=='CallStatic':
                target,method,dest=a[:3];p=[get(x) for x in a[3:]];target=target.lower();method=method.lower()
                if target in scripts:
                    if target=='aiagentnsfwdevices':assert self.present,'Absent dependency adapter invoked'
                    value=self.run(method,*p,cls=target)
                elif target=='aiagentnsfwsceneengine':
                    if method=='isprotectedminoractor':value=p[0] in self.child
                    elif method=='hasostim':value=self.framework=='ostim'
                    elif method=='hassexlab':value=self.framework=='sexlab'
                    else:raise AssertionError(line)
                elif target in ['oactor','sexlabutil']:value=p[0] in self.scene
                elif target=='game':
                    value={'getmodbyname':lambda:1 if self.present else 255,'getformfromfile':lambda:'library' if self.present else None,'getplayer':lambda:'Player'}[method]()
                elif target=='zadnativefunctions':
                    assert self.present
                    value=self.props.get(p[1].lower(), [] if method.endswith('array') else p[2])
                elif target=='math':value=p[0]<<p[1] if method=='leftshift' else p[0]&p[1]
                elif target=='stringutil':
                    value={'split':lambda:p[0].split(p[1]),'find':lambda:p[0].find(p[1],p[2] if len(p)>2 else 0),'substring':lambda:p[0][p[1]:p[1]+p[2]] if len(p)>2 and p[2]>0 else p[0][p[1]:]}[method]()
                elif target=='utility':
                    value=123 if method=='randomint' else 456.0
                    if method=='wait':
                        self.waits+=1
                        if self.pending and self.waits>=self.delay:self.change(*self.pending);self.pending=None
                elif target=='aiagentfunctions':
                    if method=='getagentbyname':value=p[0] if p[0] in self.inventory else None
                    elif method=='findallnearbyactors':value=[]
                    else:self.logs.append((method,p));value=1
                else:raise AssertionError(line)
                put(dest,value)
            elif op=='CallMethod':
                method,obj,dest=a[:3];obj=get(obj);p=[get(x) for x in a[3:]];method=method.lower()
                value=self.method(obj,method,p)
                put(dest,value)
            else:raise AssertionError(line)
        raise AssertionError('VM execution limit')
    def change(self,actor,item,locking):
        if not self.apply:return
        rendered=self.items[item]['render']
        if locking:self.worn.add((actor,rendered))
        else:self.worn.discard((actor,rendered));self.worn.discard((actor,item))
    def method(self,obj,m,p):
        if obj=='quest':
            if m=='senddevicestate':return None
            return self.run(m,*p,cls='aiagentnsfw')
        if obj=='library':
            assert self.present
            if m=='getversion':return self.version
            if m=='getdevicekeyword':return self.items[p[0]]['kw']
            if m=='getrendereddevice':return self.items[p[0]]['render']
            if m=='getdevicekey':return self.props.get('devicekey','key')
            if m=='islockjammed':return self.jammed
            if m.startswith('getworn'):
                rendered=next((item for actor,item in self.worn if actor==p[0] and self.items[item]['kw']==p[1]),None)
                return rendered if 'rendered' in m else next((item for item,d in self.items.items() if d.get('render')==rendered),None)
            if m in ['lockdevice','unlockdevice']:
                self.calls.append((m,p))
                if self.accept:
                    if self.delay:self.pending=(p[0],p[1],m=='lockdevice')
                    else:self.change(p[0],p[1],m=='lockdevice')
                return self.accept
            raise AssertionError((obj,m,p))
        if m=='haskeyword':
            if p[0] in self.protected.get(obj,[]):return True
            if p[0]=='zad_inventorydevice':return 'render' in self.items.get(obj,{})
            if p[0] in ['zad_lockable','zad_nonuniquekey']:return True
            if self.items.get(obj,{}).get('kw')==p[0]:return True
            return False
        if m=='getslotmask':return self.items[obj]['mask']
        if obj in self.inventory:
            if m=='getdisplayname':return obj
            if m=='getnumitems':return len(self.inventory[obj])
            if m=='getnthform':return self.inventory[obj][p[0]]
            if m=='getitemcount':return self.inventory[obj].count(p[0])
            if m=='removeitem':
                for _ in range(p[1]):self.inventory[obj].remove(p[0])
                return None
            if m=='wornhaskeyword':return p[0] in self.extra_keywords.get(obj,[]) or any(a==obj and self.items[item]['kw']==p[0] for a,item in self.worn)
            if m=='isequipped':return (obj,p[0]) in self.worn
            if m=='getwornform':return next((item for a,item in self.worn if a==obj and self.items[item]['mask']&p[0]),None)
            if m=='getdistance':return self.distance
            if m=='is3dloaded':return obj not in self.unloaded
            if m=='isdead':return obj in self.dead
            if m=='isincombat':return obj in self.combat
        raise AssertionError((obj,m,p))

checks=0
def check(value,message):
    global checks;checks+=1
    assert value,message
def execute(e,locking=True):return e.run('Execute','Operator','Player','collar',locking)
e=Engine();check(execute(e).startswith('success:'),'Verified equip');check(e.calls[0]==('lockdevice',['Player','item',False]),'No force swapping')
e=Engine();e.delay=4;check(execute(e).startswith('success:') and e.waits==4,'Wait for asynchronous rendered equip')
e=Engine();e.apply=False;check(execute(e).startswith('pending:') and e.waits==60,'Accepted call without equipment is not success')
e=Engine();e.accept=False;check(execute(e).startswith('error:') and not e.waits,'DD rejection is reported')
for protected_half in ['item','render']:
    for keyword in ['zad_questitem','zad_blockgeneric']:
        e=Engine();e.protected[protected_half]=[keyword];check(execute(e).startswith('error:') and not e.calls,'Protected '+protected_half+' '+keyword)
for flag in ['child','combat','dead','unloaded']:
    for actor in ['Operator','Player']:
        e=Engine();getattr(e,flag).add(actor);check(execute(e).startswith('error:') and not e.calls,flag+' '+actor)
e=Engine();e.distance=1000;check(execute(e).startswith('error:') and not e.calls,'Distant target')
e=Engine();e.inventory['Player']=[];check(execute(e).startswith('error:') and not e.calls,'No inventory device: never conjure one')
e=Engine();e.items['item2']=dict(e.items['item']);e.inventory['Player'].append('item2');check(execute(e).startswith('error:') and not e.calls,'Ambiguous variants')
e=Engine();e.worn.add(('Player','render'));check(execute(e).startswith('error:') and not e.calls,'Already-worn device')
e=Engine();e.items['other']={'kw':'zad_deviousbra','mask':4};e.worn.add(('Player','other'));check(execute(e).startswith('error:') and not e.calls,'Cross-category DD slot conflict')
for prop,keywords in [('equipconflictingdevices',['zad_deviousbra']),('equiprequireddevices',[])]:
    e=Engine();e.props[prop]=['zad_deviousbra'];e.extra_keywords['Player']=keywords
    check(execute(e).startswith('error:') and not e.calls,'DD '+prop)
e=Engine();e.extra_keywords['Operator']=['zad_deviousheavybondage'];check(execute(e).startswith('error:') and not e.calls,'Bound operator cannot handle locks')
for framework in ['ostim','sexlab']:
    for actor in ['Operator','Player']:
        e=Engine();e.framework=framework;e.scene.add(actor);check(execute(e).startswith('error:') and not e.calls,'Live '+framework+' scene on '+actor)
for category,cover in [('plugvaginal','zad_deviousbelt'),('pluganal','zad_deviousbelt'),('piercingsnipple','zad_deviousbra')]:
    e=Engine();e.items['item']['kw']=e.items['render']['kw']='zad_devious'+category;e.worn.add(('Player','render'));e.extra_keywords['Player']=[cover]
    check(e.run('Execute','Operator','Player',category,False).startswith('error:') and not e.calls,'Covered device cannot be accessed '+category)
e=Engine();e.worn.add(('Player','render'));check(execute(e,False).startswith('success:'),'Verified unlock')
check(e.calls[0]==('unlockdevice',['Player','item','render','zad_deviouscollar',False,True]),'Generic-only unlock, no destruction')
e=Engine();e.worn.add(('Player','render'));e.inventory['Operator']=[];check(execute(e,False).startswith('error:') and not e.calls,'Missing key')
e=Engine();e.worn.add(('Player','render'));e.inventory['Operator']=[];e.inventory['Player'].append('key');check(execute(e,False).startswith('success:'),'Wearer-held key')
e=Engine();e.worn.add(('Player','render'));e.props['numberofkeysneeded']=2;check(execute(e,False).startswith('error:') and not e.calls,'Multiple keys required')
for prop,val in [('destroykey',True),('global',True)]:
    e=Engine();e.worn.add(('Player','render'));e.global_destroy=prop=='global';e.props[prop]=val
    check(execute(e,False).startswith('success:') and 'key' not in e.inventory['Operator'],'Consume key after verified unlock '+prop)
for prop,val in [('lockshieldtimermin',2.0),('lockshieldtimermax',2.0),('keybreakchance',0.2),('timedunlock',True)]:
    e=Engine();e.worn.add(('Player','render'));e.props[prop]=val
    check(execute(e,False).startswith('error:') and not e.calls,'Special lock remains with DD '+prop)
for jam in [-1,1]:
    e=Engine();e.worn.add(('Player','render'));e.jammed=jam;check(execute(e,False).startswith('error:') and not e.calls,'Jammed/unknown lock')
e=Engine();e.version=13;check(execute(e).startswith('error:') and not e.calls,'Older API does not run actions')
e=Engine();check(e.run('Choices','Player',True)=='collar,','Inventory-backed offers')
e.worn.add(('Player','render'));check(e.run('Choices','Player',True)=='' and e.run('Choices','Player',False)=='collar,','Worn device only removable')
e=Engine();e.present=False;e.run('InitDeviousDevices',cls='aiagentnsfw');check(not e.quest['hasdeviousdevices'],'Absent DD init does not touch optional adapter')
check(e.run('GetWornDevicesCSV','Player',cls='aiagentnsfw')=='','No-DD awareness remains empty')
e.run('DeviceCommand','Operator','Operator','ExtCmdLockDevice','Player / collar',cls='aiagentnsfw');check(not e.calls and 'unavailable' in e.logs[-1][1][0],'No-DD command returns controlled failure')
for target in ['Missing / collar','Player','Player / collar / extra']:
    e=Engine();e.run('DeviceCommand','Operator','Operator','ExtCmdLockDevice',target,cls='aiagentnsfw');check(not e.calls,'Malformed/unresolved target never falls back to player')
e=Engine();e.quest['devicecommandbusy']=True;e.run('DeviceCommand','Operator','Operator','ExtCmdLockDevice','Player / collar',cls='aiagentnsfw');check(not e.calls,'Overlapping commands rejected')
e=Engine();e.apply=False;e.run('DeviceCommand','Operator','Operator','ExtCmdLockDevice','Player / collar',cls='aiagentnsfw')
check(e.quest['devicependingactor']=='Player','Unverified action tracked')
e.run('DeviceCommand','Operator','Operator','ExtCmdLockDevice','Player / collar',cls='aiagentnsfw')
check(len(e.calls)==1,'Pending result cannot trigger repeated equip attempts')
e.worn.add(('Player','render'));e.run('DeviceCommand','Operator','Operator','ExtCmdLockDevice','Player / collar',cls='aiagentnsfw')
check(e.quest['devicependingactor'] is None and len(e.calls)==1,'Settled state clears pending; already worn guard still holds')
print(f'Device compiled-bytecode fixtures: {checks} checks passed')
