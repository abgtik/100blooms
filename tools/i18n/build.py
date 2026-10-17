# Builds public_html/assets/i18n-en.json from the dictionaries in this folder (run after editing them).
import json, os, re, importlib.util, glob
D = os.path.dirname(os.path.abspath(__file__))
def load(n):
    spec = importlib.util.spec_from_file_location(n, os.path.join(D, n + '.py')); m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m
X, R = {}, []
for n in ['flow', 'semak']:
    if os.path.exists(os.path.join(D, n + '.py')):
        m = load(n); X.update(m.X); R += m.R
R += load('flow').G
for p, _ in R: re.compile(p)
w = load('wish')
out = {'x': X, 'r': R, 'wish': w.WISH, 'call': w.CALL}
json.dump(out, open(os.path.join(D, '../../public_html/assets/i18n-en.json'), 'w'), ensure_ascii=False, separators=(',', ':'))
print(len(X), 'phrases,', len(R), 'rules')
