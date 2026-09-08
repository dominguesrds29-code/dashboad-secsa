/**
 * NEWS MODULE - NOTÍCIAS DO BRASIL
 * Carrossel automatizado, barra de progresso e sincronização com o letreiro inferior
 */

const NewsModule = (function () {
  let newsList = [];
  let currentIndex = 0;
  let cycleTimer = null;
  let progressAnimation = null;
  const ROTATION_INTERVAL = 14000; // 14 segundos por notícia para leitura confortável em TV

  // Carrega notícias locais ou de API remota
  async function loadNews() {
    try {
      // Tenta carregar notícias da Agência Brasil ou feed público
      const localResp = await fetch('assets/mock-news.json');
      if (localResp.ok) {
        newsList = await localResp.json();
      }
    } catch (e) {
      console.warn('Erro ao carregar notícias:', e);
      // Fallback embutido caso haja erro de rede
      newsList = [
        {
          id: 1,
          title: "Governo Federal amplia investimentos em infraestrutura e modernização digital",
          category: "GESTÃO PÚBLICA",
          categoryColor: "#3b82f6",
          summary: "Novas plataformas prometem acelerar a análise de processos e garantir atendimento prioritário ao cidadão.",
          source: "Agência Brasil",
          time: "Há 15 min",
          image: "https://images.unsplash.com/photo-1541872703-74c5e44368f9?auto=format&fit=crop&w=1200&q=80"
        }
      ];
    }

    if (newsList.length > 0) {
      renderCurrentNews();
      renderUpcomingNews();
      renderTicker();
      startCycle();
    }
  }

  function renderCurrentNews() {
    const item = newsList[currentIndex];
    if (!item) return;

    const card = document.getElementById('featured-news-card');
    const imgBg = document.getElementById('news-image-bg');
    const catBadge = document.getElementById('news-category-badge');
    const timeElem = document.getElementById('news-time-elem');
    const sourceElem = document.getElementById('news-source-elem');
    const titleElem = document.getElementById('news-title-elem');
    const summaryElem = document.getElementById('news-summary-elem');

    if (imgBg) {
      imgBg.src = item.image;
      imgBg.alt = item.title;
    }

    if (catBadge) {
      catBadge.textContent = item.category;
      if (item.categoryColor) {
        catBadge.style.backgroundColor = item.categoryColor;
      }
    }

    if (timeElem) timeElem.textContent = item.time;
    if (sourceElem) sourceElem.textContent = item.source;
    if (titleElem) titleElem.textContent = item.title;
    if (summaryElem) summaryElem.textContent = item.summary;

    // Adiciona classe de animação suave de transição
    if (card) {
      card.classList.remove('news-fade-in');
      void card.offsetWidth; // Trigger reflow
      card.classList.add('news-fade-in');
    }

    renderUpcomingNews();
  }

  function renderUpcomingNews() {
    const listContainer = document.getElementById('upcoming-news-list');
    if (!listContainer || newsList.length === 0) return;

    // Mostra as próximas 3 notícias na fila
    const upcoming = [];
    for (let i = 1; i <= 3; i++) {
      const idx = (currentIndex + i) % newsList.length;
      upcoming.push({ ...newsList[idx], listIndex: idx });
    }

    listContainer.innerHTML = upcoming.map((item, i) => `
      <div class="upcoming-news-item ${i === 0 ? 'active' : ''}" onclick="NewsModule.jumpTo(${item.listIndex})">
        <span class="upcoming-item-tag" style="color: ${item.categoryColor || '#38bdf8'}">${item.category}</span>
        <div class="upcoming-item-title">${item.title}</div>
      </div>
    `).join('');
  }

  function renderTicker() {
    const tickerTrack = document.getElementById('ticker-content-track');
    if (!tickerTrack || newsList.length === 0) return;

    // Duplica a lista para rolagem contínua sem quebras
    const combinedNews = [...newsList, ...newsList];
    tickerTrack.innerHTML = combinedNews.map(item => `
      <div class="ticker-news-item">
        <span class="ticker-bullet">✦</span>
        <strong style="color: ${item.categoryColor || '#38bdf8'}">[${item.category}]</strong>
        <span>${item.title}</span>
      </div>
    `).join('');
  }

  function startCycle() {
    clearInterval(cycleTimer);
    resetProgressBar();

    cycleTimer = setInterval(() => {
      nextNews();
    }, ROTATION_INTERVAL);

    animateProgressBar();
  }

  function resetProgressBar() {
    const fill = document.getElementById('news-timer-fill');
    if (fill) {
      fill.style.transition = 'none';
      fill.style.width = '0%';
    }
  }

  function animateProgressBar() {
    const fill = document.getElementById('news-timer-fill');
    if (fill) {
      setTimeout(() => {
        fill.style.transition = `width ${ROTATION_INTERVAL}ms linear`;
        fill.style.width = '100%';
      }, 50);
    }
  }

  function nextNews() {
    currentIndex = (currentIndex + 1) % newsList.length;
    renderCurrentNews();
    resetProgressBar();
    animateProgressBar();
  }

  function jumpTo(index) {
    currentIndex = index;
    renderCurrentNews();
    startCycle();
  }

  function init() {
    loadNews();
    // Atualiza o feed de notícias a cada 30 minutos
    setInterval(loadNews, 30 * 60 * 1000);
  }

  return {
    init,
    nextNews,
    jumpTo
  };
})();
