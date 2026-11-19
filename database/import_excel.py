import openpyxl, sys, json, re
from openpyxl.utils import get_column_letter

MODEL_MAP = [
    ('SEWAAN', 'SP'), ('BAYARAN PENGURUSAN', 'BP'), ('PERKONGSIAN', 'PK'),
    ('TANAH PPK', 'TP'), ('SKIM LADANG BENIH', 'LKSLB'), ('ENTITI', 'EP'),
    ('KONTRAK PPK', 'LKPPK'), ('KONTAK PPK', 'LKPPK'), ('KONRTAK PPK', 'LKPPK'),
]

def model_code(h):
    h = h.upper()
    for k, v in MODEL_MAP:
        if k in h:
            return v
    return None

def s(v):
    if v is None: return ''
    return str(v).strip()

def num(v):
    if v is None or v == '': return 0
    if isinstance(v, (int, float)): return float(v)
    try: return float(str(v).replace(',', ''))
    except: return 0

def parse_sheet(ws, wsf):
    maxc = ws.max_column
    row3 = {c: s(ws.cell(3, c).value) for c in range(1, maxc + 1)}
    starts = []
    end_col = None
    tamat_col = tind_col = catatan_col = None
    for c, v in row3.items():
        if not v: continue
        u = v.upper()
        if u.startswith('MODEL') and end_col is None:
            starts.append((c, model_code(u)))
        elif u == 'JUMLAH' and end_col is None:
            end_col = c
        elif u == 'TAMAT':
            tamat_col = c
        elif u.startswith('TINDAKAN'):
            tind_col = c
        elif u == 'CATATAN':
            catatan_col = c
    groups = []
    for i, (c, code) in enumerate(starts):
        nxt = starts[i + 1][0] if i + 1 < len(starts) else end_col
        g = {'code': code}
        for cc in range(c, nxt):
            h = s(ws.cell(4, cc).value).upper()
            if h.startswith('BIL PELADANG'): g['bil'] = cc
            elif h.startswith('LUAS'): g['luas'] = cc
            elif h == 'KUMP': g['kump'] = cc
            elif h.startswith('SEWAAN'):
                g['kadar'] = cc; g['sewa'] = cc + 1
            elif h.startswith('KADAR SEWAAN'):
                g['kadar'] = cc + 1
                for k in range(cc, nxt):
                    if s(ws.cell(5, k).value).upper().startswith('JUMLAH'): g['sewa'] = k
            elif h.startswith('TEMPOH'): g['bm'] = cc
            elif h.startswith('MODAL OPERASI'): g['mo'] = cc
            elif h.startswith('MUSIM PELAKSANAAN'): g['mp'] = cc
            elif h.startswith('BIL MUSIM'): g['bm'] = cc
            elif h.startswith('FASA'): g['fasa'] = cc
        groups.append(g)
    # section markers
    lab = {}
    for r in range(1, ws.max_row + 1):
        a = s(ws.cell(r, 1).value).upper()
        if a in ('JUMLAH ASAL', 'JUMLAH (TOLAK TAMAT)', 'JUMLAH SAMBUNG PERJANJIAN', 'JUMLAH SEMASA') and a not in lab:
            lab[a] = r
    L = get_column_letter(end_col)
    ft = s(wsf.cell(lab['JUMLAH (TOLAK TAMAT)'], end_col).value)
    m = re.match(r'=' + L + r'(\d+)', ft)
    base = int(m.group(1)) if m else lab['JUMLAH (TOLAK TAMAT)'] - 1
    tamat_rows = set(int(x) for x in re.findall(r'-' + L + r'(\d+)', ft))
    fb = s(wsf.cell(base, end_col).value)
    nums = [int(x) for x in re.findall(L + r'(\d+)', fb)]
    a0, a1 = min(nums), max(nums)
    lab['ASAL_ROW'] = base
    fs = s(wsf.cell(lab['JUMLAH SAMBUNG PERJANJIAN'], end_col).value)
    m = re.search(r'SUM\(' + L + r'(\d+):' + L + r'(\d+)\)', fs)
    s0, s1 = int(m.group(1)), int(m.group(2))
    tamat_rows |= set(int(x) for x in re.findall(r'-' + L + r'(\d+)', fs))
    sections = [('ASAL', a0, a1), ('SAMBUNG', s0, s1)]
    recs = []
    for jenis, r0, r1 in sections:
        musim = ''
        for r in range(r0, r1 + 1):
            a = s(ws.cell(r, 1).value)
            if a: musim = a
            blok = s(ws.cell(r, 2).value); projek = s(ws.cell(r, 3).value)
            for g in groups:
                get = lambda k: ws.cell(r, g[k]).value if k in g else None
                bil, luas, mo = num(get('bil')), num(get('luas')), num(get('mo'))
                sewa = num(get('sewa'))
                if bil == 0 and luas == 0 and mo == 0 and sewa == 0:
                    continue
                recs.append({
                    'jenis': jenis, 'row': r, 'musim': musim, 'no_blok': blok, 'projek': projek,
                    'model': g['code'], 'bil_peladang': bil, 'luas_ha': luas,
                    'kump': s(get('kump')), 'kadar_sewa': num(get('kadar')), 'sewaan': sewa,
                    'modal_operasi': mo, 'musim_pelaksanaan': s(get('mp')),
                    'bil_musim': s(get('bm')), 'fasa': s(get('fasa')),
                    'musim_tamat': s(ws.cell(r, tamat_col).value) if tamat_col else '',
                    'tindakan': s(ws.cell(r, tind_col).value) if tind_col else '',
                    'status': 'TAMAT' if r in tamat_rows else 'AKTIF',
                    'catatan': s(ws.cell(r, catatan_col).value) if catatan_col else '',
                })
    # verification totals from sheet
    tot_cols = {}
    for c in range(end_col, end_col + 4):
        tot_cols[c] = None
    check = {}
    for key in lab:
        r = lab[key]
        check[key] = [num(ws.cell(r, c).value) for c in range(end_col, end_col + 4)]
    check['ASAL'] = [num(ws.cell(base, c).value) for c in range(end_col, end_col + 4)]
    return recs, check, [g['code'] for g in groups]

wb = openpyxl.load_workbook(sys.argv[1], data_only=True)
wbf = openpyxl.load_workbook(sys.argv[1])
out = {}
for ws in wb.worksheets[:27]:
    recs, check, codes = parse_sheet(ws, wbf[ws.title])
    out[ws.title] = {'nama': s(ws['A1'].value), 'records': recs}
    asal = [r for r in recs if r['jenis'] == 'ASAL']
    def tot(rs): return [round(sum(x[k] for x in rs), 3) for k in ('bil_peladang', 'luas_ha', 'sewaan', 'modal_operasi')]
    t_asal = tot(asal)
    t_tolak = tot([r for r in asal if 'TAMAT' not in r['tindakan'].upper()])
    t_samb = tot([r for r in recs if r['jenis'] == 'SAMBUNG'])
    ok = lambda a, b: all(abs(x - y) < 0.5 for x, y in zip(a, b))
    msg = [ws.title, codes, len(recs)]
    t_tolak = tot([r for r in asal if r['status'] == 'AKTIF'])
    t_samb = tot([r for r in recs if r['jenis'] == 'SAMBUNG' and r['status'] == 'AKTIF'])
    t_sem = tot([r for r in recs if r['status'] == 'AKTIF'])
    msg.append(('ASAL', ok(t_asal, check['ASAL']) or (t_asal, check['ASAL'])))
    msg.append(('TOLAK', ok(t_tolak, check['JUMLAH (TOLAK TAMAT)']) or (t_tolak, check['JUMLAH (TOLAK TAMAT)'])))
    msg.append(('SAMB', ok(t_samb, check['JUMLAH SAMBUNG PERJANJIAN']) or (t_samb, check['JUMLAH SAMBUNG PERJANJIAN'])))
    msg.append(('SEMASA', ok(t_sem, check['JUMLAH SEMASA']) or (t_sem, check['JUMLAH SEMASA'])))
    print(*msg)
json.dump(out, open(sys.argv[2], 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
