const reportsScreen = document.getElementById('reports-screen')

if (reportsScreen) {
  const $ = (selector) => reportsScreen.querySelector(selector)
  const state = { data: null, filters: null, requestId: 0, chartPoints: [] }
  const groupOrder = ['Healthy', 'Needs Attention', 'Unhealthy', 'Unable to Determine']
  const groupClass = {
    Healthy: 'healthy',
    'Needs Attention': 'attention',
    Unhealthy: 'unhealthy',
    'Unable to Determine': 'undetermined',
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
    })[character])
  }

  function todayString() {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(new Date())
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
    return `${values.year}-${values.month}-${values.day}`
  }

  function dateOffset(value, months = 0, days = 0) {
    const [year, month, day] = value.split('-').map(Number)
    const firstOfTargetMonth = new Date(Date.UTC(year, month - 1 + months, 1))
    const lastDay = new Date(Date.UTC(
      firstOfTargetMonth.getUTCFullYear(),
      firstOfTargetMonth.getUTCMonth() + 1,
      0,
    )).getUTCDate()
    const targetDay = Math.min(day, lastDay)
    const result = new Date(Date.UTC(
      firstOfTargetMonth.getUTCFullYear(),
      firstOfTargetMonth.getUTCMonth(),
      targetDay + days,
    ))
    return `${result.getUTCFullYear()}-${String(result.getUTCMonth() + 1).padStart(2, '0')}-${String(result.getUTCDate()).padStart(2, '0')}`
  }

  function dateLabel(value, options = { dateStyle: 'medium' }) {
    if (!value) return 'Not recorded'
    const text = String(value)
    const date = /^\d{4}-\d{2}-\d{2}$/.test(text)
      ? new Date(`${text}T12:00:00+08:00`)
      : new Date(text)
    if (Number.isNaN(date.getTime())) return 'Date unavailable'
    return new Intl.DateTimeFormat('en-PH', {
      ...options,
      timeZone: 'Asia/Manila',
    }).format(date)
  }

  function timeLabel(value) {
    const time = String(value ?? '').slice(0, 5)
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) return 'Time not set'
    return new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      timeZone: 'Asia/Manila',
    }).format(new Date(`2000-01-01T${time}:00+08:00`))
  }

  function setFeedback(message = '') {
    const feedback = $('#reports-feedback')
    feedback.textContent = message
    feedback.hidden = !message
  }

  function showCustomDates() {
    const isCustom = $('#reports-range').value === 'custom'
    const dates = $('#reports-custom-dates')
    $('#reports-filter-row').hidden = !isCustom
    dates.hidden = !isCustom
    $('#reports-apply').hidden = !isCustom
    reportsScreen.querySelectorAll('[data-report-range]').forEach((button) => {
      const selected = button.dataset.reportRange === $('#reports-range').value
      button.classList.toggle('selected', selected)
      button.setAttribute('aria-pressed', String(selected))
    })
    dates.querySelectorAll('input').forEach((input) => {
      input.disabled = !isCustom
      input.required = isCustom
    })
    if (isCustom) {
      const today = todayString()
      if (!$('#reports-to').value) $('#reports-to').value = today
      if (!$('#reports-from').value) $('#reports-from').value = dateOffset(today, 0, -29)
    }
  }

  function selectedFilters() {
    const today = todayString()
    const range = $('#reports-range').value
    const from = range === 'custom'
      ? $('#reports-from').value
      : range === '3m'
        ? dateOffset(today, -3, 0)
        : dateOffset(today, 0, -29)
    const to = range === 'custom' ? $('#reports-to').value : today
    if (!from || !to || from > to) {
      throw new Error('Choose a valid start and end date.')
    }
    return {
      from,
      to,
      plant_id: $('#reports-plant').value || '',
    }
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json', ...(options.headers || {}) },
      ...options,
    })
    let result
    try {
      result = await response.json()
    } catch {
      throw new Error('The reports service returned an invalid response.')
    }
    if (!response.ok || result?.success !== true) {
      if (response.status === 401) throw new Error('Your session expired. Sign in again to view reports.')
      throw new Error(result?.message || 'Unable to load farm reports.')
    }
    return result
  }

  function renderPlantOptions(plants, selectedId) {
    const selector = $('#reports-plant')
    const current = plants.some((plant) => plant.id === selectedId) ? selectedId : ''
    selector.innerHTML = `<option value="">All registered plants</option>${plants.map((plant) => (
      `<option value="${escapeHtml(plant.id)}">${escapeHtml(plant.name)}${plant.species ? ` · ${escapeHtml(plant.species)}` : ''}</option>`
    )).join('')}`
    selector.value = current
  }

  function renderSummary(data) {
    $('#reports-total-plants').textContent = String(data.summary.registered_plants)
    $('#reports-health-percent').textContent = data.summary.healthy_percent === null
      ? '—'
      : `${data.summary.healthy_percent}%`
    $('#reports-completed-tasks').textContent = String(data.watering_activity.completed)
  }

  function renderHealthOverview(data) {
    const container = $('#reports-health-overview')
    const total = data.summary.registered_plants
    if (!total) {
      container.innerHTML = '<p class="reports-empty">No registered plants yet. Add a plant in Farm Logs to start tracking health.</p>'
      return
    }
    const distribution = data.summary.health_distribution
    const circumference = 2 * Math.PI * 38
    let offset = 0
    const segments = groupOrder.map((group) => {
      const item = distribution[group]
      if (!item.count) return ''
      const length = circumference * item.count / total
      const segment = `<circle class="reports-donut-segment ${groupClass[group]}" cx="50" cy="50" r="38" stroke-dasharray="${length} ${circumference - length}" stroke-dashoffset="${-offset}" transform="rotate(-90 50 50)"><title>${escapeHtml(group)}: ${item.count} plant${item.count === 1 ? '' : 's'} (${item.percent}%)</title></circle>`
      offset += length
      return segment
    }).join('')
    const legend = groupOrder.map((group) => {
      const item = distribution[group]
      if (group === 'Unable to Determine' && !item.count) return ''
      return `<div class="reports-health-legend-item"><i class="reports-health-dot ${groupClass[group]}" aria-hidden="true"></i><span>${escapeHtml(group)}</span><strong>${item.count} plant${item.count === 1 ? '' : 's'}</strong></div>`
    }).join('')
    container.innerHTML = `<div class="reports-health-donut" role="img" aria-label="${total} plants: ${groupOrder.map((group) => `${group} ${distribution[group].count}`).join(', ')}">
        <svg viewBox="0 0 100 100" aria-hidden="true"><circle class="reports-donut-track" cx="50" cy="50" r="38"></circle>${segments}</svg>
        <div class="reports-donut-center"><strong>${data.summary.healthy_percent === null ? '—' : `${data.summary.healthy_percent}%`}</strong><span>Healthy</span></div>
      </div><div class="reports-health-legend">${legend}</div>`
  }

  function renderWatering(data) {
    const activity = data.watering_activity
    const completedValue = Number(activity.completed)
    const scheduledValue = Number(activity.scheduled)
    const completed = Number.isFinite(completedValue) ? Math.max(0, completedValue) : 0
    const scheduled = Number.isFinite(scheduledValue) ? Math.max(0, scheduledValue) : 0
    const reportedRate = activity.completion_percent === null || activity.completion_percent === undefined
      ? Number.NaN
      : Number(activity.completion_percent)
    const calculatedRate = scheduled > 0 ? 100 * completed / scheduled : 0
    const rate = Math.min(100, Math.max(0, Number.isFinite(reportedRate) ? reportedRate : calculatedRate))
    const rateLabel = `${rate}%`
    const circumference = 2 * Math.PI * 27
    const progress = circumference * rate / 100
    $('#reports-watering-metrics').innerHTML = `<div class="reports-watering-ring" role="img" aria-label="${scheduled === 0 ? 'No watering tasks in the selected period; completion is 0%' : `${rateLabel} of ${scheduled} watering tasks completed`}">
        <svg viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="reports-watering-progress" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4c9fcb"></stop><stop offset="100%" stop-color="#4a9a72"></stop></linearGradient></defs><circle class="track" cx="32" cy="32" r="27"></circle><circle class="progress" cx="32" cy="32" r="27" stroke="url(#reports-watering-progress)" stroke-dasharray="${progress} ${circumference - progress}"></circle></svg>
        <div class="reports-watering-ring-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C9 8 5 12 5 16a7 7 0 0 0 14 0c0-4-4-8-7-13Z" fill="#42a8df"></path></svg><strong>${rateLabel}</strong></div>
      </div><div class="reports-watering-total"><strong>${completed} / ${scheduled}</strong><span>Watering Tasks<br>Completed</span></div>`
    const next = activity.next_scheduled
    $('#reports-next-watering').textContent = next ? dateLabel(next.scheduled_on) : 'No task scheduled'
    const remaining = next
      ? Math.round((Date.parse(`${next.scheduled_on}T00:00:00Z`) - Date.parse(`${todayString()}T00:00:00Z`)) / 86400000)
      : null
    $('#reports-next-watering-countdown').textContent = next
      ? remaining === 0 ? `Today · ${timeLabel(next.scheduled_time)}` : `In ${remaining} day${remaining === 1 ? '' : 's'}`
      : ''
    $('#reports-watering-history').innerHTML = data.watering_history.length
      ? `<div class="reports-watering-list">${data.watering_history.map((task) => {
        const status = task.status
        const klass = status === 'overdue' ? 'unhealthy' : status === 'completed' ? 'healthy' : 'attention'
        const amount = task.amount_ml
          ? `${Number(task.amount_ml).toLocaleString()} mL`
          : task.amount_min_ml && task.amount_max_ml
            ? `${Number(task.amount_min_ml).toLocaleString()}–${Number(task.amount_max_ml).toLocaleString()} mL`
            : 'Amount not recorded'
        return `<article class="reports-watering-row"><div class="reports-watering-main"><strong>${escapeHtml(task.plant_name)} · ${escapeHtml(dateLabel(task.scheduled_on))}</strong><small>${escapeHtml(timeLabel(task.scheduled_time))} · ${escapeHtml(amount)}${task.completed_at ? ` · Completed ${escapeHtml(dateLabel(task.completed_at))}` : ''}</small></div><span class="reports-status-badge ${klass}">${escapeHtml(status)}</span></article>`
      }).join('')}</div>`
      : '<p class="reports-empty">No watering task records in this period.</p>'
  }

  function renderTrend(data) {
    const scans = data.scan_history
    const daily = new Map()
    scans.forEach((scan) => {
      if (scan.health_group === 'Unable to Determine') return
      const date = String(scan.scanned_at || '').slice(0, 10)
      if (!date) return
      const entry = daily.get(date) || { date, healthy: 0, determined: 0, scans: [] }
      entry.determined += 1
      if (scan.health_group === 'Healthy') entry.healthy += 1
      entry.scans.push(scan)
      daily.set(date, entry)
    })
    const points = [...daily.values()].map((item) => ({
      ...item,
      percent: Math.round(100 * item.healthy / item.determined),
    }))
    state.chartPoints = points
    if (!points.length) {
      $('#reports-trend-chart').innerHTML = scans.length
        ? '<p class="reports-empty">Saved scans in this period do not have a determined health category to chart.</p>'
        : '<p class="reports-empty">No saved plant scans match this date range and plant filter.</p>'
    } else {
      const width = 350
      const height = 100
      const left = 25
      const right = 9
      const top = 11
      const bottom = 20
      const xAt = (index) => points.length === 1
        ? (left + width - right) / 2
        : left + index * ((width - left - right) / (points.length - 1))
      const yAt = (percent) => top + (100 - percent) * (height - top - bottom) / 100
      const coords = points.map((point, index) => [xAt(index), yAt(point.percent)])
      const line = coords.map(([x, y], index) => `${index ? 'L' : 'M'} ${x} ${y}`).join(' ')
      const area = `${line} L ${coords[coords.length - 1][0]} ${height - bottom} L ${coords[0][0]} ${height - bottom} Z`
      const grid = [0, 50, 100].map((value) => {
        const y = yAt(value)
        return `<line class="reports-chart-grid" x1="${left}" y1="${y}" x2="${width - right}" y2="${y}"></line><text class="reports-chart-axis" x="${left - 5}" y="${y + 3}" text-anchor="end">${value}%</text>`
      }).join('')
      const labelStep = Math.max(1, Math.ceil((points.length - 1) / 4))
      const plotted = points.map((point, index) => {
        const [x, y] = coords[index]
        const label = dateLabel(point.date, { month: 'short', day: 'numeric' })
        const showDate = index === 0 || index === points.length - 1 || index % labelStep === 0
        const anchor = index === 0 ? 'start' : index === points.length - 1 ? 'end' : 'middle'
        return `<g class="reports-trend-point-group"><circle class="reports-trend-point" cx="${x}" cy="${y}" r="3.5" tabindex="0" role="button" aria-label="${escapeHtml(label)}: ${point.percent}% of determined scans classified healthy" data-scan-date="${escapeHtml(point.date)}"></circle>
          ${showDate ? `<text class="reports-chart-axis" x="${x}" y="${height - 7}" text-anchor="${anchor}">${escapeHtml(label)}</text>` : ''}</g>`
      }).join('')
      $('#reports-trend-chart').innerHTML = `<div class="reports-chart-scroll"><svg class="reports-trend-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="Healthy scan share by date, calculated from saved scan results.">
        ${grid}<path class="reports-trend-area" d="${area}"></path><path class="reports-trend-line" d="${line}"></path>${plotted}</svg></div>`
    }
    const scanList = $('#reports-scan-list')
    scanList.innerHTML = scans.length
      ? `<div class="reports-scan-list">${[...scans].reverse().map((scan, index) => {
      const klass = groupClass[scan.health_group] || 'undetermined'
      const change = scan.previous_health_group
        ? scan.health_changed
          ? `Health group changed from ${scan.previous_health_group} to ${scan.health_group} during this period. `
          : 'Same health group as the previous scan in this period. '
        : ''
      return `<article class="reports-scan-row"><div class="reports-scan-main"><strong>${index === 0 ? 'Latest · ' : ''}${escapeHtml(scan.plant_name)} · ${escapeHtml(dateLabel(scan.scanned_at))}</strong>
        <small>${escapeHtml(change)}${escapeHtml(scan.finding || `Saved analysis: ${scan.health_status}`)}</small></div>
        <span class="reports-status-badge ${klass}">${escapeHtml(scan.health_status)}</span></article>`
      }).join('')}</div>`
      : '<p class="reports-empty">Scan history will appear here after an AI scan is saved.</p>'
    $('#reports-scan-tooltip').hidden = true
  }

  function renderPlants(data) {
    const container = $('#reports-plant-list')
    const plants = data.selected_plant_id
      ? data.plants.filter((plant) => plant.id === data.selected_plant_id)
      : data.plants
    if (!plants.length) {
      container.innerHTML = '<p class="reports-empty">No registered plants yet. Add one from Farm Logs.</p>'
      return
    }
    container.innerHTML = plants.map((plant) => {
      const klass = groupClass[plant.health_group] || 'undetermined'
      const latestDate = plant.latest_scan_at ? dateLabel(plant.latest_scan_at) : 'No scan recorded'
      return `<article class="reports-plant-card"><div class="reports-plant-card-head">
        <div><h3>${escapeHtml(plant.name)}</h3><p>${escapeHtml(plant.species || 'Crop type not recorded')} · ${escapeHtml(plant.growth_stage || 'Growth stage unavailable')}</p></div>
        <span class="reports-status-badge ${klass}">${escapeHtml(plant.health_status || 'No scan')}</span></div>
        <p>Latest scan: ${escapeHtml(latestDate)}</p><p class="reports-plant-finding">${escapeHtml(plant.finding || (plant.latest_scan_at ? 'No written finding saved.' : 'No AI scan available.'))}</p>
        <div class="reports-plant-actions"><button type="button" data-report-plant="${escapeHtml(plant.id)}">View report</button>
        <a href="/plant-monitoring.html?plant_id=${encodeURIComponent(plant.id)}">Monitoring details</a></div></article>`
    }).join('')
  }

  function renderFarmLog(activities, filters, errorMessage = '') {
    const container = $('#reports-farm-log')
    if (errorMessage) {
      $('#reports-farm-log-count').textContent = 'Unavailable'
      container.innerHTML = `<p class="reports-empty">${escapeHtml(errorMessage)}</p>`
      return
    }
    const entries = activities.filter((activity) => (
      activity.title !== 'Plant Growth Scan'
      && String(activity.activity_date || '') >= filters.from
      && String(activity.activity_date || '') <= filters.to
      && (!filters.plant_id || activity.plant_id === filters.plant_id)
    ))
    $('#reports-farm-log-count').textContent = `${entries.length} entr${entries.length === 1 ? 'y' : 'ies'}`
    if (!entries.length) {
      container.innerHTML = '<p class="reports-empty">No Farm Log entries match this period and plant filter.</p>'
      return
    }
    container.innerHTML = `<div class="reports-scan-list">${entries.map((activity) => {
      const details = [
        activity.details,
        activity.growth_stage ? `Growth stage: ${activity.growth_stage}` : '',
        activity.climate_risk ? `Climate risk: ${activity.climate_risk}` : '',
        activity.actions_taken ? `Actions: ${activity.actions_taken}` : '',
        activity.harvest_quantity
          ? `Harvest: ${activity.harvest_quantity}${activity.harvest_unit ? ` ${activity.harvest_unit}` : ''}`
          : '',
      ].filter(Boolean).join(' · ')
      const plant = activity.related_plant || ''
      return `<article class="reports-scan-row"><div class="reports-scan-main">
        <strong>${escapeHtml(activity.activity_type)} · ${escapeHtml(dateLabel(activity.activity_date))}${plant ? ` · ${escapeHtml(plant)}` : ''}</strong>
        <small>${escapeHtml(details || 'No additional details saved.')}</small></div>
        <span class="reports-status-badge">${escapeHtml(activity.activity_type)}</span></article>`
    }).join('')}</div>`
  }

  function renderInsightsPreview(data) {
    const distribution = data.summary.health_distribution
    const attention = distribution['Needs Attention'].count + distribution.Unhealthy.count
    const activity = data.watering_activity
    let message
    if (!data.plants.length && !data.scan_history.length && !activity.scheduled) {
      message = 'No saved plant scans or watering tasks yet. Add real activity to receive relevant care tips.'
    } else if (activity.overdue > 0) {
      message = `${activity.overdue} watering task${activity.overdue === 1 ? ' is' : 's are'} overdue in this period. Review the schedule and check soil moisture before watering.`
    } else if (attention > 0) {
      message = `${attention} plant${attention === 1 ? ' needs' : 's need'} attention in the latest saved scans. Review the findings and confirm growing conditions.`
    } else if (!data.scan_history.length && !activity.scheduled) {
      message = 'No scans or watering tasks fall within this date range. The health overview above still reflects each plant’s latest saved scan.'
    } else if (distribution.Healthy.count > 0) {
      message = `${distribution.Healthy.count} of ${data.summary.registered_plants} plants ${distribution.Healthy.count === 1 ? 'is' : 'are'} classified healthy by the latest saved scan. Keep monitoring and verify soil moisture before watering.`
    } else if (activity.pending > 0) {
      message = `${activity.pending} watering task${activity.pending === 1 ? ' remains' : 's remain'} pending in this period. Check the selected dates and mark completed tasks when done.`
    } else if (data.scan_history.length > 0) {
      message = 'Recent scans do not provide enough determined health information for a plant-care conclusion.'
    } else {
      message = `${activity.completed} watering task${activity.completed === 1 ? ' was' : 's were'} completed in this period. A saved plant scan can add current health context.`
    }
    $('#reports-insights-preview').textContent = message
  }

  function clearInsights(message = 'Generate insights from the currently selected report records.') {
    const button = $('#reports-insights-button')
    button.disabled = !state.data?.insights_available
    $('#reports-insights-list').innerHTML = `<p class="reports-empty">${escapeHtml(message)}</p>`
    $('#reports-insights-list').hidden = true
  }

  function renderReports(data, activities, filters, farmLogError) {
    state.data = data
    renderPlantOptions(data.plants, data.selected_plant_id || '')
    renderSummary(data)
    renderHealthOverview(data)
    renderWatering(data)
    renderTrend(data)
    renderPlants(data)
    renderFarmLog(activities, filters, farmLogError)
    renderInsightsPreview(data)
    $('#reports-health-details').hidden = true
    $('#reports-view-health').textContent = 'View Details →'
    $('#reports-scan-history').hidden = true
    $('#reports-view-all-scans').textContent = 'View All →'
    clearInsights(data.insights_available
      ? 'Gemini insights use only saved records for the selected period.'
      : 'Save a plant scan or watering record before generating AI insights.')
  }

  async function loadReports(filters) {
    const requestId = ++state.requestId
    const query = new URLSearchParams({ from: filters.from, to: filters.to })
    if (filters.plant_id) query.set('plant_id', filters.plant_id)
    setFeedback('')
    try {
      const data = await fetchJson(`/api/farm-reports.php?${query}`)
      let activities = []
      let farmLogError = ''
      try {
        const activityData = await fetchJson('/api/farm-activities.php')
        if (!Array.isArray(activityData.activities)) throw new Error('The Farm Log service returned an invalid response.')
        activities = activityData.activities
      } catch (error) {
        farmLogError = error.message
        console.error('Unable to load Farm Log entries for reports:', error)
      }
      if (requestId !== state.requestId) return
      state.filters = filters
      renderReports(data, activities, filters, farmLogError)
    } catch (error) {
      if (requestId !== state.requestId) return
      console.error('Unable to load farm reports:', error)
      setFeedback(error.message)
      $('#reports-health-overview').innerHTML = `<p class="reports-empty">${escapeHtml(error.message)}</p>`
    }
  }

  $('#reports-range').addEventListener('change', showCustomDates)
  reportsScreen.querySelectorAll('[data-report-range]').forEach((button) => {
    button.addEventListener('click', () => {
      $('#reports-range').value = button.dataset.reportRange
      showCustomDates()
      if (button.dataset.reportRange !== 'custom') {
        try {
          void loadReports(selectedFilters())
        } catch (error) {
          setFeedback(error.message)
        }
      }
    })
  })
  $('#reports-plant').addEventListener('change', () => {
    try {
      void loadReports(selectedFilters())
    } catch (error) {
      setFeedback(error.message)
    }
  })
  $('#reports-filters').addEventListener('submit', (event) => {
    event.preventDefault()
    try {
      void loadReports(selectedFilters())
    } catch (error) {
      setFeedback(error.message)
    }
  })
  $('#reports-view-health').addEventListener('click', (event) => {
    const details = $('#reports-health-details')
    details.hidden = !details.hidden
    event.currentTarget.textContent = details.hidden ? 'View Details →' : 'Hide Details ↑'
  })
  $('#reports-view-all-scans').addEventListener('click', (event) => {
    const history = $('#reports-scan-history')
    history.hidden = !history.hidden
    event.currentTarget.textContent = history.hidden ? 'View All →' : 'Show Less ↑'
  })
  $('#reports-trend-chart').addEventListener('click', (event) => {
    const point = event.target.closest('[data-scan-date]')
    if (!point) return
    const entry = state.chartPoints.find((item) => item.date === point.dataset.scanDate)
    if (!entry) return
    const names = entry.scans.map((scan) => `${scan.plant_name}: ${scan.health_status}`).join('; ')
    $('#reports-scan-tooltip').textContent = `${dateLabel(entry.date)} · ${entry.healthy}/${entry.determined} determined scans classified healthy (${entry.percent}%). ${names}`
    $('#reports-scan-tooltip').hidden = false
  })
  $('#reports-trend-chart').addEventListener('keydown', (event) => {
    if (!['Enter', ' '].includes(event.key) || !event.target.matches('[data-scan-date]')) return
    event.preventDefault()
    event.target.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  })
  $('#reports-plant-list').addEventListener('click', (event) => {
    const button = event.target.closest('[data-report-plant]')
    if (!button) return
    $('#reports-plant').value = button.dataset.reportPlant
    try {
      void loadReports(selectedFilters())
      $('#reports-trend-title').scrollIntoView({ behavior: 'smooth', block: 'start' })
    } catch (error) {
      setFeedback(error.message)
    }
  })
  $('#reports-insights-button').addEventListener('click', async (event) => {
    const button = event.currentTarget
    let filters
    try {
      filters = selectedFilters()
    } catch (error) {
      setFeedback(error.message)
      return
    }
    button.disabled = true
    $('#reports-insights-list').innerHTML = '<p class="reports-empty">Gemini is reviewing your saved scan and watering records…</p>'
    $('#reports-insights-list').hidden = false
    try {
      const result = await fetchJson('/api/farm-reports.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...filters, action: 'insights' }),
      })
      $('#reports-insights-list').innerHTML = result.insights.length
        ? result.insights.map((insight) => `<article class="reports-insight-card"><span class="reports-insight-category">${escapeHtml(insight.category)}</span>
          <strong>${escapeHtml(insight.title)}${insight.plant_name ? ` · ${escapeHtml(insight.plant_name)}` : ''}</strong><p>${escapeHtml(insight.detail)}</p></article>`).join('')
        : '<p class="reports-empty">Gemini found no supported insight in these records. Try another date range.</p>'
    } catch (error) {
      console.error('Unable to generate farm report insights:', error)
      setFeedback(error.message)
      $('#reports-insights-list').innerHTML = `<p class="reports-empty">${escapeHtml(error.message)}</p>`
    } finally {
      button.disabled = !state.data?.insights_available
    }
  })

  window.addEventListener('hashchange', () => {
    if (location.hash !== '#reports') return
    try {
      void loadReports(selectedFilters())
    } catch (error) {
      setFeedback(error.message)
    }
  })
  window.addEventListener('focus', () => {
    if (location.hash !== '#reports') return
    try {
      void loadReports(selectedFilters())
    } catch (error) {
      setFeedback(error.message)
    }
  })
  window.addEventListener('tanodbukid:watering-updated', () => {
    if (location.hash === '#reports') void loadReports(state.filters || selectedFilters())
  })
  window.addEventListener('tanodbukid:scan-history-changed', () => {
    if (location.hash === '#reports') void loadReports(state.filters || selectedFilters())
  })
  reportsScreen.querySelectorAll('[data-screen-route]').forEach((button) => {
    button.addEventListener('click', () => {
      location.hash = button.dataset.screenRoute || ''
    })
  })
  $('#reports-notifications').addEventListener('click', () => {
    document.getElementById('notifications-button')?.click()
  })
  $('#reports-profile').addEventListener('click', () => { location.hash = 'profile' })

  showCustomDates()
  if (location.hash === '#reports') {
    try {
      void loadReports(selectedFilters())
    } catch (error) {
      setFeedback(error.message)
    }
  }
}
