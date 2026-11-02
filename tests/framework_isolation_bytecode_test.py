"""Execute compiled framework routing and event handlers with engine API mocks.

Usage: python framework_isolation_bytecode_test.py <disassembly-directory> [baseline-directory]
The fixture checks bytecode and declared types; it is not the Skyrim VM.
"""
import itertools
import json
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
scripts = {}
for name in ['AIAgentNSFW', 'AIAgentNSFWSceneEngine', 'AIAgentNSFWSexLab', 'AIAgentNSFWOStim']:
    scripts[name.lower()] = (root/(name+'.disassemble.pas')).read_text(encoding='utf-8-sig')

def function(cls, name):
    found = re.search(r'\.function '+re.escape(name)+r'\b.*?\.endFunction', scripts[cls.lower()], re.S|re.I)
    assert found, (cls, name)
    return found[0]

def framework_types(source):
    declarations = re.findall(r'\.(?:local|param|variable)\s+\S+\s+(\S+)|\.return\s+(\S+)',source)
    return {a or b for a,b in declarations if re.match(r'(?:sexlab|ssl|osexintegration|zad)',a or b,re.I)}

assert not framework_types(scripts['aiagentnsfwsceneengine']), 'shared global script has optional framework types'
assert not framework_types(scripts['aiagentnsfw']), 'shared quest/command script has optional framework types'
assert framework_types(scripts['aiagentnsfwsexlab']), 'fixture must inspect the actual typed adapter'
assert framework_types(scripts['aiagentnsfwostim'])

if len(sys.argv)>2:
    baseline=Path(sys.argv[2])
    original=(baseline/'AIAgentNSFWSceneEngine.disassemble.pas').read_text(encoding='utf-8-sig')
    assert framework_types(original), 'baseline must exhibit the optional-type linking defect'
    def normalized_code(source,name):
        body=re.search(r'\.function '+name+r'\b.*?\.endFunction',source,re.S|re.I)[0]
        code=body.split('.code',1)[1].split('.endCode',1)[0]
        code=re.sub(r';@line[^\n]*','',code).lower().replace('aiagentnsfwsexlab','aiagentnsfwsceneengine')
        labels={}
        code=re.sub(r'_label\d+',lambda m:labels.setdefault(m[0],'label'+str(len(labels))),code)
        return [' '.join(line.split()) for line in code.splitlines() if line.strip()]
    for name in ['GetSexLab','NormalizeSexLabActors','SexLabRosterIsReleased','StopSexLabControllerAndWait',
                 'StartOrJoinSexLab','StartSexLabScene','AddActorToSexLabThread','ShiftSexLabScene']:
        assert normalized_code(original,name)==normalized_code(scripts['aiagentnsfwsexlab'],name), name+' changed behavior while moving'
    print('Baseline contains missing-framework type dependencies; eight moved SexLab helper bodies preserve compiled control flow.')

class Fixture:
    def __init__(self, ostim=True, sexlab=True, ostim_enabled=True, sexlab_enabled=True):
        self.present = {'ostim.esp':ostim, 'sexlab.esm':sexlab}
        self.config = {'.ostim':int(ostim_enabled), '.sexlab':int(sexlab_enabled)}
        self.events = {}; self.calls = []; self.outputs = []; self.callbacks = []
        self.actors = ['NPC', 'Player']; self.mouth_busy = False; self.controller = True
        self.ostim_thread = -1
        self.sexlab_thread = 7
        self.override_starts = False

    def run(self, cls, name, *args):
        cls=cls.lower(); name=name.lower()
        if cls=='aiagentnsfwsexlab': assert self.present['sexlab.esm'], 'loaded absent SexLab adapter'
        if cls=='aiagentnsfwostim': assert self.present['ostim.esp'], 'loaded absent OStim adapter'
        if self.override_starts and name in ['startostimscene','startsexlabscene','startorjoinostim','startorjoinsexlab']:
            self.calls.append((cls,name,args))
            return 9 if name=='startostimscene' else True
        body=function(cls,name)
        params=re.findall(r'\.param (\S+) \S+',body)
        types={n.lower():t.lower() for n,t in re.findall(r'\.local (\S+) (\S+)',body)}
        v={'self':'quest','hasdeviousdevices':False,'::grabcorrelationwindow_var':1.25,'actorswithcolliders':1,'::enablecbpc_var':False}
        v.update({p.lower():a for p,a in zip(params,args)})
        lines=[s.strip().split(';@line')[0].strip() for s in body.split('.code',1)[1].split('.endCode')[0].splitlines() if s.strip()]
        labels={line[:-1]:i for i,line in enumerate(lines) if line.endswith(':')}
        def get(s):
            if s.startswith('"'):return json.loads(s)
            if s.lower()=='none':return None
            if s.lower() in ['true','false']:return s.lower()=='true'
            if re.fullmatch(r'-?\d+(?:\.\d+)?',s):return float(s) if '.' in s else int(s)
            assert s.lower() in v, (cls,name,s)
            return v[s.lower()]
        def put(s,value):v[s.lower()]=value
        pc=0
        for _ in range(10000):
            if pc>=len(lines):return None
            line=lines[pc];pc+=1
            if line.endswith(':'):continue
            op,*a=re.findall(r'"(?:\\.|[^"\\])*"|\S+',line)
            if op=='Assign':put(a[0],get(a[1]))
            elif op=='Cast':
                value=get(a[1]);t=types.get(a[0].lower())
                put(a[0],str(value) if t=='string' else bool(value) if t=='bool' else int(value or 0) if t=='int' else value)
            elif op=='Not':put(a[0],not get(a[1]))
            elif op.startswith('Compare'):
                x,y=get(a[1]),get(a[2]);kind=op[7:]
                put(a[0],{'EQ':lambda:x==y,'LT':lambda:x<y,'LTE':lambda:x<=y,'GT':lambda:x>y,'GTE':lambda:x>=y}[kind]())
            elif op in ['IAdd','ISub','ISubtract']:put(a[0],get(a[1])+get(a[2])*(1 if op=='IAdd' else -1))
            elif op=='StrCat':put(a[0],str(get(a[1]))+str(get(a[2])))
            elif op=='Jump':pc=labels[a[0]]
            elif op in ['JumpT','JumpF']:
                if bool(get(a[0]))==(op=='JumpT'):pc=labels[a[1]]
            elif op=='Return':return get(a[0])
            elif op=='ArrayLength':put(a[0],len(get(a[1]) or []))
            elif op=='ArrayCreate':put(a[0],[None]*get(a[1]))
            elif op=='ArrayGetElement':put(a[0],get(a[1])[get(a[2])])
            elif op=='ArraySetElement':get(a[0])[get(a[1])]=get(a[2])
            elif op=='ArrayFindElement':
                arr,wanted,start=get(a[0]),get(a[2]),get(a[3]);put(a[1],next((i for i in range(start,len(arr)) if arr[i]==wanted),-1))
            elif op=='PropGet':
                prop,obj,dest=a;obj=get(obj)
                value={'positions':self.actors,'animation':'animation','name':'SceneFixture','stage':2}[prop.lower()]
                put(dest,value)
            elif op=='CallStatic':
                target,method,dest=a[:3];p=[get(x) for x in a[3:]]
                target=target.lower();method=method.lower()
                if target in scripts:value=self.run(target,method,*p)
                elif target=='game':
                    if method=='getformfromfile':value=p[1].lower() if self.present.get(p[1].lower(),False) else None
                    elif method=='getmodbyname':value=1 if self.present.get(p[0].lower(),False) else 255
                    elif method=='getplayer':value='Player'
                    else:raise AssertionError(line)
                elif target=='jsonutil':value=True if method=='jsonexists' else self.config[p[1]]
                elif target in ['debug','utility']:value=None
                elif target=='aiagentfunctions':self.outputs.append((method,p));value=1
                elif target=='sexlabutil':assert self.present['sexlab.esm'];value='sexlab.esm'
                elif target=='papyrusutil':
                    if method=='actorarray':value=[None]*p[0]
                    elif method=='pushactor':value=p[0]+[p[1]]
                    elif method=='stringjoin':value=p[1].join(p[0])
                    else:raise AssertionError(line)
                elif target=='stringutil':value=p[0].find(p[1])
                elif target=='oactor':
                    assert self.present['ostim.esp'], 'called absent OStim API'
                    assert method=='getsceneid';value=self.ostim_thread
                else:raise AssertionError(line)
                put(dest,value)
            elif op=='CallMethod':
                method,obj,dest=a[:3];obj=get(obj);p=[get(x) for x in a[3:]];method=method.lower();value=None
                if obj=='quest':
                    if method=='unregisterforallmodevents':self.events.clear()
                    elif method=='registerformodevent':self.events[p[0]]=p[1]
                    elif method=='unregisterformodevent':self.events.pop(p[0],None)
                    elif method in ['initdeviousdevices','inithiggs','sendplayerbodystate']:pass
                    else:
                        self.callbacks.append((method,p))
                        if method=='buildscoringfromstash':value='/NPC@50'
                elif obj=='sexlab.esm':
                    assert self.present['sexlab.esm']
                    if method=='getcontroller':value='controller' if self.controller else None
                    elif method=='findactorcontroller':value=self.sexlab_thread
                    elif method=='ismouthopen':value=self.mouth_busy
                    elif method=='sortactors':value=list(p[0])
                    elif method=='getenjoyment':value=50
                    else:raise AssertionError(line)
                elif obj=='controller':
                    if method=='getvictim':value=None
                    elif method=='endanimation':
                        self.calls.append(('controller',method,p));self.sexlab_thread=-1
                    else:raise AssertionError(line)
                elif obj=='animation':
                    if method=='gettags':value=['FixtureTag']
                    elif method=='fetchstage':value=['FixtureStage']
                    else:raise AssertionError(line)
                elif method=='getdisplayname':value=obj
                elif method=='getformid':value=0x14 if obj=='Player' else 0x123
                elif method=='getrelationshiprank':value=2
                else:raise AssertionError(line)
                put(dest,value)
            else:raise AssertionError(line)
        raise AssertionError('execution limit')

checks=0
for ostim,sexlab,oe,se in itertools.product([False,True],repeat=4):
    f=Fixture(ostim,sexlab,oe,se)
    f.run('AIAgentNSFW','DoRegister')
    assert ('ostim_scenechanged' in f.events)==(ostim and oe)
    assert ('HookStageStart' in f.events)==(sexlab and se)
    assert 'CHIM_CommandReceived' in f.events
    first_events=dict(f.events)
    f.run('AIAgentNSFW','DoRegister')
    assert f.events==first_events, 'reload changed registered handlers'
    f.override_starts=True
    result=f.run('AIAgentNSFWSceneEngine','StartOrJoinScene','NPC','Player',True,'kiss')
    expected='startorjoinostim' if ostim and oe else 'startorjoinsexlab' if sexlab and se else None
    assert bool(result)==bool(expected)
    assert ([x[1] for x in f.calls]==[expected]) if expected else not f.calls
    checks+=1
    for entry,args in [('StartSoloScene',('NPC','masturbation')),('StartGroupScene',(['NPC','Player'],2,'kiss'))]:
        f.calls=[];f.sexlab_thread=-1
        result=f.run('AIAgentNSFWSceneEngine',entry,*args)
        expected='startostimscene' if ostim and oe else 'startsexlabscene' if sexlab and se else None
        assert bool(result)==bool(expected)
        assert ([x[1] for x in f.calls]==[expected]) if expected else not f.calls
        checks+=1

for event,args in [('OnSexLabAnimStart',(7,True)),('OnSexLabStageStart',(7,True)),('OnSexLabActorOrgasm',('Player',100,1)),('OnSexLabAnimEnd',(7,True))]:
    for present,enabled in [(False,True),(True,False),(True,True)]:
        f=Fixture(False,present,True,enabled)
        f.run('AIAgentNSFW',event,*args)
        assert bool(f.outputs or f.callbacks)==(present and enabled),(event,present,enabled)
        if present and enabled and event=='OnSexLabStageStart':
            sent=[p for method,p in f.outputs if method=='requestmessageforactor']
            assert len(sent)==1 and sent[0][1:] == ['ext_nsfw_sexcene','NPC']
            assert '/npc/player/fw=sexlab' in sent[0][0].lower(), ('actor slots reordered', sent)
        checks+=1

for event in ['OnSexLabAnimStart','OnSexLabStageStart']:
    f=Fixture(False,True);f.actors=['NPC','Partner']
    f.run('AIAgentNSFW',event,7,False)
    turns=[p for method,p in f.callbacks if method=='requestnpcsceneturn']
    assert len(turns)==1 and turns[0][0]==f.actors
    assert not any(m=='requestmessageforactor' for m,p in f.outputs), 'duplicate player-scene request'
    checks+=1
f=Fixture(False,True);f.mouth_busy=True
f.run('AIAgentNSFW','OnSexLabStageStart',7,True)
assert not any(m=='requestmessageforactor' for m,p in f.outputs)
assert any(p[-1]=='info_sexscene' for m,p in f.outputs if m=='logmessage')
checks+=1
f=Fixture(False,True);f.controller=False
f.run('AIAgentNSFW','OnSexLabAnimEnd',7,True)
assert ('sendsceneendwithhardspeechflush',['/NPC@50']) in f.callbacks
checks+=1
f=Fixture(True,True);f.actors=['NPC','Player'];f.ostim_thread=-1
assert f.run('AIAgentNSFWSceneEngine','LeaveScene','NPC') is True
assert any(method=='endanimation' for cls,method,p in f.calls)
checks+=1
print(f'framework_isolation_bytecode_test: {checks} compiled scenarios and shared-type isolation checks passed')
