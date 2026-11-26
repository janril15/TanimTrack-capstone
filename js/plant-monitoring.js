const params = new URLSearchParams(location.search)
const plantId = params.get('plant_id') || ''
const plantName = document.querySelector('#plant-name')
const feedback = document.querySelector('#monitoring-feedback')
const latestImage = document.querySelector('#latest-image')
const latestImagePlaceholder = document.querySelector('#latest-image-placeholder')
const latestScanDate = document.querySelector('#latest-scan-date')
const latestHealth = document.querySelector('#latest-health')
const healthFeedback = document.querySelector('#health-feedback')
const historyList = document.querySelector('#scan-history-list')
const historyToggle = document.querySelector('#history-toggle')
const selectedScanDetails = document.querySelector('#selected-scan-details')
const scanDetailsCard = document.querySelector('#scan-details-card')
const olderSelect = document.querySelector('#comparison-older')
const newerSelect = document.querySelector('#comparison-newer')
const comparisonResult = document.querySelector('#comparison-result')
const scanIdPattern = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i
const historyPreviewCount = 2
let plant = null
let scans = []
let selectedScanId = null
let historyExpanded = false
let scanDetailsExpanded = false

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
})[character])

function showFeedback(message, kind = '') {
  feedback.textContent = message
  feedback.dataset.kind = kind
}

function scanDate(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Date unavailable'
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function scanDateOnly(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Date unavailable'
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

function displayMeasurement(value, digits = 0) {
  if (value === null || value === undefined || value === '') return 'Not recorded'
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: digits }).format(Number(value))
}

function rawHealthStatus(scan) {
  return scan?.analysis_result?.health_status || scan?.health_status || 'Uncertain'
}

function scanStatus(scan) {
  if (!scan) return { text: 'Not scanned', className: 'is-uncertain' }
  const status = rawHealthStatus(scan)
  if (scan.analysis_status === 'unavailable') return { text: 'Result unavailable', className: 'is-unavailable' }
  if (status === 'Healthy') return { text: 'Healthy', className: '' }
  if (status === 'Uncertain') return { text: 'Needs verification', className: 'is-uncertain' }
  if (status === 'Nutrient Deficiency') return { text: 'Nutrient Stress', className: 'is-attention' }
  return { text: status, className: 'is-attention' }
}

function scanImageMarkup(scan, className, alt = '') {
  if (!scan?.image_url) return `<span class="${className} is-placeholder" aria-hidden="true">🌱</span>`
  return `<img class="${className}" data-scan-image src="${escapeHtml(scan.image_url)}" alt="${escapeHtml(alt)}" loading="lazy">`
}

function attachImageFallbacks(root) {
  root.querySelectorAll('img[data-scan-image]').forEach((image) => {
    image.addEventListener('error', () => {
      const placeholder = document.createElement('span')
      placeholder.className = `${image.className} is-placeholder`
      placeholder.setAttribute('aria-hidden', 'true')
      placeholder.textContent = '🌱'
      image.replaceWith(placeholder)
    }, { once: true })
  })
}

function renderLatestScan() {
  const latest = scans[0] || null
  const latestAvailableMeasurement = (field) => scans.find((scan) => scan[field] !== null && scan[field] !== undefined && scan[field] !== '')
  const leafScan = latestAvailableMeasurement('leaf_count')
  const sizeScan = latestAvailableMeasurement('plant_size_cm')
  const heightScan = latestAvailableMeasurement('plant_height_cm')
  document.querySelector('#latest-leaf-count').textContent = leafScan ? displayMeasurement(leafScan.leaf_count) : '—'
  document.querySelector('#latest-plant-size').textContent = sizeScan ? displayMeasurement(sizeScan.plant_size_cm, 2) : '—'
  document.querySelector('#latest-plant-height').textContent = heightScan ? displayMeasurement(heightScan.plant_height_cm, 2) : '—'
  latestScanDate.textContent = latest ? scanDate(latest.scanned_at) : 'No scans recorded yet'
  const status = scanStatus(latest)
  latestHealth.textContent = status.text
  latestHealth.className = `plant-health-badge ${status.className}`
  latestImage.hidden = !latest?.image_url
  latestImagePlaceholder.hidden = Boolean(latest?.image_url)
  latestImage.alt = latest ? `Latest scan of ${plant.name}` : ''
  if (latest?.image_url) {
    latestImage.src = latest.image_url
    latestImage.onerror = () => {
      latestImage.hidden = true
      latestImagePlaceholder.hidden = false
    }
  }
  else latestImage.removeAttribute('src')
  if (!latest) {
    healthFeedback.textContent = 'Scan your plant to start tracking its growth and health.'
  } else if (rawHealthStatus(latest) === 'Healthy') {
    healthFeedback.textContent = 'Your plant looks healthy and is growing well!'
  } else if (rawHealthStatus(latest) === 'Uncertain') {
    healthFeedback.textContent = 'Result unclear. Review details or scan again.'
  } else {
    healthFeedback.textContent = 'May need attention. Review scan findings.'
  }
}

function renderHistory() {
  historyToggle.hidden = scans.length <= historyPreviewCount
  historyToggle.innerHTML = historyExpanded ? 'Show Recent <span aria-hidden="true">‹</span>' : 'View All <span aria-hidden="true">›</span>'
  const visibleScans = historyExpanded ? scans : scans.slice(0, historyPreviewCount)
  if (!visibleScans.length) {
    historyList.innerHTML = '<p class="plant-history-empty">No scans have been saved for this plant yet. Choose Scan Again to begin its history.</p>'
    return
  }
  historyList.innerHTML = visibleScans.map((scan, index) => {
    const status = scanStatus(scan)
    const selected = scan.id === selectedScanId
    const latest = index === 0
    const leafCount = scan.leaf_count == null ? '—' : displayMeasurement(scan.leaf_count)
    const plantSize = scan.plant_size_cm == null ? '—' : `${displayMeasurement(scan.plant_size_cm, 2)} cm`
    const plantHeight = scan.plant_height_cm == null ? '—' : `${displayMeasurement(scan.plant_height_cm, 2)} cm`
    return `<button class="details-history-row${selected ? ' is-selected' : ''}${latest ? ' is-latest' : ''}" type="button" data-scan-id="${escapeHtml(scan.id)}" aria-pressed="${selected}"><span class="details-history-image">${scanImageMarkup(scan, 'details-history-thumb', `${plant.name} scan`)}</span><span class="details-history-copy"><strong>${escapeHtml(scanDate(scan.scanned_at))}</strong><span>${escapeHtml(leafCount)} leaves · ${escapeHtml(plantSize)} · ${escapeHtml(plantHeight)}</span></span><span class="details-history-status"><span class="plant-health-badge ${status.className}">${escapeHtml(status.text)}</span>${latest ? '<small class="details-latest-label">Latest</small>' : ''}</span><span class="details-history-chevron" aria-hidden="true">›</span></button>`
  }).join('')
  attachImageFallbacks(historyList)
}

function renderScanDetails() {
  const scan = scans.find((item) => item.id === selectedScanId)
  scanDetailsCard.hidden = !scan || !scanDetailsExpanded
  if (!scan) {
    selectedScanDetails.innerHTML = '<p class="plant-history-empty">Select a scan to review its full AI analysis.</p>'
    return
  }

  const result = scan.analysis_result || {}
  const symptoms = Array.isArray(result.visible_symptoms) ? result.visible_symptoms : []
  const recommendations = Array.isArray(result.recommendations) ? result.recommendations : []
  const preventionTips = Array.isArray(result.prevention_tips) ? result.prevention_tips : []
  const listMarkup = (items, emptyText) => items.length
    ? `<ul>${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`
    : `<p>${escapeHtml(emptyText)}</p>`
  const status = scanStatus(scan)
  selectedScanDetails.innerHTML = `
    <div class="details-scan-summary">
      <div class="details-scan-image">${scanImageMarkup(scan, 'details-scan-thumb', `${plant.name} scan details`)}</div>
      <div><span class="plant-health-badge ${status.className}">${escapeHtml(status.text)}</span><p>${escapeHtml(scanDate(scan.scanned_at))}</p></div>
    </div>
    <div class="details-analysis-grid">
      <div><h3>Detected issue</h3><p>${escapeHtml(result.detected_issue || scan.detected_issue || 'No specific issue recorded.')}</p></div>
      <div><h3>AI analysis</h3><p>${escapeHtml(result.analysis || 'No AI analysis was saved for this scan.')}</p></div>
      <div><h3>Visible symptoms</h3>${listMarkup(symptoms, 'No clear symptoms were recorded.')}</div>
      <div><h3>Recommendations</h3>${listMarkup(recommendations, 'No recommendations were recorded.')}</div>
      <div><h3>Prevention tips</h3>${listMarkup(preventionTips, 'No prevention tips were recorded.')}</div>
    </div>
    <form class="plant-measurement-form" id="measurement-form">
      <label>Leaf count<input name="leaf_count" type="number" min="0" max="1000000" step="1" value="${escapeHtml(scan.leaf_count ?? '')}" inputmode="numeric"></label>
      <label>Plant size · cm<input name="plant_size_cm" type="number" min="0" max="999999.99" step="0.01" value="${escapeHtml(scan.plant_size_cm ?? '')}" inputmode="decimal"></label>
      <label>Plant height · cm<input name="plant_height_cm" type="number" min="0" max="999999.99" step="0.01" value="${escapeHtml(scan.plant_height_cm ?? '')}" inputmode="decimal"></label>
      <p class="plant-measurement-help">Measurements are optional and must be recorded manually; size means the plant's widest canopy span. Blank values remain unrecorded.</p>
      <button class="plant-measurement-save" type="submit">Save measurements</button>
      <p class="plant-measurement-feedback" id="measurement-feedback" role="status"></p>
    </form>`
  attachImageFallbacks(selectedScanDetails)
}

function populateComparisonSelects() {
  const previousOlder = olderSelect.value
  const previousNewer = newerSelect.value
  const options = scans.map((scan) => `<option value="${escapeHtml(scan.id)}">${escapeHtml(scanDateOnly(scan.scanned_at))} · ${escapeHtml(scanStatus(scan).text)}</option>`).join('')
  olderSelect.innerHTML = options
  newerSelect.innerHTML = options
  if (scans.length >= 2) {
    const validIds = new Set(scans.map((scan) => scan.id))
    olderSelect.value = validIds.has(previousOlder) && previousOlder !== previousNewer ? previousOlder : scans[1].id
    newerSelect.value = validIds.has(previousNewer) && previousNewer !== olderSelect.value ? previousNewer : scans[0].id
  }
  const canCompare = scans.length >= 2
  olderSelect.disabled = !canCompare
  newerSelect.disabled = !canCompare
  if (canCompare) compareSelectedScans()
  else comparisonResult.innerHTML = '<p class="plant-history-empty">Two scans of this plant are needed for a comparison.</p>'
}

function renderPage() {
  document.title = `${plant.name} Monitoring Details | TanimTrack`
  plantName.textContent = plant.name
  renderLatestScan()
  renderHistory()
  renderScanDetails()
  populateComparisonSelects()
}

function comparisonTrend(earlier, later) {
  const previous = earlier.analysis_result || {}
  const current = later.analysis_result || {}
  const statusRank = (status) => status === 'Healthy' ? 0
    : ['Diseased', 'Pest-Affected', 'Nutrient Deficiency'].includes(status) ? 1
      : null
  const severityRank = { None: 0, Low: 1, Moderate: 2, High: 3 }
  const directions = []
  const beforeStatus = statusRank(previous.health_status || earlier.health_status)
  const afterStatus = statusRank(current.health_status || later.health_status)
  if (beforeStatus === null || afterStatus === null) {
    return { label: 'Insufficient evidence to classify', className: 'is-caution' }
  }
  if (beforeStatus !== null && afterStatus !== null && beforeStatus !== afterStatus) {
    directions.push(beforeStatus > afterStatus ? 'up' : 'down')
  }
  const beforeSeverity = severityRank[previous.severity || earlier.severity]
  const afterSeverity = severityRank[current.severity || later.severity]
  if (beforeSeverity === undefined || afterSeverity === undefined) {
    return { label: 'Insufficient evidence to classify', className: 'is-caution' }
  }
  if (beforeSeverity !== undefined && afterSeverity !== undefined && beforeSeverity !== afterSeverity) {
    directions.push(beforeSeverity > afterSeverity ? 'up' : 'down')
  }
  const beforeIssue = String(previous.detected_issue || earlier.detected_issue || '').trim()
  const afterIssue = String(current.detected_issue || later.detected_issue || '').trim()
  if (beforeIssue && !afterIssue) directions.push('up')
  if (!beforeIssue && afterIssue) directions.push('down')
  if (beforeIssue && afterIssue && beforeIssue.toLowerCase() !== afterIssue.toLowerCase()) {
    return { label: 'Mixed signals · monitor closely', className: 'is-caution' }
  }

  const hasUncertainStatus = [previous.health_status || earlier.health_status, current.health_status || later.health_status].includes('Uncertain')
  if (hasUncertainStatus) return { label: 'Insufficient evidence to classify', className: 'is-caution' }
  for (const field of ['leaf_count', 'plant_size_cm', 'plant_height_cm']) {
    if (earlier[field] == null || later[field] == null) continue
    const difference = Number(later[field]) - Number(earlier[field])
    if (difference > 0) directions.push('up')
    if (difference < 0) directions.push('down')
  }
  const upCount = directions.filter((direction) => direction === 'up').length
  const downCount = directions.filter((direction) => direction === 'down').length
  if (upCount && downCount) return { label: 'Mixed signals · monitor closely', className: 'is-caution' }
  if (upCount) return { label: 'Improving', className: '' }
  if (downCount) return { label: 'Declining', className: 'is-caution' }
  return { label: 'Unchanged', className: '' }
}

function renderComparisonScan(scan, label) {
  const status = scanStatus(scan)
  const measurement = (value, digits = 0) => value == null ? '—' : displayMeasurement(value, digits)
  const date = scanDateOnly(scan.scanned_at)
  const size = scan.plant_size_cm == null ? '—' : `${measurement(scan.plant_size_cm, 2)} cm`
  const height = scan.plant_height_cm == null ? '—' : `${measurement(scan.plant_height_cm, 2)} cm`
  return `<article class="monitoring-compare-card">
    <div class="monitoring-compare-card-heading"><strong aria-label="${escapeHtml(label)}">${escapeHtml(date)}</strong><span class="plant-health-badge ${status.className}">${escapeHtml(status.text)}</span></div>
    <div class="monitoring-compare-image">${scanImageMarkup(scan, 'monitoring-compare-thumb', `${plant.name} ${label.toLowerCase()} scan`)}</div>
    <div class="monitoring-compare-measurements">
      <strong>${escapeHtml(measurement(scan.leaf_count))} leaves</strong>
      <strong>${escapeHtml(size)}</strong>
      <strong>${escapeHtml(height)}</strong>
    </div>
  </article>`
}

function compareSelectedScans() {
  const first = scans.find((scan) => scan.id === olderSelect.value)
  const second = scans.find((scan) => scan.id === newerSelect.value)
  if (!first || !second || first.id === second.id) {
    comparisonResult.innerHTML = '<p class="plant-history-empty">Select two different scans for this comparison.</p>'
    return
  }
  const [earlier, later] = new Date(first.scanned_at) <= new Date(second.scanned_at) ? [first, second] : [second, first]
  olderSelect.value = earlier.id
  newerSelect.value = later.id
  const trend = comparisonTrend(earlier, later)
  const trendMessage = trend.label.startsWith('Improving')
    ? 'Your plant is improving!'
    : trend.label.startsWith('Declining')
      ? 'Your plant may be declining.'
      : trend.label === 'Unchanged'
        ? 'Your plant is unchanged.'
        : trend.label
  const trendIcon = trend.label.startsWith('Declining') ? '↘' : trend.label === 'Unchanged' ? '→' : '↗'
  comparisonResult.innerHTML = `<div class="monitoring-compare-cards">${renderComparisonScan(earlier, 'Earlier scan')}<span class="monitoring-compare-arrow" aria-hidden="true">›</span>${renderComparisonScan(later, 'Later scan')}</div><p class="plant-trend ${trend.className}"><span aria-hidden="true">${trendIcon}</span>${escapeHtml(trendMessage)}</p>`
  attachImageFallbacks(comparisonResult)
}

async function loadPlant() {
  if (!scanIdPattern.test(plantId)) {
    showFeedback('A valid plant was not selected. Return to Farm Logs and choose a registered plant.', 'error')
    plantName.textContent = 'Plant not found'
    return
  }
  try {
    const response = await fetch(`/api/plant-monitoring.php?plant_id=${encodeURIComponent(plantId)}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
    const result = await response.json()
    if (response.status === 401) throw new Error('Your session has expired. Sign in again to view plant monitoring.')
    if (!response.ok || !result.success || !result.plant || !Array.isArray(result.scans)) {
      throw new Error(result.message || 'Unable to load this plant’s monitoring details.')
    }
    plant = result.plant
    scans = result.scans
    selectedScanId = scans[0]?.id || null
    showFeedback('')
    renderPage()
  } catch (error) {
    console.error('Unable to load plant monitoring:', error)
    plantName.textContent = 'Plant monitoring unavailable'
    showFeedback(error.message || 'Unable to load this plant. Please try again.', 'error')
  }
}

function openScanner() {
  if (!plantId) return
  window.location.href = `/dashboard.html?plant_id=${encodeURIComponent(plantId)}`
}

document.querySelector('#scan-again').addEventListener('click', openScanner)
document.querySelector('#scan-again-bottom').addEventListener('click', openScanner)

historyToggle.addEventListener('click', () => {
  historyExpanded = !historyExpanded
  renderHistory()
})

document.querySelector('#close-scan-details').addEventListener('click', () => {
  scanDetailsExpanded = false
  renderScanDetails()
})

historyList.addEventListener('click', (event) => {
  const button = event.target.closest('[data-scan-id]')
  if (!button) return
  selectedScanId = button.dataset.scanId
  scanDetailsExpanded = true
  renderHistory()
  renderScanDetails()
  scanDetailsCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
})

selectedScanDetails.addEventListener('submit', async (event) => {
  const form = event.target.closest('#measurement-form')
  if (!form) return
  event.preventDefault()
  const selected = scans.find((scan) => scan.id === selectedScanId)
  if (!selected) return
  const formData = new FormData(form)
  const parseNumber = (field) => {
    const value = String(formData.get(field) || '').trim()
    return value === '' ? null : Number(value)
  }
  const saveButton = form.querySelector('.plant-measurement-save')
  const formFeedback = form.querySelector('#measurement-feedback')
  saveButton.disabled = true
  saveButton.textContent = 'Saving…'
  formFeedback.textContent = ''
  formFeedback.dataset.kind = ''
  try {
    const response = await fetch('/api/plant-monitoring.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        plant_id: plantId,
        scan_id: selected.id,
        leaf_count: parseNumber('leaf_count'),
        plant_size_cm: parseNumber('plant_size_cm'),
        plant_height_cm: parseNumber('plant_height_cm'),
      }),
    })
    const result = await response.json()
    if (response.status === 401) throw new Error('Your session has expired. Sign in again before saving measurements.')
    if (!response.ok || !result.success || !result.scan) throw new Error(result.message || 'Unable to save these measurements.')
    scans = scans.map((scan) => scan.id === result.scan.id ? result.scan : scan)
    renderPage()
    const updatedFeedback = document.querySelector('#measurement-feedback')
    if (updatedFeedback) updatedFeedback.textContent = 'Measurements saved for this scan.'
    showFeedback('')
  } catch (error) {
    console.error('Unable to save plant measurements:', error)
    formFeedback.textContent = error.message || 'Unable to save these measurements. Please try again.'
    formFeedback.dataset.kind = 'error'
  } finally {
    if (saveButton.isConnected) {
      saveButton.disabled = false
      saveButton.textContent = 'Save measurements'
    }
  }
})

olderSelect.addEventListener('change', () => {
  if (olderSelect.value === newerSelect.value) {
    newerSelect.value = scans.find((scan) => scan.id !== olderSelect.value)?.id || ''
  }
  compareSelectedScans()
})
newerSelect.addEventListener('change', () => {
  if (newerSelect.value === olderSelect.value) {
    olderSelect.value = scans.find((scan) => scan.id !== newerSelect.value)?.id || ''
  }
  compareSelectedScans()
})

document.querySelectorAll('[data-monitor-route]').forEach((button) => button.addEventListener('click', () => {
  const route = button.dataset.monitorRoute
  const homePath = '/TanimTrack%20Dashboard/index%20(1).html'
  if (route === 'scan') {
    window.location.href = `/dashboard.html?plant_id=${encodeURIComponent(plantId)}`
    return
  }
  window.location.href = route === 'home' ? homePath : `${homePath}#${route}`
}))

void loadPlant()
