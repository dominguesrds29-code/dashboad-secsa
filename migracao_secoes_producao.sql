-- ======================================================================
-- MIGRAÇÃO DE SEÇÕES E ESCALAS DO EFETIVO PARA PRODUÇÃO (efetivosj)
-- Executar no banco de dados: efetivosj
-- 100% Compatível com SARAMs reais do banco, novos SARAMs e Nomes
-- ======================================================================

-- 1. INFORMÁTICA / SSTI (section_id = 15, escala = 0)
UPDATE users SET section_id = 15, escala = 0, war_name = 'DOMINGUES' WHERE saram LIKE '%393068%' OR saram LIKE '%393.068%' OR name LIKE '%RENATO DOMINGUES%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'PEREIRA' WHERE saram LIKE '%422055%' OR saram LIKE '%422.055%' OR name LIKE '%FERNANDO BARBOSA PEREIRA%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'WINNIE' WHERE saram LIKE '%615886%' OR saram LIKE '%615.886%' OR saram LIKE '%609071%' OR name LIKE '%GABRIELA WINNIE%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'LIMA' WHERE saram LIKE '%729501%' OR saram LIKE '%729.501%' OR saram LIKE '%689622%' OR name LIKE '%MARCOS VINICIUS LIMA%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'CÂNDIDO' WHERE saram LIKE '%770267%' OR saram LIKE '%770.267%' OR saram LIKE '%711314%' OR name LIKE '%GUSTAVO HENRIQUE C%NDIDO%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'SILVA' WHERE saram LIKE '%770268%' OR saram LIKE '%770.268%' OR saram LIKE '%711333%' OR name LIKE '%VICTOR LUIZ LOPES%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'LINO' WHERE saram LIKE '%770276%' OR saram LIKE '%770.276%' OR saram LIKE '%711342%' OR name LIKE '%KAYKY ESDRAS%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'CARVALHO' WHERE saram LIKE '%770279%' OR saram LIKE '%770.279%' OR saram LIKE '%711345%' OR name LIKE '%MATHEUS VIEIRA DE CARVALHO%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'SOARES' WHERE saram LIKE '%174415%' OR saram LIKE '%174.415%' OR name LIKE '%MILTON GON%ALVES SOARES%';
UPDATE users SET section_id = 15, escala = 0, war_name = 'ROMACHO' WHERE saram LIKE '%174246%' OR saram LIKE '%174.246%' OR name LIKE '%CLAUDIONOR DE SOUZA ROMACHO%';

-- 2. SECRETARIA ADMINISTRATIVA (section_id = 4, escala = 0)
UPDATE users SET section_id = 4, escala = 0, war_name = 'SANTOS' WHERE saram LIKE '%393029%' OR saram LIKE '%393.029%' OR saram LIKE '%406161%' OR name LIKE '%INGRID LAGO%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'ALMEIDA' WHERE saram LIKE '%615891%' OR saram LIKE '%615.891%' OR saram LIKE '%609072%' OR name LIKE '%LUCIMARA FERNANDES%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'FERRO' WHERE saram LIKE '%624032%' OR saram LIKE '%624.032%' OR name LIKE '%CAROLINA DE ALENCAR%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'SANTOS' WHERE saram LIKE '%666614%' OR saram LIKE '%666.614%' OR saram LIKE '%649065%' OR name LIKE '%ALESSANDRA SUZANE%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'SOUZA' WHERE saram LIKE '%666627%' OR saram LIKE '%666.627%' OR saram LIKE '%649066%' OR name LIKE '%JO%O PAULO DA SILVA SOUZA%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'ROCHA' WHERE saram LIKE '%690947%' OR saram LIKE '%690.947%' OR saram LIKE '%654762%' OR name LIKE '%SARAH PEREIRA%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'CORRÊA' WHERE saram LIKE '%729486%' OR saram LIKE '%729.486%' OR saram LIKE '%689620%' OR name LIKE '%PAULO EDUARDO CORR%A%';
UPDATE users SET section_id = 4, escala = 0, war_name = 'SANTOS' WHERE saram LIKE '%751996%' OR saram LIKE '%751.996%' OR saram LIKE '%704604%' OR name LIKE '%VITOR ELOI DE BORBONHA%';

-- 3. SECRETARIA OPERACIONAL (section_id = 9, escala = 0)
UPDATE users SET section_id = 9, escala = 0, war_name = 'TAÍS' WHERE saram LIKE '%420197%' OR saram LIKE '%420.197%' OR name LIKE '%TA%S RIBEIRO%';
UPDATE users SET section_id = 9, escala = 0, war_name = 'VICENTE' WHERE saram LIKE '%424013%' OR saram LIKE '%424.013%' OR name LIKE '%INGRID MARTINS VICENTE%';
UPDATE users SET section_id = 9, escala = 0, war_name = 'DILÉO' WHERE saram LIKE '%657653%' OR saram LIKE '%657.653%' OR saram LIKE '%633818%' OR name LIKE '%MARCELLE ALCANTARA%';
UPDATE users SET section_id = 9, escala = 0, war_name = 'COSTA' WHERE saram LIKE '%645396%' OR saram LIKE '%645.396%' OR name LIKE '%ANA CAROLINA POMPEO%';
UPDATE users SET section_id = 9, escala = 0, war_name = 'SILVA' WHERE saram LIKE '%690956%' OR saram LIKE '%690.956%' OR saram LIKE '%654877%' OR name LIKE '%LUCAS ROBERTO%';
UPDATE users SET section_id = 9, escala = 0, war_name = 'SILVA' WHERE saram LIKE '%770318%' OR saram LIKE '%770.318%' OR saram LIKE '%711320%' OR name LIKE '%JO%O GABRIEL THOMAZ%';

-- 4. ASSIPACEA (section_id = 3, escala = 0)
UPDATE users SET section_id = 3, escala = 0, war_name = 'PROENÇA' WHERE saram LIKE '%427932%' OR saram LIKE '%427.932%' OR saram LIKE '%437943%' OR name LIKE '%ERICA FREIRE%';
UPDATE users SET section_id = 3, escala = 0, war_name = 'SANTOS' WHERE saram LIKE '%440465%' OR saram LIKE '%440.465%' OR name LIKE '%JOS% CARLOS SOUSA%';
UPDATE users SET section_id = 3, escala = 0, war_name = 'RAMOS' WHERE saram LIKE '%440468%' OR saram LIKE '%440.468%' OR name LIKE '%KELLY CRISTINA BATALHA%';
UPDATE users SET section_id = 3, escala = 0, war_name = 'FRANCONERE' WHERE saram LIKE '%447814%' OR saram LIKE '%447.814%' OR name LIKE '%RENATO DE OLIVEIRA FRANCONERE%';
UPDATE users SET section_id = 3, escala = 0, war_name = 'VASCONCELOS' WHERE saram LIKE '%615878%' OR saram LIKE '%615.878%' OR saram LIKE '%609070%' OR name LIKE '%CAROLINE RUSSELL%';

-- 5. SIATO (section_id = 6, escala = 0)
UPDATE users SET section_id = 6, escala = 0, war_name = 'SOUZA' WHERE saram LIKE '%423802%' OR saram LIKE '%423.802%' OR name LIKE '%MUNIQUE CAROLINE%';
UPDATE users SET section_id = 6, escala = 0, war_name = 'PAULA' WHERE saram LIKE '%437945%' OR saram LIKE '%437.945%' OR name LIKE '%BEATRIZ LAIA%';

-- 6. ELETROMECÂNICA / SELM (section_id = 13, escala = 0)
UPDATE users SET section_id = 13, escala = 0, war_name = 'OLIVEIRA' WHERE saram LIKE '%438069%' OR saram LIKE '%438.069%' OR name LIKE '%BIANCA BEATRIZ%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'ROCHA' WHERE saram LIKE '%422056%' OR saram LIKE '%422.056%' OR name LIKE '%ALEX MESQUITA%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'BECKMANN' WHERE saram LIKE '%422057%' OR saram LIKE '%422.057%' OR name LIKE '%F%BIO DE SENE%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'PEREIRA' WHERE saram LIKE '%423610%' OR saram LIKE '%423.610%' OR name LIKE '%MICHELY ADRIANA%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'SILVA' WHERE saram LIKE '%440464%' OR saram LIKE '%440.464%' OR name LIKE '%GUILHERME RAMOS%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'OLIVEIRA' WHERE saram LIKE '%615858%' OR saram LIKE '%615.858%' OR saram LIKE '%608933%' OR name LIKE '%WELTON NOGUEIRA%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'RAMOS' WHERE saram LIKE '%624034%' OR saram LIKE '%624.034%' OR name LIKE '%ALEX CONDE%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'MENDES' WHERE saram LIKE '%657662%' OR saram LIKE '%657.662%' OR saram LIKE '%633820%' OR name LIKE '%MARRANI DE SOUZA%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'CARVALHO' WHERE saram LIKE '%690960%' OR saram LIKE '%690.960%' OR saram LIKE '%654884%' OR name LIKE '%ANA FL%VIA%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'PEREIRA' WHERE saram LIKE '%751998%' OR saram LIKE '%751.998%' OR saram LIKE '%704605%' OR name LIKE '%LUAN RIBEIRO%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'SILVA' WHERE saram LIKE '%770273%' OR saram LIKE '%770.273%' OR saram LIKE '%711339%' OR name LIKE '%LEONARDO MOREIRA%';
UPDATE users SET section_id = 13, escala = 0, war_name = 'ROSA' WHERE saram LIKE '%770278%' OR saram LIKE '%770.278%' OR saram LIKE '%711344%' OR name LIKE '%ENZO GABRIEL AZUMA%';

-- 7. SUPRIMENTO (section_id = 14, escala = 0)
UPDATE users SET section_id = 14, escala = 0, war_name = 'ALEXANDRIA' WHERE saram LIKE '%657658%' OR saram LIKE '%657.658%' OR saram LIKE '%633819%' OR name LIKE '%MAYSE CORREIA%';
UPDATE users SET section_id = 14, escala = 0, war_name = 'MIRANDA' WHERE saram LIKE '%751989%' OR saram LIKE '%751.989%' OR saram LIKE '%704601%' OR name LIKE '%GABRIEL ALVES MIRANDA%';

-- 8. ELETRÔNICA (section_id = 12, escala = 0)
UPDATE users SET section_id = 12, escala = 0, war_name = 'SANTOS' WHERE saram LIKE '%615530%' OR saram LIKE '%615.530%' OR name LIKE '%BRUNO JESUS DA SILVA%';

-- 9. COMANDO (section_id = 2, escala = 0)
UPDATE users SET section_id = 2, escala = 0, war_name = 'GODOY' WHERE saram LIKE '%437872%' OR saram LIKE '%437.872%' OR saram LIKE '%336338%' OR name LIKE '%JORGE HENRIQUE DE OLIVEIRA%';
UPDATE users SET section_id = 2, escala = 0, war_name = 'SOUSA' WHERE saram LIKE '%256039%' OR saram LIKE '%256.039%' OR saram LIKE '%364658%' OR name LIKE '%ANT%NIO GL%UDIO%';
UPDATE users SET section_id = 2, escala = 0, war_name = 'ALVES' WHERE saram LIKE '%332648%' OR saram LIKE '%332.648%' OR saram LIKE '%364654%' OR name LIKE '%LUCIANO FERREIRA ALVES%';

-- 10. TORRE DE CONTROLE (section_id = 8, escala = 1)
UPDATE users SET section_id = 8, escala = 1, war_name = 'SILVA' WHERE saram LIKE '%427982%' OR saram LIKE '%427.982%' OR saram LIKE '%437944%' OR name LIKE '%RAFAEL CIPRIANO%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'GONÇALVES' WHERE saram LIKE '%437953%' OR saram LIKE '%437.953%' OR saram LIKE '%437946%' OR name LIKE '%JONATHAN FERNANDES%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'REIS' WHERE saram LIKE '%438072%' OR saram LIKE '%438.072%' OR name LIKE '%THAIS VITOR HERZOG%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'NUNES' WHERE saram LIKE '%422053%' OR saram LIKE '%422.053%' OR name LIKE '%WELLINGTON FERREIRA%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'CAMARGO' WHERE saram LIKE '%440461%' OR saram LIKE '%440.461%' OR name LIKE '%DIANE RIBEIRO%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'GONÇALVES' WHERE saram LIKE '%440462%' OR saram LIKE '%440.462%' OR name LIKE '%ANA CAROLINA THOMAZ%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'SANTOS' WHERE saram LIKE '%440469%' OR saram LIKE '%440.469%' OR name LIKE '%RAFAEL ESTEVES%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'SILVA' WHERE saram LIKE '%608853%' OR saram LIKE '%608.853%' OR name LIKE '%BRUNO HENRIQUE DA SILVA%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'MARTINI' WHERE saram LIKE '%615893%' OR saram LIKE '%615.893%' OR saram LIKE '%609074%' OR name LIKE '%LET%CIA CLAUDINO%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'PAULA' WHERE saram LIKE '%615894%' OR saram LIKE '%615.894%' OR saram LIKE '%609075%' OR name LIKE '%MARCELA SOUZA DE PAULA%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'FERREIRA' WHERE saram LIKE '%624035%' OR saram LIKE '%624.035%' OR name LIKE '%JESSICA DOS ANJOS%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'GOUVEA' WHERE saram LIKE '%657667%' OR saram LIKE '%657.667%' OR saram LIKE '%633822%' OR name LIKE '%ALISSON MEDEIROS%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'FARIAS' WHERE saram LIKE '%645395%' OR saram LIKE '%645.395%' OR name LIKE '%PEDRO LEIVA DE FARIAS%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'LIMA' WHERE saram LIKE '%666613%' OR saram LIKE '%666.613%' OR saram LIKE '%649064%' OR name LIKE '%THAMIRES MAGALH%ES%';
UPDATE users SET section_id = 8, escala = 1, war_name = 'VELASQUE' WHERE saram LIKE '%666632%' OR saram LIKE '%666.632%' OR saram LIKE '%649068%' OR name LIKE '%L%LLIAN COUTINHO%';

-- 11. EMS-1 / CMA-2 (section_id = 10, escala = 1)
UPDATE users SET section_id = 10, escala = 1, war_name = 'FONTENELES' WHERE saram LIKE '%422058%' OR saram LIKE '%422.058%' OR name LIKE '%RONALDO TELES%';
UPDATE users SET section_id = 10, escala = 1, war_name = 'RIBEIRO' WHERE saram LIKE '%423611%' OR saram LIKE '%423.611%' OR name LIKE '%MICHELLI BEZERRA%';
UPDATE users SET section_id = 10, escala = 1, war_name = 'ALMEIDA' WHERE saram LIKE '%440458%' OR saram LIKE '%440.458%' OR name LIKE '%LUIS EDUARDO GOMES%';
UPDATE users SET section_id = 10, escala = 1, war_name = 'GOMES' WHERE saram LIKE '%624029%' OR saram LIKE '%624.029%' OR name LIKE '%MILAINE MARQUES%';
UPDATE users SET section_id = 10, escala = 1, war_name = 'FERREIRA' WHERE saram LIKE '%690961%' OR saram LIKE '%690.961%' OR saram LIKE '%654885%' OR name LIKE '%NAT%LIA VASCONCELOS%';

-- 12. SALA AIS (section_id = 11, escala = 1)
UPDATE users SET section_id = 11, escala = 1, war_name = 'MENDONÇA' WHERE saram LIKE '%404007%' OR saram LIKE '%404.007%' OR name LIKE '%LUCIANY DA SILVA%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'ALVES' WHERE saram LIKE '%423766%' OR saram LIKE '%423.766%' OR name LIKE '%MARCOS PAULO GARCIA%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'LEITE' WHERE saram LIKE '%226472%' OR saram LIKE '%226.472%' OR saram LIKE '%422051%' OR name LIKE '%PAULO C%SAR LEITE%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'FREIRE' WHERE saram LIKE '%440457%' OR saram LIKE '%440.457%' OR name LIKE '%MICHELE DE AZEVEDO%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'SANTANA' WHERE saram LIKE '%447817%' OR saram LIKE '%447.817%' OR name LIKE '%RICARDO ALCINO%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'SODRÉ' WHERE saram LIKE '%624037%' OR saram LIKE '%624.037%' OR name LIKE '%KESSYA RODRIGUES%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'RIBEIRO' WHERE saram LIKE '%657663%' OR saram LIKE '%657.663%' OR saram LIKE '%633821%' OR name LIKE '%BRUNA PESSOA%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'OLIVEIRA' WHERE saram LIKE '%645398%' OR saram LIKE '%645.398%' OR name LIKE '%MARCIO DA CUNHA%';
UPDATE users SET section_id = 11, escala = 1, war_name = 'GONÇALVES' WHERE saram LIKE '%690959%' OR saram LIKE '%690.959%' OR saram LIKE '%654881%' OR name LIKE '%IN%S SAMPAIO%';
