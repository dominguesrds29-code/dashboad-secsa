<?php
// api_bca.php
// Endpoint para leitura 100% dinâmica de Boletins da Aeronáutica (.pdf) na pasta /bca e geração de notícias no estilo NotebookLM (Tema com emoji, MANCHETE e Linha de Apoio)

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

function sintetizarAtoJornalistico($textoAto, $bcaNumero, $bcaData) {
    $textoAto = trim($textoAto);
    $textoAto = preg_replace('/Fl\.\s*n[ºo\?\s]*.*?15[0-9]{3}/iu', ' ', $textoAto);
    $textoAto = preg_replace('/\(Continuação.*?\)/iu', ' ', $textoAto);
    $textoAto = trim(preg_replace('/\s+/', ' ', $textoAto));

    if (mb_strlen($textoAto) < 55) return null;

    $tema = null;
    $manchete = null;

    // 1. DIPLOMACIA & MISSÕES NO EXTERIOR (PLAMTAX / Afastamento / Apoio Presidencial)
    if (preg_match('/(?:AUTORIZAR\s+o\s+afastamento\s+do\s+pa[íi]s|viagem\s+ao\s+exterior|PLAMTAX|missão\s+no\s+exterior)/iu', $textoAto)) {
        $tema = '🌐 DIPLOMACIA E MISSÕES NO EXTERIOR';
        
        $destino = 'no exterior';
        if (preg_match('/(Nova Iorque|Washington|Pereira|Colômbia|Marrocos|Marrakech|Santiago|Chile|Paris|França|Lisboa|Portugal|Roma|Itália|Madri|Espanha|Londres|Inglaterra|Alemanha|Suécia)/iu', $textoAto, $mDest)) {
            $destino = "em {$mDest[1]}";
        }

        if (stripos($textoAto, 'Presidência da República') !== false) {
            $manchete = "Aeronáutica mobiliza militares para apoio à Presidência da República {$destino}";
        } elseif (preg_match('/(?:para|a fim de)\s+([a-záéíóúâêôãõç\s\-]{8,60}?)(?:,|\.|\(|$)/iu', $textoAto, $mFin)) {
            $manchete = "Autorizado afastamento do país de militares para " . trim($mFin[1]);
        } else {
            $manchete = "Comando da Aeronáutica autoriza missão e afastamento oficial do país {$destino}";
        }
    }
    // 2. ATOS DO GABAER / COMANDANTE
    elseif (preg_match('/PORTARIA\s+GABAER\s*(?:N[ºO\?\s]*([0-9\/\-A-Z]+))?/iu', $textoAto, $mGabaer)) {
        $tema = '🏛️ ATOS DO COMANDANTE DA AERONÁUTICA';
        $num = !empty($mGabaer[1]) ? "Nº " . trim($mGabaer[1]) : "";
        $manchete = "Gabinete do Comandante expede Portaria GABAER {$num} com diretrizes oficiais";
    }
    // 3. SAÚDE OPERACIONAL / DIRSA / HOSPITAL / PERÍCIAS
    elseif (preg_match('/\b(DIRSA|Saúde|Hospital|Junta de Saúde|Inspeção de Saúde|NSCA 160)\b/iu', $textoAto)) {
        $tema = '🏥 SAÚDE OPERACIONAL E ASSISTÊNCIA';
        $manchete = "Diretoria de Saúde atualiza normas assistenciais e juntas periciais da Aeronáutica";
    }
    // 4. SISCEAB / DECEA / CINDACTA / DTCEA
    elseif (preg_match('/\b(DECEA|CINDACTA|DTCEA|CGNA|SRPV|SISCEAB)\b/iu', $textoAto)) {
        $tema = '📡 CONTROLE DO ESPAÇO AÉREO E SISCEAB';
        $manchete = "DECEA e organizações do SISCEAB publicam atos de prontidão operacional";
    }
    // 5. DCTA / ITA / SJC
    elseif (preg_match('/\b(DCTA|ITA|IAE|IEAv|IFI|GAP-SJ|DTCEA-SJ|São José dos Campos)\b/iu', $textoAto)) {
        $tema = '🔬 CIÊNCIA, TECNOLOGIA E GUARNIÇÃO SJ';
        $manchete = "Atos administrativos de interesse do DCTA e da Guarnição de São José dos Campos";
    }
    // 6. DESIGNAÇÕES & REESTRUTURAÇÃO DE COMANDO
    elseif (preg_match('/DESIGNAR\s+(?:o|a|os|as)?\s*([A-Za-zÀ-ÿ\s\-\(\)\/\d]+?)\s+para\s+(?:a|o)?\s*(?:função|cargo|exercer|compor|missão)\s*(?:de\s+)?([^,;\.\n]+)/iu', $textoAto, $mDesig)) {
        $tema = '🛡️ REESTRUTURAÇÃO E QUADRO DE PESSOAL';
        $funcao = trim($mDesig[2]);

        $setor = '';
        if (preg_match('/(?:da|do|no|na)\s+([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç\s\-]+?(?:Subchefia|Chefia|Gabinete|Diretoria|Centro|Comando|Base|Esquadrão|Instituto|Departamento)[A-Za-zÀ-ÿ\s\-]*)/u', $textoAto, $mSetor)) {
            $setor = ' na ' . trim($mSetor[1]);
        }
        $manchete = "Designação de militar para exercer função de {$funcao}{$setor}";
    }
    // 7. ENSINO & CURSOS
    elseif (preg_match('/\b(Curso|Matr[íi]cula|Aproveitamento|Conclus[ãa]o|Estágio|EEAR|AFA|EPCAR|CIAAR)\b/iu', $textoAto)) {
        $tema = '🎓 ENSINO, FORMAÇÃO E CAPACITAÇÃO';
        $manchete = "Comando da Aeronáutica homologa atos regulamentares de cursos e estágios";
    }
    // 8. CONCESSÕES & HONRARIAS
    elseif (preg_match('/\b(CONCEDER|Medalha|Elogio|Láurea|Menção)\b/iu', $textoAto)) {
        $tema = '🎖️ CONCESSÕES E MÉRITO MILITAR';
        $manchete = "Comando da Aeronáutica concede honrarias e medalhas regulamentares ao efetivo";
    }
    // 9. INTEGRAÇÃO & MINISTÉRIO DA DEFESA
    elseif (preg_match('/(Marinha|Exército|Ministério da Defesa|EMCFA|Interforças)/iu', $textoAto)) {
        $tema = '🪖 INTEGRAÇÃO DAS FORÇAS ARMADAS';
        $manchete = "Ações conjuntas fortalecem a cooperação entre a Aeronáutica e o Ministério da Defesa";
    }
    // 10. PORTARIAS GERAIS COM CONTEÚDO
    elseif (preg_match('/PORTARIA\s+([A-Z0-9\-\/]+)\s*N[ºO\?\s]*([0-9\.\/]+)/iu', $textoAto, $mPort)) {
        $tema = '📋 DIRETRIZES E PORTARIAS OFICIAIS';
        $manchete = "Publicada Portaria {$mPort[1]} Nº {$mPort[2]} com atos normativos no Boletim da Aeronáutica";
    }
    else {
        return null;
    }

    // Linha de Apoio Inteligente: Síntese executiva em 1 frase elegante
    $linhaApoio = '';

    if (stripos($tema, 'DIPLOMACIA') !== false || stripos($tema, 'EXTERIOR') !== false) {
        $militar = '';
        if (preg_match('/(?:do|da|ao|à)\s+((?:General|Brigadeiro|Coronel|Tenente-Coronel|Major|Capitão|Tenente|Sargento|Cabo)[A-Za-zÀ-ÿ\s\(\)]+?)(?:,|\.|\s+para|\s+do|\s+da)/u', $textoAto, $mMil)) {
            $militar = trim($mMil[1]);
        }
        $destino = '';
        if (preg_match('/(Nova Iorque|Washington|Pereira|Colômbia|Marrocos|Marrakech|Santiago|Chile|Paris|França|Lisboa|Portugal|Roma|Itália|Madri|Espanha|Londres|Inglaterra)/iu', $textoAto, $mDest)) {
            $destino = ' em ' . trim($mDest[1]);
        }
        if (stripos($textoAto, 'Presidência da República') !== false) {
            $linhaApoio = "Militares designados pelo Comando prestarão apoio à comitiva presidencial durante compromissos oficiais{$destino}.";
        } elseif ($militar) {
            $linhaApoio = "A autorização contempla {$militar} para cumprimento de missão oficial e representação institucional{$destino}.";
        } else {
            $linhaApoio = "Militares da Força Aérea Brasileira foram designados para cumprir missão de intercâmbio e cooperação{$destino}.";
        }
    } elseif (stripos($tema, 'REESTRUTURAÇÃO') !== false || stripos($tema, 'PESSOAL') !== false) {
        $militar = '';
        if (preg_match('/DESIGNAR\s+(?:o|a|os|as)?\s*([A-Za-zÀ-ÿ\s\-\(\)\/\d]+?)\s+para/iu', $textoAto, $mMil)) {
            $militar = trim($mMil[1]);
        }
        $funcao = '';
        if (preg_match('/função\s+de\s+([A-Za-zÀ-ÿ\s\-]+?)(?:,|\.|\s+código|\s+da|\s+do)/iu', $textoAto, $mFunc)) {
            $funcao = 'de ' . trim($mFunc[1]);
        }
        $setor = '';
        if (preg_match('/(?:da|do|no|na)\s+([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç\s\-]+?(?:Subchefia|Chefia|Gabinete|Diretoria|Centro|Comando|Base|Esquadrão|Instituto|Departamento)[A-Za-zÀ-ÿ\s\-]*)/u', $textoAto, $mSetor)) {
            $setor = ' na ' . trim($mSetor[1]);
        }
        if ($militar && $funcao) {
            $linhaApoio = "O ato oficial nomeia {$militar} para exercer a função {$funcao}{$setor}.";
        } elseif ($funcao) {
            $linhaApoio = "A publicação formaliza a designação para a função {$funcao}{$setor} na estrutura organizacional.";
        } else {
            $linhaApoio = "A portaria define novas atribuições e movimentações estratégicas no quadro de pessoal da Força.";
        }
    } elseif (stripos($tema, 'GUARNIÇÃO SJ') !== false || stripos($tema, 'CIÊNCIA') !== false) {
        if (preg_match('/Programa de Gestão/iu', $textoAto)) {
            $linhaApoio = "A instrução aprovada pela Direção-Geral do DCTA regulamenta as novas diretrizes do Programa de Gestão e Desempenho no campus.";
        } elseif (preg_match('/(IFI|IEAv|IAE|ITA|GAP-SJ|DTCEA-SJ)/iu', $textoAto, $mOrg)) {
            $sigla = strtoupper(trim($mOrg[1]));
            $linhaApoio = "A publicação oficial contempla diretrizes administrativas e atos de gestão voltados ao {$sigla} em São José dos Campos.";
        } else {
            $linhaApoio = "Normativas e resoluções administrativas atualizam procedimentos técnicos e de gestão na Guarnição de São José dos Campos.";
        }
    } elseif (stripos($tema, 'COMANDANTE') !== false || stripos($tema, 'GABAER') !== false) {
        $linhaApoio = "O Comandante da Aeronáutica homologou decisões normativas e atos de pessoal com vigência imediata para as Organizações Militares.";
    } elseif (stripos($tema, 'SAÚDE') !== false) {
        $linhaApoio = "A Diretoria de Saúde padroniza diretrizes técnicas, rotinas periciais e procedimentos hospitalares no Sistema de Saúde da Aeronáutica.";
    } elseif (stripos($tema, 'ESPAÇO AÉREO') !== false || stripos($tema, 'SISCEAB') !== false) {
        $linhaApoio = "Instruções técnicas emitidas pelo DECEA reforçam a operacionalidade, radiocomunicação e prontidão dos Destacamentos de Controle.";
    } elseif (stripos($tema, 'ENSINO') !== false || stripos($tema, 'CAPACITAÇÃO') !== false) {
        if (preg_match('/Curso de ([A-Za-zÀ-ÿ\s\-]+?)(?:,|\.|\(|\s+a ser)/iu', $textoAto, $mCur)) {
            $linhaApoio = "A publicação oficial autoriza a matrícula de militares no Curso de " . trim($mCur[1]) . " para capacitação continuada.";
        } else {
            $linhaApoio = "O ato oficial homologa matrículas e etapas de capacitação técnica para o contínuo aperfeiçoamento do efetivo.";
        }
    } elseif (stripos($tema, 'INTEGRAÇÃO') !== false || stripos($tema, 'DEFESA') !== false) {
        if (preg_match('/Segurança Cibernética|NSCA 7-22/iu', $textoAto)) {
            $linhaApoio = "A norma atualiza os procedimentos obrigatórios para comunicação e acompanhamento de eventos de segurança cibernética no COMAER.";
        } else {
            $linhaApoio = "Medidas conjuntas entre a Aeronáutica e o Ministério da Defesa ampliam a sinergia institucional e a governança operacional.";
        }
    } else {
        // Fallback dinâmico com extração da primeira frase substancial
        $textoLimpo = preg_replace('/^(?:PORTARIA|AUTORIZAR|DESIGNAR|CONCEDER|O\s+COMANDANTE|O\s+CHEFE|A\s+SECRETÁRIA).*?resolve:\s*/iu', '', $textoAto);
        $textoLimpo = trim(preg_replace('/\s+/', ' ', $textoLimpo));
        if (mb_strlen($textoLimpo) > 170) {
            $corte = mb_substr($textoLimpo, 0, 170);
            $ponto = max(mb_strrpos($corte, '.'), mb_strrpos($corte, ';'));
            if ($ponto !== false && $ponto > 60) {
                $linhaApoio = trim(mb_substr($corte, 0, $ponto + 1));
            } else {
                $linhaApoio = trim($corte) . '.';
            }
        } else {
            $linhaApoio = $textoLimpo;
        }
    }

    if (mb_strlen($linhaApoio) < 25) return null;

    return [
        'tema' => $tema,
        'manchete' => $manchete,
        'linha_apoio' => $linhaApoio
    ];
}

function downloadHttp($url, $timeout = 4) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DTCEA-SJ Dashboard BCA Downloader');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $httpCode >= 200 && $httpCode < 300) {
            return $data;
        }
    }
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DTCEA-SJ Dashboard BCA Downloader'
        ]
    ]);
    return @file_get_contents($url, false, $ctx);
}

function sincronizarUltimoBcaCendoc($bcaDir) {
    $cendocBase = 'http://www.cendoc.intraer/sisbca/';
    $info = [
        'tentou_download' => false,
        'sucesso' => false,
        'mensagem' => '',
        'bca_numero' => null,
        'bca_data' => null,
        'pdf_arquivo' => null
    ];

    $html = downloadHttp($cendocBase, 3);
    if (!$html) {
        $info['mensagem'] = 'Sem conexão direta com o SISBCA (CENDOC). Usando cache local.';
        return $info;
    }

    $info['tentou_download'] = true;

    // Procura o Último Boletim Ostensivo no HTML do SISBCA
    // Ex: <h3>- Último Boletim Ostensivo:</h3><p><b>BCA nº.: 163 de 24-09-2026</b> ... <a href='bca_pdf/2026/bca_163_24-09-2026.pdf'
    if (preg_match('/Último\s+Boletim\s+Ostensivo.*?<b>BCA\s+n[ºo\?\.]*:\s*([0-9]+)\s+de\s+([0-9]{2}[_\-][0-9]{2}[_\-][0-9]{4})<\/b>.*?href=[\'"]([^\'"]+?\.pdf)[\'"]/is', $html, $matches)) {
        $nr = $matches[1];
        $dataRaw = $matches[2];
        $pdfRelPath = $matches[3];

        $info['bca_numero'] = $nr;
        $info['bca_data'] = str_replace('-', '/', $dataRaw);

        if (preg_match('/^https?:\/\//i', $pdfRelPath)) {
            $pdfUrl = $pdfRelPath;
        } else {
            $pdfUrl = rtrim($cendocBase, '/') . '/' . ltrim($pdfRelPath, '/');
        }

        $nomeArquivo = basename(parse_url($pdfUrl, PHP_URL_PATH));
        if (empty($nomeArquivo) || substr($nomeArquivo, -4) !== '.pdf') {
            $nomeArquivo = "bca_{$nr}_{$dataRaw}.pdf";
        }

        $destinoLocal = rtrim($bcaDir, '/\\') . DIRECTORY_SEPARATOR . $nomeArquivo;

        // Se o arquivo ainda não existe ou está corrompido/vazio
        if (!file_exists($destinoLocal) || filesize($destinoLocal) < 2000) {
            $conteudoPdf = downloadHttp($pdfUrl, 8);
            if ($conteudoPdf && strlen($conteudoPdf) > 2000) {
                file_put_contents($destinoLocal, $conteudoPdf);
                $info['sucesso'] = true;
                $info['mensagem'] = "BCA nº {$nr} baixado com sucesso do CENDOC!";
                $info['pdf_arquivo'] = $nomeArquivo;
            } else {
                $info['mensagem'] = "Identificado BCA nº {$nr} no CENDOC, mas não foi possível baixar o PDF no momento.";
            }
        } else {
            $info['sucesso'] = true;
            $info['mensagem'] = "BCA nº {$nr} de {$info['bca_data']} já está no cache local mais recente.";
            $info['pdf_arquivo'] = $nomeArquivo;
        }
    }

    return $info;
}

$bcaDir = __DIR__ . '/bca';
if (!is_dir($bcaDir)) {
    mkdir($bcaDir, 0777, true);
}

// 1. Tenta sincronizar e baixar o último boletim oficial do CENDOC SISBCA
$syncCendoc = sincronizarUltimoBcaCendoc($bcaDir);

// 2. Localiza arquivos PDF locais
$files = glob($bcaDir . '/*.pdf');
if (empty($files)) {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum boletim PDF encontrado na pasta bca/ e CENDOC inacessível',
        'sync_cendoc' => $syncCendoc,
        'noticias' => [
            [
                'tema' => '📄 PUBLICOU NO BCA',
                'manchete' => 'Aguardando publicação oficial do BCA',
                'linha_apoio' => 'Conectando ao SISBCA (CENDOC) para baixar o último Boletim Ostensivo automaticamente.'
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Ordena para obter o arquivo mais recente (prioriza por data de modificação ou número do BCA)
usort($files, function($a, $b) {
    preg_match('/bca[_\-\s]*([0-9]+)/i', basename($a), $ma);
    preg_match('/bca[_\-\s]*([0-9]+)/i', basename($b), $mb);
    $numA = isset($ma[1]) ? (int)$ma[1] : 0;
    $numB = isset($mb[1]) ? (int)$mb[1] : 0;
    if ($numA !== $numB) {
        return $numB - $numA;
    }
    return filemtime($b) - filemtime($a);
});

$latestPdf = $files[0];
$pdfContent = file_get_contents($latestPdf);
$pdfText = extractTextFromPdfContent($pdfContent);

// Identifica Número do BCA e Data
$bcaNumero = '';
$bcaData = '';

if (preg_match('/bca[_\-\s]*([0-9]+)/i', basename($latestPdf), $mNum)) {
    $bcaNumero = $mNum[1];
} elseif (preg_match('/BOLETIM DO COMANDO DA AERON[AÁ]UTICA N[ºO\?\s]*([0-9]+)/iu', $pdfText, $mNum)) {
    $bcaNumero = $mNum[1];
}

if (preg_match('/([0-9]{2})[_\-]([0-9]{2})[_\-](20[0-9]{2})/', basename($latestPdf), $mDate)) {
    $bcaData = "{$mDate[1]}/{$mDate[2]}/{$mDate[3]}";
} elseif (preg_match('/([0-9]{1,2}\s+de\s+[a-zç]+\s+de\s+20[0-9]{2})/iu', $pdfText, $mDate)) {
    $bcaData = trim($mDate[1]);
}

// Divide o PDF em blocos de atos
$blocos = preg_split('/(?=(?:PORTARIA|AUTORIZAR\s+o\s+afastamento|DESIGNAR\s+(?:o|a|os|as)|CONCEDER\s+(?:ao|à|aos|às)|DESPACHO)\b)/iu', $pdfText);

$noticias = [];
$temasContabilizados = [];
$hashes = [];

// 1. Manchete Institucional do Boletim Lido
$noticias[] = [
    'tema' => "🔴 BOLETIM OFICIAL Nº " . ($bcaNumero ?: 'DO DIA'),
    'manchete' => "Boletim do Comando da Aeronáutica Nº " . ($bcaNumero ?: '') . " publicado e disponível na íntegra",
    'linha_apoio' => "Edição oficial" . ($bcaData ? " datada de {$bcaData}" : "") . " encontra-se disponível para conhecimento e cumprimento de todo o efetivo da Guarnição."
];

// 2. Itera sobre os atos reais do PDF e sintetiza manchetes variadas
foreach ($blocos as $bloco) {
    $item = sintetizarAtoJornalistico($bloco, $bcaNumero, $bcaData);
    if ($item) {
        $temaNome = $item['tema'];
        
        // Limita a 2 notícias por mesmo tema para garantir diversidade editorial de assuntos
        if (!isset($temasContabilizados[$temaNome])) {
            $temasContabilizados[$temaNome] = 0;
        }
        if ($temasContabilizados[$temaNome] >= 2) {
            continue;
        }

        $hash = md5($item['manchete'] . mb_substr($item['linha_apoio'], 0, 30));
        if (!isset($hashes[$hash])) {
            $hashes[$hash] = true;
            $temasContabilizados[$temaNome]++;
            $noticias[] = $item;
        }
    }
    if (count($noticias) >= 14) break;
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

