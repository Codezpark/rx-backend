#!/usr/bin/env python3
"""List every user-facing message the API can send (the English text is the translation key).
Run from rx-backend:  python3 tools/extract_messages.py > lang/_keys.json
Plural messages (Lang::n) list their PLURAL form, which is the key in lang/<code>.json."""
import glob, json, re, sys

STR = re.compile(r"""'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)\"""")
SKIP_END = ('fetched', 'fetched successfully')
SKIP_SUB = ('Y-m-d', 'SELECT', 'INSERT', 'FROM ', ' WHERE ', 'LIKE :', 'Content-', 'Cache-Control', 'X-RateLimit', 'Retry-After', 'Authorization',
            'Deny from', 'Options -', 'FilesMatch', 'DB Connection', 'Database connection', 'DB Query', 'Query execution', 'Incoming Request',
            'Outgoing Response', 'Controller', 'Action \'', 'Route \'', 'Unexpected error', 'Attempted delete', 'File move failed', 'Cannot create upload',
            'OTP issued', 'Invalid token', 'Token not', 'Token has', 'Authorization header', 'Invalid Authorization', 'Auto-closed', 'Reminder logged',
            'Doctor verified', 'Password reset', 'Receptionist logged', 'Doctor logged', 'Unknown kind', 'Device registered', 'Device removed')
# calculator sentences stay English until a clinician reviews translations
SKIP_FILES = ('PediatricDoseHelper.php',)
# Success notes ("Patient added") are not shown by the app, so only sentences that end like a message are kept,
# plus these short ones that are shown (follow-up list lines, a few errors without a full stop).
ALLOW = {'Message is required (max 1000 characters)', 'This patient has no mobile number', 'Account is not active',
         'Reminders can be sent for follow-ups, refills and missed visits', 'Follow-up due today', 'Follow-up was due yesterday',
         'Medicines run out today', 'Medicines ran out yesterday', 'Missed appointment today', 'Missed appointment yesterday',
         'Result in, not yet reviewed', 'Report in, not yet reviewed', 'Result in: {n} abnormal', '+{n} more'}
HTTP_TEXT = {'Bad Request', 'No Content', 'Method Not Allowed', 'Method not allowed', 'Unprocessable Entity', 'Too Many Requests', 'Too many requests',
             'Internal Server Error', 'Service Unavailable', 'Created successfully', 'Resource not found', 'Something went wrong', 'Validation failed',
             'Rate limit exceeded'}
keys = {}
for f in sorted(glob.glob('app/controllers/*.php') + glob.glob('helpers/*.php') + glob.glob('core/*.php')):
    if f.endswith(SKIP_FILES): continue
    for line in open(f, encoding='utf-8'):
        st = line.strip()
        if st.startswith(('//', '*', '/*', '#')): continue
        lang_n = re.search(r"Lang::n\(\s*('(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\")\s*,\s*('(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\")", line)
        if lang_n:
            plural = lang_n.group(2)[1:-1].replace("\\'", "'")
            keys[plural] = {'plural': True, 'singular': lang_n.group(1)[1:-1].replace("\\'", "'")}
        for m in STR.finditer(line):
            s = (m.group(1) if m.group(1) is not None else m.group(2)).replace("\\'", "'")
            if len(s) < 10 or ' ' not in s or not re.match(r"^[A-Z“‘(+]", s): continue
            if s.endswith(SKIP_END) or any(x in s for x in SKIP_SUB): continue
            if re.search(r"\$\w|\{\$", s): continue          # an unconverted interpolation: fix the call site
            if s in keys: continue
            if any(v.get('singular') == s for v in keys.values() if isinstance(v, dict)): continue
            if s in HTTP_TEXT: continue
            if not (s.endswith(('.', '!')) or s in ALLOW): continue
            if any(m in s for m in ('File exceeds', 'File was only', 'Missing temporary', 'Failed to write', 'Upload stopped', 'Unknown upload', 'Invalid upload', 'File too large', 'File uploaded', 'File deleted', 'Failed to save uploaded')): continue
            keys.setdefault(s, {'plural': False})
keys.setdefault('+{n} more', {'plural': False})
# Validator.php: its templates and the field names it puts in {label}
for m in re.finditer(r"Lang::t\('(\{label\}[^']*)'", open('core/Validator.php', encoding='utf-8').read()):
    keys.setdefault(m.group(1), {'plural': False})
fields = set()
for f in glob.glob('app/controllers/*.php'):
    src = open(f, encoding='utf-8').read()
    for blk in re.finditer(r"(?:validate\(\[|Validator::make\([^\[]*\[)(.*?)\]", src, re.S):
        fields.update(re.findall(r"'([a-z_]+)'\s*=>\s*'", blk.group(1)))
for fld in sorted(fields):
    keys.setdefault(fld.replace('_', ' ').capitalize(), {'plural': False})
out = {k: v for k, v in sorted(keys.items())}
json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
