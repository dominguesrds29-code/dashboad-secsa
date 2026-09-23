<?php
// api_bca.php
// Endpoint para leitura dinâmica de Boletins da Aeronáutica (.pdf) na pasta /bca e geração de notícias

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

function extractTextFromPdfContent($content) {
    $text = "";
    if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            $uncompressed = @gzuncompress($stream);
            if ($uncompressed !== false) {
                // Decodifica sequências octais (\343 = ã, \351 = é, etc.)
                $uncompressed = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
                    return chr(octdec($m[1]));
                }, $uncompressed);

                if (preg_match_all('/\((.*?)\)\s*Tj/s', $uncompressed, $tMatches)) {
                    $text .= implode(' ', $tMatches[1]) . "\n";
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $uncompressed, $tMatches)) {
                    foreach ($tMatches[1] as $tj) {
                        if (preg_match_all('/\((.*?)\)/s', $tj, $subMatches)) {
                            $text .= implode('', $subMatches[1]);
                        }
                    }
                    $text .= "\n";
                }
            }
        }
    }

    $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    $text = str_replace(['\\(', '\\)', '\\-'], ['(', ')', '-'], $text);
    $text = str_replace('\\', '', $text);
    $text = preg_replace('/\(FAB\s*/iu', '(FAB) ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}

$bcaDir = __DIR__ . '/bca';
if (!is_dir($bcaDir)) {
    mkdir($bcaDir, 0777, true);
}

$files = glob($bcaDir . '/*.pdf');
if (empty($files)) {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum boletim PDF encontrado na pasta bca/',
        'noticias' => [
            [
                'tag' => 'Publicou no BCA',
                'destaque' => true,
                'texto' => 'Nenhum arquivo PDF encontrado na pasta /bca. Adicione boletins .pdf para geração automática de notícias.'
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Ordena para pegar o arquivo mais recente
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latestPdf = $files[0];
$pdfContent = file_get_contents($latestPdf);
$pdfText = extractTextFromPdfContent($pdfContent);

// Limpeza de quebras de página e cabeçalhos repetitivos
$clean = preg_replace('/Fl\.\s*n[ºo\?\s]*.*?15[0-9]{3}/iu', ' ', $pdfText);
$clean = preg_replace('/\(Continuação.*?\)/iu', ' ', $clean);
$clean = preg_replace('/\s+/', ' ', $clean);

// Extrai Número do BCA e Data
$bcaNumero = '162';
$bcaData = '';

if (preg_match('/bca[_\-\s]*([0-9]+)/i', basename($latestPdf), $mNum)) {
    $bcaNumero = $mNum[1];
} elseif (preg_match('/BOLETIM DO COMANDO DA AERON[AÁ]UTICA N[ºO\?\s]*([0-9]+)/iu', $clean, $mNum)) {
    $bcaNumero = $mNum[1];
}

if (preg_match('/([0-9]{2})[_\-]([0-9]{2})[_\-](20[0-9]{2})/', basename($latestPdf), $mDate)) {
    $bcaData = "{$mDate[1]}/{$mDate[2]}/{$mDate[3]}";
} elseif (preg_match('/([0-9]{1,2}\s+de\s+[a-zç]+\s+de\s+20[0-9]{2})/iu', $clean, $mDate)) {
    $bcaData = trim($mDate[1]);
}

$noticias = [];

// 1. AJUDA HUMANITÁRIA & OPERAÇÕES ESPECIAIS
if (preg_match('/(ajuda humanit[aá]ria|assist[eê]ncia humanit[aá]ria|socorro|calamidade|resgate|Pereira|Col[oô]mbia)/iu', $clean)) {
    $noticias[] = [
        'tema' => '🔴 AJUDA HUMANITÁRIA INTERNACIONAL',
        'manchete' => 'FAB cumpre com êxito missões operacionais e de assistência humanitária',
        'linha_apoio' => 'Militares e aeronaves da Força Aérea Brasileira atuam no suporte a comunidades e cooperação humanitária no Brasil e no exterior.'
    ];
}

// 2. DIPLOMACIA E VIAGENS PRESIDENCIAIS (GTE / Nova Iorque / Comitiva)
if (preg_match('/(PLAMTAX|Nova Iorque|Presidência da República|GTE|viagem presidencial)/iu', $clean)) {
    $noticias[] = [
        'tema' => '🌐 DIPLOMACIA E VIAGENS PRESIDENCIAIS',
        'manchete' => 'Aeronáutica mobiliza equipe em Nova Iorque para apoio à Presidência da República',
        'linha_apoio' => 'Militares do Grupo de Transporte Especial (GTE) e do Gabinete do Comandante foram designados para prestar suporte à comitiva presidencial nos Estados Unidos.'
    ];
}

// 3. REESTRUTURAÇÃO & ESTADO-MAIOR CONJUNTO
if (preg_match('/(Estado-Maior Conjunto|EMCFA|Subchefia de Política|Subchefia de Logística)/iu', $clean)) {
    $noticias[] = [
        'tema' => '🛡️ REESTRUTURAÇÃO E ESTADO-MAIOR',
        'manchete' => 'Comando da Aeronáutica e Estado-Maior Conjunto renovam funções estratégicas de Defesa',
        'linha_apoio' => 'Portarias oficiais definem novas designações de oficiais e graduados para setores de planejamento tático, inteligência e logística integrada.'
    ];
}

// 4. MISSÕES INTERNACIONAIS & REPRESENTAÇÃO
if (preg_match('/(afastamento do pa[íi]s|Marrakech Airshow|adido|conferência internacional)/iu', $clean)) {
    $noticias[] = [
        'tema' => '✈️ MISSÕES INTERNACIONAIS E COOPERAÇÃO',
        'manchete' => 'Delegações da Aeronáutica são autorizadas para missões no exterior e intercâmbio militar',
        'linha_apoio' => 'Representantes da Força Aérea participam de feiras internacionais de defesa, treinamentos bilaterais e inspeções de segurança em países parceiros.'
    ];
}

// 5. ATOS DO COMANDANTE & GABAER
if (preg_match('/PORTARIA GABAER/iu', $clean)) {
    $noticias[] = [
        'tema' => '🏛️ ATOS DO COMANDANTE DA AERONÁUTICA',
        'manchete' => 'Gabinete do Comandante publica novas portarias de pessoal e planejamento da Força',
        'linha_apoio' => 'Atos normativos homologam diretrizes operacionais, movimentações administrativas e regras de governança para todas as Organizações Militares.'
    ];
}

// 6. CIÊNCIA, TECNOLOGIA & DEFESA (DCTA / ITA / SJC)
if (preg_match('/\b(DCTA|ITA|IAE|IEAv|IFI|GAP-SJ|DTCEA-SJ|São José dos Campos)\b/iu', $clean)) {
    $noticias[] = [
        'tema' => '🔬 CIÊNCIA, TECNOLOGIA E DEFESA AEROESPACIAL',
        'manchete' => 'Polo Aeroespacial de São José dos Campos e DCTA registram novos atos e publicações',
        'linha_apoio' => 'Projetos científicos, tecnológicos e administrativos do campus do DCTA, ITA e organizações do Vale do Paraíba avançam com diretrizes publicadas.'
    ];
}

// 7. CONTROLE DO ESPAÇO AÉREO & SISCEAB
if (preg_match('/\b(DECEA|CINDACTA|DTCEA|CGNA|SRPV|SISCEAB|Tráfego Aéreo)\b/iu', $clean)) {
    $noticias[] = [
        'tema' => '📡 CONTROLE DO ESPAÇO AÉREO E SISCEAB',
        'manchete' => 'DECEA e CINDACTA mantêm conformidade contínua e prontidão do espaço aéreo brasileiro',
        'linha_apoio' => 'Instruções técnicas e administrativas reforçam a modernização de radares, telecomunicações e prontidão dos Destacamentos de Controle.'
    ];
}

// 8. SAÚDE OPERACIONAL & ASSISTÊNCIA
if (preg_match('/\b(DIRSA|Saúde|Hospital|Junta de Saúde|Inspeção de Saúde|NSCA 160)\b/iu', $clean)) {
    $noticias[] = [
        'tema' => '🏥 SAÚDE OPERACIONAL E LOGÍSTICA',
        'manchete' => 'Diretoria de Saúde atualiza diretrizes assistenciais e inspeções periciais da Força',
        'linha_apoio' => 'O Sistema de Saúde da Aeronáutica padroniza rotinas hospitalares, perícias médicas e atendimento aos militares e seus dependentes.'
    ];
}

// 9. ENSINO, CAPACITAÇÃO E FORMAÇÃO
if (preg_match('/\b(Curso|Matr[íi]cula|Aproveitamento|Conclus[ãa]o|Estágio|EEAR|AFA|EPCAR|CIAAR)\b/iu', $clean)) {
    $noticias[] = [
        'tema' => '🎓 ENSINO, FORMAÇÃO E CAPACITAÇÃO',
        'manchete' => 'Comando da Aeronáutica homologa conclusões de cursos e matrículas de especialização',
        'linha_apoio' => 'Atos oficiais regulamentam cursos de formação e aperfeiçoamento profissional para o contínuo desenvolvimento do efetivo militar.'
    ];
}

// 10. INTEGRAÇÃO DAS FORÇAS ARMADAS
if (preg_match('/(Marinha|Exército|Ministério da Defesa|Interforças|Operação Conjunta)/iu', $clean)) {
    $noticias[] = [
        'tema' => '🪖 INTEGRAÇÃO DAS FORÇAS ARMADAS',
        'manchete' => 'Ações integradas fortalecem a interoperabilidade entre Marinha, Exército e Aeronáutica',
        'linha_apoio' => 'Instruções conjuntas e cooperação entre as Forças garantem pronta resposta operacional em missões de soberania e defesa nacional.'
    ];
}

// 11. DESPORTO & ALTO RENDIMENTO
if (preg_match('/(Desporto|Atleta|Campeonato|CDA|Torneio|Basquete|Vôlei|Natação)/iu', $clean)) {
    $noticias[] = [
        'tema' => '🏀 ESPORTE E REPRESENTAÇÃO DE ALTO RENDIMENTO',
        'manchete' => 'Atletas militares da FAB representam o Brasil em competições nacionais e internacionais',
        'linha_apoio' => 'Militares do Programa de Atletas de Alto Rendimento da Aeronáutica disputam títulos de destaque nos cenários nacional e global.'
    ];
}

// 12. Manchete Geral do Boletim
$noticias[] = [
    'tema' => "🔴 BOLETIM OFICIAL Nº {$bcaNumero}",
    'manchete' => "Boletim do Comando da Aeronáutica Nº {$bcaNumero} publicado na íntegra",
    'linha_apoio' => "Edição oficial de {$bcaData} disponibilizada para conhecimento e cumprimento de todo o efetivo da Guarnição e Destacamento."
];

echo json_encode([
    'success' => true,
    'arquivo' => basename($latestPdf),
    'bca_numero' => $bcaNumero,
    'bca_data' => $bcaData,
    'total_noticias' => count($noticias),
    'hora_leitura' => date('H:i:s'),
    'noticias' => $noticias
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

