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

## 📰 Monitoramento Automático de BCA (Boletim do Comando da Aeronáutica)

- **Rotina Diária Agendada (06:10)**: Execução única e controlada às **06:10 da manhã** via `cron` do Linux em produção.
- **Proteção da Infraestrutura de Rede**: As requisições ao portal SISBCA/CENDOC na Intraer ocorrem **exclusivamente 1 vez ao dia** às 06:10. O dashboard web consome apenas o arquivo local de cache (`bca_cache.json`), impedindo qualquer sobrecarga ou interpretação indevida como ataque.
- **Gestão de Armazenamento Inteligente**: A pasta `bca/` mantém **no máximo 10 arquivos PDF** (os 10 mais recentes). A rotina realiza limpeza e descarte automático dos boletins mais antigos.
- **Configuração no Crontab do Linux**:
  ```bash
  10 6 * * * /usr/bin/php /var/www/html/dashboad-secsa/sync_bca.php > /dev/null 2>&1
  ```

## 📋 Pré-requisitos e Execução

1. Coloque a pasta `dashboad-secsa` no diretório raiz do servidor web (ex: `/var/www/html/dashboad-secsa` no Linux).
2. Certifique-se de que o banco MySQL do `ctr_efetivo` esteja ativo.
3. Configure a linha no `crontab -e` do servidor Linux para execução diária às 06:10.
4. Acesse via navegador: `http://<ip-do-servidor>/dashboad-secsa/`


