/**
 * MAIN APP CONTROLLER - DASHBOARD TV 42"
 * Inicialização dos módulos, relógio em tempo real, fullscreen e auto-hide de cursor
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Relógio e Calendário em Tempo Real
  function updateClock() {
    const now = new Date();

    // Formato de horas e minutos com zero à esquerda
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');

    const clockMain = document.getElementById('clock-main-time');
    const clockSec = document.getElementById('clock-seconds');
    if (clockMain) clockMain.textContent = `${hours}:${minutes}`;
    if (clockSec) clockSec.textContent = `:${seconds}`;

    // Dia da semana e data por extenso em português
    const weekdays = [
      'Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira',
      'Quinta-feira', 'Sexta-feira', 'Sábado'
    ];
    const months = [
      'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
      'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
    ];

    const weekdayStr = weekdays[now.getDay()];
    const day = String(now.getDate()).padStart(2, '0');
    const monthStr = months[now.getMonth()];
    const year = now.getFullYear();

    const weekdayElem = document.getElementById('clock-weekday');
    const calendarElem = document.getElementById('clock-calendar');

    if (weekdayElem) weekdayElem.textContent = weekdayStr;
    if (calendarElem) calendarElem.textContent = `${day} de ${monthStr} de ${year}`;
  }

  // Inicia relógio e executa a cada segundo
  updateClock();
  setInterval(updateClock, 1000);

  // 2. Modo Tela Cheia (Fullscreen)
  const fullscreenBtn = document.getElementById('btn-toggle-fullscreen');
  function toggleFullScreen() {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch((err) => {
        console.warn(`Erro ao tentar modo tela cheia: ${err.message}`);
      });
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    }
  }

  if (fullscreenBtn) {
    fullscreenBtn.addEventListener('click', toggleFullScreen);
  }

  // Tecla F ou duplo clique no cabeçalho ativa tela cheia
  window.addEventListener('keydown', (e) => {
    if (e.key === 'f' || e.key === 'F') {
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
      toggleFullScreen();
    }
  });

  const header = document.querySelector('.app-header');
  if (header) {
    header.addEventListener('dblclick', toggleFullScreen);
  }

  // 3. Ocultação Automática do Cursor em Caso de Inatividade (Modo TV)
  let cursorTimer = null;
  function handleMouseMove() {
    document.body.classList.remove('cursor-hidden');
    clearTimeout(cursorTimer);
    cursorTimer = setTimeout(() => {
      // Oculta o cursor após 3.5 segundos sem movimento na TV
      document.body.classList.add('cursor-hidden');
    }, 3500);
  }

  window.addEventListener('mousemove', handleMouseMove);
  handleMouseMove(); // Inicializa timeout

  // 4. Inicialização dos Módulos Especializados
  if (typeof WeatherModule !== 'undefined') {
    WeatherModule.init();
  }

  if (typeof NewsModule !== 'undefined') {
    NewsModule.init();
  }

  if (typeof SecretaryModule !== 'undefined') {
    SecretaryModule.init();
  }

  console.log('✅ Dashboard da Secretaria Administrativa carregado e ativo para TV de 42".');
});
