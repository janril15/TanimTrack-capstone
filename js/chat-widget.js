(() => {
  'use strict'

  const existingWidget = window.__tanimTrackAiChatWidget
  if (existingWidget && existingWidget.panel && existingWidget.launcher) {
    window.initAuthenticatedChatbot?.()
    return
  }

  const state = {
    open: false,
    panel: null,
    launcher: null,
    appContainer: null,
    dragPointerId: null,
    startPointerX: 0,
    startPointerY: 0,
    startLeft: 0,
    startTop: 0,
    launcherScreenLeft: null,
    launcherScreenTop: null,
    pointerMoved: false,
    suppressClick: false,
    loadConversationData: null,
  }

  function isAuthenticatedHomeVisible() {
    const dashboardApp = document.querySelector('#dashboard-app')
    const dashboardShell = document.querySelector('.dashboard-page')
    const dashboardNav = document.querySelector('.new-bottom-nav')
    const pathIsDashboard = /dashboard\.html/i.test(window.location.pathname || '') || /#dashboard/i.test(window.location.hash || '')
    return Boolean((dashboardApp && !dashboardApp.hidden) || (dashboardShell && !dashboardShell.hidden) || dashboardNav || pathIsDashboard)
  }

  function getAppContainer() {
    const container = document.querySelector('#dashboard-app') || document.querySelector('.dashboard-page.app-shell') || document.querySelector('.dashboard-page') || document.querySelector('.app-shell') || document.body
    if (container && container !== document.body) {
      container.style.position = container.style.position || 'relative'
      container.style.overflow = container.style.overflow || 'visible'
    }
    return container
  }

  function setLauncherScreenPosition(left, top) {
    if (!state.launcher || !state.appContainer) return
    const containerRect = state.appContainer.getBoundingClientRect()
    state.launcherScreenLeft = left
    state.launcherScreenTop = top
    state.launcher.style.left = `${left - containerRect.left + state.appContainer.scrollLeft}px`
    state.launcher.style.top = `${top - containerRect.top + state.appContainer.scrollTop}px`
  }

  function syncLauncherToScreenPosition() {
    if (state.launcherScreenLeft === null || state.launcherScreenTop === null) return
    setLauncherScreenPosition(state.launcherScreenLeft, state.launcherScreenTop)
  }

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max)
  }

  function updateLauncherBounds() {
    if (!state.launcher || !state.appContainer) return
    const containerRect = state.appContainer.getBoundingClientRect()
    const launcherRect = state.launcher.getBoundingClientRect()
    const minX = Math.max(0, containerRect.left)
    const minY = Math.max(0, containerRect.top)
    const maxX = Math.max(minX, Math.min(window.innerWidth, containerRect.right) - launcherRect.width)
    const maxY = Math.max(minY, Math.min(window.innerHeight, containerRect.bottom) - launcherRect.height)
    setLauncherScreenPosition(clamp(launcherRect.left, minX, maxX), clamp(launcherRect.top, minY, maxY))
  }

  function positionLauncherAtDefault() {
    if (!state.launcher || !state.appContainer) return
    const containerRect = state.appContainer.getBoundingClientRect()
    const launcherWidth = state.launcher.offsetWidth || 52
    const launcherHeight = state.launcher.offsetHeight || 52
    const padding = 16
    const minX = Math.max(0, containerRect.left)
    const minY = Math.max(0, containerRect.top)
    const maxX = Math.max(minX, Math.min(window.innerWidth, containerRect.right) - launcherWidth)
    const maxY = Math.max(minY, Math.min(window.innerHeight, containerRect.bottom) - launcherHeight)
    const defaultLeft = clamp(containerRect.right - launcherWidth - padding, minX, maxX)
    const defaultTop = clamp(containerRect.bottom - launcherHeight - 88, minY, maxY)
    setLauncherScreenPosition(defaultLeft, defaultTop)
  }

  function openChatbot() {
    if (!state.panel || !state.launcher) return
    state.open = true
    state.loadConversationData?.()
    state.panel.hidden = false
    state.panel.style.display = 'flex'
    state.launcher.hidden = false
    state.launcher.setAttribute('aria-expanded', 'true')
    state.launcher.style.display = 'grid'
  }

  function closeChatbot() {
    if (!state.panel || !state.launcher) return
    state.open = false
    state.panel.hidden = true
    state.panel.style.display = 'none'
    state.launcher.hidden = false
    state.launcher.setAttribute('aria-expanded', 'false')
    state.launcher.style.display = 'grid'
  }

  function toggleChatbot() {
    setChatOpen(!state.open)
  }

  function setChatOpen(open) {
    if (open) openChatbot()
    else closeChatbot()
  }

  function attachDragBehavior() {
    if (!state.launcher || state.launcher.dataset.dragBound === 'true') return
    state.launcher.dataset.dragBound = 'true'

    state.launcher.addEventListener('pointerdown', (event) => {
      if (event.button !== undefined && event.button !== 0) return
      state.dragPointerId = event.pointerId
      state.pointerMoved = false
      state.suppressClick = false
      state.startPointerX = event.clientX
      state.startPointerY = event.clientY
      const launcherRect = state.launcher.getBoundingClientRect()
      state.startLeft = launcherRect.left
      state.startTop = launcherRect.top
      state.launcher.setPointerCapture?.(event.pointerId)
      event.preventDefault()
    })

    state.launcher.addEventListener('pointermove', (event) => {
      if (state.dragPointerId !== event.pointerId) return
      const deltaX = event.clientX - state.startPointerX
      const deltaY = event.clientY - state.startPointerY
      if (Math.abs(deltaX) > 8 || Math.abs(deltaY) > 8) {
        state.pointerMoved = true
        state.suppressClick = true
      }
      const containerRect = state.appContainer.getBoundingClientRect()
      const width = state.launcher.offsetWidth || 52
      const height = state.launcher.offsetHeight || 52
      const minLeft = Math.max(0, containerRect.left)
      const minTop = Math.max(0, containerRect.top)
      const maxLeft = Math.max(minLeft, Math.min(window.innerWidth, containerRect.right) - width)
      const maxTop = Math.max(minTop, Math.min(window.innerHeight, containerRect.bottom) - height)
      const nextLeft = clamp(state.startLeft + deltaX, minLeft, maxLeft)
      const nextTop = clamp(state.startTop + deltaY, minTop, maxTop)
      setLauncherScreenPosition(nextLeft, nextTop)
      event.preventDefault()
    })

    state.launcher.addEventListener('pointerup', (event) => {
      if (state.dragPointerId !== event.pointerId) return
      state.dragPointerId = null
      if (state.pointerMoved) {
        state.suppressClick = true
        setTimeout(() => { state.suppressClick = false }, 0)
      }
      state.launcher.releasePointerCapture?.(event.pointerId)
    })

    state.launcher.addEventListener('pointercancel', () => {
      state.dragPointerId = null
      state.pointerMoved = false
      state.suppressClick = false
    })

    state.launcher.addEventListener('click', (event) => {
      if (state.suppressClick) {
        state.suppressClick = false
        event.preventDefault()
        event.stopPropagation()
        return
      }
      event.preventDefault()
      event.stopPropagation()
      toggleChatbot()
    })
  }

  function createChatbot() {
    if (!isAuthenticatedHomeVisible()) return null

    const existingPanel = document.querySelector('#ai-chat')
    const existingLauncher = document.querySelector('#chat-launcher')
    const hasCurrentChatControls = existingPanel && [
      '#chat-conversation-select',
      '#chat-plant-select',
      '#new-chat',
      '#clear-chat',
      '#chat-input',
      '#chat-send',
    ].every((selector) => existingPanel.querySelector(selector))
    if (existingPanel && existingLauncher && hasCurrentChatControls) {
      state.panel = existingPanel
      state.launcher = existingLauncher
      state.appContainer = getAppContainer()
      existingPanel.hidden = !state.open
      existingLauncher.hidden = false
      existingLauncher.setAttribute('aria-expanded', String(state.open))
      attachDragBehavior()
      return state.panel
    }
    existingPanel?.remove()
    existingLauncher?.remove()

    const assistantAvatar = new URL('../TANIMTRACK/images/ai profile pic.png', import.meta.url).href
    const panel = document.createElement('section')
    panel.id = 'ai-chat'
    panel.className = 'ai-chat'
    panel.hidden = true
    panel.setAttribute('aria-label', 'TanimTrack AI chat')
    panel.setAttribute('role', 'dialog')
    panel.innerHTML = `
      <header class="chat-header">
        <button id="close-chat-back" type="button" aria-label="Close TanimTrack AI">‹</button>
        <img class="chat-avatar" src="${assistantAvatar}" alt="TanimTrack AI">
        <span><strong>TanimTrack AI</strong><small>Your gardening and farming assistant</small></span>
        <button id="close-chat" type="button" aria-label="Close chat">×</button>
      </header>
      <div class="chat-controls">
        <div class="chat-history-controls">
          <label class="sr-only" for="chat-conversation-select">Conversation history</label>
          <select id="chat-conversation-select" aria-label="Conversation history"><option value="">New conversation</option></select>
          <button id="new-chat" type="button">New</button>
          <button id="clear-chat" type="button" disabled>Clear</button>
        </div>
        <div class="chat-plant-controls">
          <label for="chat-plant-select">Plant context <small>Optional</small></label>
          <select id="chat-plant-select"><option value="">Choose one of your saved plants</option></select>
        </div>
      </div>
      <div id="chat-content" class="chat-content" role="log" aria-live="polite">
        <div class="chat-welcome">
          <img class="welcome-avatar" src="${assistantAvatar}" alt="TanimTrack AI">
          <div>
            <strong>Hi! I’m TanimTrack AI <span>🌱</span></strong>
            <p>Your practical gardening and farming assistant. I can help you grow healthier plants, understand common problems, prevent pests, and plan your harvest.</p>
          </div>
        </div>
        <div id="chat-empty" class="chat-empty"><span>♧</span><strong>Welcome to TanimTrack AI</strong><p>Choose a question below or ask about your garden.</p></div>
        <div id="suggested-questions" class="suggested-questions">
          <div><strong>✦ &nbsp;Suggested questions</strong></div>
          <button type="button" data-question="How do I grow pechay from seeds?">🌱 <span>How do I grow pechay?</span>›</button>
          <button type="button" data-question="Why are my leaves turning yellow?">🍃 <span>Why are my leaves turning yellow?</span>›</button>
          <button type="button" data-question="How often should I water my plants?">💧 <span>How often should I water?</span>›</button>
          <button type="button" data-question="How can I prevent pests in my vegetable garden?">🐛 <span>How do I prevent pests?</span>›</button>
          <button type="button" data-question="What fertilizer should I use for vegetables?">🌿 <span>What fertilizer should I use?</span>›</button>
          <button type="button" data-question="When should I harvest my crops?">🌾 <span>When should I harvest?</span>›</button>
        </div>
      </div>
      <div id="image-preview" class="image-preview" hidden><img id="image-preview-photo" alt="Selected plant preview"><span id="image-preview-name"></span><button id="remove-image" type="button" aria-label="Remove selected image">×</button></div>
      <div id="image-action-sheet" class="image-action-sheet" hidden><strong>What would you like to do?</strong><button id="send-picture-action" type="button"><span>▧</span><b>Send a picture</b><small>Share a plant photo in the chat</small></button><button id="scan-plant-action" type="button"><span>⌾</span><b>Scan plant</b><small>Send the photo and get an AI health result</small></button></div>
      <div id="scan-camera-view" class="scan-camera-view" hidden><button id="scan-camera-back" class="scan-camera-back" type="button">‹ Back to chat</button><div class="scan-camera-frame"><video id="scan-camera-video" autoplay playsinline muted aria-label="Scan plant camera"></video><img id="captured-photo" alt="Captured plant" hidden></div><button id="capture-photo" class="capture-photo-button" type="button">Take photo</button><button id="send-photo" class="send-photo-button" type="button" hidden>Send photo</button></div>
      <form id="chat-composer" class="chat-composer"><input id="image-input" class="image-input" type="file" accept="image/*"><button id="attach-image" type="button" aria-label="Attach plant picture">+</button><input id="chat-input" maxlength="2000" placeholder="Ask TanimTrack AI..." aria-label="Ask TanimTrack AI" autocomplete="off"><button id="chat-send" type="submit" aria-label="Send question">➤</button></form>
    </section>`

    const launcher = document.createElement('button')
    launcher.id = 'chat-launcher'
    launcher.className = 'chat-launcher'
    launcher.type = 'button'
    launcher.title = 'Open TanimTrack AI'
    launcher.setAttribute('aria-label', 'Open TanimTrack AI')
    launcher.setAttribute('aria-controls', 'ai-chat')
    launcher.setAttribute('aria-expanded', 'false')
    launcher.innerHTML = '<span aria-hidden="true">◌</span>'

    state.panel = panel
    state.launcher = launcher
    state.appContainer = getAppContainer()
    const container = state.appContainer || document.body
    container.append(launcher, panel)
    attachDragBehavior()

    const chatContent = panel.querySelector('#chat-content')
    const chatInput = panel.querySelector('#chat-input')
    const chatEmpty = panel.querySelector('#chat-empty')
    const chatWelcome = panel.querySelector('.chat-welcome')
    const suggestedQuestions = panel.querySelector('#suggested-questions')
    const imageInput = panel.querySelector('#image-input')
    const imageActionSheet = panel.querySelector('#image-action-sheet')
    const scanCameraView = panel.querySelector('#scan-camera-view')
    const scanCameraVideo = panel.querySelector('#scan-camera-video')
    const capturedPhoto = panel.querySelector('#captured-photo')
    const capturePhoto = panel.querySelector('#capture-photo')
    const sendPhoto = panel.querySelector('#send-photo')
    const conversationSelect = panel.querySelector('#chat-conversation-select')
    const plantSelect = panel.querySelector('#chat-plant-select')
    const newChatButton = panel.querySelector('#new-chat')
    const clearChatButton = panel.querySelector('#clear-chat')
    const sendButton = panel.querySelector('#chat-send')
    const closeButton = panel.querySelector('#close-chat')
    const closeBackButton = panel.querySelector('#close-chat-back')

    let scanStream = null
    let capturedPhotoData = ''
    let loadingRequest = null
    let conversations = []
    let plants = []

    function scrollToLatest() {
      chatContent.scrollTop = chatContent.scrollHeight
    }

    function setIntroVisible(visible) {
      chatWelcome.hidden = !visible
      chatEmpty.hidden = !visible
      suggestedQuestions.hidden = !visible
    }

    function appendMessage(kind, text, retryAction = null) {
      const message = document.createElement('div')
      message.className = `chat-message ${kind}`
      if (kind === 'assistant' || kind === 'error') {
        const avatar = document.createElement('img')
        avatar.className = 'message-avatar'
        avatar.src = assistantAvatar
        avatar.alt = 'TanimTrack AI'
        message.append(avatar)
        if (kind === 'error') message.classList.add('chat-message-error')
      }
      const paragraph = document.createElement('p')
      paragraph.textContent = text
      message.append(paragraph)
      if (retryAction) {
        const retry = document.createElement('button')
        retry.type = 'button'
        retry.className = 'chat-retry'
        retry.textContent = 'Retry'
        retry.addEventListener('click', () => {
          message.remove()
          retryAction()
        })
        message.append(retry)
      }
      chatContent.append(message)
      scrollToLatest()
      return message
    }

    function appendLoading(messageText = 'TanimTrack AI is thinking…') {
      const message = appendMessage('assistant', messageText)
      message.classList.add('chat-loading')
      message.setAttribute('role', 'status')
      return message
    }

    function setBusy(busy) {
      chatInput.disabled = busy
      sendButton.disabled = busy
      newChatButton.disabled = busy
      conversationSelect.disabled = busy
      plantSelect.disabled = busy
      clearChatButton.disabled = busy || !state.conversationId
      panel.setAttribute('aria-busy', String(busy))
    }

    async function requestApi(url, options = {}) {
      let response
      try {
        response = await fetch(url, { credentials: 'same-origin', ...options })
      } catch {
        throw new Error('Could not reach TanimTrack AI. Check your connection and retry.')
      }
      let result
      try {
        result = await response.json()
      } catch {
        throw new Error('TanimTrack AI returned an unreadable response. Please retry.')
      }
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'TanimTrack AI is temporarily unavailable. Please retry.')
      }
      return result
    }

    function renderConversationOptions() {
      conversationSelect.replaceChildren()
      const newOption = document.createElement('option')
      newOption.value = ''
      newOption.textContent = 'New conversation'
      conversationSelect.append(newOption)
      conversations.forEach((conversation) => {
        const option = document.createElement('option')
        option.value = conversation.id
        option.textContent = conversation.title
        conversationSelect.append(option)
      })
      conversationSelect.value = state.conversationId || ''
      clearChatButton.disabled = !state.conversationId
    }

    function renderPlantOptions() {
      plantSelect.replaceChildren()
      const noPlantOption = document.createElement('option')
      noPlantOption.value = ''
      noPlantOption.textContent = 'Choose one of your saved plants'
      plantSelect.append(noPlantOption)
      plants.forEach((plant) => {
        const option = document.createElement('option')
        option.value = plant.id
        option.textContent = plant.species ? `${plant.name} · ${plant.species}` : plant.name
        plantSelect.append(option)
      })
      plantSelect.value = state.selectedPlantId || ''
    }

    async function loadConversationData() {
      if (loadingRequest) return loadingRequest
      loadingRequest = (async () => {
        const result = await requestApi('/api/chat.php')
        conversations = result.conversations
        plants = result.plants
        if (state.conversationId && !conversations.some((item) => item.id === state.conversationId)) {
          state.conversationId = null
          state.selectedPlantId = null
          renderMessages([])
        }
        renderConversationOptions()
        renderPlantOptions()
      })()
      try {
        await loadingRequest
      } catch (error) {
        appendMessage('error', error.message, () => { void loadConversationData() })
      } finally {
        loadingRequest = null
      }
    }
    state.loadConversationData = loadConversationData

    function renderMessages(messages) {
      chatContent.querySelectorAll('.chat-message').forEach((message) => message.remove())
      messages.forEach((message) => appendMessage(
        message.role === 'user' ? 'user' : 'assistant',
        message.content
      ))
      setIntroVisible(messages.length === 0)
      scrollToLatest()
    }

    function startNewConversation() {
      state.conversationId = null
      state.selectedPlantId = null
      conversationSelect.value = ''
      plantSelect.value = ''
      renderMessages([])
      clearChatButton.disabled = true
      chatInput.focus()
    }

    async function loadConversation(conversationId) {
      setBusy(true)
      try {
        const result = await requestApi(`/api/chat.php?conversation_id=${encodeURIComponent(conversationId)}`)
        state.conversationId = result.conversation.id
        state.selectedPlantId = result.conversation.plant_id
        conversations = result.conversations
        plants = result.plants
        renderConversationOptions()
        renderPlantOptions()
        renderMessages(result.messages)
      } catch (error) {
        appendMessage('error', error.message, () => { void loadConversation(conversationId) })
      } finally {
        setBusy(false)
      }
    }

    async function clearConversation() {
      if (!state.conversationId || !window.confirm('Clear this conversation and its saved messages? This cannot be undone.')) return
      const conversationId = state.conversationId
      setBusy(true)
      try {
        await requestApi(`/api/chat.php?conversation_id=${encodeURIComponent(conversationId)}`, { method: 'DELETE' })
        conversations = conversations.filter((conversation) => conversation.id !== conversationId)
        startNewConversation()
        renderConversationOptions()
        renderPlantOptions()
      } catch (error) {
        appendMessage('error', error.message, () => { void clearConversation() })
      } finally {
        setBusy(false)
      }
    }

    function addConversation(conversation) {
      conversations = [
        conversation,
        ...conversations.filter((item) => item.id !== conversation.id),
      ].slice(0, 50)
      state.conversationId = conversation.id
      state.selectedPlantId = conversation.plant_id
      renderConversationOptions()
      renderPlantOptions()
    }

    async function sendQuestion(question, isRetry = false) {
      const prompt = question.trim()
      if (!prompt || panel.getAttribute('aria-busy') === 'true') return
      if (!isRetry) {
        setIntroVisible(false)
        appendMessage('user', prompt)
      }
      chatInput.value = ''
      setBusy(true)
      const loading = appendLoading()
      try {
        const result = await requestApi('/api/chat.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            message: prompt,
            conversation_id: state.conversationId,
            plant_id: plantSelect.value || null,
          }),
        })
        loading.remove()
        addConversation(result.conversation)
        appendMessage('assistant', result.answer)
      } catch (error) {
        loading.remove()
        appendMessage('error', error.message, () => { void sendQuestion(prompt, true) })
      } finally {
        setBusy(false)
        chatInput.focus()
      }
    }

    function sendPicture(image) {
      setIntroVisible(false)
      const message = document.createElement('div')
      message.className = 'chat-message image'
      const photo = document.createElement('img')
      photo.className = 'sent-plant-image'
      photo.src = image.url
      photo.alt = `Uploaded plant: ${image.name}`
      message.append(photo)
      chatContent.append(message)
      scrollToLatest()
    }

    async function runPlantScan(image) {
      if (panel.getAttribute('aria-busy') === 'true') return
      setBusy(true)
      const loading = appendLoading('TanimTrack AI is analyzing your plant photo…')
      try {
        const file = image.file || new File(
          [await (await fetch(image.url)).blob()],
          image.name || 'plant-scan.jpg',
          { type: 'image/jpeg' }
        )
        if (!file.type.startsWith('image/') || file.size === 0 || file.size > 5 * 1024 * 1024) {
          throw new Error('Choose a valid plant photo smaller than 5 MB.')
        }
        const formData = new FormData()
        formData.append('scan_image', file, file.name || 'plant-scan.jpg')
        if (plantSelect.value) formData.append('plant_id', plantSelect.value)
        const result = await requestApi('/api/scan.php', { method: 'POST', body: formData })
        loading.remove()
        const details = [
          `${result.plant_name || 'Plant'} — ${result.health_status}`,
          result.detected_issue ? `Possible issue: ${result.detected_issue}` : '',
          result.analysis,
          result.visible_symptoms?.length ? `Visible signs: ${result.visible_symptoms.join('; ')}` : '',
          result.recommendations?.length ? `Suggestions: ${result.recommendations.join('; ')}` : '',
          result.prevention_tips?.length ? `Prevention: ${result.prevention_tips.join('; ')}` : '',
          result.needs_verification
            ? 'This scan needs manual verification; it is not a confirmed diagnosis.'
            : 'AI image analysis is guidance, not a confirmed diagnosis.',
          result.scan_save_error || '',
        ].filter(Boolean)
        appendMessage('assistant', details.join('\n\n'))
      } catch (error) {
        loading.remove()
        appendMessage('error', error.message, () => { void runPlantScan(image) })
      } finally {
        setBusy(false)
      }
    }

    function closeScanCamera() {
      scanStream?.getTracks().forEach((track) => track.stop())
      scanStream = null
      scanCameraView.hidden = true
      capturedPhoto.hidden = true
      scanCameraVideo.hidden = false
      capturePhoto.hidden = false
      sendPhoto.hidden = true
      capturedPhotoData = ''
    }

    function setChatOpen(open) {
      if (open) openChatbot()
      else closeChatbot()
      if (!open) closeScanCamera()
    }

    closeButton?.addEventListener('click', () => setChatOpen(false))
    closeBackButton?.addEventListener('click', () => setChatOpen(false))
    panel.querySelector('#chat-composer')?.addEventListener('submit', (event) => {
      event.preventDefault()
      void sendQuestion(chatInput.value)
    })
    panel.querySelectorAll('[data-question]').forEach((button) => button.addEventListener('click', () => {
      void sendQuestion(button.dataset.question)
    }))
    conversationSelect.addEventListener('change', () => {
      if (conversationSelect.value) void loadConversation(conversationSelect.value)
      else startNewConversation()
    })
    plantSelect.addEventListener('change', () => {
      state.selectedPlantId = plantSelect.value || null
    })
    newChatButton.addEventListener('click', startNewConversation)
    clearChatButton.addEventListener('click', () => { void clearConversation() })
    imageInput.addEventListener('change', () => {
      const file = imageInput.files?.[0]
      if (!file || !file.type.startsWith('image/')) return
      const reader = new FileReader()
      reader.onload = () => {
        imageActionSheet.hidden = true
        sendPicture({ name: file.name, url: reader.result })
        imageInput.value = ''
      }
      reader.readAsDataURL(file)
    })
    panel.querySelector('#attach-image').addEventListener('click', () => { imageActionSheet.hidden = !imageActionSheet.hidden })
    panel.querySelector('#send-picture-action').addEventListener('click', () => imageInput.click())
    panel.querySelector('#scan-plant-action').addEventListener('click', async () => {
      imageActionSheet.hidden = true
      scanCameraView.hidden = false
      if (!navigator.mediaDevices?.getUserMedia) {
        scanCameraView.hidden = true
        appendMessage('error', 'Camera access is not available in this browser. You can use the Plant/Crop Scan page instead.')
        return
      }
      try {
        scanStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
        scanCameraVideo.srcObject = scanStream
      } catch {
        scanCameraView.hidden = true
        appendMessage('error', 'Camera access is off. Allow camera permission or use the Plant/Crop Scan page.')
      }
    })
    capturePhoto.addEventListener('click', () => {
      if (!scanCameraVideo.videoWidth) return
      const canvas = document.createElement('canvas')
      const scale = Math.min(1, 1600 / Math.max(scanCameraVideo.videoWidth, scanCameraVideo.videoHeight))
      canvas.width = Math.round(scanCameraVideo.videoWidth * scale)
      canvas.height = Math.round(scanCameraVideo.videoHeight * scale)
      const context = canvas.getContext('2d')
      if (!context) {
        appendMessage('error', 'Could not capture the photo. Please try again.')
        return
      }
      context.drawImage(scanCameraVideo, 0, 0, canvas.width, canvas.height)
      capturedPhotoData = canvas.toDataURL('image/jpeg', 0.82)
      capturedPhoto.src = capturedPhotoData
      capturedPhoto.hidden = false
      scanCameraVideo.hidden = true
      capturePhoto.hidden = true
      sendPhoto.hidden = false
      scanStream?.getTracks().forEach((track) => track.stop())
      scanStream = null
    })
    sendPhoto.addEventListener('click', () => {
      if (!capturedPhotoData) return
      const image = { name: 'plant-scan.jpg', url: capturedPhotoData }
      sendPicture(image)
      closeScanCamera()
      void runPlantScan(image)
    })
    panel.querySelector('#scan-camera-back').addEventListener('click', closeScanCamera)

    document.querySelector('#ask-ai-button')?.addEventListener('click', () => setChatOpen(true))
    window.addEventListener('pagehide', closeScanCamera, { once: true })

    positionLauncherAtDefault()
    return panel
  }

  function initAuthenticatedChatbot() {
    if (!isAuthenticatedHomeVisible()) {
      const panel = document.querySelector('#ai-chat')
      const launcher = document.querySelector('#chat-launcher')
      if (panel) {
        panel.hidden = true
        panel.style.display = 'none'
      }
      if (launcher) {
        launcher.hidden = false
        launcher.style.display = 'grid'
      }
      return null
    }

    const panel = document.querySelector('#ai-chat') || state.panel || createChatbot()
    const launcher = document.querySelector('#chat-launcher') || state.launcher
    if (!panel || !launcher) return null

    const container = getAppContainer()
    if (container && container !== document.body && !container.contains(panel)) container.append(panel)
    if (container && container !== document.body && !container.contains(launcher)) container.append(launcher)

    state.panel = panel
    state.launcher = launcher
    state.appContainer = container
    panel.hidden = !state.open
    panel.style.display = state.open ? 'flex' : 'none'
    launcher.hidden = false
    launcher.style.display = 'grid'
    launcher.style.opacity = '1'
    launcher.style.visibility = 'visible'
    launcher.setAttribute('aria-expanded', String(state.open))
    if (!state.open) positionLauncherAtDefault()
    return panel
  }

  if (!isAuthenticatedHomeVisible()) return

  createChatbot()
  window.__tanimTrackAiChatWidget = {
    openChatbot,
    closeChatbot,
    toggleChatbot,
    setChatOpen,
    launcher: state.launcher,
    panel: state.panel,
    appContainer: state.appContainer,
    initialized: true,
  }
  window.initAuthenticatedChatbot = initAuthenticatedChatbot
  window.openChatbot = openChatbot
  window.closeChatbot = closeChatbot
  window.toggleChatbot = toggleChatbot
  window.setChatOpen = setChatOpen
  window.addEventListener('resize', updateLauncherBounds)
  window.addEventListener('scroll', syncLauncherToScreenPosition, { capture: true, passive: true })
  window.addEventListener('orientationchange', updateLauncherBounds)
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.panel && !state.panel.hidden) closeChatbot()
  })
})()