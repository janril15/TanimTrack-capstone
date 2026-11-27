const wateringScreen = document.getElementById('watering-screen')

if (wateringScreen) {
  const $ = (selector) => wateringScreen.querySelector(selector)
  const feedback = $('#watering-feedback')
  const state = {
    month: manilaToday().slice(0, 7),
    selectedDate: manilaToday(),
    data: null,
    loading: false,
    reassessing: new Set(),
  }
  const channel = 'BroadcastChannel' in window ? new BroadcastChannel('tanimtrack-watering') : null
  const countNode = document.getElementById('tasks-due-today-count')

  function manilaToday() {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(new Date())
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
    return `${values.year}-${values.month}-${values.day}`
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
    })[character])
  }

  function dateLabel(value, options = { dateStyle: 'medium' }) {
    const text = String(value ?? '').slice(0, 10)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return 'Not recorded'
    return new Intl.DateTimeFormat('en-PH', { ...options, timeZone: 'Asia/Manila' })
      .format(new Date(`${text}T12:00:00+08:00`))
  }

  function timeLabel(value) {
    const time = String(value ?? '').slice(0, 5)
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) return 'Time not set'
    return new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila',
    }).format(new Date(`2000-01-01T${time}:00+08:00`))
  }

  function setFeedback(message, kind = '') {
    feedback.textContent = message
    feedback.dataset.kind = kind
    feedback.hidden = !message
  }

  function updateCount(count) {
    const value = Number.isSafeInteger(Number(count)) ? Math.max(0, Number(count)) : 0
    const todayCount = document.getElementById('watering-today-count')
    if (todayCount) todayCount.textContent = String(value)
    else console.error('Watering screen is missing its pending-task count element.')
    if (countNode) countNode.textContent = String(value)
    window.dispatchEvent(new CustomEvent('tanodbukid:watering-updated', { detail: { count: value } }))
    channel?.postMessage({ count: value })
    try {
      localStorage.setItem('tanodbukid-watering-count', JSON.stringify({ count: value, at: Date.now() }))
    } catch (error) {
      console.warn('Unable to broadcast watering task count:', error)
    }
  }

  async function request(url, options = {}) {
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
      throw new Error('The watering service returned an invalid response.')
    }
    if (!response.ok || result?.success !== true) {
      if (response.status === 401) throw new Error('Your session expired. Sign in again to manage watering tasks.')
      throw new Error(result?.message || 'Unable to load watering data.')
    }
    return result
  }

  async function loadCount() {
    try {
      const result = await request('/api/watering.php?action=count')
      updateCount(result.today_count)
    } catch (error) {
      console.error('Unable to load watering task count:', error)
      if (countNode) countNode.textContent = '—'
    }
  }

  function image(url, className) {
    const valid = typeof url === 'string' && /^\/api\/plant-monitoring\.php\?image=[a-f0-9-]{36}$/i.test(url)
    return valid
      ? `<span class="${className}"><img src="${escapeHtml(url)}" alt="" loading="lazy"></span>`
      : `<span class="${className}" aria-hidden="true">🌱</span>`
  }

  function amountLabel(record, settings = {}) {
    if (settings.custom_amount_ml) return `${Number(settings.custom_amount_ml).toLocaleString()} mL · custom`
    if (record.amount_ml) return `${Number(record.amount_ml).toLocaleString()} mL`
    if (record.amount_min_ml && record.amount_max_ml) {
      return record.amount_min_ml === record.amount_max_ml
        ? `${Number(record.amount_min_ml).toLocaleString()} mL`
        : `${Number(record.amount_min_ml).toLocaleString()}–${Number(record.amount_max_ml).toLocaleString()} mL`
    }
    return 'Not estimated'
  }

  function cupLabel(record) {
    const minimum = Number(record.amount_ml || record.amount_min_ml || 0)
    const maximum = Number(record.amount_ml || record.amount_max_ml || 0)
    if (!minimum || !maximum) return ''
    const cups = (value) => Number((value / 250).toFixed(1)).toLocaleString()
    return `≈ ${cups(minimum)}${maximum !== minimum ? `–${cups(maximum)}` : ''} cups`
  }

  function taskMarkup(task) {
    const pending = task.status === 'pending'
    const amount = amountLabel(task)
    const cups = cupLabel(task)
    const time = timeLabel(task.scheduled_time)
    const details = pending
      ? `<div class="watering-task-actions">
          <button class="watering-task-done" type="button" data-action="complete" data-task-id="${escapeHtml(task.id)}">✓ Mark Done</button>
          <details class="watering-reschedule"><summary aria-label="Reschedule watering task">···</summary>
            <form class="watering-reschedule-form" data-reschedule>
              <input name="scheduled_on" type="date" min="${escapeHtml(manilaToday())}" value="${escapeHtml(task.scheduled_on)}" required aria-label="New watering date">
              <input name="scheduled_time" type="time" value="${escapeHtml(task.scheduled_time)}" required aria-label="New watering time">
              <button type="submit" data-task-id="${escapeHtml(task.id)}">Save</button>
            </form>
          </details>
        </div>`
      : ''
    const status = task.display_status === 'pending'
      ? ''
      : `<span class="watering-task-status ${escapeHtml(task.display_status)}">${task.status === 'completed' ? 'Completed' : escapeHtml(task.display_status)}</span>`
    return `<article class="watering-task-card">
      ${image(task.plant_image_url, 'watering-task-image')}
      <div class="watering-task-main">
        <h4>${escapeHtml(task.plant_name)}</h4>
        <span class="watering-task-meta">🌱 ${escapeHtml(task.growth_stage || 'Growth stage not recorded')} · ${task.plant_size_cm === null || task.plant_size_cm === undefined ? 'Size not recorded' : `${escapeHtml(task.plant_size_cm)} cm`}</span>
        ${status}
      </div>
      <span class="watering-task-divider" aria-hidden="true"></span>
      <span class="watering-task-quantity" title="Scheduled for ${escapeHtml(time)}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C9 8 5 12 5 16a7 7 0 0 0 14 0c0-4-4-8-7-13Z" fill="#39a9e8"/></svg>
        <span><strong>${escapeHtml(amount)}</strong>${cups ? `<small>(${escapeHtml(cups)}) · ${escapeHtml(time)}</small>` : `<small>${escapeHtml(time)}</small>`}</span>
      </span>
      ${details}
    </article>`
  }

  function renderTaskList(container, tasks, emptyText) {
    container.innerHTML = tasks.length ? tasks.map(taskMarkup).join('') : `<p class="watering-empty">${escapeHtml(emptyText)}</p>`
  }

  function renderCalendar() {
    const monthLabel = $('#watering-calendar-month')
    const monthPicker = $('#watering-month-picker')
    monthLabel.textContent = new Intl.DateTimeFormat('en-PH', {
      month: 'long', year: 'numeric', timeZone: 'Asia/Manila',
    }).format(new Date(`${state.month}-01T12:00:00+08:00`))
    monthPicker.value = state.month
    const [year, month] = state.month.split('-').map(Number)
    const offset = new Date(Date.UTC(year, month - 1, 1)).getUTCDay()
    const days = new Date(Date.UTC(year, month, 0)).getUTCDate()
    const map = new Map()
    state.data.tasks.forEach((task) => {
      if (!map.has(task.scheduled_on)) map.set(task.scheduled_on, [])
      map.get(task.scheduled_on).push(task)
    })
    const cells = Array.from({ length: offset }, () => '<button class="watering-calendar-day" type="button" disabled aria-hidden="true"></button>')
    for (let day = 1; day <= days; day += 1) {
      const date = `${state.month}-${String(day).padStart(2, '0')}`
      const dayTasks = map.get(date) || []
      const dots = dayTasks.slice(0, 4).map((task) => {
        const status = task.display_status === 'overdue' ? 'overdue' : task.status
        return `<i class="${escapeHtml(status)}" aria-hidden="true"></i>`
      }).join('')
      cells.push(`<button class="watering-calendar-day${date === state.data.today ? ' is-today' : ''}${date === state.selectedDate ? ' is-selected' : ''}" type="button" role="gridcell" data-calendar-date="${date}" aria-selected="${date === state.selectedDate}">
        <span>${day}</span><span class="watering-calendar-dots">${dots}</span></button>`)
    }
    $('#watering-calendar-grid').innerHTML = cells.join('')
    const selected = state.data.tasks.filter((task) => task.scheduled_on === state.selectedDate)
    const selectedPending = selected.filter((task) => task.status === 'pending').length
    const isToday = state.selectedDate === state.data.today
    $('#watering-today-title').textContent = isToday
      ? 'Tasks Due Today'
      : `Tasks for ${dateLabel(state.selectedDate, { month: 'short', day: 'numeric' })}`
    $('#watering-today-count').textContent = String(selectedPending)
    renderTaskList(
      $('#watering-tasks-today'),
      selected,
      isToday ? 'No incomplete watering tasks due today.' : 'No watering tasks scheduled for this date.',
    )
  }

  function recommendationMarkup(plant) {
    const latest = plant.latest_scan
    const settings = plant.plan.settings
    const recommendation = plant.plan.recommendation
    if (!latest) {
      return `<div class="watering-no-scan"><strong>No AI scan available.</strong> Use the existing Plant/Crop Scan to establish a monitoring baseline.
        <p><a class="watering-primary-button" href="/dashboard.html?plant_id=${encodeURIComponent(plant.id)}&amp;return_to=watering">Scan this plant</a></p></div>`
    }
    if (!recommendation) {
      return `<div class="watering-no-scan"><strong>AI recommendation not generated.</strong>
        <p><button class="watering-primary-button" type="button" data-action="reassess" data-plant-id="${escapeHtml(plant.id)}">Generate recommendation</button></p></div>`
    }
    const title = {
      water_now: 'Watering suggested',
      check_soil: 'Check soil before watering',
      not_needed: 'No watering indicated now',
    }[recommendation.decision]
    const quantity = amountLabel(recommendation, settings)
    const hasSize = settings.container_volume_l || settings.growing_area_m2
    const quantityText = quantity === 'Not estimated'
      ? 'No reliable amount estimate; add pot volume or growing area.'
      : settings.custom_amount_ml
        ? `Your plant-specific amount: ${quantity}. Adjust to soil moisture and growing conditions.`
        : hasSize
          ? `Suggested quantity: ${quantity}. Adjust to soil moisture and growing conditions.`
          : `Cautious AI starting range: ${quantity}; not a scan measurement. Adjust to soil moisture and growing conditions.`
    const evidence = plant.plan.needs_reassessment
      ? 'New scan or setting awaiting reassessment'
      : ({
          recent_scan: 'Based on recent AI scan',
          previous_scan: 'Based on previous scan data',
          awaiting_soil_confirmation: 'Awaiting soil moisture confirmation',
        }[recommendation.evidence_status] || 'Evidence status unavailable')
    const nextTask = plant.next_watering
      ? `Next watering: ${dateLabel(plant.next_watering.scheduled_on)} · ${timeLabel(plant.next_watering.scheduled_time)}`
      : recommendation.first_scheduled_on
        ? `Next watering: ${dateLabel(recommendation.first_scheduled_on)}`
        : 'No automatic watering task until soil moisture is confirmed.'
    const weather = plant.weather.available && plant.weather.data
      ? `Matched weather for ${plant.weather.data.location_name}, updated ${dateLabel(plant.weather.fetched_at)}`
      : (plant.weather.reason || 'No matched local weather snapshot.')
    const manualTask = recommendation.decision === 'check_soil'
      ? `<details class="watering-manual-task"><summary>I checked the soil — schedule watering</summary>
          <form class="watering-manual-form" data-manual-task data-plant-id="${escapeHtml(plant.id)}">
            <input type="date" name="scheduled_on" min="${escapeHtml(state.data.today)}" value="${escapeHtml(state.data.today)}" required aria-label="Watering date">
            <input type="time" name="scheduled_time" value="${escapeHtml(recommendation.recommended_time)}" required aria-label="Watering time">
            <input type="number" name="amount_ml" min="1" max="1000000" placeholder="Optional mL" aria-label="Water amount in milliliters">
            <input type="time" name="reminder_time" value="${escapeHtml(settings.reminder_time || recommendation.recommended_time)}" aria-label="Reminder time">
            <button type="submit">Add task</button>
          </form></details>`
      : ''
    const warnings = recommendation.warnings.length
      ? `<ul>${recommendation.warnings.map((warning) => `<li>${escapeHtml(warning)}</li>`).join('')}</ul>`
      : ''
    return `<div class="watering-recommendation" data-decision="${escapeHtml(recommendation.decision)}">
      <h4>${escapeHtml(title)} · ${escapeHtml(recommendation.confidence)} confidence</h4>
      <p>${escapeHtml(recommendation.reason)}</p><p>${escapeHtml(quantityText)}</p>
      <p>${settings.custom_frequency_days ? `Your interval: every ${settings.custom_frequency_days} days` : `Suggested frequency: every ${recommendation.frequency_days} days`} · ${escapeHtml(timeLabel(recommendation.recommended_time))}</p>
      <p>${escapeHtml(nextTask)}</p><p><strong>Weather:</strong> ${escapeHtml(weather)}</p>
      <p><strong>Last watered:</strong> ${escapeHtml(plant.last_watering ? dateLabel(plant.last_watering.completed_at) : 'No completed watering')}</p>
      ${warnings}<span class="watering-evidence">${escapeHtml(evidence)}</span>${manualTask}
      <p><button class="watering-primary-button" type="button" data-action="reassess" data-plant-id="${escapeHtml(plant.id)}">Reassess recommendation</button></p>
    </div>`
  }

  function plantCard(plant) {
    const latest = plant.latest_scan
    const settings = plant.plan.settings
    const findings = latest
      ? `<p>${escapeHtml(latest.analysis || latest.detected_issue || 'No written AI findings saved.')}</p>${latest.visible_symptoms.length ? `<small>Visible findings: ${escapeHtml(latest.visible_symptoms.join(', '))}</small>` : ''}`
      : '<p>No plant findings are available until an AI scan is saved.</p>'
    const settingsForm = `<form class="watering-plant-settings" data-settings data-plant-id="${escapeHtml(plant.id)}">
      <label class="watering-field">Container volume (L)<input name="container_volume_l" type="number" min=".01" step=".01" value="${escapeHtml(settings.container_volume_l ?? '')}" placeholder="Optional"></label>
      <label class="watering-field">Growing area (m²)<input name="growing_area_m2" type="number" min=".001" step=".001" value="${escapeHtml(settings.growing_area_m2 ?? '')}" placeholder="Optional"></label>
      <label class="watering-field">Soil type<input name="soil_type" maxlength="80" value="${escapeHtml(settings.soil_type ?? '')}" placeholder="Optional"></label>
      <label class="watering-field">Soil moisture (%)<input name="soil_moisture_percent" type="number" min="0" max="100" step=".1" value="${escapeHtml(settings.soil_moisture_percent ?? '')}" placeholder="Manual check"></label>
      <label class="watering-field">Custom amount (mL)<input name="custom_amount_ml" type="number" min="1" step="1" value="${escapeHtml(settings.custom_amount_ml ?? '')}" placeholder="Optional"></label>
      <label class="watering-field">Custom interval (days)<input name="custom_frequency_days" type="number" min="1" max="30" step="1" value="${escapeHtml(settings.custom_frequency_days ?? '')}" placeholder="AI suggested"></label>
      <label class="watering-field">Reminder time<input name="reminder_time" type="time" value="${escapeHtml(settings.reminder_time ?? '')}"></label>
      <div class="watering-settings-footer"><small>${settings.soil_moisture_percent !== null ? `Manual reading: ${Number(settings.soil_moisture_percent)}% · ${dateLabel(settings.soil_moisture_recorded_at)}. AI readings expire after 24 hours.` : 'Soil moisture is manual input, not a scan reading.'} Saving settings reassesses the plan.</small><button type="submit">Save settings</button></div>
    </form>`
    return `<article class="watering-plant-card">
      <div class="watering-plant-head">${image(latest?.image_url, 'watering-plant-image')}
        <div class="watering-plant-title"><h3>${escapeHtml(plant.name)}</h3><p>${escapeHtml([plant.species, plant.growth_stage].filter(Boolean).join(' · ') || 'Growth stage not recorded')}</p></div>
        <span class="watering-health-badge${!latest || latest.health_status === 'Uncertain' ? ' uncertain' : ''}">${escapeHtml(latest?.health_status || 'No scan')}</span></div>
      <div class="watering-plant-summary"><div><span>Latest scan</span><strong>${escapeHtml(latest ? dateLabel(latest.scanned_at) : 'Not scanned')}</strong></div>
        <div><span>Last watered</span><strong>${escapeHtml(plant.last_watering ? dateLabel(plant.last_watering.completed_at) : 'No record')}</strong></div>
        <div><span>Next task</span><strong>${escapeHtml(plant.next_watering ? dateLabel(plant.next_watering.scheduled_on) : 'Not scheduled')}</strong></div></div>
      <div class="watering-findings"><p class="watering-kicker">LATEST AI FINDINGS</p>${findings}</div>
      ${recommendationMarkup(plant)}${settingsForm}</article>`
  }

  async function post(data) {
    return request('/api/watering.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    })
  }

  async function pollNotifications() {
    if (!state.data || !('Notification' in window) || Notification.permission !== 'granted') return
    const due = [...state.data.tasks_due_today, ...state.data.overdue_tasks]
    for (const task of new Map(due.map((item) => [item.id, item])).values()) {
      if (!task.reminder_time || task.reminder_sent_at) continue
      try {
        const claimed = await post({ action: 'claim-reminder', task_id: task.id })
        if (claimed.claimed) new Notification(`Watering reminder: ${task.plant_name}`, {
          body: `Scheduled ${dateLabel(task.scheduled_on)} at ${timeLabel(task.scheduled_time)}.`,
          tag: `watering-${task.id}`,
        })
      } catch (error) {
        console.error('Unable to create browser watering reminder:', error)
      }
    }
  }

  async function loadSchedule({ auto = true } = {}) {
    if (state.loading) return
    state.loading = true
    let shouldReassess = false
    try {
      const query = new URLSearchParams({ month: state.month, date: state.selectedDate })
      state.data = await request(`/api/watering.php?${query}`)
      state.month = state.data.month
      state.selectedDate = state.data.selected_date
      updateCount(state.data.today_count)
      renderCalendar()
      renderTaskList($('#watering-overdue-tasks'), state.data.overdue_tasks, 'No overdue watering tasks.')
      $('#watering-overdue-count').textContent = state.data.overdue_tasks.length
        ? `(${state.data.overdue_tasks.length})`
        : ''
      $('.watering-overdue-section').hidden = state.data.overdue_tasks.length === 0
      $('#watering-plants-list').innerHTML = state.data.plants.length
        ? state.data.plants.map(plantCard).join('')
        : '<p class="watering-empty">No registered plants yet. Add one through Farm Logs.</p>'
      const due = state.data.tasks_due_today.length
      const overdue = state.data.overdue_tasks.length
      $('#watering-reminder-status').textContent = `${due} task${due === 1 ? '' : 's'} due today · ${overdue} overdue. Browser notifications require permission and this page to remain open.`
      shouldReassess = auto && state.data.plants.some((plant) => plant.latest_scan && plant.plan.needs_reassessment)
      await pollNotifications()
    } catch (error) {
      console.error('Unable to load the watering schedule:', error)
      setFeedback(error.message)
      if (!state.data) {
        $('#watering-plants-list').innerHTML = `<p class="watering-empty">${escapeHtml(error.message)}</p>`
        $('#watering-tasks-today').innerHTML = `<p class="watering-empty">${escapeHtml(error.message)}</p>`
      }
    } finally {
      state.loading = false
    }
    if (shouldReassess) await reassessPending()
  }

  async function reassessPending() {
    for (const plant of state.data.plants.filter((item) => item.latest_scan && item.plan.needs_reassessment)) {
      if (state.reassessing.has(plant.id)) continue
      state.reassessing.add(plant.id)
      setFeedback(`Updating ${plant.name}'s recommendation from the latest scan…`)
      try {
        await post({ action: 'reassess', plant_id: plant.id })
      } catch (error) {
        console.error('Unable to reassess watering:', error)
        setFeedback(`${plant.name}: ${error.message}`)
      } finally {
        state.reassessing.delete(plant.id)
      }
    }
    await loadSchedule({ auto: false })
  }

  async function action(button) {
    const actionName = button.dataset.action
    if (actionName === 'reassess') {
      const id = button.dataset.plantId
      button.disabled = true
      setFeedback('Gemini is reviewing the latest scan, watering history, user settings, and matched fresh weather.')
      try {
        await post({ action: 'reassess', plant_id: id })
        setFeedback('Recommendation updated. Completed watering history was preserved.', 'success')
        await loadSchedule({ auto: false })
      } catch (error) {
        console.error('Unable to reassess plant watering:', error)
        setFeedback(error.message)
      } finally {
        if (button.isConnected) button.disabled = false
      }
      return
    }
    if (actionName !== 'complete') return
    button.disabled = true
    try {
      const result = await post({ action: 'complete', task_id: button.dataset.taskId })
      updateCount(result.today_count)
      setFeedback(result.message, 'success')
      await loadSchedule({ auto: false })
    } catch (error) {
      console.error('Unable to complete watering task:', error)
      setFeedback(error.message)
      button.disabled = false
    }
  }

  async function submitSettings(form) {
    const data = new FormData(form)
    const get = (name) => {
      const value = String(data.get(name) ?? '').trim()
      if (!value) return null
      return ['soil_type', 'reminder_time'].includes(name) ? value : Number(value)
    }
    const button = form.querySelector('button[type="submit"]')
    button.disabled = true
    try {
      await post({
        action: 'save-settings', plant_id: form.dataset.plantId,
        container_volume_l: get('container_volume_l'), growing_area_m2: get('growing_area_m2'),
        soil_type: get('soil_type'), soil_moisture_percent: get('soil_moisture_percent'),
        custom_amount_ml: get('custom_amount_ml'), custom_frequency_days: get('custom_frequency_days'),
        reminder_time: get('reminder_time'),
      })
      setFeedback('Settings saved. Reassessing the plant recommendation…', 'success')
      await post({ action: 'reassess', plant_id: form.dataset.plantId })
      await loadSchedule({ auto: false })
    } catch (error) {
      console.error('Unable to save watering settings:', error)
      setFeedback(error.message)
      button.disabled = false
    }
  }

  wateringScreen.addEventListener('click', (event) => {
    const dateButton = event.target.closest('[data-calendar-date]')
    if (dateButton) {
      state.selectedDate = dateButton.dataset.calendarDate
      renderCalendar()
      return
    }
    const actionButton = event.target.closest('[data-action]')
    if (actionButton) void action(actionButton)
  })

  wateringScreen.addEventListener('submit', async (event) => {
    const form = event.target
    event.preventDefault()
    const values = new FormData(form)
    const button = form.querySelector('button[type="submit"]')
    button.disabled = true
    try {
      if (form.matches('[data-settings]')) {
        await submitSettings(form)
      } else if (form.matches('[data-reschedule]')) {
        const result = await post({
          action: 'reschedule', task_id: button.dataset.taskId,
          scheduled_on: values.get('scheduled_on'), scheduled_time: values.get('scheduled_time'),
        })
        updateCount(result.today_count)
        setFeedback(result.message, 'success')
        await loadSchedule({ auto: false })
      } else if (form.matches('[data-manual-task]')) {
        const rawAmount = String(values.get('amount_ml') ?? '').trim()
        const result = await post({
          action: 'create-task', plant_id: form.dataset.plantId,
          scheduled_on: values.get('scheduled_on'), scheduled_time: values.get('scheduled_time'),
          reminder_time: values.get('reminder_time'), amount_ml: rawAmount ? Number(rawAmount) : null,
        })
        updateCount(result.today_count)
        setFeedback(result.message, 'success')
        await loadSchedule({ auto: false })
      }
    } catch (error) {
      console.error('Unable to update watering task:', error)
      setFeedback(error.message)
      button.disabled = false
    }
  })

  $('#watering-month-previous').addEventListener('click', () => {
    const [year, month] = state.month.split('-').map(Number)
    const previous = new Date(Date.UTC(year, month - 2, 1))
    state.month = `${previous.getUTCFullYear()}-${String(previous.getUTCMonth() + 1).padStart(2, '0')}`
    state.selectedDate = `${state.month}-01`
    void loadSchedule({ auto: false })
  })

  $('#watering-month-next').addEventListener('click', () => {
    const [year, month] = state.month.split('-').map(Number)
    const next = new Date(Date.UTC(year, month, 1))
    state.month = `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`
    state.selectedDate = `${state.month}-01`
    void loadSchedule({ auto: false })
  })

  $('#watering-month-picker').addEventListener('change', (event) => {
    const month = event.currentTarget.value
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) return
    state.month = month
    state.selectedDate = month === manilaToday().slice(0, 7) ? manilaToday() : `${month}-01`
    void loadSchedule({ auto: false })
  })

  $('#watering-refresh').addEventListener('click', () => void loadSchedule())
  $('#watering-reminder-enable').addEventListener('click', async () => {
    if (!('Notification' in window)) {
      setFeedback('This browser does not support desktop notifications. In-app reminder history remains available.')
      return
    }
    if (Notification.permission === 'denied') {
      setFeedback('Browser notifications are blocked in browser settings. In-app reminder history remains available.')
      return
    }
    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission()
    if (permission === 'granted') {
      setFeedback('Browser reminders are enabled while TanimTrack is open; delivery is not guaranteed.', 'success')
      await pollNotifications()
    } else {
      setFeedback('Browser permission was not granted; in-app reminder history remains available.')
    }
  })

  channel?.addEventListener('message', (event) => {
    if (countNode && Number.isSafeInteger(event.data?.count)) countNode.textContent = String(event.data.count)
  })
  window.addEventListener('storage', (event) => {
    if (event.key !== 'tanodbukid-watering-count' || !event.newValue || !countNode) return
    try {
      countNode.textContent = String(JSON.parse(event.newValue).count)
    } catch (error) {
      console.warn('Unable to read watering count update:', error)
    }
  })
  window.addEventListener('hashchange', () => {
    void loadCount()
    if (location.hash === '#watering') void loadSchedule()
  })
  wateringScreen.querySelectorAll('[data-screen-route]').forEach((button) => {
    button.addEventListener('click', () => {
      location.hash = button.dataset.screenRoute || ''
    })
  })
  $('#watering-notifications').addEventListener('click', () => {
    document.getElementById('notifications-button')?.click()
  })
  $('#watering-profile').addEventListener('click', () => { location.hash = 'profile' })
  window.addEventListener('focus', () => {
    void loadCount()
    if (location.hash === '#watering') void loadSchedule({ auto: false })
  })
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return
    void loadCount()
    if (location.hash === '#watering') void loadSchedule({ auto: false })
  })
  window.setInterval(() => {
    void loadCount()
    if (location.hash === '#watering' && document.visibilityState === 'visible') void loadSchedule({ auto: false })
  }, 60000)

  void loadCount()
  if (location.hash === '#watering') void loadSchedule()
}
