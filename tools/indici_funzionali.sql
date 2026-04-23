-- ============================================================
-- INDICI FUNZIONALI per velocizzare le query generate da Claude
-- ============================================================
-- Lanciare UNO ALLA VOLTA su Navicat, preferibilmente di notte.
-- Ogni ALTER può richiedere 5-30 minuti a seconda della dimensione della tabella.
-- ============================================================

-- =====  EDICUS_2023_MARZO.SUPERPOD_2023 (5.4M record)  =====
USE Edicus_2023_marzo;

-- Per UPPER(TRIM(localita)) IN (...)
ALTER TABLE superpod_2023 ADD INDEX idx_localita_upper ((UPPER(TRIM(localita))));

-- Per STR_TO_DATE(data_attivazione, '%Y-%m-%d')
ALTER TABLE superpod_2023 ADD INDEX idx_data_att_norm ((STR_TO_DATE(data_attivazione, '%Y-%m-%d')));

-- Per trader (filtri tipo NOT LIKE '%ENEL%')
ALTER TABLE superpod_2023 ADD INDEX idx_trader (trader(50));


-- =====  EDICUS2021_LUGLIO.SUPERPOD (24M record — lento, 30-60 min)  =====
USE Edicus2021_luglio;

ALTER TABLE SUPERPOD ADD INDEX idx_localita_upper ((UPPER(TRIM(Localita))));


-- =====  EDICUS_2021_AGOSTO.ML_POD_2021 (1.5M record)  =====
USE edicus_2021_agosto;

ALTER TABLE ML_POD_2021 ADD INDEX idx_localita_upper ((UPPER(TRIM(Localita))));
ALTER TABLE ML_POD_2021 ADD INDEX idx_provincia (PROVINCIA);
ALTER TABLE ML_POD_2021 ADD INDEX idx_trader (Trader(50));


-- =====  EDICUS_TOTALE.TOT_ML2019 (13.7M record — lento, 30 min)  =====
USE edicus_totale;

ALTER TABLE TOT_ML2019 ADD INDEX idx_localita_upper ((UPPER(TRIM(Localita))));
ALTER TABLE TOT_ML2019 ADD INDEX idx_provincia (provincia);
ALTER TABLE TOT_ML2019 ADD INDEX idx_trader (Trader(50));


-- =====  ELETTRICO_MAC.A (23.5M record — lento, 40-60 min)  =====
USE elettrico_mac;

ALTER TABLE a ADD INDEX idx_localita_upper ((UPPER(TRIM(Localita))));
ALTER TABLE a ADD INDEX idx_trader (Trader(50));


-- =====  LIBERO_2020.ML_POD_2020 (24M record — lento, 40-60 min)  =====
USE LIBERO_2020;

-- idx_localita già discussso, ma funzionale (upper/trim) per velocità massima
ALTER TABLE ML_POD_2020 ADD INDEX idx_localita_upper ((UPPER(TRIM(Localita))));


-- =====  ESE_2022.SEN_ITALIA_2021_TOTALE_copy1 (6.8M record)  =====
USE ese_2022;

ALTER TABLE SEN_ITALIA_2021_TOTALE_copy1
    ADD INDEX idx_localita_upper ((UPPER(TRIM(FORNIAMO_ENERGIA_LOCALITA))));
ALTER TABLE SEN_ITALIA_2021_TOTALE_copy1 ADD INDEX idx_provincia (pronvincia);


-- =====  ALTRI_USI_2020.A (5.9M record)  =====
USE altri_usi_2020;

ALTER TABLE a ADD INDEX idx_localita_upper ((UPPER(TRIM(localita))));
ALTER TABLE a ADD INDEX idx_provincia (PROVINCIA);
ALTER TABLE a ADD INDEX idx_trader (Trader(50));


-- =====  BUSINESS2025.BUSINESS (50K record — velocissimo, 30 sec)  =====
USE BUSINESS2025;

ALTER TABLE business ADD INDEX idx_citta_upper ((UPPER(TRIM(CITTA))));
ALTER TABLE business ADD INDEX idx_provincia (PROVINCIA);
ALTER TABLE business ADD INDEX idx_trader (TRADER(50));


-- =====  EDICUS_2023_MARZO.GAS (948K record)  =====
USE Edicus_2023_marzo;

ALTER TABLE gas ADD INDEX idx_comune_upper ((UPPER(TRIM(Comune))));
-- Provincia/Regione già indicizzate


-- =====  EDICUS_2024_MAGGIO.PDR_UNIFICATA (1.2M record)  =====
USE Edicus_2024_maggio;

ALTER TABLE pdr_unificata ADD INDEX idx_localita_upper ((UPPER(TRIM(localita))));
ALTER TABLE pdr_unificata ADD INDEX idx_provincia (provincia);
ALTER TABLE pdr_unificata ADD INDEX idx_regione (regione);
ALTER TABLE pdr_unificata ADD INDEX idx_trader (societa_vendita_richiedente(50));
ALTER TABLE pdr_unificata ADD INDEX idx_data_decorrenza ((STR_TO_DATE(data_decorrenza, '%d/%m/%Y')));
