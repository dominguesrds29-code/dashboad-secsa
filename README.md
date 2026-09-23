# DTCEA-SJ - Painel Administrativo Integrado (SECSA)

Painel de Gestão Administrativa e Operacional para monitoramento em tempo real do DTCEA-SJ / INTRAER.

## 🚀 Funcionalidades

- **Controle de Efetivo em Tempo Real**: Integração direta com a base do projeto `ctr_efetivo` (`efetivosj`), exibindo:
  - Efetivo total previsto do expediente
  - Militares presentes e taxa de prontidão (%)
  - Ausências, férias, dispensas médicas e missões externas
  - Distribuição e taxa de disponibilidade por Seção
  - Relação de militares afastados / situações especiais
- **Processos & Demandas do Dia**: Acompanhamento de trâmites e boletins ostensivos
- **Prazos Críticos & Entregas**: Cronograma regulatório DECEA e prestação de contas com contagem regressiva
- **News Ticker**: Avisos institucionais em rodapé animado
- **Atualização Automática**: Auto-sync a cada 30 segundos (ideal para TVs e monitores de sala de situação)

## 🛠️ Tecnologias

- HTML5 / CSS3 / JavaScript (Vanilla)
- Tailwind CSS
- PHP 8+ (Endpoint `api_efetivo.php`)
- MySQL (`efetivosj`)
- Material Symbols Outlined & Google Fonts (Inter)

## 📋 Pré-requisitos e Execução

1. Coloque a pasta `dashboad-secsa` no diretório raiz do servidor web (ex: `xampp/htdocs/dashboad-secsa`).
2. Certifique-se de que o banco MySQL do `ctr_efetivo` esteja ativo.
3. Acesse via navegador: `http://localhost/dashboad-secsa/`
