#!/usr/bin/env python3
"""
Source of truth for the pediatric dosing reference.

Run:  python3 migrations/tools/pediatric_seed.py
Writes migrations/data/pediatric_drugs.json and migrations/sql/017_seed_pediatric_drugs.sql.

Conventions
  basis  per_kg_day  dose_* are mg/kg/DAY (the daily total is divided by doses_per_day)
         per_kg_dose dose_* are mg/kg/DOSE (the daily total is the dose x doses_per_day)
         band        a fixed dose chosen by age (months) or weight (kg): see `bands`
  dose_unit          mg (default), mcg, IU or ml - the unit of the dose AND of every formulation strength
  formulation        (type, strength, per_ml, label, note[, strength_total])
                     liquids: `strength` of the DOSING component per `per_ml` ml (5 for syrups, 1 for drops)
                     solids : `strength` per tablet/capsule, per_ml None
                     strength_total is the label strength when it differs from the dosing component
                     (e.g. co-amoxiclav 228.5 mg/5 ml is 200 mg amoxicillin + 28.5 mg clavulanate)
  verified 1 = supplied by the clinician for this project; 0 = standard reference ranges that still
           need a clinician's review (the app says so).
"""
import json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))

REF = 'IAP / BNFc / WHO (standard ranges)'
drugs = []


def D(slug, name, aliases, comps, cat, ind, all_ind, basis, forms, *, dmin=None, dtyp=None, dmax=None, n=1,
      hours=None, max_doses=None, max_single=None, max_daily=None, min_age=0, max_age=216, min_wt=0,
      dur='', route='Oral', unit='mg', bands=None, renal='', hepatic='', neonatal='', food='',
      se=(), ci=(), warn=(), inter=(), notes='', brands=(), verified=0, ref=REF):
    drugs.append(dict(
        slug=slug, name=name, aliases=list(aliases), components=comps, category=cat, indication=ind,
        all_indications=list(all_ind), basis=basis, dose_unit=unit, dose_min=dmin, dose_typ=dtyp, dose_max=dmax,
        doses_per_day=n, frequency_hours=hours, max_doses_per_day=max_doses, max_single=max_single,
        max_daily=max_daily, min_age_months=min_age, max_age_months=max_age, min_weight_kg=min_wt,
        bands=bands, duration=dur, route=route, renal_note=renal, hepatic_note=hepatic, neonatal_note=neonatal,
        food_note=food, side_effects=list(se), contraindications=list(ci), warnings=list(warn),
        interactions=list(inter), brands=list(brands), reference=ref, notes=notes, verified=verified,
        formulations=[dict(form_type=f[0], strength=f[1], per_volume_ml=f[2], label=f[3], note=f[4],
                           strength_total=(f[5] if len(f) > 5 else None)) for f in forms]))


# ───────────────────────── Analgesics / antipyretics ─────────────────────────
D('paracetamol', 'Paracetamol', ['acetaminophen', 'paracetamol / acetaminophen', 'calpol', 'crocin', 'dolo', 'pacimol', 'febrex', 'tylenol'],
  'paracetamol', 'Analgesic / antipyretic', 'Fever, mild to moderate pain',
  ['Fever', 'Post-vaccination fever', 'Headache', 'Mild pain', 'Teething pain'], 'per_kg_dose',
  [('syrup', 120, 5, '120 mg/5 ml syrup', 'Shake well'), ('syrup', 250, 5, '250 mg/5 ml suspension', 'Shake well'),
   ('syrup', 125, 5, '125 mg/5 ml syrup', ''), ('drops', 100, 1, '100 mg/ml drops', 'For infants'),
   ('tablet', 500, None, '500 mg tablet', 'Can be crushed'), ('tablet', 650, None, '650 mg tablet', 'Older children / adolescents')],
  dmin=10, dtyp=15, dmax=15, n=4, hours=6, max_doses=5, max_single=1000, max_daily=4000, min_age=1, min_wt=3,
  dur='As needed (3 days, review if fever persists)', hepatic='Avoid in severe hepatic impairment',
  neonatal='10 mg/kg/dose every 6-8 hours', food='No restriction',
  se=['Hepatotoxicity on overdose', 'Rash (rare)'], ci=['Severe hepatic disease'],
  warn=['Do not exceed 5 doses in 24 hours', 'Keep at least 4 hours between doses', 'Avoid combining with other paracetamol-containing products'],
  inter=['Warfarin (prolonged use)', 'Alcohol', 'Enzyme inducers (phenytoin, carbamazepine)'],
  notes='10-15 mg/kg/dose every 4-6 hours. Do not exceed 5 doses per 24 hours.', brands=['Calpol', 'Crocin', 'Dolo', 'Pacimol', 'Febrex'], verified=1)

D('ibuprofen', 'Ibuprofen', ['brufen', 'ibugesic', 'ibuprin'], 'ibuprofen', 'Analgesic / NSAID', 'Fever, pain, inflammation',
  ['Fever', 'Pain', 'Inflammation', 'Dysmenorrhoea', 'Juvenile arthritis'], 'per_kg_dose',
  [('syrup', 100, 5, '100 mg/5 ml suspension', 'Shake well; give with food'), ('drops', 40, 1, '40 mg/ml drops', 'For infants'),
   ('tablet', 200, None, '200 mg tablet', ''), ('tablet', 400, None, '400 mg tablet', '')],
  dmin=4, dtyp=10, dmax=10, n=3, hours=8, max_single=400, max_daily=1200, min_age=3, min_wt=5,
  dur='As needed (max 3-5 days without review)', renal='Avoid in renal impairment / dehydration', food='Give with or after food',
  se=['Gastric irritation', 'Renal impairment if dehydrated', 'Bronchospasm in asthmatics'],
  ci=['Dehydration', 'Active GI bleeding', 'Dengue / bleeding disorders', 'Aspirin-sensitive asthma'],
  warn=['Avoid in suspected dengue', 'Ensure good hydration', 'Do not combine with another NSAID'], inter=['Aspirin', 'Anticoagulants', 'ACE inhibitors', 'Methotrexate'],
  notes='4-10 mg/kg/dose every 6-8 hours.', brands=['Brufen', 'Ibugesic'], verified=1)

D('mefenamic-acid', 'Mefenamic Acid', ['mefenamic', 'meftal', 'meftal-p'], 'mefenamic acid', 'Analgesic / NSAID', 'Fever, pain',
  ['Fever', 'Pain', 'Dysmenorrhoea'], 'per_kg_dose',
  [('syrup', 50, 5, '50 mg/5 ml suspension', 'Give with food'), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=6.5, dtyp=6.5, dmax=6.5, n=3, hours=8, max_single=500, max_daily=1500, min_age=6, dur='Max 7 days',
  renal='Avoid in renal impairment', food='Give with food', se=['Diarrhoea', 'Gastric irritation', 'Rash'],
  ci=['Peptic ulcer', 'Renal impairment', 'Inflammatory bowel disease'], warn=['Stop if diarrhoea or rash develops', 'Avoid in suspected dengue'], inter=['Anticoagulants', 'Aspirin'],
  notes='6.5 mg/kg/dose three times a day, short course.', brands=['Meftal'])

# ───────────────────────── Antibiotics ─────────────────────────
D('amoxicillin', 'Amoxicillin', ['amoxycillin', 'amoxil', 'novamox', 'mox'], 'amoxicillin', 'Antibiotic (penicillin)',
  'Bacterial infections of ear, throat, chest, urine',
  ['Otitis media', 'Pharyngitis', 'Pneumonia', 'Sinusitis', 'Urinary tract infection'], 'per_kg_day',
  [('syrup', 125, 5, '125 mg/5 ml dry syrup', 'Reconstitute with boiled, cooled water'), ('syrup', 250, 5, '250 mg/5 ml dry syrup', ''),
   ('drops', 100, 1, '100 mg/ml drops', 'For infants'), ('dispersible', 125, None, '125 mg dispersible', ''), ('dispersible', 250, None, '250 mg dispersible', ''),
   ('capsule', 250, None, '250 mg capsule', ''), ('capsule', 500, None, '500 mg capsule', '')],
  dmin=20, dtyp=40, dmax=90, n=3, hours=8, max_daily=3000, min_wt=2, dur='5-7 days', renal='Reduce dose if GFR < 30',
  neonatal='<7 days: 30 mg/kg/day in 2 doses', food='With or without food',
  se=['Diarrhoea', 'Rash', 'Nausea'], ci=['Penicillin allergy'],
  warn=['Check for penicillin allergy', 'Complete the course', 'High dose (80-90 mg/kg/day) is used for otitis media / resistant pneumococcus'],
  inter=['Methotrexate', 'Warfarin', 'Oral contraceptives (rare)'], notes='20-40 mg/kg/day in divided doses every 8 hours.',
  brands=['Novamox', 'Mox', 'Amoxil'], verified=1)

D('amoxicillin-clavulanate', 'Amoxicillin + Clavulanate', ['amoxycillin + clavulanic acid', 'amoxicillin + clavulanate potassium', 'co-amoxiclav', 'augmentin', 'augmentin duo', 'clavam', 'moxikind-cv', 'mega-cv'],
  'amoxicillin+clavulanic acid', 'Antibiotic (penicillin + beta-lactamase inhibitor)', 'Resistant ear, sinus, chest, skin, urine infections',
  ['Otitis media', 'Sinusitis', 'Pneumonia', 'Skin and soft tissue infection', 'Urinary tract infection'], 'per_kg_day',
  [('syrup', 200, 5, '200/28.5 mg per 5 ml dry syrup (Duo)', 'Dose by the AMOXICILLIN component', 228.5),
   ('syrup', 400, 5, '400/57 mg per 5 ml dry syrup (Duo forte)', 'Dose by the AMOXICILLIN component', 457),
   ('syrup', 600, 5, '600/42.9 mg per 5 ml (ES)', 'High-dose; dose by the AMOXICILLIN component', 642.9),
   ('tablet', 250, None, '375 mg tablet (250/125)', 'Dose by amoxicillin', 375), ('tablet', 500, None, '625 mg tablet (500/125)', 'Dose by amoxicillin', 625)],
  dmin=25, dtyp=45, dmax=90, n=2, hours=12, max_daily=1750, min_wt=3, dur='5-7 days', renal='Reduce dose if GFR < 30',
  food='Give at the start of a meal to reduce stomach upset', se=['Diarrhoea', 'Nausea', 'Rash', 'Cholestatic jaundice (rare)'],
  ci=['Penicillin allergy', 'Previous amoxicillin-clavulanate jaundice'], warn=['Dose by the amoxicillin component, not the total label strength',
  'Use a 7:1 or 14:1 product for higher doses to limit clavulanate-related diarrhoea'], inter=['Methotrexate', 'Warfarin', 'Allopurinol (rash)'],
  notes='25-45 mg/kg/day (as amoxicillin) in divided doses every 12 hours.', brands=['Augmentin', 'Clavam', 'Moxikind-CV', 'Mega-CV'], verified=1)

D('cefalexin', 'Cefalexin', ['cephalexin', 'sporidex', 'phexin'], 'cefalexin', 'Antibiotic (1st-gen cephalosporin)', 'Skin, throat, bone and urinary infections',
  ['Skin and soft tissue infection', 'Pharyngitis', 'Urinary tract infection', 'Bone and joint infection'], 'per_kg_day',
  [('syrup', 125, 5, '125 mg/5 ml dry syrup', ''), ('syrup', 250, 5, '250 mg/5 ml dry syrup', ''), ('capsule', 250, None, '250 mg capsule', ''), ('capsule', 500, None, '500 mg capsule', '')],
  dmin=25, dtyp=50, dmax=100, n=4, hours=6, max_daily=4000, dur='5-7 days', renal='Reduce dose if GFR < 30', food='With or without food',
  se=['Diarrhoea', 'Rash', 'Nausea'], ci=['Cephalosporin allergy'], warn=['Caution in penicillin allergy'], inter=['Probenecid', 'Metformin'],
  notes='25-50 mg/kg/day in divided doses.', brands=['Sporidex', 'Phexin'], verified=1)

D('cefadroxil', 'Cefadroxil', ['cefadroxyl', 'droxyl'], 'cefadroxil', 'Antibiotic (1st-gen cephalosporin)', 'Skin, throat, urinary infections',
  ['Skin and soft tissue infection', 'Pharyngitis', 'Urinary tract infection'], 'per_kg_day',
  [('syrup', 125, 5, '125 mg/5 ml dry syrup', ''), ('syrup', 250, 5, '250 mg/5 ml dry syrup', ''), ('dispersible', 125, None, '125 mg DT', ''), ('dispersible', 250, None, '250 mg DT', ''), ('capsule', 500, None, '500 mg capsule', '')],
  dmin=30, dtyp=30, dmax=50, n=2, hours=12, max_daily=2000, dur='5-10 days', renal='Reduce dose if GFR < 50', se=['Diarrhoea', 'Rash'], ci=['Cephalosporin allergy'], warn=['Caution in penicillin allergy'],
  notes='30 mg/kg/day in two divided doses.')

D('cefixime', 'Cefixime', ['zifi', 'taxim-o', 'mahacef', 'cefolac'], 'cefixime', 'Antibiotic (3rd-gen cephalosporin)', 'Respiratory, urinary, typhoid and ENT infections',
  ['Urinary tract infection', 'Typhoid', 'Pharyngitis', 'Otitis media', 'Pneumonia'], 'per_kg_day',
  [('syrup', 100, 5, '100 mg/5 ml dry syrup', 'Reconstitute with boiled, cooled water'), ('syrup', 50, 5, '50 mg/5 ml dry syrup', ''),
   ('dispersible', 50, None, '50 mg dispersible', ''), ('dispersible', 100, None, '100 mg dispersible', ''), ('tablet', 100, None, '100 mg tablet', ''), ('tablet', 200, None, '200 mg tablet', '')],
  dmin=8, dtyp=8, dmax=10, n=2, hours=12, max_daily=400, min_age=6, dur='5-7 days (typhoid 10-14 days)', renal='Reduce dose if GFR < 20',
  food='With or without food', se=['Diarrhoea', 'Abdominal pain', 'Rash'], ci=['Cephalosporin allergy'], warn=['Caution in penicillin allergy'], inter=['Carbamazepine', 'Warfarin'],
  notes='8 mg/kg/day divided every 12 hours.', brands=['Zifi', 'Taxim-O', 'Mahacef', 'Cefolac'], verified=1)

D('cefpodoxime', 'Cefpodoxime', ['cepodem', 'doxcef', 'cefpodoxime proxetil'], 'cefpodoxime', 'Antibiotic (3rd-gen cephalosporin)', 'Respiratory, ENT, urinary and skin infections',
  ['Pharyngitis', 'Otitis media', 'Pneumonia', 'Urinary tract infection', 'Skin infection'], 'per_kg_day',
  [('syrup', 50, 5, '50 mg/5 ml dry syrup', ''), ('syrup', 100, 5, '100 mg/5 ml dry syrup', ''), ('dispersible', 50, None, '50 mg DT', ''), ('dispersible', 100, None, '100 mg DT', ''), ('tablet', 100, None, '100 mg tablet', ''), ('tablet', 200, None, '200 mg tablet', '')],
  dmin=8, dtyp=10, dmax=10, n=2, hours=12, max_daily=400, min_age=2, dur='5-10 days', renal='Reduce dose if GFR < 30', food='Give with food',
  se=['Diarrhoea', 'Nausea', 'Rash'], ci=['Cephalosporin allergy'], warn=['Caution in penicillin allergy'], inter=['Antacids / H2 blockers reduce absorption'],
  notes='10 mg/kg/day in two divided doses.', brands=['Cepodem', 'Doxcef'])

D('cefuroxime', 'Cefuroxime', ['cefuroxime axetil', 'ceftum', 'zocef'], 'cefuroxime', 'Antibiotic (2nd-gen cephalosporin)', 'Respiratory, ENT, skin and urinary infections',
  ['Otitis media', 'Pharyngitis', 'Pneumonia', 'Urinary tract infection'], 'per_kg_day',
  [('syrup', 125, 5, '125 mg/5 ml dry syrup', ''), ('tablet', 125, None, '125 mg tablet', ''), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=20, dtyp=30, dmax=30, n=2, hours=12, max_daily=1000, min_age=3, dur='5-10 days', renal='Reduce dose if GFR < 30', food='Give with food (improves absorption)',
  se=['Diarrhoea', 'Nausea', 'Rash'], ci=['Cephalosporin allergy'], warn=['Caution in penicillin allergy'], inter=['Antacids reduce absorption'],
  notes='20-30 mg/kg/day in two divided doses with food.', brands=['Ceftum', 'Zocef'])

D('azithromycin', 'Azithromycin', ['azithral', 'zithrox', 'azee'], 'azithromycin', 'Antibiotic (macrolide)', 'Respiratory, throat, ear, enteric and atypical infections',
  ['Pharyngitis', 'Community-acquired pneumonia', 'Otitis media', 'Typhoid (alternative)', 'Pertussis', 'Scrub typhus / atypical pathogens'], 'per_kg_day',
  [('syrup', 100, 5, '100 mg/5 ml suspension', 'Shake well'), ('syrup', 200, 5, '200 mg/5 ml suspension', 'Shake well'),
   ('tablet', 100, None, '100 mg tablet', ''), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=10, dtyp=10, dmax=12, n=1, hours=24, max_daily=500, min_age=6, dur='3 days (5 days for some indications)', hepatic='Caution in hepatic impairment',
  food='Suspension may be given with or without food', se=['Nausea', 'Diarrhoea', 'Abdominal pain', 'QT prolongation (rare)'], ci=['Macrolide allergy', 'Known QT prolongation'],
  warn=['Once daily; complete the course', 'Antacids: separate by 2 hours'], inter=['Antacids', 'Warfarin', 'Drugs that prolong QT'],
  notes='10 mg/kg/day once daily for 3 days.', brands=['Azithral', 'Zithrox', 'Azee'], verified=1)

D('clarithromycin', 'Clarithromycin', ['claribid', 'klacid'], 'clarithromycin', 'Antibiotic (macrolide)', 'Respiratory and skin infections',
  ['Pharyngitis', 'Pneumonia', 'Sinusitis', 'Skin infection', 'H. pylori (combination)'], 'per_kg_day',
  [('syrup', 125, 5, '125 mg/5 ml suspension', ''), ('syrup', 250, 5, '250 mg/5 ml suspension', ''), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=15, dtyp=15, dmax=15, n=2, hours=12, max_daily=1000, min_age=6, dur='5-10 days', renal='Halve the dose if GFR < 30', se=['Taste disturbance', 'Nausea', 'QT prolongation'],
  ci=['Macrolide allergy', 'QT prolongation'], warn=['Many drug interactions (CYP3A4)'], inter=['Carbamazepine', 'Theophylline', 'Statins', 'Warfarin'], notes='7.5 mg/kg/dose twice daily.')

D('erythromycin', 'Erythromycin', ['erythrocin', 'erythromycin ethylsuccinate', 'erythromycin estolate'], 'erythromycin', 'Antibiotic (macrolide)', 'Penicillin-allergic respiratory / skin infections, pertussis',
  ['Pertussis', 'Pharyngitis (penicillin allergy)', 'Skin infection'], 'per_kg_day',
  [('syrup', 125, 5, '125 mg/5 ml suspension', ''), ('syrup', 200, 5, '200 mg/5 ml (ethylsuccinate)', ''), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=30, dtyp=40, dmax=50, n=4, hours=6, max_daily=2000, dur='7-14 days', hepatic='Avoid estolate in liver disease', food='Give after food',
  se=['Nausea', 'Vomiting', 'Abdominal cramps', 'Infantile hypertrophic pyloric stenosis in neonates'], ci=['Macrolide allergy'], warn=['Avoid in neonates unless essential'],
  inter=['Theophylline', 'Carbamazepine', 'Warfarin', 'Statins'], notes='30-50 mg/kg/day in divided doses every 6 hours.')

D('cloxacillin', 'Cloxacillin', ['cloxacillin sodium', 'cloxin', 'staphylococcal penicillin'], 'cloxacillin', 'Antibiotic (anti-staphylococcal penicillin)', 'Staphylococcal skin and soft tissue infections',
  ['Impetigo', 'Cellulitis', 'Boils / abscess', 'Staphylococcal infections'], 'per_kg_day',
  [('capsule', 250, None, '250 mg capsule', ''), ('capsule', 500, None, '500 mg capsule', ''), ('syrup', 125, 5, '125 mg/5 ml dry syrup', '')],
  dmin=50, dtyp=50, dmax=100, n=4, hours=6, max_daily=2000, dur='7-10 days', food='Give on an empty stomach (1 hour before food)',
  se=['Diarrhoea', 'Rash', 'Hepatitis (rare)'], ci=['Penicillin allergy'], warn=['Take on an empty stomach for best absorption'], notes='50-100 mg/kg/day in 4 divided doses.')

D('co-trimoxazole', 'Co-trimoxazole (TMP-SMX)', ['trimethoprim + sulfamethoxazole', 'sulfamethoxazole + trimethoprim', 'trimethoprim + sulphamethoxazole', 'cotrimoxazole', 'tmp-smx', 'septran', 'bactrim'],
  'sulfamethoxazole+trimethoprim', 'Antibiotic (sulphonamide combination)', 'Urinary tract infection, Pneumocystis prophylaxis',
  ['Urinary tract infection', 'Pneumocystis prophylaxis', 'Shigellosis', 'Chronic suppurative otitis media'], 'per_kg_day',
  [('syrup', 40, 5, '40 mg TMP + 200 mg SMX per 5 ml', 'Dose by the TRIMETHOPRIM component', 240), ('tablet', 80, None, '80 mg TMP + 400 mg SMX tablet (SS)', 'Dose by trimethoprim', 480),
   ('tablet', 160, None, '160 mg TMP + 800 mg SMX tablet (DS)', 'Dose by trimethoprim', 960)],
  dmin=6, dtyp=8, dmax=12, n=2, hours=12, max_daily=320, min_age=2, dur='5-7 days (UTI)', renal='Halve the dose if GFR 15-30; avoid if < 15',
  food='With plenty of fluids', se=['Rash', 'Nausea', 'Hyperkalaemia', 'Stevens-Johnson syndrome (rare)'], ci=['Age < 6 weeks', 'G6PD deficiency', 'Sulpha allergy', 'Severe renal/hepatic disease'],
  warn=['Dose by the TRIMETHOPRIM component', 'Stop at the first sign of rash'], inter=['Warfarin', 'Phenytoin', 'Methotrexate', 'ACE inhibitors'], notes='Trimethoprim 6-12 mg/kg/day in two divided doses.')

D('metronidazole', 'Metronidazole', ['metrogyl', 'flagyl'], 'metronidazole', 'Antiprotozoal / antibacterial', 'Amoebiasis, giardiasis, anaerobic infections',
  ['Amoebic dysentery', 'Giardiasis', 'Anaerobic infections', 'Dental infections'], 'per_kg_day',
  [('syrup', 200, 5, '200 mg/5 ml suspension (benzoate)', 'Check the label: some brands state the benzoate salt'), ('tablet', 200, None, '200 mg tablet', ''), ('tablet', 400, None, '400 mg tablet', '')],
  dmin=22.5, dtyp=30, dmax=50, n=3, hours=8, max_daily=1200, dur='5-7 days (amoebiasis 7-10 days)', hepatic='Reduce dose in severe hepatic impairment', neonatal='15 mg/kg/day in 2 doses',
  food='Give after food', se=['Metallic taste', 'Nausea', 'Peripheral neuropathy on long courses'], ci=['Hypersensitivity to nitroimidazoles'], warn=['Avoid alcohol in adolescents'], inter=['Warfarin', 'Phenytoin', 'Alcohol (disulfiram-like)'],
  notes='22.5-40 mg/kg/day in three divided doses; up to 50 mg/kg/day for amoebiasis.', brands=['Metrogyl', 'Flagyl'])

D('nitrofurantoin', 'Nitrofurantoin', ['furadantin', 'nitrofurantoin macrocrystals'], 'nitrofurantoin', 'Urinary antiseptic', 'Lower urinary tract infection; prophylaxis',
  ['Cystitis', 'UTI prophylaxis'], 'per_kg_day',
  [('syrup', 25, 5, '25 mg/5 ml suspension', ''), ('capsule', 50, None, '50 mg capsule', ''), ('capsule', 100, None, '100 mg capsule', '')],
  dmin=5, dtyp=5, dmax=7, n=4, hours=6, max_daily=400, min_age=3, dur='5-7 days (prophylaxis: 1-2 mg/kg once at night)', renal='Avoid if GFR < 45', hepatic='Caution',
  food='Give with food', se=['Nausea', 'Vomiting', 'Pulmonary reactions (rare)'], ci=['Age < 3 months', 'G6PD deficiency', 'Renal impairment'], warn=['Not for pyelonephritis / systemic infection'], notes='5-7 mg/kg/day in 4 divided doses for treatment.')

D('linezolid', 'Linezolid', ['zyvox', 'linox'], 'linezolid', 'Antibiotic (oxazolidinone)', 'Resistant gram-positive infections (specialist use)',
  ['MRSA / VRE infection', 'Resistant pneumonia', 'Skin infection'], 'per_kg_dose',
  [('syrup', 100, 5, '100 mg/5 ml suspension', ''), ('tablet', 600, None, '600 mg tablet', '')],
  dmin=10, dtyp=10, dmax=10, n=3, hours=8, max_single=600, max_daily=1200, dur='As per culture / specialist', se=['Myelosuppression', 'Diarrhoea', 'Headache'], ci=['Use with MAO inhibitors'],
  warn=['Specialist / culture-guided use; monitor blood counts if > 10-14 days', 'Age 12 y and over: 600 mg twice daily'], inter=['SSRIs', 'MAO inhibitors', 'Pseudoephedrine'], notes='10 mg/kg/dose every 8 hours up to age 12 years.')

D('doxycycline', 'Doxycycline', ['doxy', 'doxt'], 'doxycycline', 'Antibiotic (tetracycline)', 'Scrub typhus, rickettsial, atypical and acne infections (age 8 y and over)',
  ['Scrub typhus', 'Rickettsial infection', 'Leptospirosis', 'Acne', 'Cholera'], 'per_kg_day',
  [('capsule', 100, None, '100 mg capsule', ''), ('tablet', 100, None, '100 mg tablet', ''), ('syrup', 50, 5, '50 mg/5 ml syrup', '')],
  dmin=2, dtyp=4, dmax=4, n=2, hours=12, max_daily=200, min_age=96, dur='7-10 days (scrub typhus 5-7 days)', food='Give with a full glass of water; avoid dairy / antacids within 2 hours',
  se=['Nausea', 'Oesophagitis', 'Photosensitivity', 'Tooth discolouration < 8 years'], ci=['Age under 8 years (except severe rickettsial disease)', 'Pregnancy'], warn=['Take upright with water', 'Avoid sun exposure'],
  inter=['Antacids', 'Iron', 'Calcium', 'Isotretinoin'], notes='2-4 mg/kg/day in two divided doses (max 200 mg/day).')

# ───────────────────────── Anthelmintic / antiparasitic ─────────────────────────
D('albendazole', 'Albendazole', ['zentel', 'bandy', 'alben'], 'albendazole', 'Anthelmintic', 'Roundworm, hookworm, whipworm, threadworm, giardia',
  ['Ascariasis', 'Hookworm', 'Trichuriasis', 'Enterobiasis', 'Giardiasis'], 'band',
  [('syrup', 200, 5, '200 mg/5 ml suspension', 'Shake well'), ('tablet', 400, None, '400 mg tablet', ''), ('chewable', 400, None, '400 mg chewable', '')],
  n=1, min_age=12, dur='Single dose; repeat after 2 weeks if needed', hepatic='Caution in hepatic impairment', food='Give with a fatty meal',
  bands=[dict(min_age_m=12, max_age_m=23, dose=200, n=1), dict(min_age_m=24, max_age_m=216, dose=400, n=1)],
  se=['Abdominal pain', 'Headache', 'Raised liver enzymes'], ci=['Age < 1 year', 'Pregnancy'], warn=['Not recommended under 1 year', 'Repeat after 2 weeks for heavy infection'], notes='200 mg for 1-2 years; 400 mg for 2 years and above.', brands=['Zentel', 'Bandy'])

D('mebendazole', 'Mebendazole', ['vermox', 'wormin'], 'mebendazole', 'Anthelmintic', 'Roundworm, whipworm, hookworm, threadworm',
  ['Ascariasis', 'Trichuriasis', 'Enterobiasis', 'Hookworm'], 'band',
  [('chewable', 100, None, '100 mg chewable', ''), ('chewable', 500, None, '500 mg chewable', ''), ('syrup', 100, 5, '100 mg/5 ml suspension', '')],
  n=2, min_age=24, dur='3 days (100 mg twice daily); or 500 mg once', bands=[dict(min_age_m=24, max_age_m=216, dose=100, n=2)],
  se=['Abdominal pain', 'Diarrhoea'], ci=['Age < 2 years'], warn=['Not recommended under 2 years'], notes='100 mg twice daily for 3 days, or 500 mg as a single dose.', brands=['Vermox', 'Wormin'])

D('ivermectin', 'Ivermectin', ['iverheal', 'ivecop', 'scabioral'], 'ivermectin', 'Antiparasitic', 'Scabies, strongyloidiasis',
  ['Scabies', 'Strongyloidiasis', 'Pediculosis'], 'per_kg_dose', [('tablet', 3, None, '3 mg tablet', ''), ('tablet', 6, None, '6 mg tablet', ''), ('tablet', 12, None, '12 mg tablet', '')],
  dmin=0.2, dtyp=0.2, dmax=0.2, n=1, min_wt=15, dur='Single dose; repeat after 7-14 days for scabies', food='Give on an empty stomach with water',
  se=['Itching (dead mites)', 'Dizziness', 'Nausea'], ci=['Weight < 15 kg'], warn=['Not for children under 15 kg', 'Treat close contacts'], notes='0.2 mg/kg (200 mcg/kg) as a single dose.', brands=['Iverheal', 'Scabioral'])

D('nitazoxanide', 'Nitazoxanide', ['nitazox', 'alinia'], 'nitazoxanide', 'Antiprotozoal', 'Giardiasis, amoebiasis, cryptosporidiosis',
  ['Giardiasis', 'Amoebiasis', 'Cryptosporidiosis', 'Infectious diarrhoea'], 'band',
  [('syrup', 100, 5, '100 mg/5 ml suspension', 'Shake well'), ('tablet', 200, None, '200 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  n=2, min_age=12, dur='3 days', food='Give with food',
  bands=[dict(min_age_m=12, max_age_m=47, dose=100, n=2), dict(min_age_m=48, max_age_m=143, dose=200, n=2), dict(min_age_m=144, max_age_m=216, dose=500, n=2)],
  se=['Abdominal pain', 'Nausea', 'Yellow discolouration of urine'], ci=['Age < 1 year'], notes='1-3 y: 100 mg; 4-11 y: 200 mg; 12 y and above: 500 mg, twice daily for 3 days.')

D('tinidazole', 'Tinidazole', ['tiniba', 'fasigyn'], 'tinidazole', 'Antiprotozoal', 'Giardiasis, amoebiasis',
  ['Giardiasis', 'Amoebiasis', 'Bacterial vaginosis'], 'per_kg_dose', [('tablet', 300, None, '300 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', ''), ('tablet', 1000, None, '1000 mg tablet', '')],
  dmin=50, dtyp=50, dmax=50, n=1, max_single=2000, min_age=36, dur='Single dose (amoebiasis: 3 days)', food='Give with food', se=['Metallic taste', 'Nausea'], ci=['Age < 3 years', 'First trimester of pregnancy'], warn=['Avoid alcohol'], notes='50 mg/kg as a single dose (max 2 g).')

# ───────────────────────── Antifungals ─────────────────────────
D('fluconazole', 'Fluconazole', ['forcan', 'zocon', 'syscan'], 'fluconazole', 'Antifungal', 'Candidiasis, tinea (selected), cryptococcal infection',
  ['Oral candidiasis', 'Oesophageal candidiasis', 'Tinea capitis (alternative)', 'Cryptococcal meningitis'], 'per_kg_day',
  [('syrup', 50, 5, '50 mg/5 ml suspension', ''), ('tablet', 50, None, '50 mg tablet', ''), ('tablet', 100, None, '100 mg tablet', ''), ('tablet', 150, None, '150 mg tablet', ''), ('tablet', 200, None, '200 mg tablet', '')],
  dmin=3, dtyp=3, dmax=6, n=1, hours=24, max_daily=400, dur='7-14 days (oral candidiasis)', renal='Halve the dose if GFR < 50', hepatic='Caution',
  se=['Nausea', 'Headache', 'Raised liver enzymes', 'QT prolongation (rare)'], ci=['Concurrent QT-prolonging drugs'], warn=['6 mg/kg on day 1, then 3 mg/kg/day is common for oral candidiasis'],
  inter=['Warfarin', 'Phenytoin', 'Statins', 'QT-prolonging drugs'], notes='3-6 mg/kg once daily.', brands=['Forcan', 'Zocon'])

D('griseofulvin', 'Griseofulvin', ['grisovin', 'griseofulvin microsize'], 'griseofulvin', 'Antifungal', 'Tinea capitis and extensive dermatophyte infections',
  ['Tinea capitis', 'Tinea corporis (extensive)'], 'per_kg_day', [('tablet', 125, None, '125 mg tablet', ''), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=10, dtyp=10, dmax=20, n=1, hours=24, max_daily=1000, dur='6-8 weeks (tinea capitis)', food='Give with a fatty meal', se=['Headache', 'Nausea', 'Photosensitivity', 'Rash'],
  ci=['Hepatic disease', 'Porphyria'], warn=['Long course; check liver enzymes if prolonged'], inter=['Warfarin', 'Oral contraceptives'], notes='10 mg/kg/day (microsize) once daily or in 2 divided doses.')

# ───────────────────────── Respiratory ─────────────────────────
D('salbutamol', 'Salbutamol (oral)', ['albuterol', 'asthalin', 'ventolin'], 'salbutamol', 'Bronchodilator', 'Wheeze, asthma, bronchospasm',
  ['Asthma', 'Wheezing', 'Bronchiolitis (selected)'], 'per_kg_dose', [('syrup', 2, 5, '2 mg/5 ml syrup', ''), ('tablet', 2, None, '2 mg tablet', ''), ('tablet', 4, None, '4 mg tablet', '')],
  dmin=0.1, dtyp=0.1, dmax=0.15, n=3, hours=8, max_single=4, max_daily=12, min_age=6, dur='3-5 days, review', se=['Tremor', 'Tachycardia', 'Hypokalaemia', 'Irritability'], ci=['Hypersensitivity'],
  warn=['Inhaled route (MDI with spacer / nebuliser) is preferred in acute asthma'], inter=['Beta-blockers', 'Steroids / diuretics (low potassium)'], notes='0.1-0.15 mg/kg/dose three times a day.', brands=['Asthalin', 'Ventolin'])

D('montelukast', 'Montelukast', ['singulair', 'montair', 'montek'], 'montelukast', 'Leukotriene antagonist', 'Allergic rhinitis, asthma prophylaxis',
  ['Asthma prophylaxis', 'Allergic rhinitis', 'Exercise-induced bronchoconstriction'], 'band',
  [('chewable', 4, None, '4 mg chewable', ''), ('chewable', 5, None, '5 mg chewable', ''), ('tablet', 10, None, '10 mg tablet', ''), ('syrup', 4, 5, '4 mg/5 ml syrup / granules sachet', '')],
  n=1, min_age=6, dur='As per condition (usually weeks)', food='Give in the evening', bands=[dict(min_age_m=6, max_age_m=71, dose=4, n=1), dict(min_age_m=72, max_age_m=179, dose=5, n=1), dict(min_age_m=180, max_age_m=216, dose=10, n=1)],
  se=['Headache', 'Abdominal pain', 'Sleep disturbance / behaviour changes'], ci=['Hypersensitivity'], warn=['Report mood or behaviour changes', 'Not for acute attacks'],
  notes='6 months-5 y: 4 mg; 6-14 y: 5 mg; 15 y and above: 10 mg, once daily in the evening.', brands=['Montair', 'Montek', 'Singulair'])

D('prednisolone', 'Prednisolone', ['wysolone', 'omnacortil', 'predmet'], 'prednisolone', 'Corticosteroid', 'Asthma exacerbation, croup, allergic and inflammatory conditions',
  ['Acute asthma', 'Croup', 'Nephrotic syndrome (specialist)', 'Allergic reactions', 'Inflammatory conditions'], 'per_kg_day',
  [('syrup', 5, 5, '5 mg/5 ml syrup', ''), ('syrup', 15, 5, '15 mg/5 ml syrup', ''), ('dispersible', 5, None, '5 mg DT', ''), ('dispersible', 10, None, '10 mg DT', ''), ('dispersible', 20, None, '20 mg DT', ''), ('tablet', 5, None, '5 mg tablet', ''), ('tablet', 10, None, '10 mg tablet', ''), ('tablet', 20, None, '20 mg tablet', '')],
  dmin=0.5, dtyp=1, dmax=2, n=1, hours=24, max_daily=60, dur='3-5 days for asthma (no taper needed if < 10 days)', food='Give with food, in the morning',
  se=['Increased appetite', 'Mood changes', 'Hyperglycaemia', 'Growth suppression on long courses'], ci=['Systemic fungal infection', 'Live vaccines on high doses'], warn=['Do not stop abruptly after long courses', 'Watch for varicella exposure'],
  inter=['NSAIDs', 'Anticonvulsants (reduce effect)', 'Diuretics'], notes='1-2 mg/kg/day, usually a single morning dose (max 60 mg/day).', brands=['Wysolone', 'Omnacortil'])

D('dexamethasone', 'Dexamethasone', ['dexona', 'decdan'], 'dexamethasone', 'Corticosteroid', 'Croup, severe allergic / inflammatory conditions, cerebral oedema',
  ['Croup', 'Airway oedema', 'Allergic reaction', 'Bacterial meningitis adjunct'], 'per_kg_dose', [('syrup', 0.5, 5, '0.5 mg/5 ml elixir', ''), ('tablet', 0.5, None, '0.5 mg tablet', ''), ('tablet', 4, None, '4 mg tablet', '')],
  dmin=0.15, dtyp=0.15, dmax=0.6, n=1, max_single=10, dur='Single dose (croup); 1-3 days otherwise', food='Give with food', se=['Hyperglycaemia', 'Mood changes', 'Gastric irritation'], ci=['Systemic infection without cover'],
  warn=['Croup: a single 0.15-0.6 mg/kg dose is usually enough'], notes='0.15-0.6 mg/kg/dose.')

D('ambroxol', 'Ambroxol', ['ambrodil', 'mucolite', 'ambroxol hydrochloride'], 'ambroxol', 'Mucolytic', 'Productive cough, thick mucus', ['Productive cough', 'Bronchitis'], 'per_kg_day',
  [('syrup', 15, 5, '15 mg/5 ml syrup', ''), ('syrup', 30, 5, '30 mg/5 ml syrup', ''), ('drops', 7.5, 1, '7.5 mg/ml drops', ''), ('tablet', 30, None, '30 mg tablet', '')],
  dmin=1.2, dtyp=1.2, dmax=1.6, n=3, hours=8, max_daily=90, min_age=24, dur='5 days', food='Give after food', se=['Nausea', 'Rash (rare)'], ci=['Age < 2 years (advised against)', 'Peptic ulcer'],
  warn=['Cough and cold combinations are not recommended below 4 years'], notes='1.2-1.6 mg/kg/day in 2-3 divided doses.')

# ───────────────────────── Antihistamines ─────────────────────────
D('cetirizine', 'Cetirizine', ['alerid', 'okacet', 'cetzine'], 'cetirizine', 'Antihistamine', 'Allergic rhinitis, urticaria, itching',
  ['Allergic rhinitis', 'Urticaria', 'Itching', 'Allergic conjunctivitis'], 'band',
  [('syrup', 5, 5, '5 mg/5 ml syrup', ''), ('drops', 10, 1, '10 mg/ml drops', ''), ('tablet', 5, None, '5 mg tablet', ''), ('tablet', 10, None, '10 mg tablet', '')],
  n=1, min_age=6, dur='As needed (3-7 days)', renal='Halve the dose if GFR < 30', bands=[dict(min_age_m=6, max_age_m=23, dose=2.5, n=1), dict(min_age_m=24, max_age_m=71, dose=2.5, n=2), dict(min_age_m=72, max_age_m=143, dose=5, n=2), dict(min_age_m=144, max_age_m=216, dose=10, n=1)],
  se=['Drowsiness', 'Dry mouth', 'Headache'], ci=['Hypersensitivity'], warn=['May cause drowsiness'], inter=['Alcohol / sedatives'], notes='6-23 m: 2.5 mg once daily; 2-5 y: 2.5 mg twice daily; 6-11 y: 5 mg twice daily; 12 y and above: 10 mg once daily.', brands=['Alerid', 'Okacet'])

D('levocetirizine', 'Levocetirizine', ['levocet', 'xyzal', 'teczine'], 'levocetirizine', 'Antihistamine', 'Allergic rhinitis, urticaria', ['Allergic rhinitis', 'Urticaria', 'Itching'], 'band',
  [('syrup', 2.5, 5, '2.5 mg/5 ml syrup', ''), ('tablet', 2.5, None, '2.5 mg tablet', ''), ('tablet', 5, None, '5 mg tablet', '')],
  n=1, min_age=6, dur='As needed', renal='Avoid if GFR < 10', food='Give in the evening', bands=[dict(min_age_m=6, max_age_m=71, dose=1.25, n=1), dict(min_age_m=72, max_age_m=143, dose=2.5, n=1), dict(min_age_m=144, max_age_m=216, dose=5, n=1)],
  se=['Drowsiness', 'Dry mouth'], ci=['Hypersensitivity', 'Severe renal impairment'], warn=['May cause drowsiness'], notes='6 m-5 y: 1.25 mg; 6-11 y: 2.5 mg; 12 y and above: 5 mg, once daily in the evening.')

D('hydroxyzine', 'Hydroxyzine', ['atarax', 'hydroxyzine hydrochloride'], 'hydroxyzine', 'Antihistamine (sedating)', 'Itching, urticaria, anxiety, pre-medication', ['Itching', 'Urticaria', 'Allergic dermatitis'], 'per_kg_day',
  [('syrup', 10, 5, '10 mg/5 ml syrup', ''), ('tablet', 10, None, '10 mg tablet', ''), ('tablet', 25, None, '25 mg tablet', '')],
  dmin=1, dtyp=2, dmax=2, n=3, hours=8, max_daily=100, min_age=6, dur='As needed', renal='Reduce dose in renal impairment', se=['Drowsiness', 'Dry mouth', 'QT prolongation (rare)'], ci=['QT prolongation', 'Porphyria'],
  warn=['Sedating; avoid driving / cycling'], inter=['Sedatives', 'QT-prolonging drugs'], notes='1-2 mg/kg/day in 3 divided doses (max 100 mg/day).')

D('chlorpheniramine', 'Chlorpheniramine', ['chlorphenamine', 'cpm', 'piriton'], 'chlorpheniramine', 'Antihistamine (sedating)', 'Allergic rhinitis, urticaria, itching', ['Allergic rhinitis', 'Urticaria', 'Itching'], 'per_kg_day',
  [('syrup', 2, 5, '2 mg/5 ml syrup', ''), ('tablet', 4, None, '4 mg tablet', '')], dmin=0.35, dtyp=0.35, dmax=0.35, n=4, hours=6, max_single=4, max_daily=12, min_age=24, dur='As needed (short course)',
  se=['Drowsiness', 'Dry mouth', 'Paradoxical excitement'], ci=['Age < 2 years', 'Neonates'], warn=['Avoid in infants', 'Combination cough / cold products are not recommended below 4 years'], inter=['Sedatives', 'MAO inhibitors'], notes='0.35 mg/kg/day in divided doses.')

# ───────────────────────── Gastro-intestinal ─────────────────────────
D('ondansetron', 'Ondansetron', ['emeset', 'ondem', 'vomikind'], 'ondansetron', 'Antiemetic', 'Vomiting (gastroenteritis, chemotherapy, post-operative)', ['Acute gastroenteritis vomiting', 'Post-operative nausea', 'Chemotherapy-induced nausea'], 'per_kg_dose',
  [('syrup', 2, 5, '2 mg/5 ml syrup', ''), ('syrup', 4, 5, '4 mg/5 ml syrup', ''), ('tablet', 4, None, '4 mg tablet', ''), ('mouth-dissolving', 4, None, '4 mg MD tablet', ''), ('mouth-dissolving', 8, None, '8 mg MD tablet', '')],
  dmin=0.1, dtyp=0.15, dmax=0.15, n=3, hours=8, max_single=8, max_daily=24, min_age=6, dur='1-2 days', hepatic='Max 8 mg/day in severe hepatic impairment', se=['Headache', 'Constipation', 'QT prolongation'], ci=['Congenital long QT'], warn=['Oral rehydration remains the mainstay'],
  inter=['QT-prolonging drugs', 'Apomorphine'], notes='0.15 mg/kg/dose up to three times a day (max 8 mg/dose).', brands=['Emeset', 'Ondem'])

D('domperidone', 'Domperidone', ['domstal', 'vomistop', 'motilium'], 'domperidone', 'Prokinetic / antiemetic', 'Nausea, vomiting, reflux', ['Nausea', 'Vomiting', 'Gastro-oesophageal reflux'], 'per_kg_dose',
  [('syrup', 5, 5, '5 mg/5 ml suspension', ''), ('tablet', 10, None, '10 mg tablet', '')], dmin=0.25, dtyp=0.25, dmax=0.5, n=3, hours=8, max_single=10, max_daily=30, min_age=12, min_wt=15, dur='Short course (up to 1 week)', food='Give 15-30 minutes before food',
  se=['Dry mouth', 'Headache', 'QT prolongation'], ci=['Cardiac conduction disorders', 'GI bleeding / obstruction', 'Prolactinoma'], warn=['Use at the lowest dose for the shortest time', 'Not recommended in infants'], inter=['QT-prolonging drugs', 'Ketoconazole', 'Macrolides'],
  notes='0.25 mg/kg/dose up to three times a day (max 30 mg/day).', brands=['Domstal'])

D('omeprazole', 'Omeprazole', ['omez', 'ocid', 'prilosec'], 'omeprazole', 'Proton pump inhibitor', 'Gastro-oesophageal reflux, peptic ulcer, H. pylori', ['GERD', 'Peptic ulcer', 'H. pylori (combination)'], 'per_kg_day',
  [('capsule', 10, None, '10 mg capsule', ''), ('capsule', 20, None, '20 mg capsule', ''), ('capsule', 40, None, '40 mg capsule', ''), ('sachet', 10, None, '10 mg sachet', 'Mix with water / juice')],
  dmin=0.7, dtyp=1, dmax=3.3, n=1, hours=24, max_daily=40, min_age=12, dur='4-8 weeks', hepatic='Reduce dose in severe hepatic impairment', food='Give 30 minutes before breakfast', se=['Headache', 'Diarrhoea', 'Abdominal pain'], ci=['Hypersensitivity'],
  warn=['Do not crush capsules; open and mix pellets with soft food'], inter=['Clopidogrel', 'Methotrexate', 'Phenytoin'], notes='1 mg/kg/day once daily (max 40 mg).')

D('pantoprazole', 'Pantoprazole', ['pantocid', 'pan', 'pantop'], 'pantoprazole', 'Proton pump inhibitor', 'Reflux, gastritis, peptic ulcer', ['GERD', 'Gastritis', 'Peptic ulcer'], 'per_kg_day',
  [('tablet', 20, None, '20 mg tablet', ''), ('tablet', 40, None, '40 mg tablet', '')], dmin=0.5, dtyp=1, dmax=1, n=1, hours=24, max_daily=40, min_age=60, dur='4-8 weeks', food='Give 30 minutes before breakfast', se=['Headache', 'Diarrhoea'], ci=['Hypersensitivity'], warn=['Swallow tablet whole'],
  inter=['Clopidogrel', 'Warfarin'], notes='0.5-1 mg/kg/day once daily (max 40 mg).', brands=['Pantocid', 'Pan'])

D('zinc-sulfate', 'Zinc (elemental)', ['zinc sulphate', 'zinc acetate', 'zinc gluconate', 'zincovit-z', 'zinconia', 'zinc'], 'zinc', 'Mineral supplement', 'Acute diarrhoea, zinc deficiency', ['Acute diarrhoea', 'Zinc deficiency', 'Malnutrition'], 'band',
  [('dispersible', 20, None, '20 mg elemental dispersible tablet', 'Dissolve in a little water / ORS'), ('syrup', 20, 5, '20 mg elemental/5 ml syrup', ''), ('drops', 10, 1, '10 mg elemental/ml drops', '')],
  n=1, hours=24, dur='14 days', renal='Caution in renal impairment', bands=[dict(min_age_m=0, max_age_m=5, dose=10, n=1), dict(min_age_m=6, max_age_m=216, dose=20, n=1)],
  se=['Nausea', 'Vomiting', 'Metallic taste'], ci=['Hypersensitivity'], warn=['Give for the full 14 days even after the diarrhoea stops', 'Doses are elemental zinc'], inter=['Iron and calcium (separate)', 'Quinolones / tetracyclines (separate)'],
  notes='Under 6 months: 10 mg/day; 6 months and above: 20 mg/day for 14 days.', brands=['Zinconia', 'Zincovit-Z'])

D('racecadotril', 'Racecadotril', ['redotil', 'hidrasec'], 'racecadotril', 'Antidiarrhoeal (antisecretory)', 'Acute watery diarrhoea', ['Acute watery diarrhoea'], 'per_kg_dose',
  [('sachet', 10, None, '10 mg sachet (infants)', 'Mix with food / water'), ('sachet', 30, None, '30 mg sachet (children)', 'Mix with food / water'), ('capsule', 100, None, '100 mg capsule', '')],
  dmin=1.5, dtyp=1.5, dmax=1.5, n=3, hours=8, max_single=100, min_age=3, dur='Until 2 formed stools (max 7 days)', se=['Headache', 'Rash'], ci=['Hypersensitivity', 'Blood / mucus in stool'], warn=['Always give with oral rehydration', 'Not for bloody diarrhoea'], notes='1.5 mg/kg/dose three times a day.')

D('lactulose', 'Lactulose', ['duphalac', 'looz', 'cremaffin'], 'lactulose', 'Osmotic laxative', 'Constipation', ['Constipation', 'Hepatic encephalopathy (specialist)'], 'per_kg_day',
  [('syrup', 10, 15, '10 g/15 ml solution', 'Dose is the volume; label strength shown for reference')], unit='ml', dmin=1, dtyp=1.5, dmax=3, n=2, hours=12, max_daily=40, dur='Until stools are regular; wean gradually',
  se=['Flatulence', 'Abdominal cramps', 'Diarrhoea'], ci=['Galactosaemia', 'Intestinal obstruction'], warn=['May take 24-48 hours to work', 'Reduce the dose if stools are loose'], notes='1-3 ml/kg/day in 1-2 divided doses (volume).')

D('bisacodyl', 'Bisacodyl', ['dulcolax', 'bisacodyl suppository'], 'bisacodyl', 'Stimulant laxative', 'Short-term relief of constipation', ['Constipation'], 'band',
  [('tablet', 5, None, '5 mg tablet', 'Swallow whole'), ('suppository', 5, None, '5 mg suppository', ''), ('suppository', 10, None, '10 mg suppository', '')],
  n=1, min_age=48, dur='Short term only', food='Give at night', bands=[dict(min_age_m=48, max_age_m=119, dose=5, n=1), dict(min_age_m=120, max_age_m=216, dose=5, n=1)],
  se=['Abdominal cramps', 'Diarrhoea'], ci=['Intestinal obstruction', 'Age < 4 years'], warn=['Do not use for long periods', 'Not for children under 4 years without advice'], notes='4-9 y: 5 mg; 10 y and above: 5-10 mg once daily at night.')

# ───────────────────────── Neurology ─────────────────────────
D('phenobarbitone', 'Phenobarbitone', ['phenobarbital', 'gardenal'], 'phenobarbitone', 'Anticonvulsant', 'Seizures', ['Generalised seizures', 'Neonatal seizures'], 'per_kg_day',
  [('tablet', 15, None, '15 mg tablet', ''), ('tablet', 30, None, '30 mg tablet', ''), ('tablet', 60, None, '60 mg tablet', ''), ('syrup', 20, 5, '20 mg/5 ml elixir', '')],
  dmin=3, dtyp=3, dmax=5, n=2, hours=12, max_daily=300, dur='Long term as advised', hepatic='Caution', se=['Sedation', 'Hyperactivity', 'Cognitive effects'], ci=['Porphyria', 'Severe respiratory depression'],
  warn=['Do not stop abruptly', 'Specialist follow-up; monitor levels if needed'], inter=['Many (enzyme inducer): valproate, warfarin, oral contraceptives'], notes='3-5 mg/kg/day maintenance (single or divided).')

D('sodium-valproate', 'Sodium Valproate', ['valproate', 'valproic acid', 'epilex', 'encorate', 'divalproex'], 'valproate', 'Anticonvulsant', 'Epilepsy', ['Generalised seizures', 'Absence seizures', 'Febrile seizure prophylaxis (selected)'], 'per_kg_day',
  [('syrup', 200, 5, '200 mg/5 ml syrup', ''), ('tablet', 200, None, '200 mg tablet', ''), ('tablet', 300, None, '300 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', '')],
  dmin=20, dtyp=20, dmax=40, n=2, hours=12, max_daily=2500, dur='Long term as advised', hepatic='Avoid in hepatic disease', food='Give with food',
  se=['Weight gain', 'Tremor', 'Hepatotoxicity', 'Pancreatitis', 'Thrombocytopenia'], ci=['Liver disease', 'Mitochondrial disorders', 'Age < 2 years (high-risk of liver toxicity)'], warn=['Check liver function and platelets', 'Avoid in adolescent girls where possible (teratogenic)'],
  inter=['Phenobarbitone', 'Carbamazepine', 'Aspirin', 'Lamotrigine'], notes='20-30 mg/kg/day in 2 divided doses.')

D('levetiracetam', 'Levetiracetam', ['levipil', 'keppra', 'levera'], 'levetiracetam', 'Anticonvulsant', 'Epilepsy', ['Focal seizures', 'Generalised seizures', 'Myoclonic seizures'], 'per_kg_day',
  [('syrup', 500, 5, '500 mg/5 ml (100 mg/ml) oral solution', ''), ('tablet', 250, None, '250 mg tablet', ''), ('tablet', 500, None, '500 mg tablet', ''), ('tablet', 750, None, '750 mg tablet', '')],
  dmin=20, dtyp=20, dmax=60, n=2, hours=12, max_daily=3000, min_age=1, dur='Long term as advised', renal='Reduce dose if GFR < 80', se=['Somnolence', 'Irritability / behaviour change', 'Dizziness'], ci=['Hypersensitivity'],
  warn=['Do not stop abruptly', 'Watch for mood / behaviour changes'], notes='Start 10 mg/kg twice daily; maintenance 20-60 mg/kg/day in 2 divided doses.')

D('carbamazepine', 'Carbamazepine', ['tegretol', 'mazetol', 'zen'], 'carbamazepine', 'Anticonvulsant', 'Focal seizures, trigeminal neuralgia', ['Focal seizures', 'Generalised tonic-clonic seizures'], 'per_kg_day',
  [('syrup', 100, 5, '100 mg/5 ml suspension', ''), ('tablet', 100, None, '100 mg tablet', ''), ('tablet', 200, None, '200 mg tablet', ''), ('tablet', 400, None, '400 mg tablet', '')],
  dmin=10, dtyp=10, dmax=20, n=2, hours=12, max_daily=1200, dur='Long term as advised', hepatic='Avoid in hepatic disease', food='Give with food',
  se=['Drowsiness', 'Dizziness', 'Rash', 'Hyponatraemia', 'Stevens-Johnson syndrome (HLA-B*1502)'], ci=['AV block', 'Bone marrow depression', 'Porphyria'], warn=['Start low and titrate', 'Check HLA-B*1502 in at-risk populations'], inter=['Macrolides', 'Valproate', 'Oral contraceptives'], notes='10-20 mg/kg/day in 2 divided doses.')

D('topiramate', 'Topiramate', ['topamac', 'topamax', 'topirol'], 'topiramate', 'Anticonvulsant', 'Epilepsy, migraine prophylaxis', ['Focal seizures', 'Generalised seizures', 'Migraine prophylaxis'], 'per_kg_day',
  [('tablet', 25, None, '25 mg tablet', ''), ('tablet', 50, None, '50 mg tablet', ''), ('tablet', 100, None, '100 mg tablet', ''), ('capsule', 15, None, '15 mg sprinkle capsule', '')],
  dmin=1, dtyp=3, dmax=9, n=2, hours=12, max_daily=400, min_age=24, dur='Long term as advised', renal='Halve the dose if GFR < 70', se=['Drowsiness', 'Weight loss', 'Paraesthesia', 'Cognitive slowing', 'Kidney stones'], ci=['Hypersensitivity'],
  warn=['Titrate slowly', 'Maintain hydration'], notes='Start 1-3 mg/kg/day; maintenance 5-9 mg/kg/day in 2 divided doses.')

# ───────────────────────── Nutrition / vitamins ─────────────────────────
D('vitamin-d3', 'Vitamin D3 (Cholecalciferol)', ['cholecalciferol', 'vitamin d', 'calcirol', 'd-rise', 'uprise-d3'], 'cholecalciferol', 'Vitamin', 'Rickets prevention and treatment', ['Rickets prevention', 'Vitamin D deficiency', 'Rickets treatment (higher doses)'], 'band',
  [('drops', 400, 1, '400 IU/ml drops', ''), ('drops', 800, 1, '800 IU/ml drops', ''), ('syrup', 60000, 5, '60,000 IU/5 ml (weekly)', 'Weekly treatment dose'), ('sachet', 60000, None, '60,000 IU sachet (weekly)', 'Weekly treatment dose')],
  unit='IU', n=1, hours=24, max_daily=4000, dur='Prophylaxis: ongoing; treatment: 6-12 weeks', bands=[dict(min_age_m=0, max_age_m=11, dose=400, n=1), dict(min_age_m=12, max_age_m=216, dose=600, n=1)],
  se=['Hypercalcaemia on overdose'], ci=['Hypercalcaemia', 'Hypervitaminosis D'], warn=['Prophylactic doses shown. Treatment (e.g. 2,000 IU/day for 6-12 weeks, or 60,000 IU weekly) is set by the doctor'], notes='Prophylaxis: 400 IU/day under 1 year; 600 IU/day above.')

D('iron', 'Ferrous Salts (elemental iron)', ['ferrous sulphate', 'ferrous sulfate', 'ferrous ascorbate', 'ferrous fumarate', 'iron', 'ferrous bisglycinate', 'orofer', 'fer-in-sol'], 'iron', 'Mineral supplement', 'Iron deficiency anaemia', ['Iron deficiency anaemia', 'Iron supplementation'], 'per_kg_day',
  [('drops', 25, 1, '25 mg elemental iron/ml drops', 'Doses are elemental iron'), ('syrup', 50, 5, '50 mg elemental iron/5 ml syrup', 'Doses are elemental iron'), ('tablet', 100, None, '100 mg elemental iron tablet', '')],
  dmin=1, dtyp=3, dmax=6, n=2, hours=12, max_daily=200, dur='3 months, continue 1-3 months after haemoglobin normalises', food='Between meals if tolerated; with vitamin C', se=['Constipation', 'Dark stools', 'Nausea', 'Teeth staining (liquids)'], ci=['Haemochromatosis', 'Haemolytic anaemia'],
  warn=['Doses are ELEMENTAL iron', 'Keep out of reach: iron overdose is dangerous in children'], inter=['Antacids', 'Calcium', 'Tetracyclines', 'Levothyroxine'], notes='Therapeutic 3 mg/kg/day elemental iron in 1-2 doses (prophylaxis 1-2 mg/kg/day).')

D('folic-acid', 'Folic Acid', ['folate', 'folvite', 'folic acid 5 mg'], 'folic acid', 'Vitamin', 'Folate deficiency, haemolytic anaemia, supplementation', ['Folate deficiency anaemia', 'Haemolytic anaemia', 'Methotrexate rescue (specialist)'], 'band',
  [('tablet', 5, None, '5 mg tablet', 'A 1 mg dose is one-fifth of a tablet: use a liquid / 1 mg product'), ('syrup', 1, 5, '1 mg/5 ml syrup', '')],
  n=1, hours=24, dur='4 months (deficiency); ongoing for haemolytic anaemia', bands=[dict(min_age_m=0, max_age_m=11, dose=0.5, n=1), dict(min_age_m=12, max_age_m=216, dose=1, n=1)],
  se=['Rare: nausea, bloating'], ci=['Untreated vitamin B12 deficiency'], warn=['Therapeutic doses for megaloblastic anaemia are higher (5 mg/day); the figures shown are supplementation doses'], notes='0.5 mg/day under 1 year; 1 mg/day above (supplementation).')

# ───────────────────────── Antivirals / antimalarials ─────────────────────────
D('oseltamivir', 'Oseltamivir', ['tamiflu', 'fluvir', 'antiflu'], 'oseltamivir', 'Antiviral', 'Influenza treatment and prophylaxis', ['Influenza (treatment)', 'Influenza (post-exposure prophylaxis)'], 'band',
  [('capsule', 30, None, '30 mg capsule', ''), ('capsule', 45, None, '45 mg capsule', ''), ('capsule', 75, None, '75 mg capsule', ''), ('syrup', 30, 5, '30 mg/5 ml (6 mg/ml) suspension', '')],
  n=2, hours=12, min_age=0, dur='5 days (start within 48 hours)', renal='Halve the dose if GFR 10-30',
  bands=[dict(max_wt=15, dose=30, n=2), dict(min_wt=15.01, max_wt=23, dose=45, n=2), dict(min_wt=23.01, max_wt=40, dose=60, n=2), dict(min_wt=40.01, dose=75, n=2)],
  se=['Nausea', 'Vomiting', 'Neuropsychiatric events (rare)'], ci=['Hypersensitivity'], warn=['Doses are by weight; under 1 year the dose is 3 mg/kg twice daily'], inter=['Live attenuated influenza vaccine'], notes='By weight: 15 kg or less 30 mg; 15-23 kg 45 mg; 23-40 kg 60 mg; over 40 kg 75 mg, twice daily for 5 days.')

D('acyclovir', 'Acyclovir', ['aciclovir', 'zovirax', 'herpex'], 'acyclovir', 'Antiviral', 'Chickenpox, herpes simplex, herpes zoster', ['Chickenpox (varicella)', 'Herpes simplex gingivostomatitis', 'Herpes zoster'], 'per_kg_dose',
  [('syrup', 200, 5, '200 mg/5 ml suspension', ''), ('tablet', 200, None, '200 mg tablet', ''), ('tablet', 400, None, '400 mg tablet', ''), ('tablet', 800, None, '800 mg tablet', '')],
  dmin=10, dtyp=20, dmax=20, n=4, hours=6, max_single=800, min_age=24, dur='5 days (start within 24 hours of the rash)', renal='Reduce dose if GFR < 50', food='Give with plenty of fluids', se=['Nausea', 'Headache', 'Renal impairment if dehydrated'], ci=['Hypersensitivity'],
  warn=['Keep well hydrated'], notes='Chickenpox: 20 mg/kg/dose four times a day (max 800 mg/dose).')

D('artemether-lumefantrine', 'Artemether + Lumefantrine', ['coartem', 'lumerax', 'larinate'], 'artemether+lumefantrine', 'Antimalarial', 'Uncomplicated falciparum malaria', ['Uncomplicated P. falciparum malaria'], 'band',
  [('tablet', 20, None, '20/120 mg tablet', 'Dose by the artemether component', 140), ('dispersible', 20, None, '20/120 mg dispersible tablet', 'Dose by the artemether component', 140)],
  n=2, hours=12, min_wt=5, dur='3 days', food='Give with fatty food or milk', bands=[dict(min_wt=5, max_wt=14.99, dose=20, n=2), dict(min_wt=15, max_wt=24.99, dose=40, n=2), dict(min_wt=25, max_wt=34.99, dose=60, n=2), dict(min_wt=35, dose=80, n=2)],
  se=['Headache', 'Dizziness', 'Nausea', 'QT prolongation'], ci=['Weight < 5 kg', 'Known QT prolongation'], warn=['Take with fatty food', 'Test for malaria before treating'], inter=['QT-prolonging drugs', 'CYP3A4 inducers / inhibitors'],
  notes='By weight: 5-14 kg 1 tablet; 15-24 kg 2; 25-34 kg 3; 35 kg and above 4, twice daily for 3 days.')


# ───────────────────────── writers ─────────────────────────
def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, bool):
        return '1' if v else '0'
    if isinstance(v, (int, float)):
        return repr(v) if isinstance(v, float) else str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


def j(v):
    return q(json.dumps(v, ensure_ascii=False)) if v else 'NULL'


def main():
    slugs = [d['slug'] for d in drugs]
    assert len(slugs) == len(set(slugs)), 'duplicate slug'
    os.makedirs(os.path.join(ROOT, 'migrations', 'data'), exist_ok=True)
    with open(os.path.join(ROOT, 'migrations', 'data', 'pediatric_drugs.json'), 'w') as f:
        json.dump(drugs, f, indent=1, ensure_ascii=False)

    out = ['-- GENERATED by migrations/tools/pediatric_seed.py - edit that file, not this one.',
           '-- Pediatric dosing reference (%d drugs). Idempotent: re-running updates the drugs and rebuilds their formulations and aliases.' % len(drugs), '']
    cols = ['slug', 'name', 'components', 'category', 'route', 'indication', 'all_indications', 'dose_unit', 'basis', 'dose_min', 'dose_typ', 'dose_max',
            'doses_per_day', 'frequency_hours', 'max_doses_per_day', 'max_single', 'max_daily', 'min_age_months', 'max_age_months', 'min_weight_kg', 'bands',
            'duration', 'renal_note', 'hepatic_note', 'neonatal_note', 'food_note', 'side_effects', 'contraindications', 'warnings', 'interactions', 'brands',
            'reference', 'notes', 'verified']
    for d in drugs:
        vals = []
        for c in cols:
            v = d.get(c) if c not in ('all_indications', 'side_effects', 'contraindications', 'warnings', 'interactions', 'brands', 'bands') else d[c]
            if c in ('all_indications', 'side_effects', 'contraindications', 'warnings', 'interactions', 'brands', 'bands'):
                vals.append(j(v))
            else:
                vals.append(q(v if v != '' else None) if c in ('renal_note', 'hepatic_note', 'neonatal_note', 'food_note', 'notes', 'duration') else q(v))
        upd = ', '.join('`%s` = VALUES(`%s`)' % (c, c) for c in cols if c != 'slug') + ', `is_active` = 1'
        out.append('INSERT INTO `pediatric_drugs` (%s) VALUES (%s)\n  ON DUPLICATE KEY UPDATE %s;' % (', '.join('`%s`' % c for c in cols), ', '.join(vals), upd))
        out.append("SET @d := (SELECT id FROM `pediatric_drugs` WHERE slug = %s);" % q(d['slug']))
        out.append('DELETE FROM `pediatric_drug_formulations` WHERE drug_id = @d;')
        for i, f in enumerate(d['formulations']):
            out.append("INSERT INTO `pediatric_drug_formulations` (drug_id, form_type, strength, per_volume_ml, strength_total, label, note, sort_order) VALUES (@d, %s, %s, %s, %s, %s, %s, %d);" % (
                q(f['form_type']), q(f['strength']), q(f['per_volume_ml']), q(f['strength_total']), q(f['label']), q(f['note'] or None), i))
        out.append('DELETE FROM `pediatric_drug_aliases` WHERE drug_id = @d;')
        names = [d['name']] + d['aliases'] + d['brands']
        seen = set()
        for a in names:
            k = a.lower().strip()
            if k and k not in seen:
                seen.add(k)
                out.append("INSERT INTO `pediatric_drug_aliases` (drug_id, alias) VALUES (@d, %s);" % q(k))
        out.append('')
    with open(os.path.join(ROOT, 'migrations', 'sql', '017_seed_pediatric_drugs.sql'), 'w') as f:
        f.write('\n'.join(out))
    print('drugs:', len(drugs), 'formulations:', sum(len(d['formulations']) for d in drugs))


if __name__ == '__main__':
    main()
