#!/usr/bin/env python3
"""Merge translated message files into lang/<code>.json.
   python3 tools/merge_lang.py <dir with en.txt index.json and <code>.txt> hi gu ...
en.txt lines: `N|English` or, for plurals, `N.one|singular` + `N.other|plural`. Translated files use the same N
(plurals: N.<category> for the categories the language needs: one/other; Arabic also zero/two/few/many)."""
import json, os, re, sys
d = sys.argv[1]
idx = {int(k): v for k, v in json.load(open(os.path.join(d, 'index.json'), encoding='utf-8')).items()}
src = {}
for ln in open(os.path.join(d, 'en.txt'), encoding='utf-8').read().split('\n'):
    if ln: k, v = ln.split('|', 1); src[k] = v
ph = lambda s: sorted(re.findall(r'\{\w+\}', s))
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lang')
for code in sys.argv[2:]:
    t = {}
    for ln in open(os.path.join(d, code + '.txt'), encoding='utf-8').read().split('\n'):
        if ln.strip(): k, v = ln.split('|', 1); t[k] = v
    target = os.path.join(root, code + '.json')
    out = json.load(open(target, encoding='utf-8')) if os.path.exists(target) else {}  # add to what is there
    bad = []
    for n, eng in idx.items():
        forms = {k.split('.', 1)[1]: v for k, v in t.items() if k.startswith(f'{n}.')}
        if forms:
            for c, v in forms.items():
                if ph(v) != ph(src[f'{n}.other']) and c not in ('one', 'two', 'zero'): bad.append(f'{n}.{c}')
            out[eng] = forms if len(set(forms.values())) > 1 or len(forms) > 1 else forms['other']
        elif str(n) in t:
            if ph(t[str(n)]) != ph(src[str(n)]): bad.append(str(n))
            out[eng] = t[str(n)]
        else:
            bad.append(f'missing {n}')
    if bad: print(code, 'PROBLEMS', bad[:12]); continue
    json.dump(out, open(target, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(code, 'ok', len(out))
