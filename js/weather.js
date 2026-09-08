/**
 * WEATHER MODULE - DASHBOARD TV
 * Suporte a previsão do tempo em tempo real com Open-Meteo API e contingência
 */

const WeatherModule = (function () {
  // Cidades capitais e coordenadas pré-mapeadas para carregamento instantâneo
  const PRESET_CITIES = {
    'Brasília': { lat: -15.7975, lon: -47.8919, state: 'DF' },
    'São Paulo': { lat: -23.5505, lon: -46.6333, state: 'SP' },
    'Rio de Janeiro': { lat: -22.9068, lon: -43.1729, state: 'RJ' },
    'Belo Horizonte': { lat: -19.9167, lon: -43.9345, state: 'MG' },
    'Curitiba': { lat: -25.4284, lon: -49.2733, state: 'PR' },
    'Salvador': { lat: -12.9714, lon: -38.5014, state: 'BA' },
    'Fortaleza': { lat: -3.7172, lon: -38.5433, state: 'CE' },
    'Recife': { lat: -8.0476, lon: -34.8770, state: 'PE' },
    'Porto Alegre': { lat: -30.0346, lon: -51.2177, state: 'RS' },
    'Goiânia': { lat: -16.6869, lon: -49.2648, state: 'GO' },
    'Manaus': { lat: -3.1190, lon: -60.0217, state: 'AM' },
    'Belém': { lat: -1.4558, lon: -48.4902, state: 'PA' }
  };

  // Código WMO para Descrições e Ícones em Português
  const WMO_CODES = {
    0: { desc: 'Céu Limpo', type: 'clear' },
    1: { desc: 'Predomínio de Sol', type: 'mostly-clear' },
    2: { desc: 'Parcialmente Nublado', type: 'partly-cloudy' },
    3: { desc: 'Nublado', type: 'cloudy' },
    45: { desc: 'Nevoeiro', type: 'fog' },
    48: { desc: 'Nevoeiro Denso', type: 'fog' },
    51: { desc: 'Garoa Leve', type: 'drizzle' },
    53: { desc: 'Garoa Moderada', type: 'drizzle' },
    55: { desc: 'Garoa Intensa', type: 'drizzle' },
    61: { desc: 'Chuva Fraca', type: 'rain' },
    63: { desc: 'Chuva Moderada', type: 'rain' },
    65: { desc: 'Chuva Forte', type: 'rain-heavy' },
    80: { desc: 'Pancadas de Chuva', type: 'rain' },
    81: { desc: 'Pancadas Moderadas', type: 'rain' },
    82: { desc: 'Chuva Torrencial', type: 'rain-heavy' },
    95: { desc: 'Tempestade com Raios', type: 'thunder' },
    96: { desc: 'Tempestade com Granizo', type: 'thunder' },
    99: { desc: 'Tempestade Severa', type: 'thunder' }
  };

  // SVGs de clima premium e animados
  function getWeatherIconSVG(type) {
    switch (type) {
      case 'clear':
        return `
          <svg viewBox="0 0 64 64" fill="none">
            <circle cx="32" cy="32" r="14" fill="url(#sunGrad)" />
            <g class="sun-spin" stroke="#f59e0b" stroke-width="3" stroke-linecap="round">
              <line x1="32" y1="6" x2="32" y2="12" />
              <line x1="32" y1="52" x2="32" y2="58" />
              <line x1="6" y1="32" x2="12" y2="32" />
              <line x1="52" y1="32" x2="58" y2="32" />
              <line x1="13.6" y1="13.6" x2="17.8" y2="17.8" />
              <line x1="46.2" y1="46.2" x2="50.4" y2="50.4" />
              <line x1="13.6" y1="50.4" x2="17.8" y2="46.2" />
              <line x1="46.2" y1="17.8" x2="50.4" y2="13.6" />
            </g>
            <defs>
              <linearGradient id="sunGrad" x1="18" y1="18" x2="46" y2="46" gradientUnits="userSpaceOnUse">
                <stop stop-color="#fde047" />
                <stop offset="1" stop-color="#f59e0b" />
              </linearGradient>
            </defs>
          </svg>
        `;
      case 'partly-cloudy':
      case 'mostly-clear':
        return `
          <svg viewBox="0 0 64 64" fill="none">
            <circle cx="42" cy="22" r="10" fill="#f59e0b" />
            <g class="cloud-drift">
              <path d="M46 44H20a10 10 0 010-20 12 12 0 0122-3 9 9 0 014 23z" fill="url(#cloudGrad)" />
            </g>
            <defs>
              <linearGradient id="cloudGrad" x1="10" y1="20" x2="48" y2="44" gradientUnits="userSpaceOnUse">
                <stop stop-color="#94a3b8" />
                <stop offset="1" stop-color="#e2e8f0" />
              </linearGradient>
            </defs>
          </svg>
        `;
      case 'cloudy':
      case 'fog':
        return `
          <svg viewBox="0 0 64 64" fill="none">
            <g class="cloud-drift">
              <path d="M48 44H18a11 11 0 010-22 13 13 0 0124-3 10 10 0 016 25z" fill="url(#overcastGrad)" />
            </g>
            <defs>
              <linearGradient id="overcastGrad" x1="10" y1="18" x2="50" y2="44" gradientUnits="userSpaceOnUse">
                <stop stop-color="#64748b" />
                <stop offset="1" stop-color="#94a3b8" />
              </linearGradient>
            </defs>
          </svg>
        `;
      case 'rain':
      case 'drizzle':
        return `
          <svg viewBox="0 0 64 64" fill="none">
            <path d="M46 36H20a9 9 0 010-18 11 11 0 0120-2 8 8 0 016 20z" fill="#94a3b8" />
            <line class="rain-drop" x1="22" y1="42" x2="20" y2="52" stroke="#38bdf8" stroke-width="2.5" stroke-linecap="round" />
            <line class="rain-drop" style="animation-delay: 0.3s;" x1="32" y1="42" x2="30" y2="52" stroke="#38bdf8" stroke-width="2.5" stroke-linecap="round" />
            <line class="rain-drop" style="animation-delay: 0.6s;" x1="42" y1="42" x2="40" y2="52" stroke="#38bdf8" stroke-width="2.5" stroke-linecap="round" />
          </svg>
        `;
      case 'rain-heavy':
      case 'thunder':
        return `
          <svg viewBox="0 0 64 64" fill="none">
            <path d="M46 32H18a10 10 0 010-20 12 12 0 0122-3 9 9 0 016 23z" fill="#475569" />
            <polygon points="32,34 26,45 31,45 28,56 38,43 32,43" fill="#facc15" />
          </svg>
        `;
      default:
        return getWeatherIconSVG('clear');
    }
  }

  // Estado Atual
  let currentCity = localStorage.getItem('secsa_weather_city') || 'Brasília';

  async function fetchWeatherData(cityKey) {
    const city = PRESET_CITIES[cityKey] || PRESET_CITIES['Brasília'];
    try {
      const url = `https://api.open-meteo.com/v1/forecast?latitude=${city.lat}&longitude=${city.lon}&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,uv_index&daily=weather_code,temperature_2m_max,temperature_2m_min&timezone=America%2FSao_Paulo&forecast_days=5`;
      const response = await fetch(url);
      if (!response.ok) throw new Error('Falha na resposta do clima');
      const data = await response.json();
      return parseWeatherData(cityKey, city.state, data);
    } catch (err) {
      console.warn('Usando dados de contingência meteorológica:', err);
      return getFallbackWeatherData(cityKey, city.state);
    }
  }

  function parseWeatherData(cityName, state, apiData) {
    const cur = apiData.current;
    const wmo = WMO_CODES[cur.weather_code] || { desc: 'Estável', type: 'clear' };

    const daily = [];
    const weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    for (let i = 1; i <= 4; i++) {
      if (apiData.daily.time[i]) {
        const dateObj = new Date(apiData.daily.time[i] + 'T12:00:00');
        const dayCode = apiData.daily.weather_code[i];
        const dayWmo = WMO_CODES[dayCode] || { desc: 'Limpo', type: 'clear' };
        daily.push({
          day: weekdays[dateObj.getDay()],
          max: Math.round(apiData.daily.temperature_2m_max[i]),
          min: Math.round(apiData.daily.temperature_2m_min[i]),
          type: dayWmo.type
        });
      }
    }

    return {
      city: cityName,
      state: state,
      temp: Math.round(cur.temperature_2m),
      sensation: Math.round(cur.apparent_temperature),
      condition: wmo.desc,
      type: wmo.type,
      humidity: cur.relative_humidity_2m + '%',
      wind: Math.round(cur.wind_speed_10m) + ' km/h',
      rainProb: (cur.precipitation || 0) + ' mm',
      uvIndex: cur.uv_index ? Math.round(cur.uv_index) : 4,
      dailyForecast: daily
    };
  }

  function getFallbackWeatherData(cityName, state) {
    return {
      city: cityName,
      state: state,
      temp: 26,
      sensation: 27,
      condition: 'Parcialmente Nublado',
      type: 'partly-cloudy',
      humidity: '64%',
      wind: '14 km/h',
      rainProb: '10%',
      uvIndex: 6,
      dailyForecast: [
        { day: 'Amanhã', max: 28, min: 18, type: 'clear' },
        { day: 'Qui', max: 27, min: 19, type: 'partly-cloudy' },
        { day: 'Sex', max: 25, min: 17, type: 'rain' },
        { day: 'Sáb', max: 28, min: 18, type: 'clear' }
      ]
    };
  }

  function render(weather) {
    // Top Info
    const locElem = document.getElementById('weather-location-name');
    if (locElem) locElem.textContent = `${weather.city}, ${weather.state}`;

    const tempElem = document.getElementById('weather-temp-num');
    if (tempElem) tempElem.textContent = weather.temp;

    const condElem = document.getElementById('weather-condition-desc');
    if (condElem) condElem.textContent = weather.condition;

    const sensElem = document.getElementById('weather-sensation-num');
    if (sensElem) sensElem.textContent = `${weather.sensation}°C`;

    // Ícone Principal
    const iconContainer = document.getElementById('weather-main-icon');
    if (iconContainer) iconContainer.innerHTML = getWeatherIconSVG(weather.type);

    // Métricas
    const humElem = document.getElementById('weather-metric-humidity');
    if (humElem) humElem.textContent = weather.humidity;

    const windElem = document.getElementById('weather-metric-wind');
    if (windElem) windElem.textContent = weather.wind;

    const rainElem = document.getElementById('weather-metric-rain');
    if (rainElem) rainElem.textContent = weather.rainProb;

    const uvElem = document.getElementById('weather-metric-uv');
    if (uvElem) uvElem.textContent = weather.uvIndex;

    // Previsão dos Próximos 4 Dias
    const forecastStrip = document.getElementById('weather-forecast-days');
    if (forecastStrip && weather.dailyForecast) {
      forecastStrip.innerHTML = weather.dailyForecast.map(item => `
        <div class="forecast-day-card">
          <span class="forecast-day-name">${item.day}</span>
          <div class="forecast-icon-mini">${getWeatherIconSVG(item.type)}</div>
          <div class="forecast-temp-range">
            <span class="temp-max">${item.max}°</span>
            <span class="temp-min">${item.min}°</span>
          </div>
        </div>
      `).join('');
    }
  }

  async function update() {
    const data = await fetchWeatherData(currentCity);
    render(data);
  }

  function setCity(newCity) {
    if (PRESET_CITIES[newCity]) {
      currentCity = newCity;
      localStorage.setItem('secsa_weather_city', newCity);
      update();
    }
  }

  function init() {
    update();
    // Atualiza clima a cada 15 minutos (900.000 ms)
    setInterval(update, 15 * 60 * 1000);
  }

  return {
    init,
    update,
    setCity,
    getCities: () => Object.keys(PRESET_CITIES),
    getCurrentCity: () => currentCity
  };
})();
