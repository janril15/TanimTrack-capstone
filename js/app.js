(() => {
  'use strict'

  const pageTitle = document.querySelector('#page-title')
  const pageSubtitle = document.querySelector('#page-subtitle')
  const pageCard = document.querySelector('#page-card')
  const landingApp = document.querySelector('#landing-app')
  const authApp = document.querySelector('#auth-app')
  const projectBaseUrl = new URL('/', window.location.origin)
  const apiBaseUrl = new URL('api/', projectBaseUrl)
  const dashboardHomePath = 'TanimTrack Dashboard/index (1).html'
  const state = { route: window.location.hash.slice(1) }
  let pendingRegistration = null

  try {
    localStorage.removeItem('tanodbukid_users')
  } catch (error) {
    console.warn('Unable to remove old browser-stored account data:', error)
  }

  const pages = {
    signin: ['WELCOME', 'Sign in to your TanimTrack account'],
    register: ['CREATE YOUR ACCOUNT', 'Join TanimTrack for free'],
    verify: ['VERIFY YOUR NUMBER', ''],
    reset: ['RESET PASSWORD', 'Verify a mobile reset code and choose a new password']
  }
  const passwordRules = [
    ['length', 'At least 8 characters'],
    ['uppercase', 'One uppercase letter (A–Z)'],
    ['lowercase', 'One lowercase letter (a–z)'],
    ['number', 'One number (0–9)'],
    ['special', 'One special character (!@#$%)'],
    ['spaces', 'No spaces'],
    ['maxLength', 'No more than 72 bytes']
  ]

  function field(label, id, placeholder, type = 'text', autocomplete = 'off') {
    return `<div class="field"><label for="${id}" class="label">${label}</label><input id="${id}" name="${id}" class="input" type="${type}" placeholder="${placeholder || ''}" autocomplete="${autocomplete}" required></div>`
  }

  function passwordField(id, autocomplete, label = 'Password') {
    return `<div class="field"><label for="${id}" class="label">${label}</label><div class="password-row"><input id="${id}" name="${id}" class="input" type="password" autocomplete="${autocomplete}" required><button type="button" class="password-toggle" data-toggle-password="${id}">Show</button></div></div>`
  }

  function authLayout(content) {
    landingApp.hidden = true
    authApp.hidden = false
    pageCard.innerHTML = `<p id="auth-feedback" class="auth-feedback" role="alert" hidden></p>${content}`
    bindEvents()
    window.scrollTo(0, 0)
  }

  function renderSignin() {
    pageTitle.textContent = pages.signin[0]
    pageSubtitle.textContent = pages.signin[1]
    authLayout(`<form data-auth-form="login">${field('Mobile Number', 'signin-mobile', '09XX XXX XXXX', 'tel', 'tel')}${passwordField('signin-password', 'current-password')}<div style="text-align:right;margin-bottom:12px"><a href="#reset" class="link">Forgot password?</a></div><button class="primary-btn" type="submit">SIGN IN</button></form><div class="footer">Don't have an account? <a href="#register" class="link">Register</a></div>`)
  }

  function renderRegister() {
    pageTitle.textContent = pages.register[0]
    pageSubtitle.textContent = pages.register[1]
    const checklist = passwordRules.map(([rule, label]) => `<li data-password-rule="${rule}" style="color:#777;font-size:12px;line-height:1.5"><span aria-hidden="true">×</span> ${label}</li>`).join('')
    authLayout(`<form data-auth-form="register"><div style="display:flex;gap:10px"><div style="flex:1">${field('First Name', 'reg-first', 'First name', 'text', 'given-name')}</div><div style="flex:1">${field('Last Name', 'reg-last', 'Last name', 'text', 'family-name')}</div></div>${field('Mobile Number', 'reg-mobile', '09XX XXX XXXX', 'tel', 'tel')}${field('Email address', 'reg-email', 'youremail@gmail.com', 'email', 'email')}${passwordField('reg-password', 'new-password')}<div aria-live="polite" style="margin:-8px 0 12px"><strong style="font-size:12px">Password requirements</strong><ul id="password-checklist" style="display:grid;grid-template-columns:1fr 1fr;gap:1px 10px;margin:4px 0 6px;padding:0;list-style:none">${checklist}</ul><p id="password-strength" style="margin:0;font-size:12px">Strength: Weak</p><p id="password-match" role="status" style="margin:3px 0 0;font-size:12px"></p><p id="register-status" style="margin:3px 0 0;font-size:12px">Complete all required fields and password requirements.</p></div>${passwordField('reg-confirm-password', 'new-password', 'Confirm Password')}<button class="primary-btn" type="submit" disabled>CREATE ACCOUNT</button></form><div class="footer">Already Registered? <a href="#signin" class="link">Sign In</a></div>`)
  }

  function renderRegistrationVerify(mobileNumber) {
    pageTitle.textContent = pages.verify[0]
    pageSubtitle.textContent = 'Enter the one-time code sent to your phone'
    authLayout(`<form id="registration-otp-form" data-mobile-number="${mobileNumber}"><div style="text-align:center;font-size:14px;color:#333;margin-bottom:16px">A verification SMS was requested for ${mobileNumber}.</div><div class="field"><label class="label" for="registration-otp">Verification code</label><input id="registration-otp" class="input" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div><button class="primary-btn" type="submit">VERIFY PHONE</button></form><button type="button" class="link" id="resend-registration-otp">Resend code</button><button type="button" class="link" id="change-registration-details">Change registration details</button><div class="info-box">The code expires in 10 minutes.</div>`)
    showFeedback('Enter the code from the SMS to finish creating your account.', false)
    pageCard.querySelector('#registration-otp').focus()
  }

  function renderReset() {
    pageTitle.textContent = pages.reset[0]
    pageSubtitle.textContent = pages.reset[1]
    authLayout(`<form id="password-reset-request">${field('Mobile Number', 'reset-mobile', '09XX XXX XXXX', 'tel', 'tel')}<button class="primary-btn" type="submit">SEND RESET CODE</button></form><form id="password-reset-confirm" hidden><div class="field"><label class="label" for="reset-otp">6-digit reset code</label><input id="reset-otp" class="input" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>${passwordField('reset-password', 'new-password', 'New Password')}${passwordField('reset-confirm-password', 'new-password', 'Confirm New Password')}<button class="primary-btn" type="submit">RESET PASSWORD</button></form><div class="info-box">Codes are sent by SMS and expire after 15 minutes.</div><div class="footer">Remember password? <a href="#signin" class="link">Sign In</a></div>`)
  }

  function showFeedback(message, isError = true) {
    const feedback = pageCard.querySelector('#auth-feedback')
    feedback.hidden = !message
    feedback.dataset.kind = isError ? 'error' : 'success'
    feedback.textContent = message
  }

  function passwordState(password) {
    const categories = [
      /[A-Z]/.test(password),
      /[a-z]/.test(password),
      /[0-9]/.test(password),
      /[^\p{L}\p{N}\s]/u.test(password),
    ]
    const rules = {
      length: password.length >= 8,
      uppercase: categories[0],
      lowercase: categories[1],
      number: categories[2],
      special: categories[3],
      spaces: !/\s/.test(password),
      maxLength: new TextEncoder().encode(password).length <= 72,
    }
    const valid = Object.values(rules).every(Boolean)
    const variety = categories.filter(Boolean).length
    const strength = valid
      ? password.length >= 14 ? 'Very strong' : password.length >= 10 ? 'Strong' : 'Fair'
      : password.length >= 8 && variety >= 2 ? 'Fair' : 'Weak'
    return { rules, valid, strength }
  }

  function updateRegistrationState(form) {
    if (!form || form.dataset.authForm !== 'register') return
    const password = form.querySelector('#reg-password').value
    const confirmation = form.querySelector('#reg-confirm-password').value
    const { rules, valid, strength } = passwordState(password)
    const checklist = form.querySelector('#password-checklist')
    passwordRules.forEach(([rule]) => {
      const item = checklist.querySelector(`[data-password-rule="${rule}"]`)
      const passed = rules[rule]
      item.style.color = passed ? '#20563c' : '#777'
      item.querySelector('span').textContent = passed ? '✓' : '×'
    })

    const strengthElement = form.querySelector('#password-strength')
    strengthElement.textContent = `Strength: ${strength}`
    strengthElement.style.color = strength === 'Very strong' || strength === 'Strong'
      ? '#20563c'
      : strength === 'Fair' ? '#8a6d1d' : '#8a2020'

    const matchElement = form.querySelector('#password-match')
    const passwordsMatch = confirmation.length > 0 && password === confirmation
    matchElement.textContent = confirmation.length === 0
      ? 'Confirm your password.'
      : passwordsMatch ? 'Passwords match.' : 'Passwords do not match.'
    matchElement.style.color = passwordsMatch ? '#20563c' : confirmation.length === 0 ? '#777' : '#8a2020'

    const fieldsValid = form.checkValidity()
    const button = form.querySelector('button[type="submit"]')
    button.disabled = !(valid && passwordsMatch && fieldsValid)
    const status = form.querySelector('#register-status')
    if (!valid) {
      status.textContent = 'Meet all password requirements above to continue.'
    } else if (!passwordsMatch) {
      status.textContent = 'Enter the same password in both fields.'
    } else if (!fieldsValid) {
      status.textContent = 'Complete the required registration fields to continue.'
    } else {
      status.textContent = 'All requirements met.'
    }
  }

  async function requestAuth(endpoint, body) {
    let response
    try {
      response = await fetch(new URL(endpoint, apiBaseUrl), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body)
      })
    } catch {
      throw new Error('Unable to contact the authentication server. Check that the PHP server is running.')
    }

    let result
    try {
      result = await response.json()
    } catch {
      throw new Error('The authentication server returned an invalid response.')
    }

    if (!response.ok || !result.success) {
      const validationMessages = result.errors ? Object.values(result.errors).join(' ') : ''
      throw new Error(validationMessages || result.message || 'Authentication failed.')
    }
    return result
  }

  async function submitAuthForm(event) {
    event.preventDefault()
    const form = event.currentTarget
    const button = form.querySelector('button[type="submit"]')
    if (button.disabled || !form.checkValidity()) {
      updateRegistrationState(form)
      return
    }
    const originalText = button.textContent
    const values = Object.fromEntries(new FormData(form))
    button.disabled = true
    button.textContent = 'PLEASE WAIT'
    showFeedback('')

    try {
      if (form.dataset.authForm === 'register') {
        const registration = {
          first_name: values['reg-first'],
          last_name: values['reg-last'],
          mobile_number: values['reg-mobile'],
          email: values['reg-email'],
          password: values['reg-password']
        }
        const result = await requestAuth('register.php', registration)
        pendingRegistration = registration
        renderRegistrationVerify(result.mobile_number)
        return
      }

      await requestAuth('login.php', {
        mobile_number: values['signin-mobile'],
        password: values['signin-password']
      })
      window.location.assign(new URL(dashboardHomePath, projectBaseUrl))
    } catch (error) {
      showFeedback(error.message)
      button.disabled = false
      button.textContent = originalText
      updateRegistrationState(form)
    }
  }

  async function verifyRegistrationCode(event) {
    event.preventDefault()
    const form = event.currentTarget
    if (!form.checkValidity()) {
      form.reportValidity()
      return
    }

    const button = form.querySelector('button[type="submit"]')
    const originalText = button.textContent
    button.disabled = true
    button.textContent = 'PLEASE WAIT'
    showFeedback('')
    try {
      await requestAuth('verify-registration.php', {
        mobile_number: form.dataset.mobileNumber,
        otp: form.querySelector('#registration-otp').value
      })
      pendingRegistration = null
      window.location.assign(new URL('profile-setup.html', projectBaseUrl))
    } catch (error) {
      showFeedback(error.message)
      button.disabled = false
      button.textContent = originalText
    }
  }

  async function resendRegistrationCode(button) {
    if (!pendingRegistration) {
      showFeedback('Return to registration and submit your details to request another code.')
      return
    }

    const originalText = button.textContent
    button.disabled = true
    button.textContent = 'PLEASE WAIT'
    showFeedback('')
    try {
      const result = await requestAuth('register.php', pendingRegistration)
      showFeedback(result.message, false)
    } catch (error) {
      showFeedback(error.message)
    } finally {
      let remainingSeconds = 60
      button.disabled = true
      button.textContent = `RESEND IN ${remainingSeconds}s`
      const cooldown = window.setInterval(() => {
        remainingSeconds -= 1
        if (remainingSeconds <= 0) {
          window.clearInterval(cooldown)
          button.disabled = false
          button.textContent = originalText
        } else {
          button.textContent = `RESEND IN ${remainingSeconds}s`
        }
      }, 1000)
    }
  }

  async function requestPasswordReset(event) {
    event.preventDefault()
    const form = event.currentTarget
    if (!form.checkValidity()) {
      form.reportValidity()
      return
    }
    const button = form.querySelector('button[type="submit"]')
    const originalText = button.textContent
    button.disabled = true
    button.textContent = 'PLEASE WAIT'
    showFeedback('')

    try {
      const result = await requestAuth('password-reset.php', {
        action: 'send',
        mobile_number: form.querySelector('#reset-mobile').value
      })
      const confirmForm = pageCard.querySelector('#password-reset-confirm')
      confirmForm.dataset.mobileNumber = form.querySelector('#reset-mobile').value
      confirmForm.hidden = false
      showFeedback(result.message, false)
      confirmForm.querySelector('#reset-otp').focus()
    } catch (error) {
      showFeedback(error.message)
    } finally {
      button.disabled = false
      button.textContent = originalText
    }
  }

  async function confirmPasswordReset(event) {
    event.preventDefault()
    const form = event.currentTarget
    const password = form.querySelector('#reset-password').value
    const confirmation = form.querySelector('#reset-confirm-password').value
    const { valid } = passwordState(password)
    if (!form.checkValidity() || !valid || password !== confirmation) {
      showFeedback(password !== confirmation ? 'The new passwords do not match.' : 'Enter a valid six-digit code and a password that meets all requirements.')
      return
    }

    const button = form.querySelector('button[type="submit"]')
    const originalText = button.textContent
    button.disabled = true
    button.textContent = 'PLEASE WAIT'
    showFeedback('')
    try {
      const result = await requestAuth('password-reset.php', {
        action: 'reset',
        mobile_number: form.dataset.mobileNumber,
        otp: form.querySelector('#reset-otp').value,
        password
      })
      showFeedback(result.message, false)
      window.setTimeout(() => { window.location.hash = 'signin' }, 1000)
    } catch (error) {
      showFeedback(error.message)
      button.disabled = false
      button.textContent = originalText
    }
  }

  function bindEvents() {
    pageCard.querySelectorAll('[data-toggle-password]').forEach(button => button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.togglePassword)
      input.type = input.type === 'password' ? 'text' : 'password'
      button.textContent = input.type === 'password' ? 'Show' : 'Hide'
    }))
    const authForm = pageCard.querySelector('form[data-auth-form]')
    authForm?.addEventListener('submit', submitAuthForm)
    if (authForm?.dataset.authForm === 'register') {
      authForm.addEventListener('input', () => updateRegistrationState(authForm))
      updateRegistrationState(authForm)
    }
    pageCard.querySelector('#password-reset-request')?.addEventListener('submit', requestPasswordReset)
    pageCard.querySelector('#password-reset-confirm')?.addEventListener('submit', confirmPasswordReset)
    pageCard.querySelector('#registration-otp-form')?.addEventListener('submit', verifyRegistrationCode)
    pageCard.querySelector('#resend-registration-otp')?.addEventListener('click', event => resendRegistrationCode(event.currentTarget))
    pageCard.querySelector('#change-registration-details')?.addEventListener('click', () => {
      pendingRegistration = null
      window.location.hash = 'register'
    })
    pageCard.querySelectorAll('.otp').forEach((input, index, inputs) => input.addEventListener('input', () => {
      if (input.value && index < inputs.length - 1) inputs[index + 1].focus()
    }))
  }

  function render() {
    if (state.route === 'dashboard') {
      window.location.replace(new URL(dashboardHomePath, projectBaseUrl))
      return
    }
    if (!state.route) {
      landingApp.hidden = false
      authApp.hidden = true
      return
    }
    ;({ signin: renderSignin, register: renderRegister, reset: renderReset }[state.route] || renderSignin)()
  }

  window.addEventListener('hashchange', () => {
    state.route = window.location.hash.slice(1)
    render()
  })
  render()
})()
