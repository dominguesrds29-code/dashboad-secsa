/**
 * SECRETARY MODULE - MURAL DA SECRETARIA ADMINISTRATIVA
 * Gerenciamento de comunicados, agenda do dia, ramais e painel administrativo
 */

const SecretaryModule = (function () {
  const DEFAULT_NOTICES = [
    {
      id: 1,
      title: "Prazo para Envio das Folhas de Frequência",
      priority: "urgent",
      priorityLabel: "Urgente",
      date: "Hoje, até 17h00",
      text: "Todas as coordenações devem homologar os registros de ponto e justificativas de ausência no sistema até as 17h impreterivelmente."
    },
    {
      id: 2,
      title: "Manutenção Preventiva de Redes e Servidores",
      priority: "warning",
      priorityLabel: "Atenção",
      date: "Sábado, 08h00 - 13h00",
      text: "Haverá indisponibilidade temporária no acesso aos sistemas internos de processos eletrônicos para atualização de segurança."
    },
    {
      id: 3,
      title: "Campanha de Vacinação e Saúde do Servidor",
      priority: "info",
      priorityLabel: "Informativo",
      date: "10 a 14 deste mês",
      text: "Atendimento no ambulatório da secretaria das 09h às 16h para vacinação contra gripe e exames preventivos de rotina."
    }
  ];

  const DEFAULT_AGENDA = [
    {
      id: 1,
      time: "09:30",
      title: "Reunião de Alinhamento de Compras & Contratos",
      room: "Sala de Reuniões 01",
      status: "ongoing",
      statusLabel: "Em andamento"
    },
    {
      id: 2,
      time: "14:00",
      title: "Apresentação do Relatório Trimestral de Gestão",
      room: "Auditório Principal",
      status: "upcoming",
      statusLabel: "Às 14:00"
    },
    {
      id: 3,
      time: "16:30",
      title: "Capacitação: Novo Módulo de Protocolo Digital",
      room: "Laboratório de Treinamento",
      status: "upcoming",
      statusLabel: "Às 16:30"
    }
  ];

  const DIRECTORY_ITEMS = [
    { sector: "Gabinete do Secretário", ext: "Ramal 2000" },
    { sector: "Assessoria Jurídica", ext: "Ramal 2015" },
    { sector: "Protocolo Geral & Arquivo", ext: "Ramal 2101" },
    { sector: "Recursos Humanos / Gestão de Pessoas", ext: "Ramal 2140" },
    { sector: "Compras, Licitações e Contratos", ext: "Ramal 2125" },
    { sector: "Suporte Técnico & Informática", ext: "Ramal 2199" },
    { sector: "Ouvidoria & Atendimento Cidadão", ext: "Ramal 2050" },
    { sector: "Coordenação Financeira", ext: "Ramal 2180" }
  ];

  // Carrega ou inicializa dados
  let notices = JSON.parse(localStorage.getItem('secsa_notices')) || DEFAULT_NOTICES;
  let agenda = JSON.parse(localStorage.getItem('secsa_agenda')) || DEFAULT_AGENDA;
  let currentTab = 'notices'; // 'notices', 'agenda', 'directory'
  let rotationTimer = null;
  const TAB_ROTATION_INTERVAL = 18000; // 18 segundos por tela para leitura tranquila

  function renderNotices() {
    const container = document.getElementById('notices-container');
    if (!container) return;

    container.innerHTML = notices.map(n => `
      <div class="notice-card ${n.priority}">
        <div class="notice-header-row">
          <span class="notice-badge ${n.priority}">${n.priorityLabel}</span>
          <span class="notice-date">${n.date}</span>
        </div>
        <div class="notice-title">${n.title}</div>
        <div class="notice-text">${n.text}</div>
      </div>
    `).join('');
  }

  function renderAgenda() {
    const container = document.getElementById('agenda-container');
    if (!container) return;

    container.innerHTML = agenda.map(a => `
      <div class="agenda-item">
        <div class="agenda-time-pill">
          <span class="agenda-time-text">${a.time}</span>
        </div>
        <div class="agenda-info">
          <div class="agenda-title">${a.title}</div>
          <div class="agenda-meta">
            <span>📍 ${a.room}</span>
          </div>
        </div>
        <span class="agenda-status-badge ${a.status === 'ongoing' ? 'status-ongoing' : 'status-upcoming'}">
          ${a.statusLabel}
        </span>
      </div>
    `).join('');
  }

  function renderDirectory() {
    const container = document.getElementById('directory-container');
    if (!container) return;

    container.innerHTML = DIRECTORY_ITEMS.map(d => `
      <div class="directory-item">
        <span class="directory-sector">${d.sector}</span>
        <span class="directory-ext">${d.ext}</span>
      </div>
    `).join('');
  }

  function switchTab(tabKey) {
    currentTab = tabKey;
    
    // Atualiza botões
    const buttons = document.querySelectorAll('.tab-nav-btn');
    buttons.forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === tabKey);
    });

    // Atualiza views
    const views = document.querySelectorAll('.tab-view');
    views.forEach(v => {
      v.classList.toggle('active', v.id === `view-${tabKey}`);
    });
  }

  function startAutoCycle() {
    clearInterval(rotationTimer);
    const tabs = ['notices', 'agenda', 'directory'];
    let tabIndex = tabs.indexOf(currentTab);

    rotationTimer = setInterval(() => {
      tabIndex = (tabIndex + 1) % tabs.length;
      switchTab(tabs[tabIndex]);
    }, TAB_ROTATION_INTERVAL);
  }

  // Administração e Persistência
  function setupAdminModal() {
    const modal = document.getElementById('admin-modal');
    const openBtn = document.getElementById('open-admin-btn');
    const closeBtn = document.getElementById('close-admin-btn');
    const formNotice = document.getElementById('form-add-notice');
    const formAgenda = document.getElementById('form-add-agenda');
    const resetBtn = document.getElementById('btn-reset-defaults');
    const citySelect = document.getElementById('admin-weather-city');
    const deptInput = document.getElementById('admin-dept-name');

    if (openBtn && modal) {
      openBtn.addEventListener('click', () => {
        modal.classList.add('open');
        // Preenche campos atuais
        if (citySelect && WeatherModule) {
          citySelect.value = WeatherModule.getCurrentCity();
        }
        if (deptInput) {
          deptInput.value = localStorage.getItem('secsa_dept_name') || 'SECRETARIA DE ADMINISTRAÇÃO & GESTÃO';
        }
      });
    }

    if (closeBtn && modal) {
      closeBtn.addEventListener('click', () => modal.classList.remove('open'));
    }

    // Atalho de teclado: pressionar 'E' abre as configurações
    window.addEventListener('keydown', (e) => {
      if (e.key === 'e' || e.key === 'E') {
        // Se não estiver digitando em um input
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
        if (modal) modal.classList.toggle('open');
      }
    });

    // Salvar Novo Comunicado
    if (formNotice) {
      formNotice.addEventListener('submit', (e) => {
        e.preventDefault();
        const title = document.getElementById('notice-input-title').value.trim();
        const priority = document.getElementById('notice-input-priority').value;
        const text = document.getElementById('notice-input-text').value.trim();
        
        let priorityLabel = 'Informativo';
        if (priority === 'urgent') priorityLabel = 'Urgente';
        if (priority === 'warning') priorityLabel = 'Atenção';

        if (title && text) {
          notices.unshift({
            id: Date.now(),
            title,
            priority,
            priorityLabel,
            date: 'Adicionado agora',
            text
          });
          // Mantém máximo de 6 comunicados
          if (notices.length > 6) notices.pop();
          localStorage.setItem('secsa_notices', JSON.stringify(notices));
          renderNotices();
          formNotice.reset();
          alert('Comunicado adicionado com sucesso!');
        }
      });
    }

    // Salvar Novo Evento na Agenda
    if (formAgenda) {
      formAgenda.addEventListener('submit', (e) => {
        e.preventDefault();
        const time = document.getElementById('agenda-input-time').value.trim();
        const title = document.getElementById('agenda-input-title').value.trim();
        const room = document.getElementById('agenda-input-room').value.trim();

        if (time && title && room) {
          agenda.push({
            id: Date.now(),
            time,
            title,
            room,
            status: 'upcoming',
            statusLabel: `Às ${time}`
          });
          // Ordena por horário
          agenda.sort((a, b) => a.time.localeCompare(b.time));
          localStorage.setItem('secsa_agenda', JSON.stringify(agenda));
          renderAgenda();
          formAgenda.reset();
          alert('Compromisso agendado com sucesso!');
        }
      });
    }

    // Alterar Cidade do Clima
    if (citySelect) {
      citySelect.addEventListener('change', (e) => {
        if (WeatherModule) {
          WeatherModule.setCity(e.target.value);
        }
      });
    }

    // Alterar Nome da Secretaria
    if (deptInput) {
      deptInput.addEventListener('change', (e) => {
        const val = e.target.value.trim() || 'SECRETARIA DE ADMINISTRAÇÃO & GESTÃO';
        localStorage.setItem('secsa_dept_name', val);
        const el = document.getElementById('brand-department-name');
        if (el) el.textContent = val;
      });
    }

    // Restaurar Padrões
    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        if (confirm('Deseja restaurar todos os avisos e compromissos para os padrões de fábrica?')) {
          localStorage.removeItem('secsa_notices');
          localStorage.removeItem('secsa_agenda');
          localStorage.removeItem('secsa_dept_name');
          notices = [...DEFAULT_NOTICES];
          agenda = [...DEFAULT_AGENDA];
          renderNotices();
          renderAgenda();
          const el = document.getElementById('brand-department-name');
          if (el) el.textContent = 'SECRETARIA DE ADMINISTRAÇÃO & GESTÃO';
          modal.classList.remove('open');
        }
      });
    }
  }

  function init() {
    renderNotices();
    renderAgenda();
    renderDirectory();
    setupAdminModal();

    // Configuração dos botões de aba
    const buttons = document.querySelectorAll('.tab-nav-btn');
    buttons.forEach(btn => {
      btn.addEventListener('click', () => {
        switchTab(btn.dataset.tab);
        startAutoCycle(); // Reinicia o ciclo ao clicar manualmente
      });
    });

    startAutoCycle();

    // Aplica nome personalizado da secretaria se houver
    const savedDept = localStorage.getItem('secsa_dept_name');
    if (savedDept) {
      const el = document.getElementById('brand-department-name');
      if (el) el.textContent = savedDept;
    }
  }

  return {
    init,
    switchTab
  };
})();
