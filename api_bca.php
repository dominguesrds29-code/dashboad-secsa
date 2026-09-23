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

// 1. Manchete do BCA
$noticias[] = [
    'tag' => "BCA Nº {$bcaNumero}",
    'destaque' => true,
    'texto' => "Boletim do Comando da Aeronáutica Nº {$bcaNumero}" . ($bcaData ? " ({$bcaData})" : "") . " publicado e disponível para consulta."
];

$textosAdicionados = [];

// 2. Divide em atos/parágrafos
$blocos = preg_split('/(?=(?:PORTARIA|AUTORIZAR|DESIGNAR|CONCEDER|Coronel|Tenente-Coronel|Major|1S|SO)\s+)/iu', $clean);

foreach ($blocos as $bRaw) {
    $b = trim($bRaw);
    if (mb_strlen($b) < 35) continue;

    $tag = null;
    if (preg_match('/\b(GAP-SJ|São José dos Campos|CCA-SJ|SERINFRA-SJ)\b/iu', $b)) {
        $tag = 'GAP-SJ / SJC';
    } elseif (preg_match('/\b(DTCEA|DTCEA-SJ|SISCEAB|CINDACTA)\b/iu', $b)) {
        $tag = 'DTCEA / SISCEAB';
    } elseif (preg_match('/\b(DECEA|CRCEA-SE|CGNA|SRPV)\b/iu', $b)) {
        $tag = 'DECEA / SISCEAB';
    } elseif (preg_match('/\b(DCTA|ITA|IAE|IEAv|IFI)\b/iu', $b)) {
        $tag = 'DCTA / ITA';
    } elseif (stripos($b, 'AUTORIZAR o afastamento do país') !== false || stripos($b, 'viagem ao exterior') !== false || stripos($b, 'PLAMTAX') !== false) {
        $tag = 'MISSÃO EXTERIOR';
    } elseif (stripos($b, 'PORTARIA GABAER') !== false) {
        $tag = 'GABAER';
    } elseif (preg_match('/\b(GTE|COMGAP|EMAER|SEFA|DIRAP|DIRSA)\b/iu', $b, $mOrg)) {
        $tag = strtoupper($mOrg[1]);
    } elseif (stripos($b, 'Curso') !== false && (stripos($b, 'Aproveitamento') !== false || stripos($b, 'Matricular') !== false || stripos($b, 'Conclusão') !== false)) {
        $tag = 'CURSOS & ENSINO';
    } elseif (stripos($b, 'DESIGNAR') !== false || stripos($b, 'NOMEAR') !== false) {
        $tag = 'DESIGNAÇÃO';
    } elseif (stripos($b, 'CONCEDER') !== false) {
        $tag = 'CONCESSÃO';
    }

    if ($tag !== null) {
        // Formata o trecho
        $tam = min(240, mb_strlen($b));
        $trecho = mb_substr($b, 0, $tam);
        if ($tam === 240) {
            $ultimoPonto = max(mb_strrpos($trecho, '.'), mb_strrpos($trecho, ';'));
            if ($ultimoPonto !== false && $ultimoPonto > 60) {
                $trecho = mb_substr($trecho, 0, $ultimoPonto + 1);
            } else {
                $trecho .= '...';
            }
        }
        $trecho = trim($trecho);
        $hash = md5(mb_substr($trecho, 0, 45));

        if (!isset($textosAdicionados[$hash])) {
            $textosAdicionados[$hash] = true;
            $noticias[] = [
                'tag' => $tag,
                'destaque' => false,
                'texto' => $trecho
            ];
        }
    }
    if (count($noticias) >= 12) break;
}

echo json_encode([
    'success' => true,
    'arquivo' => basename($latestPdf),
    'bca_numero' => $bcaNumero,
    'bca_data' => $bcaData,
    'total_noticias' => count($noticias),
    'hora_leitura' => date('H:i:s'),
    'noticias' => $noticias
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
