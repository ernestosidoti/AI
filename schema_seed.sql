-- ============================================================
-- SEED DATI INIZIALI
-- ============================================================
USE ai_laboratory;

-- ============================================================
-- 1. CATALOGO PRODOTTI (allineato a backoffice.prodotti)
-- ============================================================
INSERT INTO products_catalog (code, label, backoffice_product_id, icon, description, display_order) VALUES
('energia',        'Liste Energia (POD luce)',          1,  'bolt',        'Clienti luce residenziali e business. POD elettrico.', 1),
('fotovoltaico',   'Liste Fotovoltaico',                2,  'sun',         'Case unifamiliari con POD residenziale. Esclude capoluoghi e cintura grandi città.', 2),
('depurazione',    'Liste Depurazione acqua',           3,  'droplet',     'Famiglie residenziali. Esclude stranieri (CF con codice ZXXX).', 3),
('telefonia',      'Liste Telefonia',                   4,  'phone',       'Contatti con cellulare valido.', 4),
('cessione_quinto','Liste Cessione del Quinto',         5,  'credit-card', 'Pensionati e dipendenti con reddito stabile.', 5),
('finanziarie',    'Liste Finanziarie',                 6,  'banknote',    'Profili per finanziamenti.', 6),
('generiche',      'Liste Generiche (altre categorie)', 7,  'list',        'Liste miscellanea.', 7),
('alimentari',     'Liste Alimentari',                  8,  'shopping-bag','Famiglie per settore alimentare.', 8),
('immobiliari',    'Liste Immobiliari',                 9,  'home',        'Proprietari immobili (POD con indirizzo valido).', 9),
('cosmetica',      'Liste Cosmetica',                   10, 'sparkles',    'Target femminile, età media.', 10),
('lead_voip',      'Lead Generation / VOIP',            11, 'headphones',  'Campagne lead per call center.', 11),
('gdpr',           'Liste GDPR compliant',              12, 'shield',      'Contatti con consenso GDPR.', 12),
('digital_mkt',    'Digital Marketing',                 13, 'sparkles',    'Target per campagne digitali.', 13),
('ricariche',      'Ricariche crediti TrovaCF',         14, 'refresh',     'Top-up sistema TrovaCodiceFiscale.', 14);

-- ============================================================
-- 2. METADATA DELLE 11 FONTI DATI
-- Priorità: 1 = preferita, 10 = ultima scelta
-- ============================================================
INSERT INTO db_metadata (source_id, label, database_name, table_name, tipo_principale, prodotti_adatti, anno, records_count, priorita, description) VALUES

-- LUCE / RESIDENZIALE
('edicus2023', 'Edicus 2023 (SuperPOD)', 'Edicus_2023_marzo', 'superpod_2023',
    'residenziale',
    JSON_ARRAY('energia','fotovoltaico','depurazione','telefonia','cessione_quinto','finanziarie','alimentari','immobiliari','cosmetica','generiche'),
    2023, 5441194, 1,
    'Fonte più aggiornata per luce residenziale. Ha data attivazione, potenza, anno nascita, sesso. Ideale per fotovoltaico (case unifamiliari) e tutte le campagne residenziali.'),

('libero_2020', 'Libero 2020', 'LIBERO_2020', 'ML_POD_2020',
    'residenziale',
    JSON_ARRAY('energia','fotovoltaico','depurazione','telefonia','cessione_quinto','alimentari','immobiliari','cosmetica','generiche'),
    2020, 24336476, 2,
    '24M record mercato libero. Buona copertura telefoni mobili e fissi.'),

('elettrico_mac', 'Elettrico MAC', 'elettrico_mac', 'a',
    'residenziale',
    JSON_ARRAY('energia','fotovoltaico','depurazione','telefonia','cessione_quinto','alimentari','generiche'),
    2020, 23534798, 3,
    'Database elettrico 23M record, qualità alta.'),

('edicus2021lug', 'Edicus 2021 Luglio', 'Edicus2021_luglio', 'SUPERPOD',
    'residenziale',
    JSON_ARRAY('energia','fotovoltaico','depurazione','cessione_quinto','generiche'),
    2021, 24628423, 4,
    'Storico 24M, nome cliente in campo unico.'),

('edicus2021ago', 'Edicus 2021 Agosto', 'edicus_2021_agosto', 'ML_POD_2021',
    'residenziale',
    JSON_ARRAY('energia','fotovoltaico','depurazione','generiche'),
    2021, 1468069, 5,
    'ML POD agosto 2021.'),

('sen_2021', 'SEN Italia 2021', 'ese_2022', 'SEN_ITALIA_2021_TOTALE_copy1',
    'residenziale',
    JSON_ARRAY('energia','depurazione','generiche'),
    2021, 6774453, 6,
    'SEN Italia con intestatario fattura.'),

('tot_ml2019', 'Totale ML 2019', 'edicus_totale', 'TOT_ML2019',
    'residenziale',
    JSON_ARRAY('energia','depurazione','generiche'),
    2019, 13665941, 7,
    'Storico 2019 (più vecchio). Da usare in ultima istanza.'),

-- BUSINESS
('business2025', 'Business 2025', 'BUSINESS2025', 'business',
    'business',
    JSON_ARRAY('energia','telefonia','lead_voip','gdpr','digital_mkt','generiche'),
    2025, 50408, 1,
    'Solo aziende 50K con P.IVA, ragione sociale, trader.'),

-- GAS
('altri_usi_2020', 'Altri Usi 2020', 'altri_usi_2020', 'a',
    'misto',
    JSON_ARRAY('energia','generiche','alimentari'),
    2020, 5876208, 3,
    'Gas altri usi: bar, negozi, attività commerciali.'),

('gas2023', 'Gas 2023', 'Edicus_2023_marzo', 'gas',
    'gas',
    JSON_ARRAY('energia','depurazione','generiche'),
    2023, 948682, 2,
    'Gas 2023 con PDR, società distribuzione, codice REMI.'),

('pdr2024', 'PDR 2024 (più recente)', 'Edicus_2024_maggio', 'pdr_unificata',
    'gas',
    JSON_ARRAY('energia','depurazione','generiche'),
    2024, 1229474, 1,
    'DB gas più recente con storico trader (cedente/richiedente) e data decorrenza switch.');

-- ============================================================
-- 3. REGOLE PRODOTTO
-- ============================================================

-- FOTOVOLTAICO: no capoluoghi, no cintura grandi città
INSERT INTO product_rules (product_code, rule_type, rule_name, description, rule_sql, priority) VALUES
('fotovoltaico', 'exclude', 'Escludi capoluoghi di provincia',
 'Nei capoluoghi ci sono tanti condomini, il fotovoltaico non è applicabile. Escludi tutti i capoluoghi (Milano, Roma, Torino, ecc.).',
 "UPPER(TRIM(localita)) NOT IN (SELECT UPPER(city_name) FROM ai_laboratory.city_exclusions WHERE list_code = 'capoluoghi_provincia' AND active = 1)",
 1),

('fotovoltaico', 'exclude', 'Escludi cintura grandi città',
 'Nei comuni di cintura di Milano, Roma, Napoli, Torino, Bologna ci sono molti condomini. Escluderli migliora il target.',
 "UPPER(TRIM(localita)) NOT IN (SELECT UPPER(city_name) FROM ai_laboratory.city_exclusions WHERE list_code IN ('cintura_milano','cintura_roma','cintura_napoli','cintura_torino','cintura_bologna') AND active = 1)",
 2),

-- DEPURAZIONE: no stranieri (CF con codice comune Z = estero)
('depurazione', 'exclude', 'Escludi cittadini stranieri',
 'I CF italiani hanno nelle posizioni 12-15 il codice del comune di nascita. Gli stranieri nati all''estero hanno codice che inizia con Z. Esclude solo i CF persona fisica (16 caratteri).',
 "(LENGTH(codice_fiscale) = 16 AND SUBSTRING(codice_fiscale, 12, 1) != 'Z')",
 1),

-- ENERGIA GENERICA: nessuna regola speciale (solo dati puliti)
('energia', 'note', 'Nessuna esclusione particolare',
 'Per le liste energia generiche usa tutto ciò che ha POD valido e contatto (mobile o fisso).',
 NULL, 1),

-- IMMOBILIARI: stesso filtro fotovoltaico per le grandi città (condomini)
('immobiliari', 'exclude', 'Escludi capoluoghi (meno proprietari esclusivi)',
 'Nei capoluoghi la maggior parte delle utenze è in condominio o affitto. Meglio comuni medi per chi cerca proprietari.',
 "UPPER(TRIM(localita)) NOT IN (SELECT UPPER(city_name) FROM ai_laboratory.city_exclusions WHERE list_code = 'capoluoghi_provincia' AND active = 1)",
 1),

-- CESSIONE DEL QUINTO: filtro età (pensionati e dipendenti)
('cessione_quinto', 'include', 'Età utile (45-75 anni)',
 'Target principale: dipendenti statali/privati (45-65) e pensionati (65-75). Usa anno nascita da CF o campo anno.',
 NULL, 1),

-- COSMETICA: solo donne
('cosmetica', 'include', 'Solo donne',
 'Target femminile. Nel CF, il giorno di nascita delle donne è > 40 (posizione 10-11).',
 "(LENGTH(codice_fiscale) = 16 AND CAST(SUBSTRING(codice_fiscale, 10, 2) AS UNSIGNED) > 40)",
 1);

-- ============================================================
-- 4. CITY EXCLUSIONS
-- ============================================================

-- Capoluoghi di provincia italiani (lista completa 107)
INSERT INTO city_exclusions (list_code, city_name, province) VALUES
('capoluoghi_provincia', 'AGRIGENTO', 'AG'),
('capoluoghi_provincia', 'ALESSANDRIA', 'AL'),
('capoluoghi_provincia', 'ANCONA', 'AN'),
('capoluoghi_provincia', 'AOSTA', 'AO'),
('capoluoghi_provincia', 'AREZZO', 'AR'),
('capoluoghi_provincia', 'ASCOLI PICENO', 'AP'),
('capoluoghi_provincia', 'ASTI', 'AT'),
('capoluoghi_provincia', 'AVELLINO', 'AV'),
('capoluoghi_provincia', 'BARI', 'BA'),
('capoluoghi_provincia', 'BARLETTA', 'BT'),
('capoluoghi_provincia', 'BELLUNO', 'BL'),
('capoluoghi_provincia', 'BENEVENTO', 'BN'),
('capoluoghi_provincia', 'BERGAMO', 'BG'),
('capoluoghi_provincia', 'BIELLA', 'BI'),
('capoluoghi_provincia', 'BOLOGNA', 'BO'),
('capoluoghi_provincia', 'BOLZANO', 'BZ'),
('capoluoghi_provincia', 'BRESCIA', 'BS'),
('capoluoghi_provincia', 'BRINDISI', 'BR'),
('capoluoghi_provincia', 'CAGLIARI', 'CA'),
('capoluoghi_provincia', 'CALTANISSETTA', 'CL'),
('capoluoghi_provincia', 'CAMPOBASSO', 'CB'),
('capoluoghi_provincia', 'CARBONIA', 'SU'),
('capoluoghi_provincia', 'CASERTA', 'CE'),
('capoluoghi_provincia', 'CATANIA', 'CT'),
('capoluoghi_provincia', 'CATANZARO', 'CZ'),
('capoluoghi_provincia', 'CHIETI', 'CH'),
('capoluoghi_provincia', 'COMO', 'CO'),
('capoluoghi_provincia', 'COSENZA', 'CS'),
('capoluoghi_provincia', 'CREMONA', 'CR'),
('capoluoghi_provincia', 'CROTONE', 'KR'),
('capoluoghi_provincia', 'CUNEO', 'CN'),
('capoluoghi_provincia', 'ENNA', 'EN'),
('capoluoghi_provincia', 'FERMO', 'FM'),
('capoluoghi_provincia', 'FERRARA', 'FE'),
('capoluoghi_provincia', 'FIRENZE', 'FI'),
('capoluoghi_provincia', 'FOGGIA', 'FG'),
('capoluoghi_provincia', 'FORLI', 'FC'),
('capoluoghi_provincia', "FORLI'", 'FC'),
('capoluoghi_provincia', 'FROSINONE', 'FR'),
('capoluoghi_provincia', 'GENOVA', 'GE'),
('capoluoghi_provincia', 'GORIZIA', 'GO'),
('capoluoghi_provincia', 'GROSSETO', 'GR'),
('capoluoghi_provincia', 'IGLESIAS', 'SU'),
('capoluoghi_provincia', 'IMPERIA', 'IM'),
('capoluoghi_provincia', 'ISERNIA', 'IS'),
('capoluoghi_provincia', "L'AQUILA", 'AQ'),
('capoluoghi_provincia', 'LA SPEZIA', 'SP'),
('capoluoghi_provincia', 'LATINA', 'LT'),
('capoluoghi_provincia', 'LECCE', 'LE'),
('capoluoghi_provincia', 'LECCO', 'LC'),
('capoluoghi_provincia', 'LIVORNO', 'LI'),
('capoluoghi_provincia', 'LODI', 'LO'),
('capoluoghi_provincia', 'LUCCA', 'LU'),
('capoluoghi_provincia', 'MACERATA', 'MC'),
('capoluoghi_provincia', 'MANTOVA', 'MN'),
('capoluoghi_provincia', 'MASSA', 'MS'),
('capoluoghi_provincia', 'MATERA', 'MT'),
('capoluoghi_provincia', 'MESSINA', 'ME'),
('capoluoghi_provincia', 'MILANO', 'MI'),
('capoluoghi_provincia', 'MODENA', 'MO'),
('capoluoghi_provincia', 'MONZA', 'MB'),
('capoluoghi_provincia', 'NAPOLI', 'NA'),
('capoluoghi_provincia', 'NOVARA', 'NO'),
('capoluoghi_provincia', 'NUORO', 'NU'),
('capoluoghi_provincia', 'ORISTANO', 'OR'),
('capoluoghi_provincia', 'PADOVA', 'PD'),
('capoluoghi_provincia', 'PALERMO', 'PA'),
('capoluoghi_provincia', 'PARMA', 'PR'),
('capoluoghi_provincia', 'PAVIA', 'PV'),
('capoluoghi_provincia', 'PERUGIA', 'PG'),
('capoluoghi_provincia', 'PESARO', 'PU'),
('capoluoghi_provincia', 'PESCARA', 'PE'),
('capoluoghi_provincia', 'PIACENZA', 'PC'),
('capoluoghi_provincia', 'PISA', 'PI'),
('capoluoghi_provincia', 'PISTOIA', 'PT'),
('capoluoghi_provincia', 'PORDENONE', 'PN'),
('capoluoghi_provincia', 'POTENZA', 'PZ'),
('capoluoghi_provincia', 'PRATO', 'PO'),
('capoluoghi_provincia', 'RAGUSA', 'RG'),
('capoluoghi_provincia', 'RAVENNA', 'RA'),
('capoluoghi_provincia', 'REGGIO CALABRIA', 'RC'),
('capoluoghi_provincia', 'REGGIO EMILIA', 'RE'),
('capoluoghi_provincia', 'RIETI', 'RI'),
('capoluoghi_provincia', 'RIMINI', 'RN'),
('capoluoghi_provincia', 'ROMA', 'RM'),
('capoluoghi_provincia', 'ROVIGO', 'RO'),
('capoluoghi_provincia', 'SALERNO', 'SA'),
('capoluoghi_provincia', 'SASSARI', 'SS'),
('capoluoghi_provincia', 'SAVONA', 'SV'),
('capoluoghi_provincia', 'SIENA', 'SI'),
('capoluoghi_provincia', 'SIRACUSA', 'SR'),
('capoluoghi_provincia', 'SONDRIO', 'SO'),
('capoluoghi_provincia', 'TARANTO', 'TA'),
('capoluoghi_provincia', 'TERAMO', 'TE'),
('capoluoghi_provincia', 'TERNI', 'TR'),
('capoluoghi_provincia', 'TORINO', 'TO'),
('capoluoghi_provincia', 'TRAPANI', 'TP'),
('capoluoghi_provincia', 'TRENTO', 'TN'),
('capoluoghi_provincia', 'TREVISO', 'TV'),
('capoluoghi_provincia', 'TRIESTE', 'TS'),
('capoluoghi_provincia', 'UDINE', 'UD'),
('capoluoghi_provincia', 'VARESE', 'VA'),
('capoluoghi_provincia', 'VENEZIA', 'VE'),
('capoluoghi_provincia', 'VERBANIA', 'VB'),
('capoluoghi_provincia', 'VERCELLI', 'VC'),
('capoluoghi_provincia', 'VERONA', 'VR'),
('capoluoghi_provincia', 'VIBO VALENTIA', 'VV'),
('capoluoghi_provincia', 'VICENZA', 'VI'),
('capoluoghi_provincia', 'VITERBO', 'VT');

-- Cintura Milano (primo hinterland con alta densità condomini)
INSERT INTO city_exclusions (list_code, city_name, province) VALUES
('cintura_milano', 'SESTO SAN GIOVANNI', 'MI'),
('cintura_milano', 'CINISELLO BALSAMO', 'MI'),
('cintura_milano', 'COLOGNO MONZESE', 'MI'),
('cintura_milano', 'RHO', 'MI'),
('cintura_milano', 'PADERNO DUGNANO', 'MI'),
('cintura_milano', 'SAN DONATO MILANESE', 'MI'),
('cintura_milano', 'SAN GIULIANO MILANESE', 'MI'),
('cintura_milano', 'BUCCINASCO', 'MI'),
('cintura_milano', 'CORSICO', 'MI'),
('cintura_milano', 'ROZZANO', 'MI'),
('cintura_milano', 'OPERA', 'MI'),
('cintura_milano', 'CUSANO MILANINO', 'MI'),
('cintura_milano', 'BRESSO', 'MI'),
('cintura_milano', 'PIOLTELLO', 'MI'),
('cintura_milano', 'SEGRATE', 'MI'),
('cintura_milano', 'PERO', 'MI'),
('cintura_milano', 'TREZZANO SUL NAVIGLIO', 'MI'),
('cintura_milano', 'ASSAGO', 'MI'),
('cintura_milano', 'PESCHIERA BORROMEO', 'MI');

-- Cintura Roma
INSERT INTO city_exclusions (list_code, city_name, province) VALUES
('cintura_roma', 'FIUMICINO', 'RM'),
('cintura_roma', 'GUIDONIA MONTECELIO', 'RM'),
('cintura_roma', 'POMEZIA', 'RM'),
('cintura_roma', 'TIVOLI', 'RM'),
('cintura_roma', 'ANZIO', 'RM'),
('cintura_roma', 'CIAMPINO', 'RM'),
('cintura_roma', 'MARINO', 'RM'),
('cintura_roma', 'ALBANO LAZIALE', 'RM'),
('cintura_roma', 'NETTUNO', 'RM'),
('cintura_roma', 'VELLETRI', 'RM'),
('cintura_roma', 'CIVITAVECCHIA', 'RM'),
('cintura_roma', 'LADISPOLI', 'RM'),
('cintura_roma', 'MONTEROTONDO', 'RM');

-- Cintura Napoli
INSERT INTO city_exclusions (list_code, city_name, province) VALUES
('cintura_napoli', 'CASORIA', 'NA'),
('cintura_napoli', 'GIUGLIANO IN CAMPANIA', 'NA'),
('cintura_napoli', 'AFRAGOLA', 'NA'),
('cintura_napoli', 'TORRE DEL GRECO', 'NA'),
('cintura_napoli', 'POZZUOLI', 'NA'),
('cintura_napoli', 'ERCOLANO', 'NA'),
('cintura_napoli', 'PORTICI', 'NA'),
('cintura_napoli', 'CASALNUOVO DI NAPOLI', 'NA'),
('cintura_napoli', 'MARANO DI NAPOLI', 'NA'),
('cintura_napoli', 'ACERRA', 'NA'),
('cintura_napoli', 'CASTELLAMMARE DI STABIA', 'NA'),
('cintura_napoli', 'TORRE ANNUNZIATA', 'NA'),
('cintura_napoli', 'SAN GIORGIO A CREMANO', 'NA');

-- Cintura Torino
INSERT INTO city_exclusions (list_code, city_name, province) VALUES
('cintura_torino', 'MONCALIERI', 'TO'),
('cintura_torino', 'COLLEGNO', 'TO'),
('cintura_torino', 'RIVOLI', 'TO'),
('cintura_torino', 'NICHELINO', 'TO'),
('cintura_torino', 'SETTIMO TORINESE', 'TO'),
('cintura_torino', 'GRUGLIASCO', 'TO'),
('cintura_torino', 'VENARIA REALE', 'TO'),
('cintura_torino', 'CHIERI', 'TO'),
('cintura_torino', 'CARMAGNOLA', 'TO'),
('cintura_torino', 'ORBASSANO', 'TO'),
('cintura_torino', 'BEINASCO', 'TO'),
('cintura_torino', 'RIVALTA DI TORINO', 'TO');

-- Cintura Bologna
INSERT INTO city_exclusions (list_code, city_name, province) VALUES
('cintura_bologna', 'CASALECCHIO DI RENO', 'BO'),
('cintura_bologna', 'IMOLA', 'BO'),
('cintura_bologna', 'SAN LAZZARO DI SAVENA', 'BO'),
('cintura_bologna', 'CASTENASO', 'BO'),
('cintura_bologna', 'PIANORO', 'BO'),
('cintura_bologna', 'ZOLA PREDOSA', 'BO'),
('cintura_bologna', 'ANZOLA DELL''EMILIA', 'BO'),
('cintura_bologna', 'CASTEL MAGGIORE', 'BO'),
('cintura_bologna', 'GRANAROLO DELL''EMILIA', 'BO');
