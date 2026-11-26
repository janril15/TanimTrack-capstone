import React from 'react'
import scanPlantImage from '../../TanimTrack Dashboard/plants (1).jpg'

const STORAGE_KEY = 'tanodbukid_profile_setup'

export default function Dashboard() {
  const [profile, setProfile] = React.useState(null)
  const [activeTab, setActiveTab] = React.useState('camera')
  const [lastScanImage, setLastScanImage] = React.useState(scanPlantImage)
  const [showChangeDetails, setShowChangeDetails] = React.useState(false)
  const [cameraError, setCameraError] = React.useState('')
  const [isMonitoring, setIsMonitoring] = React.useState(true)
  const [chatOpen, setChatOpen] = React.useState(false)
  const [chatInput, setChatInput] = React.useState('')
  const [chatBusy, setChatBusy] = React.useState(false)
  const [chatConversationId, setChatConversationId] = React.useState(null)
  const [messages, setMessages] = React.useState([])
  const [selectedImage, setSelectedImage] = React.useState(null)
  const [imageActionOpen, setImageActionOpen] = React.useState(false)
  const [imageAction, setImageAction] = React.useState('send')
  const [scanCameraOpen, setScanCameraOpen] = React.useState(false)
  const [capturedPhoto, setCapturedPhoto] = React.useState(null)
  const imageInputRef = React.useRef(null)
  const scanVideoRef = React.useRef(null)
  const scanStreamRef = React.useRef(null)
  const chatBusyRef = React.useRef(false)
  const [analysis, setAnalysis] = React.useState({ confidence: 92, health: 'Healthy', condition: 'Normal', growth: 'Vegetative', disease: 'Low', discoloration: 'None detected' })
  const videoRef = React.useRef(null)
  const streamRef = React.useRef(null)

  React.useEffect(() => {
    let cancelled = false

    async function startCamera() {
      if (!navigator.mediaDevices?.getUserMedia) {
        setCameraError('Camera access is not supported in this browser.')
        return
      }

      try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
        if (cancelled) {
          stream.getTracks().forEach((track) => track.stop())
          return
        }
        streamRef.current = stream
        videoRef.current.srcObject = stream
      } catch {
        setCameraError('Camera access is off. Allow permission to scan your plant live.')
        setIsMonitoring(false)
      }
    }

    startCamera()
    return () => {
      cancelled = true
      streamRef.current?.getTracks().forEach((track) => track.stop())
    }
  }, [])

  React.useEffect(() => {
    if (!isMonitoring) return undefined
    const interval = window.setInterval(() => {
      setAnalysis((current) => ({
        ...current,
        confidence: current.confidence >= 97 ? 91 : current.confidence + 1,
      }))
    }, 2200)
    return () => window.clearInterval(interval)
  }, [isMonitoring])

  const toggleMonitoring = () => {
    if (!isMonitoring && !streamRef.current && navigator.mediaDevices?.getUserMedia) {
      navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
        .then((stream) => {
          streamRef.current = stream
          videoRef.current.srcObject = stream
          setCameraError('')
          setIsMonitoring(true)
        })
        .catch(() => setCameraError('Camera access is off. Allow permission to scan your plant live.'))
      return
    }
    setIsMonitoring((current) => !current)
  }

  const askAi = async (question, isRetry = false) => {
    const prompt = question.trim()
    if (!prompt || chatBusyRef.current) return
    chatBusyRef.current = true
    setChatBusy(true)
    if (!isRetry) {
      setMessages((current) => [...current, { type: 'user', text: prompt }])
      setChatInput('')
    }

    try {
      const response = await fetch('/api/chat.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: prompt, conversation_id: chatConversationId }),
      })
      const result = await response.json().catch(() => null)
      if (
        !response.ok
        || result?.success !== true
        || typeof result?.answer !== 'string'
        || !result.answer.trim()
        || typeof result?.conversation?.id !== 'string'
      ) {
        const detail = typeof result?.message === 'string' ? result.message : ''
        throw new Error(detail || 'The chat service returned an invalid response.')
      }

      setChatConversationId(result.conversation.id)
      setMessages((current) => [...current, { type: 'assistant', text: result.answer }])
    } catch (error) {
      const detail = error instanceof Error ? error.message : ''
      setMessages((current) => [...current, {
        type: 'error',
        text: `TanimTrack AI is temporarily unable to generate a response.${detail ? ` ${detail}` : ''}`,
        retryQuestion: prompt,
      }])
    } finally {
      chatBusyRef.current = false
      setChatBusy(false)
    }
  }

  const handleImageSelect = (event) => {
    const file = event.target.files?.[0]
    if (!file || !file.type.startsWith('image/')) return
    const reader = new FileReader()
    reader.onload = () => {
      const image = { name: file.name, url: reader.result, file }
      setSelectedImage(image)
      setImageActionOpen(false)
      if (imageAction === 'scan') void scanSelectedImage(image)
      if (imageAction === 'send') sendImage(image)
    }
    reader.readAsDataURL(file)
    event.target.value = ''
  }

  const sendImage = (image) => {
    setMessages((current) => [...current, { type: 'image', url: image.url, name: image.name }])
    setSelectedImage(null)
  }

  const openScanCamera = async () => {
    setImageActionOpen(false)
    setCapturedPhoto(null)
    setScanCameraOpen(true)
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
      scanStreamRef.current = stream
      scanVideoRef.current.srcObject = stream
    } catch {
      setScanCameraOpen(false)
      setCameraError('Camera access is off. Allow permission to scan your plant.')
    }
  }

  const captureScanPhoto = () => {
    const video = scanVideoRef.current
    if (!video?.videoWidth) return
    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
    setCapturedPhoto(canvas.toDataURL('image/jpeg', 0.88))
    scanStreamRef.current?.getTracks().forEach((track) => track.stop())
  }

  const sendCapturedPhoto = () => {
    if (!capturedPhoto) return
    setLastScanImage(capturedPhoto)
    void scanSelectedImage({ url: capturedPhoto, name: 'scanned-plant.jpg' })
    setCapturedPhoto(null)
    setScanCameraOpen(false)
  }

  React.useEffect(() => () => scanStreamRef.current?.getTracks().forEach((track) => track.stop()), [])

  const scanSelectedImage = async (image = selectedImage, isRetry = false) => {
    if (!image) return
    if (chatBusyRef.current) return
    chatBusyRef.current = true
    setChatBusy(true)
    setLastScanImage(image.url)
    if (!isRetry) {
      setMessages((current) => [...current, { type: 'image', url: image.url, name: image.name }])
      setSelectedImage(null)
    }
    try {
      const file = image.file instanceof File
        ? image.file
        : await fetch(image.url).then((response) => {
          if (!response.ok) throw new Error('The selected plant photo could not be read.')
          return response.blob()
        }).then((blob) => new File([blob], image.name || 'plant-scan.jpg', { type: blob.type || 'image/jpeg' }))
      if (!file.type.startsWith('image/') || file.size === 0 || file.size > 5 * 1024 * 1024) {
        throw new Error('Choose a valid plant photo smaller than 5 MB.')
      }
      const formData = new FormData()
      formData.append('scan_image', file, file.name || 'plant-scan.jpg')
      const response = await fetch('/api/scan.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: formData,
      })
      const result = await response.json().catch(() => null)
      if (
        !response.ok
        || result?.success !== true
        || typeof result?.analysis !== 'string'
        || typeof result?.health_status !== 'string'
      ) {
        const detail = typeof result?.message === 'string' ? result.message : ''
        throw new Error(detail || 'The plant scanner returned an invalid response.')
      }

      const answer = [
        `${result.plant_name || 'Plant'} — ${result.health_status}`,
        result.detected_issue ? `Possible issue: ${result.detected_issue}` : '',
        result.analysis,
        Array.isArray(result.visible_symptoms) && result.visible_symptoms.length
          ? `Visible signs: ${result.visible_symptoms.join('; ')}`
          : '',
        Array.isArray(result.recommendations) && result.recommendations.length
          ? `Suggestions: ${result.recommendations.join('; ')}`
          : '',
        Array.isArray(result.prevention_tips) && result.prevention_tips.length
          ? `Prevention: ${result.prevention_tips.join('; ')}`
          : '',
        result.needs_verification ? 'This scan needs manual verification; it is not a confirmed diagnosis.' : '',
        result.scan_save_error || '',
      ].filter(Boolean).join('\n\n')
      setMessages((current) => [...current, { type: 'assistant', text: answer }])
    } catch (error) {
      const detail = error instanceof Error ? error.message : ''
      setMessages((current) => [...current, {
        type: 'error',
        text: `TanimTrack AI is temporarily unable to analyze this photo.${detail ? ` ${detail}` : ''}`,
        retryImage: image,
      }])
    } finally {
      chatBusyRef.current = false
      setChatBusy(false)
    }
  }

  React.useEffect(() => {
    try {
      setProfile(JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'))
    } catch {
      setProfile(null)
    }
  }, [])

  return (
    <main className="dashboard-page">
      <section className={`monitor-shell ${activeTab === 'analysis' ? 'showing-analysis' : ''}`} aria-label="Plant and crop scanner">
        <header className="monitor-header">
          <div className="monitor-brand"><span className="brand-mark">+</span><span><strong>Plant / Crop Scan</strong><small>Live analysis and monitoring powered by AI</small></span></div>
          <button className="history-button" type="button">History</button>
        </header>

        <div className="monitor-tabs" role="tablist" aria-label="Scan views">
          <button className={activeTab === 'camera' ? 'active' : ''} type="button" role="tab" aria-selected={activeTab === 'camera'} onClick={() => setActiveTab('camera')}>◉ &nbsp;Live Camera</button>
          <button className={activeTab === 'analysis' ? 'active' : ''} type="button" role="tab" aria-selected={activeTab === 'analysis'} onClick={() => setActiveTab('analysis')}>✦ &nbsp;AI Analysis</button>
        </div>

        {activeTab === 'camera' ? <>
          <section className="camera-card">
            <video ref={videoRef} autoPlay playsInline muted aria-label="Live plant camera" />
            <div className="camera-shade" />
            <div className="live-pill"><span /> {isMonitoring ? 'Live' : 'Paused'}</div>
            <div className="scan-frame" aria-hidden="true"><i /><i /><i /><i /><span /></div>
            <div className="detection-pill">Plant detected <strong>{analysis.confidence}% confidence</strong></div>
            {cameraError && <p className="camera-error">{cameraError}</p>}
          </section>

          <section className="analysis-card">
            <div className="section-heading"><h2><span className="leaf-icon">✦</span> AI Health Analysis</h2><span className={isMonitoring ? 'monitoring-tag' : 'paused-tag'}>{isMonitoring ? 'Monitoring...' : 'Paused'}</span></div>
            <div className="metric-list">
              <div><span className="metric-icon">✦</span><span>Plant Health</span><strong className="healthy-text">{analysis.health}</strong><b>›</b></div>
              <div><span className="metric-icon">◈</span><span>Leaf Condition</span><strong>{analysis.condition}</strong><b>›</b></div>
              <div><span className="metric-icon">▰</span><span>Growth Stage</span><strong>{analysis.growth}</strong><b>›</b></div>
              <div><span className="metric-icon">◒</span><span>Disease Risk</span><strong>{analysis.disease}</strong><b>›</b></div>
              <div><span className="metric-icon">◌</span><span>Discoloration</span><strong>{analysis.discoloration}</strong><b>›</b></div>
            </div>
            <button className="pause-button" type="button" onClick={toggleMonitoring}><span>{isMonitoring ? 'Ⅱ' : '▶'}</span>{isMonitoring ? 'Pause Monitoring' : 'Resume Monitoring'}</button>
          </section>

          <section className="dashboard-section">
            <div className="section-heading"><h2>⟳ &nbsp;Recent Changes</h2><button type="button">View all</button></div>
            <article className="change-row"><span className="row-icon leaf-bg">◒</span><span><strong>Leaf discoloration increased</strong><small>Compared to last scan (2 days ago)</small></span><em>+8%</em></article>
          </section>

          <section className="dashboard-section farm-log"><div className="section-heading"><h2>▣ &nbsp;Farm Log</h2><button type="button">View all</button></div><p><strong>Jul 17, 2025 · 10:24 AM</strong><br />Plant health: Healthy · Growth: +4.2%<br /><span>Notes: Insufficient sunlight.</span></p></section>

          <section className="dashboard-section alert-section"><div className="section-heading"><h2>△ &nbsp;Alerts</h2><button type="button">View all</button></div><article className="alert-row"><span className="row-icon alert-bg">△</span><span><strong>Possible Rice Blast</strong><small>Detected: Jul 16, 2025 · 2:14 PM<br />Recommended action: Inspect affected leaves</small></span><em>Moderate Risk</em></article></section>

          <section className="recommendation"><span>♧</span><div><strong>AI Recommendation</strong><p>Keep monitoring for further changes.<br />Ensure proper irrigation and field drainage.</p></div></section>
          <section className="quick-actions"><h2>Quick Actions</h2><div><button type="button"><span>◉</span>Scan Again</button><button type="button"><span>♧</span>Save Result</button><button type="button" onClick={() => setChatOpen(true)}><span>◌</span>Ask AI</button><button type="button"><span>▣</span>View Logs</button></div></section>
        </> : <div className="scan-results">
          <div className="result-overview">
            <section className="scan-photo-card">
              <img src={lastScanImage} alt="Scanned plant leaf" />
              <span className="analysis-complete"><span>✓</span> Analysis Complete</span>
            </section>
            <section className="result-health-card">
              <div className="result-crop-heading"><span className="crop-leaf">❧</span><div><h2>Rice</h2><em>Oryza sativa</em></div><span className="confidence-pill">{analysis.confidence}% confidence</span></div>
              <div className="result-metrics">
                <div className="result-health-summary"><span className="result-metric-icon">♥</span><span>Plant Health</span><strong>{analysis.health}</strong><p>Overall condition is good. No major issues detected.</p></div>
                <div><span className="result-metric-icon">❧</span><span>Leaf Condition</span><strong>{analysis.condition}</strong><b>›</b></div>
                <div><span className="result-metric-icon">♣</span><span>Growth Stage</span><strong>{analysis.growth}</strong><b>›</b></div>
                <div><span className="result-metric-icon">✿</span><span>Disease Risk</span><strong>{analysis.disease}</strong><b>›</b></div>
                <div><span className="result-metric-icon">◈</span><span>Discoloration</span><strong>{analysis.discoloration}</strong><b>›</b></div>
              </div>
            </section>
          </div>

          <section className="result-section change-detection">
            <div className="result-section-heading"><span className="result-section-icon">⟳</span><div><h2>Change Detection</h2><p>Compared to your previous scan (2 days ago)</p></div></div>
            <div className="result-change-row"><span className="result-leaf-badge">❧</span><div><strong>Slight increase in leaf spot areas</strong><p>Leaf discoloration increased by 8% since last scan.</p>{showChangeDetails && <p className="change-detail-copy">Continue checking affected leaves and compare again after your next scan.</p>}</div><button type="button" aria-expanded={showChangeDetails} onClick={() => setShowChangeDetails((current) => !current)}>{showChangeDetails ? 'Hide Details' : 'View Details'} <span>›</span></button></div>
          </section>

          <section className="result-section result-farm-log">
            <div className="result-section-heading"><span className="result-section-icon">▤</span><h2>Farm Log</h2><button type="button">View All</button></div>
            <div className="farm-log-entry"><img src={lastScanImage} alt="Rice scan thumbnail" /><div className="farm-log-content"><p className="farm-log-time">Jul 17, 2025 <span>·</span> 10:24 AM</p><div className="farm-log-tags"><span>Health: Healthy</span><span>Growth: Vegetative</span><span>Risk: Low</span></div><p className="farm-log-note">▤ &nbsp;No issues detected.</p></div><span className="farm-log-arrow">›</span></div>
          </section>

          <section className="result-recommendation"><span className="recommendation-bulb">♧</span><div><h2>Recommendation</h2><strong>Continue current care routine.</strong><p>Monitor the affected areas closely. If discoloration increases or spreads, check for possible fungal infection and improve field drainage.</p></div><span className="recommendation-arrow">›</span></section>
        </div>}
      </section>
      {chatOpen && <section className="ai-chat" aria-label="TanimTrack AI chat">
        <header className="chat-header"><button type="button" aria-label="Back to scanner" onClick={() => setChatOpen(false)}>‹</button><img className="chat-avatar" src="TANIMTRACK/images/ai%20profile%20pic.png" alt="TanimTrack AI" /><span><strong>TanimTrack AI</strong><small>Your farming assistant</small></span><button type="button" aria-label="Close chat" onClick={() => setChatOpen(false)}>×</button></header>
        {scanCameraOpen ? <div className="scan-camera-view"><button className="scan-camera-back" type="button" onClick={() => { scanStreamRef.current?.getTracks().forEach((track) => track.stop()); setScanCameraOpen(false) }}>‹ Back to chat</button><div className="scan-camera-frame">{capturedPhoto ? <img src={capturedPhoto} alt="Captured plant" /> : <video ref={scanVideoRef} autoPlay playsInline muted aria-label="Scan plant camera" />}</div>{capturedPhoto ? <button className="send-photo-button" type="button" onClick={sendCapturedPhoto}>Send photo</button> : <button className="capture-photo-button" type="button" onClick={captureScanPhoto}>Take photo</button>}</div> : <div className="chat-content">
          <div className="chat-welcome"><img className="welcome-avatar" src="TANIMTRACK/images/ai%20profile%20pic.png" alt="TanimTrack AI" /><div><strong>Hi! I’m TanimTrack AI <span>🌱</span></strong><p>I can help you with plant health, soil conditions, crop recommendations, and more. Ask me anything about your farm!</p></div></div>
          <div className="chat-topics"><button type="button" onClick={() => askAi('Is my rice plant healthy?')}>🌿 Plant Health</button><button type="button" onClick={() => askAi('What soil pH is best for pechay?')}>⚗ Soil Advice</button><button type="button" onClick={() => askAi('What crops can I plant in this soil?')}>🌱 Crop Recommend</button></div>
          {messages.length === 0 && <div className="chat-empty"><span>♧</span><strong>Start a conversation</strong><p>Ask about your crops, soil, or any farming concerns. I’m here to help!</p></div>}
          {messages.map((message, index) => <div className={`chat-message ${message.type}${message.type === 'error' ? ' chat-message-error' : ''}`} key={`${message.type}-${index}`}>
            {message.type === 'assistant' && <img className="message-avatar" src="TANIMTRACK/images/ai%20profile%20pic.png" alt="TanimTrack AI" />}
            {message.type === 'image' ? <img className="sent-plant-image" src={message.url} alt={`Uploaded plant: ${message.name}`} /> : <p>{message.text}</p>}
            {message.type === 'error' && <button className="chat-retry" type="button" disabled={chatBusy} onClick={() => {
              setMessages((current) => current.filter((item) => item !== message))
              if (message.retryImage) void scanSelectedImage(message.retryImage, true)
              else if (typeof message.retryQuestion === 'string') void askAi(message.retryQuestion, true)
            }}>Retry</button>}
          </div>)}
          {chatBusy && <div className="chat-message assistant chat-loading" role="status"><img className="message-avatar" src="TANIMTRACK/images/ai%20profile%20pic.png" alt="TanimTrack AI" /><p>TanimTrack AI is generating a response</p></div>}
          {messages.length === 0 && <div className="suggested-questions"><div><strong>✦ &nbsp;Try asking</strong><span>See all ›</span></div>{['Is my rice plant healthy?', 'What soil pH is best for pechay?', 'What crops can I plant in this soil?', 'How can I prevent rice blast disease?'].map((question) => <button type="button" key={question} onClick={() => askAi(question)}>🌿 <span>{question}</span>›</button>)}</div>}
        </div>}
        {selectedImage && <div className="image-preview"><img src={selectedImage.url} alt="Selected plant preview" /><span>{selectedImage.name}</span><button type="button" onClick={() => setSelectedImage(null)} aria-label="Remove selected image">×</button></div>}
        {imageActionOpen && <div className="image-action-sheet"><strong>What would you like to do?</strong><button type="button" onClick={() => { setImageAction('send'); imageInputRef.current?.click() }}><span>▧</span><b>Send a picture</b><small>Share a plant photo in the chat</small></button><button type="button" onClick={openScanCamera}><span>⌾</span><b>Scan plant</b><small>Open the camera and analyze a photo</small></button></div>}
        <form className="chat-composer" onSubmit={(event) => { event.preventDefault(); void askAi(chatInput) }}><input ref={imageInputRef} className="image-input" type="file" accept="image/*" onChange={handleImageSelect} /><button type="button" aria-label="Attach plant picture" onClick={() => setImageActionOpen((current) => !current)}>+</button><input value={chatInput} onChange={(event) => setChatInput(event.target.value)} placeholder="Type your question..." aria-label="Type your question" disabled={chatBusy} /><button type="submit" aria-label="Send question" disabled={chatBusy}>➤</button></form>
      </section>}
    </main>
  )
}
