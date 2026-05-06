// n8n ChatAgent for Unions Widget JS
(function(){
  
  
  
  if (!window.N8nUnionChat) {
    
    return;
  }
  
  const webhookConfigured = typeof N8nUnionChat.webhookConfigured !== 'undefined'
    ? N8nUnionChat.webhookConfigured
    : !!N8nUnionChat.webhook;
  if (!webhookConfigured) {
    
    
    return;
  }

  const rawGreetingMessage = (N8nUnionChat.greeting_message || '').trim();
  const greetingToggle = N8nUnionChat.show_greeting === true || N8nUnionChat.show_greeting === 1 || N8nUnionChat.show_greeting === '1';
  const disclaimerTextSetting = (N8nUnionChat.disclaimer_text || '').trim();
  const disclaimerToggle = N8nUnionChat.show_disclaimer === true || N8nUnionChat.show_disclaimer === 1 || N8nUnionChat.show_disclaimer === '1';
  const launcherIconType = N8nUnionChat.launcher_icon_type || 'default';
  const launcherIconUrl = N8nUnionChat.launcher_icon_url || '';
  const launcherIconSvg = N8nUnionChat.launcher_icon_svg || '';
  const customWelcomeEnabled = N8nUnionChat.custom_welcome_enabled === true || N8nUnionChat.custom_welcome_enabled === 1 || N8nUnionChat.custom_welcome_enabled === '1';
  const customWelcomeText = (N8nUnionChat.custom_welcome_text || '').trim();
  const siteLocal = N8nUnionChat.site_local || '';
  const userIsAuthenticated = !!(N8nUnionChat.union_user && N8nUnionChat.union_user.authenticated);
  const routeSelectorEnabled = N8nUnionChat.route_selector_enabled === true || N8nUnionChat.route_selector_enabled === 1 || N8nUnionChat.route_selector_enabled === '1';
  const routePrompt = (N8nUnionChat.route_prompt || 'What can we help with?').trim();
  const chatRoutes = (Array.isArray(N8nUnionChat.routes) && N8nUnionChat.routes.length)
    ? N8nUnionChat.routes.map(route => ({
        value: String((route && route.value) || '').trim(),
        label: String((route && route.label) || '').trim()
      })).filter(route => route.value && route.label)
    : [];
  const attachmentsAllowed = (function(value) {
    if (typeof value === 'undefined' || value === null) {
      return true;
    }
    if (typeof value === 'boolean') {
      return value;
    }
    if (typeof value === 'number') {
      return value === 1;
    }
    if (typeof value === 'string') {
      return value === '1' || value.toLowerCase() === 'true';
    }
    return false;
  })(N8nUnionChat.attachments_enabled);
  const rawAttachmentMode = (N8nUnionChat.attachments_mode || 'always').toString().trim().toLowerCase();
  const attachmentsMode = ['always', 'agent', 'never'].includes(rawAttachmentMode) ? rawAttachmentMode : 'always';
  const attachmentPromptToken = (N8nUnionChat.attachment_prompt_token || '').trim();
  const attachmentsEnabled = attachmentsAllowed && attachmentsMode !== 'never';
  let attachmentPromptActive = attachmentsMode === 'always';
  const ATTACHMENT_PROMPT_FALLBACK = 'Please upload the requested files below.';
  const defaultAgentId = N8nUnionChat.default_agent_id || null;
  const REACTION_STORAGE_KEY = 'n8nUnionRatedResponses';
  const REACTION_ACK = {
    up: 'Your feedback is important. Thank you!',
    down: 'Your feedback is important to us. Your concern has been flagged and escalated.'
  };
  const HISTORY_KEY = 'n8nUnionChatHistory';
  const SIZE_STORAGE_KEY = 'n8nUnionChatSize';
  const MAX_HISTORY_ENTRIES = 200;
  const MAX_ATTACHMENTS = N8nUnionChat.max_attachments || 3;
  const MAX_ATTACHMENT_SIZE = (N8nUnionChat.max_attachment_mb || 5) * 1024 * 1024;
  const ALLOWED_ATTACHMENT_TYPES = (Array.isArray(N8nUnionChat.allowed_attachment_types) && N8nUnionChat.allowed_attachment_types.length)
    ? N8nUnionChat.allowed_attachment_types.map(type => String(type || '').toLowerCase()).filter(Boolean)
    : ['pdf', 'png', 'jpg', 'jpeg'];
  const ratingsEnabled = (function(value) {
    if (typeof value === 'boolean') {
      return value;
    }
    if (typeof value === 'number') {
      return value === 1;
    }
    if (typeof value === 'string') {
      const normalized = value.trim().toLowerCase();
      if (normalized === 'true' || normalized === '1') {
        return true;
      }
      if (normalized === 'false' || normalized === '0') {
        return false;
      }
    }
    if (typeof value === 'undefined' || value === null) {
      return true;
    }
    return Boolean(value);
  })(N8nUnionChat.ratings_enabled);

  function normalizeTextSetting(value, fallback, allowEmpty = false) {
    if (typeof value === 'undefined' || value === null) {
      return fallback;
    }
    const str = String(value).trim();
    if (str === '') {
      return allowEmpty ? '' : fallback;
    }
    return str;
  }

  const ratingLabelText = normalizeTextSetting(N8nUnionChat.rating_label, 'Helpful?', true);
  const ratingUpText = normalizeTextSetting(N8nUnionChat.rating_up_text, '👍');
  const ratingDownText = normalizeTextSetting(N8nUnionChat.rating_down_text, '👎');

  let ratedResponses = {};

  function initN8nUnionChatWidget() {
    const greetingMessage = rawGreetingMessage;
    const shouldShowGreeting = greetingToggle && !!greetingMessage;
    const disclaimerText = disclaimerTextSetting;
    const shouldShowDisclaimer = disclaimerToggle && !!disclaimerText;
    const showDisclaimer = shouldShowDisclaimer;
    ratedResponses = loadRatedResponses();

  
  


  // Generate a unique visitor ID or get existing one

  if (!userIsAuthenticated) {
    try {
      localStorage.removeItem('n8nUnionChatHistory');
      localStorage.removeItem('n8nUnionVisitorId');
    } catch (err) {}
  }

  const visitorId = N8nUnionChat.visitor_id || ('visitor_' + Math.random().toString(36).substr(2, 9));
  const visitorToken = N8nUnionChat.visitor_token || '';
  
  // Session start time for tracking
  const sessionStartTime = Math.floor(Date.now() / 1000);

  let cachedHistory = [];
  let historyRestored = false;

  function loadChatHistory() {
    if (!window.localStorage) return [];
    try {
      const raw = localStorage.getItem(HISTORY_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
      return [];
    }
  }

  function persistChatHistory() {
    if (!window.localStorage) return;
    try {
      localStorage.setItem(HISTORY_KEY, JSON.stringify(cachedHistory));
    } catch (err) {
      
    }
  }

  function loadRatedResponses() {
    if (!window.localStorage) return {};
    try {
      const raw = localStorage.getItem(REACTION_STORAGE_KEY);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return typeof parsed === 'object' && parsed ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function persistRatedResponses() {
    if (!window.localStorage) return;
    try {
      localStorage.setItem(REACTION_STORAGE_KEY, JSON.stringify(ratedResponses));
    } catch (error) {
      
    }
  }

  function generateResponseId() {
    return 'resp_' + Date.now().toString(36) + Math.random().toString(36).substring(2, 8);
  }

  function submitResponseRating(responseId, ratingValue) {
    const payload = createChatPayload('', {
      action: 'rateMessage',
      responseId,
      rating: ratingValue
    });
    const formData = new FormData();
    formData.append('action', 'n8n_union_proxy_message');
    formData.append('payload', JSON.stringify(payload));
    formData.append('_ajax_nonce', N8nUnionChat.nonce);
    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      body: formData
    }).catch(() => {});
  }

  function createReactionBar(responseId) {
    if (!ratingsEnabled) return null;
    const bar = document.createElement('div');
    bar.className = 'n8n-union-reaction-bar';
    const upBtn = document.createElement('button');
    upBtn.type = 'button';
    upBtn.className = 'n8n-union-reaction-btn up';
    upBtn.setAttribute('aria-label', 'Mark helpful');
    upBtn.textContent = ratingUpText;
    const downBtn = document.createElement('button');
    downBtn.type = 'button';
    downBtn.className = 'n8n-union-reaction-btn down';
    downBtn.setAttribute('aria-label', 'Mark unhelpful');
    downBtn.textContent = ratingDownText;
    if (ratingLabelText !== '') {
      const label = document.createElement('span');
      label.className = 'n8n-union-reaction-label';
      label.textContent = ratingLabelText;
      bar.appendChild(label);
    } else {
      upBtn.setAttribute('aria-label', 'Mark helpful');
      downBtn.setAttribute('aria-label', 'Mark unhelpful');
      bar.classList.add('n8n-union-reaction-no-label');
    }
    bar.appendChild(upBtn);
    bar.appendChild(downBtn);
    const ack = document.createElement('div');
    ack.className = 'n8n-union-reaction-ack';
    ack.setAttribute('role', 'status');
    ack.setAttribute('aria-live', 'polite');
    ack.hidden = true;
    bar.appendChild(ack);

    const existingRating = ratedResponses[responseId];
    if (existingRating) {
      disableReactionButtons(upBtn, downBtn, existingRating, ack);
    } else {
      upBtn.addEventListener('click', () => handleReactionClick(responseId, 'up', upBtn, downBtn, ack));
      downBtn.addEventListener('click', () => handleReactionClick(responseId, 'down', upBtn, downBtn, ack));
    }
    return bar;
  }

  function showReactionAcknowledgement(ackNode, ratingValue) {
    if (!ackNode) return;
    const message = REACTION_ACK[ratingValue] || REACTION_ACK.up;
    ackNode.textContent = message;
    ackNode.hidden = false;
    ackNode.classList.add('visible');
  }

  function disableReactionButtons(upBtn, downBtn, ratingValue, ackNode) {
    upBtn.disabled = true;
    downBtn.disabled = true;
    upBtn.classList.add('n8n-union-reaction-disabled');
    downBtn.classList.add('n8n-union-reaction-disabled');
    if (ratingValue === 'up') {
      upBtn.classList.add('active');
    } else if (ratingValue === 'down') {
      downBtn.classList.add('active');
    }
    if (ratingValue) {
      showReactionAcknowledgement(ackNode, ratingValue);
    }
  }

  function handleReactionClick(responseId, ratingValue, upBtn, downBtn, ackNode) {
    if (!ratingsEnabled) return;
    if (!responseId || ratedResponses[responseId]) return;
    ratedResponses[responseId] = ratingValue;
    persistRatedResponses();
    disableReactionButtons(upBtn, downBtn, ratingValue, ackNode);
    submitResponseRating(responseId, ratingValue);
  }

  function attachReactionBar(messageElement, responseId) {
    if (!ratingsEnabled || !messageElement || !responseId) return;
    if (messageElement.querySelector('.n8n-union-reaction-bar')) {
      return;
    }
    const bar = createReactionBar(responseId);
    if (!bar) return;
    messageElement.appendChild(bar);
  }

  function attachRouteSwitchControl(messageElement) {
    if (!routeSelectorEnabled || chatRoutes.length < 2 || !messageElement) return;
    if (messageElement.querySelector('.n8n-union-inline-route-switch')) {
      return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'n8n-union-inline-route-switch';

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'n8n-union-inline-route-toggle';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.textContent = 'Switch topic';
    wrap.appendChild(toggle);

    const menu = document.createElement('div');
    menu.className = 'n8n-union-inline-route-options';
    menu.hidden = true;
    chatRoutes.forEach(route => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'n8n-union-inline-route-option';
      button.dataset.routeValue = route.value;
      button.dataset.routeLabel = route.label;
      button.textContent = route.label;
      menu.appendChild(button);
    });
    wrap.appendChild(menu);
    messageElement.appendChild(wrap);
    updateRouteSwitcher();
  }

  function resolveResponseIdFromPayload(payload) {
    if (payload && typeof payload.responseId === 'string') {
      const trimmed = payload.responseId.trim();
      if (trimmed) {
        return trimmed;
      }
    }
    return generateResponseId();
  }

  function addHistoryEntry(entry) {
    cachedHistory.push(entry);
    if (cachedHistory.length > MAX_HISTORY_ENTRIES) {
      cachedHistory = cachedHistory.slice(-MAX_HISTORY_ENTRIES);
    }
    persistChatHistory();
  }

  function clearChatHistory() {
    cachedHistory = [];
    historyRestored = false;
    if (!window.localStorage) return;
    try {
      localStorage.removeItem(HISTORY_KEY);
    } catch (err) {}
  }

  const unionUserDefaults = (function() {
    const defaults = {
      authenticated: false,
      role: 'Guest',
      name: '',
      email: '',
      token: null,
      username: 'guest',
      local: siteLocal || ''
    };
    if (N8nUnionChat.union_user && typeof N8nUnionChat.union_user === 'object') {
      return Object.assign(defaults, N8nUnionChat.union_user);
    }
    return defaults;
  })();

  let attachmentsQueue = [];
  let currentRoute = chatRoutes[0] || { value: 'website', label: 'Website Help' };

  function escapeHtml(str) {
    if (!str) return '';
    str = String(str);
    return str.replace(/[&<>"']/g, function(match) {
      switch (match) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case "'": return '&#39;';
        default: return match;
      }
    });
  }

  function cloneUnionUserMeta() {
    try {
      return JSON.parse(JSON.stringify(unionUserDefaults));
    } catch (error) {
      return Object.assign({}, unionUserDefaults);
    }
  }

  function buildVisitorMetadata() {
    const timezone = typeof Intl !== 'undefined' && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '';
    const screenSize = window.screen ? `${window.screen.width}x${window.screen.height}` : '';
    return {
      currentPage: window.location.href,
      pageTitle: document.title,
      referrer: document.referrer || '',
      userAgent: navigator.userAgent,
      language: navigator.language || navigator.userLanguage || '',
      timezone: timezone || '',
      screen: screenSize,
      platform: navigator.platform || '',
      sessionStart: sessionStartTime
    };
  }

  function createChatPayload(chatInput, extra = {}) {
    const payload = {
      action: extra.action || 'sendMessage',
      sessionId: extra.sessionId || visitorId,
      chatInput,
      visitorId,
      origin: 'wordpress',
      humanMode: isHumanMode,
      isHumanMode: isHumanMode,
      agentId: currentAgent ? currentAgent.id : defaultAgentId,
      route: currentRoute.value,
      routeLabel: currentRoute.label,
      metadata: {
        unionUser: cloneUnionUserMeta(),
        visitorMeta: buildVisitorMetadata(),
        selectedRoute: {
          value: currentRoute.value,
          label: currentRoute.label
        }
      }
    };

    if (Array.isArray(extra.attachmentsOverride)) {
      if (extra.attachmentsOverride.length) {
        payload.attachments = extra.attachmentsOverride;
      }
    } else if (attachmentsEnabled && attachmentsQueue.length) {
      payload.attachments = attachmentsQueue.map(att => ({
        name: att.name,
        type: att.type,
        size: att.size,
        data: att.data
      }));
    }

    if (extra.metadata) {
      if (extra.metadata.unionUser) {
        payload.metadata.unionUser = Object.assign(payload.metadata.unionUser, extra.metadata.unionUser);
      }
      if (extra.metadata.visitorMeta) {
        payload.metadata.visitorMeta = Object.assign(payload.metadata.visitorMeta, extra.metadata.visitorMeta);
      }
    }

    const passthrough = Object.assign({}, extra);
    delete passthrough.metadata;
    delete passthrough.action;
    delete passthrough.sessionId;

    return Object.assign(payload, passthrough);
  }

  // Get main chat icon HTML - always use default chat icon
  function getMainChatIconHtml() {
    if (launcherIconType === 'custom' && launcherIconUrl) {
      return `<img src="${launcherIconUrl}" alt="Chat" class="n8n-union-launcher-img">`;
    }
    if (launcherIconType !== 'default' && launcherIconSvg) {
      return launcherIconSvg;
    }
    return `<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path></svg>`;
  }

  if (document.body) {
    if (!shouldShowGreeting) {
      document.body.classList.add('n8n-union-hide-greeting');
    } else {
      document.body.classList.remove('n8n-union-hide-greeting');
    }
    if (!showDisclaimer) {
      document.body.classList.add('n8n-union-hide-disclaimer');
    } else {
      document.body.classList.remove('n8n-union-hide-disclaimer');
    }
  }

  // Chat widget HTML template
  const shouldStartDisabled = !attachmentsAllowed || attachmentsMode === 'agent' || attachmentsMode === 'never';
  const attachmentInputClass = shouldStartDisabled ? 'n8n-union-attachments-disabled' : '';
    const widgetHTML = `
      <div id="n8n-union-chat-icon-container">
        ${shouldShowGreeting ? `<div id="n8n-union-chat-greeting" class="hidden">${greetingMessage}</div>` : ''}
      <div id="n8n-union-chat-icon" class="${launcherIconType === 'custom' ? 'n8n-union-chat-icon-custom' : ''}">
        ${getMainChatIconHtml()}
      </div>
    </div>
    
    <div id="n8n-union-chat-widget">
      <button id="n8n-union-resize-handle" class="n8n-union-resize-handle" type="button" aria-label="Resize chat window"></button>
      <div id="n8n-union-chat-header">
        <div class="header-content">
          <div class="header-title">${N8nUnionChat.header_title}</div>
          <div class="header-subtitle">${N8nUnionChat.header_subtitle}</div>
        </div>
        <div class="header-actions">
          <button id="n8n-union-reset-chat-btn" aria-label="Start a new chat session"></button>
          <button id="n8n-union-close-chat-btn" aria-label="Close chat"></button>
        </div>
      </div>
      <div id="n8n-union-chat-messages"></div>
      <div id="n8n-union-quick-actions" style="display: none;">
        <!-- Quick action buttons will be inserted here -->
      </div>
      <div id="n8n-union-chat-input-container">
        ${shouldShowDisclaimer ? `
        <div id="n8n-union-ai-disclaimer">
          ${getDisclaimerBotIconHtml()}
          <div class="disclaimer-text" id="n8n-union-disclaimer-text"></div>
        </div>` : ''}

        <div id="n8n-union-attachment-preview" class="n8n-union-attachment-preview" style="display:none;"></div>
        
        <div id="n8n-union-chat-input" class="${attachmentInputClass}">
          <button id="n8n-union-attachment-btn" aria-label="Attach files" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.5,6.5 L8.5,14.5 C7.67,15.33 7.67,16.67 8.5,17.5 L9,18 C9.83,18.83 11.17,18.83 12,18 L19.5,10.5 C21.43,8.57 21.43,5.43 19.5,3.5 C17.57,1.57 14.43,1.57 12.5,3.5 L4.5,11.5 C2.57,13.43 2.57,16.57 4.5,18.5 C6.43,20.43 9.57,20.43 11.5,18.5 L18,12"></path></svg>
          </button>
          <textarea
            id="n8n-union-message"
            name="n8n_union_message"
            placeholder="Type a message..."
            rows="1"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="sentences"
            spellcheck="false"
            inputmode="text"
            enterkeyhint="send"
            data-lpignore="true"
            data-1p-ignore="true"
            data-password-manager="false"
            data-form-type="n8n-chat"
            aria-label="Type a message"
          ></textarea>
          <button id="n8n-union-send-btn" aria-label="Send Message">
            <svg viewBox="0 0 24 24" fill="white" style="width: 18px; height: 18px;"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
          </button>
        </div>
      </div>
      <div id="n8n-union-end-chat-container">
        <p>This chat has ended.</p>
        <button id="n8n-union-start-new-chat-btn">Start New Chat</button>
      </div>
    </div>
  `;

  // Inject widget HTML into page
  
  
  
  try {
    document.body.insertAdjacentHTML('beforeend', widgetHTML);
    
  } catch (error) {
    
    return;
  }

  // DOM elements
  
  const widget = document.getElementById('n8n-union-chat-widget');
  const iconContainer = document.getElementById('n8n-union-chat-icon-container');
  const greeting = document.getElementById('n8n-union-chat-greeting');
  const messages = document.getElementById('n8n-union-chat-messages');
  const quickActions = document.getElementById('n8n-union-quick-actions');
  const input = document.getElementById('n8n-union-message');
  const closeBtn = document.getElementById('n8n-union-close-chat-btn');
  const resetBtn = document.getElementById('n8n-union-reset-chat-btn');
  const sendBtn = document.getElementById('n8n-union-send-btn');
  const chatInputContainer = document.getElementById('n8n-union-chat-input-container');
  const chatInputBar = document.getElementById('n8n-union-chat-input');
  const resizeHandle = document.getElementById('n8n-union-resize-handle');
  
  
  
  
  
  
  
  
  
  
  // DEBUGGING: Initial greeting state
  if (greeting) {
    
    
    
    
    
    
    
    
    
    
    
  }
  
  if (!widget || !iconContainer) {
    
    return;
  }

  function renderHistoryEntry(entry) {
    if (!entry || typeof entry !== 'object') return;
    if (entry.type === 'message') {
      appendMessage(entry.sender, entry.html || '', entry.messageType || 'n8n-union-bot-message', { fromHistory: true, rawHTML: true });
    } else if (entry.type === 'attachmentSummary' && Array.isArray(entry.files) && entry.files.length) {
      appendAttachmentSummary(entry.files, { fromHistory: true });
    }
  }

  function hydrateHistoryIfNeeded() {
    if (historyRestored) return;
    cachedHistory = loadChatHistory();
    cachedHistory.forEach(renderHistoryEntry);
    historyRestored = true;
  }

  function canResizeChatWindow() {
    return window.matchMedia && window.matchMedia('(min-width: 769px)').matches;
  }

  function clampChatSize(width, height) {
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 1024;
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 768;
    const maxWidth = Math.max(320, viewportWidth - 40);
    const maxHeight = Math.max(420, viewportHeight - 130);
    return {
      width: Math.min(Math.max(Math.round(width), 320), maxWidth),
      height: Math.min(Math.max(Math.round(height), 420), maxHeight)
    };
  }

  function applyStoredChatSize() {
    if (!widget || !canResizeChatWindow() || !window.localStorage) {
      return;
    }
    try {
      const stored = JSON.parse(localStorage.getItem(SIZE_STORAGE_KEY) || '{}');
      if (!stored || !stored.width || !stored.height) {
        return;
      }
      const size = clampChatSize(stored.width, stored.height);
      widget.style.width = size.width + 'px';
      widget.style.height = size.height + 'px';
    } catch (err) {}
  }

  function persistChatSize(width, height) {
    if (!window.localStorage) {
      return;
    }
    try {
      localStorage.setItem(SIZE_STORAGE_KEY, JSON.stringify({ width, height }));
    } catch (err) {}
  }

  function initializeChatResizing() {
    if (!resizeHandle || !widget) {
      return;
    }

    applyStoredChatSize();

    let resizeState = null;

    resizeHandle.addEventListener('pointerdown', function(event) {
      if (!canResizeChatWindow()) {
        return;
      }
      event.preventDefault();
      const rect = widget.getBoundingClientRect();
      resizeState = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        startWidth: rect.width,
        startHeight: rect.height
      };
      resizeHandle.setPointerCapture(event.pointerId);
      document.body.classList.add('n8n-union-chat-resizing');
    });

    resizeHandle.addEventListener('pointermove', function(event) {
      if (!resizeState || event.pointerId !== resizeState.pointerId) {
        return;
      }
      const width = resizeState.startWidth + (resizeState.startX - event.clientX);
      const height = resizeState.startHeight + (resizeState.startY - event.clientY);
      const size = clampChatSize(width, height);
      widget.style.width = size.width + 'px';
      widget.style.height = size.height + 'px';
    });

    function finishResize(event) {
      if (!resizeState || event.pointerId !== resizeState.pointerId) {
        return;
      }
      const rect = widget.getBoundingClientRect();
      const size = clampChatSize(rect.width, rect.height);
      persistChatSize(size.width, size.height);
      resizeState = null;
      document.body.classList.remove('n8n-union-chat-resizing');
    }

    resizeHandle.addEventListener('pointerup', finishResize);
    resizeHandle.addEventListener('pointercancel', finishResize);

    window.addEventListener('resize', function() {
      if (!canResizeChatWindow()) {
        widget.style.width = '';
        widget.style.height = '';
        return;
      }
      const rect = widget.getBoundingClientRect();
      if (rect.width && rect.height) {
        const size = clampChatSize(rect.width, rect.height);
        widget.style.width = size.width + 'px';
        widget.style.height = size.height + 'px';
      }
    });
  }

  // IMMEDIATE GREETING TEST - Show greeting right now for debugging
  
  if (greeting) {
    greeting.style.display = 'block';
    greeting.style.visibility = 'visible'; 
    greeting.style.opacity = '1';
    greeting.classList.remove('hidden');
    
    
    
    
    
  } else {
    
  }
  const endChatContainer = document.getElementById('n8n-union-end-chat-container');
  const newChatBtn = document.getElementById('n8n-union-start-new-chat-btn');
  const requestHumanBtn = document.getElementById('n8n-union-request-human');
  const disclaimerTextContainer = document.getElementById('n8n-union-disclaimer-text');
  const attachmentPreview = document.getElementById('n8n-union-attachment-preview');
  const attachmentButton = document.getElementById('n8n-union-attachment-btn');
  const hiddenAttachmentInput = attachmentsAllowed ? document.createElement('input') : null;
  if (attachmentsAllowed && hiddenAttachmentInput) {
    hiddenAttachmentInput.type = 'file';
    hiddenAttachmentInput.multiple = true;
    hiddenAttachmentInput.accept = '.pdf,.png,.jpg,.jpeg,image/png,image/jpeg,application/pdf';
    hiddenAttachmentInput.style.display = 'none';
    document.body.appendChild(hiddenAttachmentInput);
  } else {
    if (attachmentButton) {
      attachmentButton.style.display = 'none';
    }
    if (attachmentPreview) {
      attachmentPreview.style.display = 'none';
    }
  }

  function refreshAttachmentButtonVisibility() {
    if (!attachmentButton) {
      return;
    }
    if (!attachmentsAllowed || attachmentsMode === 'never') {
      attachmentButton.style.display = 'none';
      if (chatInputBar) {
        chatInputBar.classList.add('n8n-union-attachments-disabled');
      }
      return;
    }
    if (attachmentsMode === 'always') {
      attachmentButton.style.display = '';
      attachmentButton.disabled = false;
      if (chatInputBar) {
        chatInputBar.classList.remove('n8n-union-attachments-disabled');
      }
      return;
    }
    if (attachmentsMode === 'agent') {
      if (attachmentPromptActive) {
        attachmentButton.style.display = '';
        attachmentButton.disabled = false;
        if (chatInputBar) {
          chatInputBar.classList.remove('n8n-union-attachments-disabled');
        }
      } else {
        attachmentButton.style.display = 'none';
        if (chatInputBar) {
          chatInputBar.classList.add('n8n-union-attachments-disabled');
        }
      }
    }
  }

  function setCurrentRoute(routeValue, routeLabel) {
    if (!routeValue || !routeLabel) {
      return;
    }
    currentRoute = {
      value: routeValue,
      label: routeLabel
    };
    updateRouteSwitcher();
  }

  function updateRouteSwitcher() {
    document.querySelectorAll('.n8n-union-inline-route-option').forEach(button => {
      const isActive = button.dataset.routeValue === currentRoute.value;
      button.disabled = isActive;
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-current', isActive ? 'true' : 'false');
    });
  }

  function getStarterQuestionsEnabled() {
    return routeSelectorEnabled && chatRoutes.length > 0;
  }

  function removeStarterQuestions() {
    const starter = document.getElementById('n8n-union-starter-questions');
    if (starter) {
      starter.remove();
    }
  }

  function renderStarterQuestions() {
    if (!getStarterQuestionsEnabled() || document.getElementById('n8n-union-starter-questions')) {
      return;
    }

    const starter = document.createElement('div');
    starter.id = 'n8n-union-starter-questions';
    starter.className = 'n8n-union-message n8n-union-bot-message n8n-union-starter-questions';
    starter.setAttribute('role', 'group');
    starter.setAttribute('aria-label', 'Starter questions');

    const senderDiv = document.createElement('div');
    senderDiv.className = 'sender';
    senderDiv.innerHTML = botIconSvg;
    const senderSpan = document.createElement('span');
    senderSpan.textContent = N8nUnionChat.ai_name;
    senderDiv.appendChild(senderSpan);
    starter.appendChild(senderDiv);

    const textDiv = document.createElement('div');
    textDiv.className = 'text n8n-union-starter-bubble';

    if (routePrompt) {
      const prompt = document.createElement('div');
      prompt.className = 'n8n-union-starter-prompt';
      prompt.textContent = routePrompt;
      textDiv.appendChild(prompt);
    }

    const options = document.createElement('div');
    options.className = 'n8n-union-starter-options';
    chatRoutes.forEach(route => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'n8n-union-starter-option';
      button.dataset.routeValue = route.value;
      button.dataset.routeLabel = route.label;
      button.textContent = route.label;
      options.appendChild(button);
    });
    textDiv.appendChild(options);
    starter.appendChild(textDiv);
    messages.appendChild(starter);
    scrollToBottom();
  }

  function handleStarterQuestionClick(button) {
    if (!button || sendBtn.classList.contains('loading')) {
      return;
    }
    const routeValue = button.dataset.routeValue || '';
    const routeLabel = button.dataset.routeLabel || button.textContent.trim();
    setCurrentRoute(routeValue, routeLabel);
    removeStarterQuestions();
    sendRouteSelection(routeValue, routeLabel);
  }

  function sendRouteSelection(routeValue, routeLabel, options = {}) {
    if (!routeValue || !routeLabel) {
      return;
    }
    const userLabel = options.switching ? `Switch to ${routeLabel}` : routeLabel;
    appendMessage('You', userLabel, 'n8n-union-user-message');
    storeVisitorMessage(userLabel);
    quickActions.style.display = 'none';

    if (isHumanMode) {
      return;
    }

    sendBtn.classList.add('loading');
    sendBtn.disabled = true;
    input.disabled = true;

    const typingId = showTypingIndicator();
    sendChatPayload(createChatPayload(userLabel, {
      action: 'selectRoute',
      selectedRoute: {
        value: routeValue,
        label: routeLabel
      },
      route: routeValue,
      routeLabel,
      routeSwitch: options.switching === true
    }))
    .then(data => {
      const indicator = document.getElementById(typingId);
      if (!indicator) {
        return;
      }
      const responseId = resolveResponseIdFromPayload(data);
      const textDiv = document.createElement('div');
      textDiv.classList.add('text');
      textDiv.innerHTML = '';
      indicator.parentNode.replaceChild(textDiv, indicator);
      const messageWrapper = textDiv.closest('.n8n-union-message');
      if (messageWrapper) {
        messageWrapper.dataset.responseId = responseId;
      }

      let messageText = data.reply || "Thanks. What would you like to know about " + routeLabel + "?";
      messageText = sanitizeAgentReply(messageText);
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = formatMarkdown(messageText);
      const messageLength = messageText.length;
      const typingSpeed = messageLength > 100 ? 20 : messageLength > 50 ? 25 : 30;

      typeWriterEffect(textDiv, tempDiv, typingSpeed).then(() => {
        if (messageWrapper) {
          attachReactionBar(messageWrapper, responseId);
          attachRouteSwitchControl(messageWrapper);
        }
        addHistoryEntry({
          type: 'message',
          sender: N8nUnionChat.ai_name,
          html: textDiv.innerHTML,
          messageType: 'n8n-union-bot-message'
        });
      });

      if (data.reply) {
        storeBotMessage(messageText, N8nUnionChat.ai_name);
      }
    })
    .catch(err => {
      const indicator = document.getElementById(typingId);
      if (indicator) {
        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.innerHTML = '';
        indicator.parentNode.replaceChild(textDiv, indicator);
        const tempDiv = document.createElement('div');
        tempDiv.textContent = err && err.message ? err.message : "Sorry, an error occurred while processing your message.";
        typeWriterEffect(textDiv, tempDiv, 30).then(() => {
          addHistoryEntry({
            type: 'message',
            sender: null,
            html: textDiv.innerHTML,
            messageType: 'n8n-union-bot-message'
          });
        });
      }
    })
    .finally(() => {
      if (endChatContainer.style.display !== 'block') {
        sendBtn.classList.remove('loading');
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
      }
    });
  }

  function setAttachmentPromptState(state) {
    if (attachmentsMode !== 'agent') {
      return;
    }
    attachmentPromptActive = !!state;
    refreshAttachmentButtonVisibility();
  }

  function sanitizeAgentReply(rawText) {
    let message = typeof rawText === 'string' ? rawText : '';
    if (attachmentsMode === 'agent') {
      if (attachmentsEnabled && attachmentPromptToken) {
        const escaped = attachmentPromptToken.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(escaped, 'ig');
        let matched = false;
        message = message.replace(regex, () => {
          matched = true;
          return '';
        }).trim();
        setAttachmentPromptState(matched);
        if (!message && matched) {
          message = ATTACHMENT_PROMPT_FALLBACK;
        }
      } else {
        setAttachmentPromptState(false);
      }
    }
    return message;
  }

  refreshAttachmentButtonVisibility();

  function sendChatPayload(payload) {
    return fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      body: (() => {
        const formData = new FormData();
        formData.append('action', 'n8n_union_proxy_message');
        formData.append('payload', JSON.stringify(payload));
        formData.append('_ajax_nonce', N8nUnionChat.nonce);
        return formData;
      })()
    })
      .then(res => res.json())
      .then(result => {
        if (!result || !result.success || !result.data) {
          const message = (result && result.data && result.data.message) ? result.data.message : 'Unable to reach the chat service.';
          throw new Error(message);
        }
        return result.data;
      });
  }

  // Initialize disclaimer text
  function initializeDisclaimerText() {
    if (!disclaimerTextContainer) {
      return;
    }
    const text = N8nUnionChat.disclaimer_text || '';
    const parts = text.split('\n');
    disclaimerTextContainer.innerHTML = parts.map((line, index) => {
      if (!line.trim()) {
        return '';
      }
      const className = index === 0 ? 'primary-text' : 'secondary-text';
      return `<div class="${className}">${line.trim()}</div>`;
    }).join('');
  }

  // Initialize the disclaimer text
  initializeDisclaimerText();

  function renderAttachments() {
    if (!attachmentsEnabled || !attachmentPreview) return;
    if (!attachmentsQueue.length) {
      attachmentPreview.style.display = 'none';
      attachmentPreview.innerHTML = '';
      return;
    }
    attachmentPreview.style.display = 'flex';
    attachmentPreview.innerHTML = attachmentsQueue.map((att, index) => `
      <div class="n8n-union-attachment-chip">
        <span>${escapeHtml(att.name)}</span>
        <button type="button" class="n8n-union-remove-attachment" data-index="${index}" aria-label="Remove ${escapeHtml(att.name)}">&times;</button>
      </div>
    `).join('');
  }

  function clearAttachments() {
    attachmentsQueue = [];
    renderAttachments();
  }

  function appendAttachmentSummary(files, options = {}) {
    if ((!attachmentsEnabled && !options.fromHistory) || !files || !files.length) {
      return;
    }
    const lastMessage = messages.lastElementChild;
    if (!lastMessage) {
      return;
    }
    const summary = document.createElement('div');
    summary.className = 'n8n-union-attachment-summary';

    const summaryHeader = document.createElement('div');
    summaryHeader.className = 'n8n-union-attachment-summary-label';
    const iconSpan = document.createElement('span');
    iconSpan.setAttribute('aria-hidden', 'true');
    iconSpan.textContent = '📎';
    const labelStrong = document.createElement('strong');
    labelStrong.textContent = 'Attachments';
    summaryHeader.appendChild(iconSpan);
    summaryHeader.appendChild(labelStrong);
    summary.appendChild(summaryHeader);

    const list = document.createElement('div');
    list.className = 'n8n-union-attachment-summary-list';

    files.forEach(file => {
      const chip = document.createElement('div');
      chip.className = 'n8n-union-attachment-chip history';

      const nameSpan = document.createElement('span');
      nameSpan.className = 'n8n-union-attachment-name';
      nameSpan.textContent = file.name || 'Attachment';

      chip.appendChild(nameSpan);

      if (file.size) {
        const sizeSpan = document.createElement('span');
        sizeSpan.className = 'n8n-union-attachment-size';
        sizeSpan.textContent = humanFileSize(file.size);
        chip.appendChild(sizeSpan);
      }

      list.appendChild(chip);
    });

    summary.appendChild(list);
    lastMessage.appendChild(summary);
    scrollToBottom();

    if (!options.fromHistory) {
      const storedFiles = files.map(file => ({
        name: file.name,
        size: file.size
      }));
      addHistoryEntry({
        type: 'attachmentSummary',
        files: storedFiles
      });
    }
  }

  function handleAttachmentSelection(event) {
    if (!attachmentsEnabled) return;
    const files = Array.from(event.target.files || []);
    if (!files.length) return;
    const availableSlots = MAX_ATTACHMENTS - attachmentsQueue.length;
    if (availableSlots <= 0) {
      showAttachmentNotice(`You can attach up to ${MAX_ATTACHMENTS} file(s).`);
      event.target.value = '';
      return;
    }
    files.slice(0, availableSlots).forEach(file => processAttachment(file));
    event.target.value = '';
  }

  function processAttachment(file) {
    if (!attachmentsEnabled) return;
    if (file.size > MAX_ATTACHMENT_SIZE) {
      showAttachmentNotice(`${file.name} is too large. Maximum size is ${(MAX_ATTACHMENT_SIZE / (1024 * 1024)).toFixed(0)}MB.`);
      return;
    }
    const extension = (file.name.split('.').pop() || '').toLowerCase();
    if (ALLOWED_ATTACHMENT_TYPES.length && !ALLOWED_ATTACHMENT_TYPES.includes(extension)) {
      const readableTypes = ALLOWED_ATTACHMENT_TYPES.map(type => type.toUpperCase()).join(', ');
      showAttachmentNotice(`${file.name} must be one of: ${readableTypes}.`);
      return;
    }
    const reader = new FileReader();
    reader.onload = function(loadEvent) {
      const result = (loadEvent.target && loadEvent.target.result) || '';
      const parts = result.split(',');
      if (!parts[1]) {
        showAttachmentNotice(`Could not read ${file.name}.`);
        return;
      }
      attachmentsQueue.push({
        name: file.name,
        type: file.type || 'application/octet-stream',
        size: file.size,
        data: parts[1]
      });
      renderAttachments();
      if (attachmentsQueue.length >= MAX_ATTACHMENTS) {
        showAttachmentNotice(`Attachment limit reached (${MAX_ATTACHMENTS}).`);
      }
    };
    reader.onerror = function() {
      showAttachmentNotice(`Could not read ${file.name}.`);
    };
    reader.readAsDataURL(file);
  }
  // Initialize quick action buttons
  function initializeQuickActions() {
    if (!N8nUnionChat.quick_actions || !N8nUnionChat.quick_actions.trim()) {
      return;
    }

    // Handle multiple formats: actual newlines, literal \n strings, or comma-separated
    let actionsText = N8nUnionChat.quick_actions;
    
    
    
    // Replace literal \n with actual newlines if they exist
    if (actionsText.includes('\\n')) {
      actionsText = actionsText.replace(/\\n/g, '\n');
      
    }
    
    // Split by newlines first, then by commas as fallback
    let actions = actionsText.split('\n').map(action => action.trim()).filter(action => action);
    
    // If we only got one action and it contains commas, try splitting by commas
    if (actions.length === 1 && actions[0].includes(',')) {
      actions = actions[0].split(',').map(action => action.trim()).filter(action => action);
      
    }
    
    
    
    if (actions.length === 0) {
      
      return;
    }

    quickActions.innerHTML = '';
    actions.forEach(action => {
      const trimmedAction = action.trim();
      if (trimmedAction) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'quick-action-btn';
        button.dataset.action = trimmedAction;
        button.textContent = trimmedAction;
        quickActions.appendChild(button);
      }
    });

    // Add click event listeners to quick action buttons
    quickActions.addEventListener('click', function(e) {
      if (e.target.classList.contains('quick-action-btn')) {
        const actionText = e.target.getAttribute('data-action');
        sendQuickAction(actionText);
      }
    });
  }

  // Function to send quick action
  function sendQuickAction(actionText) {
    removeStarterQuestions();
    // Add the message to the chat as if the user typed it
    appendMessage('You', actionText, 'n8n-union-user-message');
    
    // Store the message
    storeVisitorMessage(actionText);
    
    // Hide quick actions after first use
    quickActions.style.display = 'none';
    
    // Send to AI/webhook
    if (isHumanMode) {
      // In human mode, just store the message for the agent to see
      return;
    }
    
    // Show typing indicator and send to AI
    const typingId = showTypingIndicator();
    
    sendChatPayload(createChatPayload(actionText))
    .then(data => {
      const indicator = document.getElementById(typingId);
      if (indicator) {
        const responseId = resolveResponseIdFromPayload(data);
        // Replace typing indicator with empty text div for typewriter effect
        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.innerHTML = '';
        indicator.parentNode.replaceChild(textDiv, indicator);
        const messageWrapper = textDiv.closest('.n8n-union-message');
        if (messageWrapper) {
          messageWrapper.dataset.responseId = responseId;
        }
        
        // Start typewriter effect with adaptive speed
        const messageText = data.reply || "I received your message about: " + actionText;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = formatMarkdown(messageText);
        
        // Adaptive typing speed
        const messageLength = messageText.length;
        const typingSpeed = messageLength > 100 ? 20 : messageLength > 50 ? 25 : 30;
        
        typeWriterEffect(textDiv, tempDiv, typingSpeed).then(() => {
          if (messageWrapper) {
            attachReactionBar(messageWrapper, responseId);
            attachRouteSwitchControl(messageWrapper);
          }
          addHistoryEntry({
            type: 'message',
            sender: N8nUnionChat.ai_name,
            html: textDiv.innerHTML,
            messageType: 'n8n-union-bot-message'
          });
        });
        
      // Store bot response
      if (data.reply) {
        storeBotMessage(messageText, N8nUnionChat.ai_name);
      }
      if (attachmentsQueue.length) {
        clearAttachments();
      }
      }
    })
    .catch(error => {
      const indicator = document.getElementById(typingId);
      if (indicator) {
        // Replace typing indicator with empty text div for typewriter effect
        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.innerHTML = '';
        indicator.parentNode.replaceChild(textDiv, indicator);
        
        // Start typewriter effect for error message
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = 'Sorry, I encountered an error. Please try again.';
        typeWriterEffect(textDiv, tempDiv, 30).then(() => {
          addHistoryEntry({
            type: 'message',
            sender: null,
            html: textDiv.innerHTML,
            messageType: 'n8n-union-bot-message'
          });
        });
      }
      
    });
  }

  // Initialize quick actions
  initializeQuickActions();
  initializeChatResizing();

  messages.addEventListener('click', function(e) {
    const starterButton = e.target.closest('.n8n-union-starter-option');
    if (starterButton) {
      handleStarterQuestionClick(starterButton);
      return;
    }

    const routeToggle = e.target.closest('.n8n-union-inline-route-toggle');
    if (routeToggle) {
      const wrap = routeToggle.closest('.n8n-union-inline-route-switch');
      const menu = wrap ? wrap.querySelector('.n8n-union-inline-route-options') : null;
      if (menu) {
        const isOpen = menu.hidden;
        menu.hidden = !isOpen;
        routeToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
      return;
    }

    const routeButton = e.target.closest('.n8n-union-inline-route-option');
    if (routeButton) {
      if (sendBtn.classList.contains('loading') || routeButton.disabled) {
        return;
      }
      const routeValue = routeButton.dataset.routeValue || '';
      const routeLabel = routeButton.dataset.routeLabel || routeButton.textContent.trim();
      removeStarterQuestions();
      setCurrentRoute(routeValue, routeLabel);
      sendRouteSelection(routeValue, routeLabel, { switching: true });
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.n8n-union-inline-route-options').forEach(menu => {
        menu.hidden = true;
      });
      document.querySelectorAll('.n8n-union-inline-route-toggle').forEach(button => {
        button.setAttribute('aria-expanded', 'false');
      });
    }
  });

  if (attachmentsEnabled && attachmentButton && hiddenAttachmentInput) {
    attachmentButton.addEventListener('click', function() {
      hiddenAttachmentInput.click();
    });
  }

  if (attachmentsEnabled && hiddenAttachmentInput) {
    hiddenAttachmentInput.addEventListener('change', handleAttachmentSelection);
  }

  if (attachmentsEnabled && attachmentPreview) {
    attachmentPreview.addEventListener('click', function(e) {
      if (e.target.classList.contains('n8n-union-remove-attachment')) {
        const index = parseInt(e.target.getAttribute('data-index'), 10);
        if (!isNaN(index)) {
          attachmentsQueue.splice(index, 1);
          renderAttachments();
        }
      }
    });
  }

  // Dynamic bot icon based on settings
  function getBotIconHtml() {
    if (N8nUnionChat.bot_icon_type === 'custom' && N8nUnionChat.bot_icon_url) {
      return `<img class="bot-icon" src="${N8nUnionChat.bot_icon_url}" alt="Bot" />`;
    } else if (N8nUnionChat.bot_icon_type !== 'default' && N8nUnionChat.bot_icon_svg) {
      return `<div class="bot-icon bot-icon-svg">${N8nUnionChat.bot_icon_svg}</div>`;
    } else {
      // Default bot icon
      return `<svg class="bot-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM7.07 18.28c.43-.9 3.05-1.78 4.93-1.78s4.5.88 4.93 1.78C15.57 19.36 13.86 20 12 20s-3.57-.64-4.93-1.72zm11.26-2.85c-.32-.68-1.9-1.43-3.33-1.43s-3.01.75-3.33 1.43C11.37 15.86 11 16.62 11 17.5c0 .94.41 1.58 1 2 .65.48 1.48.75 2.5.75s1.85-.27 2.5-.75c.59-.42 1-1.06 1-2 .01-.88-.37-1.64-.67-2.07zM9 13.5c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6 0c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"></path></svg>`;
    }
  }

  // Get disclaimer bot icon (larger version for the disclaimer area)
  function getDisclaimerBotIconHtml() {
    if (N8nUnionChat.bot_icon_type === 'custom' && N8nUnionChat.bot_icon_url) {
      return `<img src="${N8nUnionChat.bot_icon_url}" alt="Bot" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;" />`;
    } else if (N8nUnionChat.bot_icon_type !== 'default' && N8nUnionChat.bot_icon_svg) {
      return N8nUnionChat.bot_icon_svg.replace('<svg', '<svg style="width: 32px; height: 32px;"');
    } else {
      // Default bot icon for disclaimer
      return `<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM7.07 18.28c.43-.9 3.05-1.78 4.93-1.78s4.5.88 4.93 1.78C15.57 19.36 13.86 20 12 20s-3.57-.64-4.93-1.72zm11.26-2.85c-.32-.68-1.9-1.43-3.33-1.43s-3.01.75-3.33 1.43C11.37 15.86 11 16.62 11 17.5c0 .94.41 1.58 1 2 .65.48 1.48.75 2.5.75s1.85-.27 2.5-.75c.59-.42 1-1.06 1-2 .01-.88-.37-1.64-.67-2.07zM9 13.5c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6 0c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"></path></svg>`;
    }
  }

  const botIconSvg = getBotIconHtml();

  // Chat state
  let isHumanMode = false;
  let currentAgent = null;
  let chatStartTime = null;
  let messageCheckInterval = null;
  let statusPollInterval = null;

  function scrollToBottom() {
    // Multiple approaches to ensure reliable scrolling
    const scroll = () => {
      messages.scrollTop = messages.scrollHeight;
    };
    
    // Immediate scroll
    scroll();
    
    // Delayed scroll to handle any layout changes
    setTimeout(scroll, 10);
    
    // Additional scroll after a longer delay for complex content
    setTimeout(scroll, 100);
  }

  function toggleChat(event) {
    if (event && typeof event.stopPropagation === 'function') {
      event.stopPropagation();
    }
    const isWidgetHidden = widget.style.display === 'none' || widget.style.display === '';
    const isOpening = isWidgetHidden;
    widget.style.display = isOpening ? 'flex' : 'none';

    if (greeting) {
      greeting.classList.toggle('hidden', isOpening);
    }

    if (document.body) {
      document.body.classList.toggle('n8n-union-chat-open', isOpening);
    }

    if (!isOpening) {
      if (statusPollInterval) {
        clearInterval(statusPollInterval);
        statusPollInterval = null;
      }
      if (messageCheckInterval) {
        clearInterval(messageCheckInterval);
        messageCheckInterval = null;
      }
      if (attachmentsQueue.length) {
        clearAttachments();
      }
      return;
    }

    hydrateHistoryIfNeeded();

    if (messages.children.length === 0) {
      initiateWelcome();
      renderStarterQuestions();
      chatStartTime = new Date();
      
      // Show quick actions when chat opens
      if (quickActions.innerHTML.trim()) {
        quickActions.style.display = 'block';
      }
    }

    if (!statusPollInterval) {
      statusPollInterval = setInterval(checkChatStatus, 5000);
    }
  }

  // Format markdown text for display
  function formatMarkdown(text) {
    if (!text) return text;
    text = escapeHtml(text);
    
    // Convert **bold** to <strong>
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    
    // Convert *italic* to <em>
    text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
    
    // Convert URLs to clickable links
    text = text.replace(/(https?:\/\/[^\s\)]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    
    // Convert line breaks to <br>
    text = text.replace(/\n/g, '<br>');
    
    return text;
  }

  function appendMessage(sender, text, messageType, options = {}) {
    const messageDiv = document.createElement('div');
    messageDiv.classList.add('n8n-union-message', messageType);
    const allowFeedback = messageType === 'n8n-union-bot-message' && options.allowFeedback !== false;
    let responseId = null;
    if (allowFeedback) {
      responseId = (options && options.responseId) || generateResponseId();
      messageDiv.dataset.responseId = responseId;
    }

    if (sender) {
      const senderDiv = document.createElement('div');
      senderDiv.classList.add('sender');
      if (messageType === 'n8n-union-bot-message') {
        senderDiv.innerHTML = botIconSvg;
        const senderSpan = document.createElement('span');
        senderSpan.textContent = sender;
        senderDiv.appendChild(senderSpan);
      } else {
        senderDiv.innerText = sender;
      }
      messageDiv.appendChild(senderDiv);
    }

    const textDiv = document.createElement('div');
    textDiv.classList.add('text');
    
    // Format markdown for bot messages, keep user messages as plain text
    if (options.rawHTML) {
      textDiv.innerHTML = text || '';
    } else if (messageType === 'n8n-union-bot-message') {
      textDiv.innerHTML = formatMarkdown(text);
    } else {
      textDiv.textContent = text || '';
    }
    
    messageDiv.appendChild(textDiv);
    if (responseId) {
      attachReactionBar(messageDiv, responseId);
      if (!options.fromHistory) {
        attachRouteSwitchControl(messageDiv);
      }
    }
    messages.appendChild(messageDiv);
    scrollToBottom();

    if (!options.fromHistory) {
      addHistoryEntry({
        type: 'message',
        sender: sender || null,
        html: textDiv.innerHTML,
        messageType
      });
    }
  }

  function showAttachmentNotice(text) {
    if (!text) return;
    appendMessage(null, text, 'n8n-union-bot-message', { allowFeedback: false });
  }

  // New function to create typewriter effect for AI responses
  function appendMessageWithTypewriter(sender, text, messageType, speed = 30) {
    const messageDiv = document.createElement('div');
    messageDiv.classList.add('n8n-union-message', messageType);

    if (sender) {
      const senderDiv = document.createElement('div');
      senderDiv.classList.add('sender');
      if (messageType === 'n8n-union-bot-message') {
        senderDiv.innerHTML = botIconSvg;
        const senderSpan = document.createElement('span');
        senderSpan.textContent = sender;
        senderDiv.appendChild(senderSpan);
      } else {
        senderDiv.innerText = sender;
      }
      messageDiv.appendChild(senderDiv);
    }

    const textDiv = document.createElement('div');
    textDiv.classList.add('text');
    textDiv.innerHTML = ''; // Start with empty content
    
    messageDiv.appendChild(textDiv);
    messages.appendChild(messageDiv);
    scrollToBottom();

    // For bot messages, apply markdown formatting first, then extract plain text for typing
    let formattedText;
    if (messageType === 'n8n-union-bot-message') {
      formattedText = formatMarkdown(text);
    } else {
      formattedText = text;
    }

    // Create a temporary element to parse HTML and extract text for typing effect
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = formattedText;
    
    // Start the typewriter effect
    return typeWriterEffect(textDiv, tempDiv, speed);
  }

  // Typewriter effect function that handles HTML content
  function typeWriterEffect(targetElement, sourceElement, speed = 30) {
    return new Promise((resolve) => {
      // Add dot indicator class during typing
      targetElement.classList.add('n8n-union-typewriter-dot');
      
      let currentText = '';
      const fullText = sourceElement.innerHTML;
      const textNodes = [];
      
      // Extract all text content while preserving HTML structure
      function extractTextNodes(element) {
        const walker = document.createTreeWalker(
          element,
          NodeFilter.SHOW_TEXT,
          null,
          false
        );
        
        const nodes = [];
        let node;
        while (node = walker.nextNode()) {
          if (node.textContent.trim()) {
            nodes.push(node.textContent);
          }
        }
        return nodes.join(' ');
      }

      const plainText = extractTextNodes(sourceElement);
      let currentIndex = 0;
      let scrollCounter = 0;
      
      function typeNextCharacter() {
        if (currentIndex < plainText.length) {
          currentText += plainText[currentIndex];
          
          // Create a version with only the characters we've typed so far
          const truncatedElement = truncateHTMLContent(sourceElement.cloneNode(true), currentIndex + 1);
          targetElement.innerHTML = truncatedElement.innerHTML;
          
          currentIndex++;
          
          // Only scroll every few characters to reduce jankiness
          scrollCounter++;
          if (scrollCounter % 3 === 0) {
            scrollToBottom();
          }
          
          // Add natural variation to typing speed
          const currentChar = plainText[currentIndex - 1];
          const nextDelay = getTypingDelay(currentChar, speed);
          
          setTimeout(typeNextCharacter, nextDelay);
        } else {
          // Remove dot indicator and ensure final content is complete
          targetElement.classList.remove('n8n-union-typewriter-dot');
          targetElement.innerHTML = fullText;
          scrollToBottom();
          resolve();
        }
      }
      
      typeNextCharacter();
    });
  }

  // Get natural typing delay with variation
  function getTypingDelay(character, baseSpeed) {
    // Add randomness to make it feel more natural
    const randomVariation = Math.random() * 15 - 7.5; // ±7.5ms variation
    
    // Longer pauses after punctuation
    if (character === '.' || character === '!' || character === '?') {
      return baseSpeed + 100 + randomVariation;
    }
    if (character === ',' || character === ';' || character === ':') {
      return baseSpeed + 50 + randomVariation;
    }
    if (character === ' ') {
      return baseSpeed + 10 + randomVariation;
    }
    
    return baseSpeed + randomVariation;
  }

  // Helper function to truncate HTML content while preserving structure
  function truncateHTMLContent(element, maxLength) {
    let charCount = 0;
    
    function processNode(node) {
      if (charCount >= maxLength) return false;
      
      if (node.nodeType === Node.TEXT_NODE) {
        const remainingChars = maxLength - charCount;
        if (node.textContent.length <= remainingChars) {
          charCount += node.textContent.length;
          return true;
        } else {
          node.textContent = node.textContent.substring(0, remainingChars);
          charCount = maxLength;
          return false;
        }
      } else if (node.nodeType === Node.ELEMENT_NODE) {
        const children = Array.from(node.childNodes);
        for (let i = 0; i < children.length; i++) {
          if (!processNode(children[i])) {
            // Remove remaining children
            for (let j = children.length - 1; j > i; j--) {
              node.removeChild(children[j]);
            }
            return false;
          }
        }
      }
      return true;
    }
    
    processNode(element);
    return element;
  }

  function showTypingIndicator() {
    const typingDiv = document.createElement('div');
    typingDiv.classList.add('n8n-union-message', 'n8n-union-bot-message');
    typingDiv.innerHTML = `
              <div class="sender">${botIconSvg}<span>${isHumanMode ? currentAgent.name : N8nUnionChat.ai_name}</span></div>
      <div class="n8n-union-typing-indicator" id="n8n-union-typing">
        <span></span><span></span><span></span>
      </div>
    `;
    messages.appendChild(typingDiv);
    scrollToBottom();
    return 'n8n-union-typing';
  }

  function initiateWelcome() {
    // Update visitor chat status to AI
    updateVisitorChatStatus('ai');

    if (customWelcomeEnabled && customWelcomeText) {
      appendMessage(null, customWelcomeText, 'n8n-union-bot-message', { allowFeedback: false });
      storeBotMessage(customWelcomeText, N8nUnionChat.ai_name);
      renderStarterQuestions();
      return;
    }

    const typingId = showTypingIndicator();

    sendChatPayload(createChatPayload("__SYSTEM_WELCOME__"))
    .then(data => {
      const indicator = document.getElementById(typingId);
      if (!indicator) return;
      
      // Replace typing indicator with empty text div for typewriter effect
      const textDiv = document.createElement('div');
      textDiv.classList.add('text');
      textDiv.innerHTML = '';
      indicator.parentNode.replaceChild(textDiv, indicator);
      const messageWrapper = textDiv.closest('.n8n-union-message');
      const responseId = resolveResponseIdFromPayload(data);
      if (messageWrapper) {
        messageWrapper.dataset.responseId = responseId;
      }
      
              // Start typewriter effect with varied speed based on message length
        let messageText = data.reply || "Hello! How can I help you today?";
        messageText = sanitizeAgentReply(messageText);
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = formatMarkdown(messageText);
        
        // Adaptive typing speed: longer messages type faster
        const messageLength = messageText.length;
        const typingSpeed = messageLength > 100 ? 20 : messageLength > 50 ? 25 : 30;
        
        typeWriterEffect(textDiv, tempDiv, typingSpeed).then(() => {
        // Show human agent request button if agents are available
        if (requestHumanBtn) {
          requestHumanBtn.style.display = data.humanAgentsAvailable ? 'block' : 'none';
        }
        
        if (data.endOfConversation) {
          endChatSession();
        }

        if (messageWrapper) {
          attachReactionBar(messageWrapper, responseId);
          attachRouteSwitchControl(messageWrapper);
        }
        addHistoryEntry({
          type: 'message',
          sender: N8nUnionChat.ai_name,
          html: textDiv.innerHTML,
          messageType: 'n8n-union-bot-message'
        });
        renderStarterQuestions();
      });
      
      // Store welcome message
      if (data.reply) {
        storeBotMessage(messageText, N8nUnionChat.ai_name);
      }
      if (attachmentsQueue.length) {
        clearAttachments();
      }
    })
    .catch(err => {
      const indicator = document.getElementById(typingId);
      if (indicator) {
        // Replace typing indicator with empty text div for typewriter effect
        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.innerHTML = '';
        indicator.parentNode.replaceChild(textDiv, indicator);
        
        // Start typewriter effect for error message
        const tempDiv = document.createElement('div');
        tempDiv.textContent = err && err.message ? err.message : "Sorry, I couldn't connect to the chat service.";
        typeWriterEffect(textDiv, tempDiv, 30).then(() => {
          addHistoryEntry({
            type: 'message',
            sender: null,
            html: textDiv.innerHTML,
            messageType: 'n8n-union-bot-message'
          });
          renderStarterQuestions();
        });
      }
      
    });
  }

  // Update visitor chat status
  function updateVisitorChatStatus(status) {
    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'n8n_union_visitor_chat_status',
        visitor_id: visitorId,
        visitor_token: visitorToken,
        chat_status: status,
        _ajax_nonce: N8nUnionChat.nonce
      })
    }).catch(err => {
      // Error handling for chat status update
    });
  }

  function sendMessage() {
    const userMsg = input.value.trim();
    if (!userMsg && attachmentsQueue.length === 0) {
      return;
    }
    if (sendBtn.classList.contains('loading')) return;
    removeStarterQuestions();

    const hasQueuedAttachments = attachmentsEnabled && attachmentsQueue.length;
    const attachmentsSnapshot = hasQueuedAttachments
      ? attachmentsQueue.map(att => ({
          name: att.name,
          size: att.size
        }))
      : null;
    const attachmentsPayload = hasQueuedAttachments
      ? attachmentsQueue.map(att => ({
          name: att.name,
          type: att.type,
          size: att.size,
          data: att.data
        }))
      : null;

    if (userMsg) {
      appendMessage(null, userMsg, 'n8n-union-user-message');
      storeVisitorMessage(userMsg);
    } else if (attachmentsQueue.length) {
      appendMessage(
        null,
        `Sent ${attachmentsQueue.length} attachment${attachmentsQueue.length > 1 ? 's' : ''}.`,
        'n8n-union-user-message'
      );
    }

    if (attachmentsSnapshot) {
      appendAttachmentSummary(attachmentsSnapshot);
    }
    if (hasQueuedAttachments) {
      clearAttachments();
    }
    input.value = '';
    
    // Hide quick actions when user starts typing/chatting naturally
    if (quickActions.style.display !== 'none') {
      quickActions.style.display = 'none';
    }
    
    sendBtn.classList.add('loading');
    sendBtn.disabled = true;
    input.disabled = true;

    if (isHumanMode) {
      // In human mode, just store the message and wait for agent response
      sendBtn.classList.remove('loading');
      sendBtn.disabled = false;
      input.disabled = false;
      input.focus();
      return;
    }

  const typingId = showTypingIndicator();

    const payloadMessage = userMsg || (hasQueuedAttachments ? '[Attachment]' : '');

    sendChatPayload(createChatPayload(payloadMessage, {
      attachmentsOverride: attachmentsPayload || undefined
    }))
    .then(data => {
      const indicator = document.getElementById(typingId);
      if (indicator) {
        // Replace typing indicator with empty text div for typewriter effect
        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.innerHTML = '';
        indicator.parentNode.replaceChild(textDiv, indicator);
        const responseId = resolveResponseIdFromPayload(data);
        const messageWrapper = textDiv.closest('.n8n-union-message');
        if (messageWrapper) {
          messageWrapper.dataset.responseId = responseId;
        }
        
        // Start typewriter effect with adaptive speed
        let messageText = data.reply || "Sorry, I didn't get that.";
        messageText = sanitizeAgentReply(messageText);
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = formatMarkdown(messageText);
        
        // Navigation command checking removed for simplification
        
        // Adaptive typing speed: longer messages type faster
        const messageLength = messageText.length;
        const typingSpeed = messageLength > 100 ? 20 : messageLength > 50 ? 25 : 30;
        
        typeWriterEffect(textDiv, tempDiv, typingSpeed).then(() => {
          // Update human agent availability
          if (requestHumanBtn) {
            if (data.humanAgentsAvailable && !isHumanMode) {
              requestHumanBtn.style.display = 'block';
            } else {
              requestHumanBtn.style.display = 'none';
            }
          }

          if (data.endOfConversation) {
            endChatSession();
          }

          if (messageWrapper) {
            attachReactionBar(messageWrapper, responseId);
            attachRouteSwitchControl(messageWrapper);
          }
          addHistoryEntry({
            type: 'message',
            sender: N8nUnionChat.ai_name,
            html: textDiv.innerHTML,
            messageType: 'n8n-union-bot-message'
          });
        });
      }

      // Store bot/agent response
      if (data.reply) {
        storeBotMessage(messageText, N8nUnionChat.ai_name);
      }
      if (attachmentsQueue.length) {
        clearAttachments();
      }
    })
    .catch(err => {
      const indicator = document.getElementById(typingId);
      if (indicator) {
        // Replace typing indicator with empty text div for typewriter effect
        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.innerHTML = '';
        indicator.parentNode.replaceChild(textDiv, indicator);
        
        // Start typewriter effect for error message
        const tempDiv = document.createElement('div');
        tempDiv.textContent = err && err.message ? err.message : "Sorry, an error occurred while processing your message.";
        typeWriterEffect(textDiv, tempDiv, 30).then(() => {
          addHistoryEntry({
            type: 'message',
            sender: null,
            html: textDiv.innerHTML,
            messageType: 'n8n-union-bot-message'
          });
        });
      }
      
    })
    .finally(() => {
      if (endChatContainer.style.display !== 'block') {
        sendBtn.classList.remove('loading');
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
      }
    });
  }

  // Store visitor message in chat history (optimized)
  function storeVisitorMessage(message) {
    
    
    // Use faster fetch without waiting for response to improve perceived speed
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 second timeout
    
    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'n8n_union_store_visitor_message',
        visitor_id: visitorId,
        visitor_token: visitorToken,
        message: message,
        _ajax_nonce: N8nUnionChat.nonce
      }),
      signal: controller.signal
    })
    .then(res => res.json())
    .then(data => {
      clearTimeout(timeoutId);
      
    })
    .catch(err => {
      clearTimeout(timeoutId);
      if (err.name !== 'AbortError') {
        
      }
    });
  }

  // Store bot/agent message in chat history
  function storeBotMessage(message, sender) {
    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'n8n_union_store_bot_message',
        visitor_id: visitorId,
        visitor_token: visitorToken,
        message: message,
        sender: sender,
        _ajax_nonce: N8nUnionChat.nonce
      })
    }).catch(err => {
      // Error handling for store bot message
    });
  }

  function requestHumanAgent() {
    sendBtn.classList.add('loading');
    sendBtn.disabled = true;
    input.disabled = true;
    
    sendChatPayload(createChatPayload("__REQUEST_HUMAN__"))
    .then(data => {
      if (data.humanAgentAssigned) {
        isHumanMode = true;
        currentAgent = data.agent;
        appendMessage(null, "Connecting you to a human agent...", 'n8n-union-bot-message', { allowFeedback: false });
        appendMessage(currentAgent.name, "Hello! I'll be helping you today.", 'n8n-union-bot-message', { allowFeedback: false });
        if (requestHumanBtn) {
          requestHumanBtn.style.display = 'none';
        }
      } else {
        appendMessage(null, "Sorry, no human agents are available right now. Please try again later.", 'n8n-union-bot-message', { allowFeedback: false });
      }
    })
    .catch(err => {
      appendMessage(null, "Sorry, there was an error connecting to a human agent.", 'n8n-union-bot-message', { allowFeedback: false });
      
    })
    .finally(() => {
      sendBtn.classList.remove('loading');
      sendBtn.disabled = false;
      input.disabled = false;
      input.focus();
    });
  }

  function endChatSession() {
    chatInputContainer.style.display = 'none';
    endChatContainer.style.display = 'block';
    isHumanMode = false;
    currentAgent = null;
    chatStartTime = null;
  }

  function resetChat() {
    messages.innerHTML = '';
    endChatContainer.style.display = 'none';
    chatInputContainer.style.display = 'block';
    isHumanMode = false;
    currentAgent = null;
    if (chatRoutes[0]) {
      setCurrentRoute(chatRoutes[0].value, chatRoutes[0].label);
    }
    clearAttachments();
    clearChatHistory();
    
    // Reset chat header
    const chatHeader = document.querySelector('#n8n-union-chat-header .header-title');
    if (chatHeader) {
      chatHeader.textContent = N8nUnionChat.header_title;
    }
    
    // Clear message checking interval
    if (messageCheckInterval) {
      clearInterval(messageCheckInterval);
      messageCheckInterval = null;
    }
    
    input.disabled = false;
    sendBtn.disabled = false;
    sendBtn.classList.remove('loading');
    
    initiateWelcome();
    renderStarterQuestions();
    chatStartTime = new Date();
    input.focus();
  }

  function handleResetRequest() {
    const hasMessages = messages && messages.children && messages.children.length > 0;
    const hasAttachments = attachmentsQueue && attachmentsQueue.length > 0;
    if (hasMessages || hasAttachments) {
      const confirmed = window.confirm('Start a new chat? This clears the current conversation.');
      if (!confirmed) {
        return;
      }
    }
    resetChat();
  }

  // Track visitor activity
  function trackVisitor() {
    const visitorData = {
      action: 'n8n_union_track_visitor',
      visitor_id: visitorId,
      visitor_token: visitorToken,
      current_page: window.location.href,
      page_title: document.title,
      referrer: document.referrer || 'Direct',
      user_agent: navigator.userAgent,
      session_start: sessionStartTime,
      _ajax_nonce: N8nUnionChat.nonce
    };

    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams(visitorData)
    }).catch(err => {
      // Error handling for visitor tracking
    });
  }

  // Track visitor every 30 seconds
  trackVisitor();
  setInterval(trackVisitor, 30000);

  // Track page changes for SPAs
  let currentUrl = window.location.href;
  setInterval(() => {
    if (window.location.href !== currentUrl) {
      currentUrl = window.location.href;
      trackVisitor();
    }
  }, 1000);

  // Mobile zoom prevention
  function preventMobileZoom() {
    const viewport = document.querySelector('meta[name=viewport]');
    if (viewport) {
      const originalContent = viewport.getAttribute('content');
      
      input.addEventListener('focus', function() {
        viewport.setAttribute('content', originalContent + ', user-scalable=no');
      });
      
      input.addEventListener('blur', function() {
        viewport.setAttribute('content', originalContent);
      });
    }
  }

  // Initialize mobile zoom prevention
  preventMobileZoom();

  // Event listeners
  
  try {
    iconContainer.addEventListener('click', toggleChat);
    closeBtn.addEventListener('click', toggleChat);
    newChatBtn.addEventListener('click', resetChat);
    if (resetBtn) {
      resetBtn.addEventListener('click', handleResetRequest);
    }
    if (requestHumanBtn) {
      requestHumanBtn.addEventListener('click', requestHumanAgent);
    }
    
  } catch (error) {
    
  }
  
  try {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
    
    
    // Hide quick actions when user starts typing
    input.addEventListener('input', function() {
      if (input.value.trim().length > 0 && quickActions.style.display !== 'none') {
        quickActions.style.display = 'none';
      }
    });
    
    
    sendBtn.addEventListener('click', sendMessage);
    
  } catch (error) {
    
  }

  
  
  // Check for agent takeover and new messages
  function checkForAgentMessages() {
    if (!isHumanMode) return;
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 1000); // 1 second timeout
    
    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'n8n_union_messages',
        visitor_id: visitorId,
        visitor_token: visitorToken,
        _ajax_nonce: N8nUnionChat.nonce
      }),
      signal: controller.signal
    })
    .then(res => res.json())
    .then(data => {
      clearTimeout(timeoutId);
      if (data.success && data.data) {
        displayNewAgentMessages(data.data);
      }
    })
    .catch(err => {
      clearTimeout(timeoutId);
      if (err.name !== 'AbortError') {
        
      }
    });
  }

  function displayNewAgentMessages(messageList) {
    
    
    // Get the last few messages and check if any are new agent/system messages
    const recentMessages = messageList.slice(-10); // Get last 10 messages for better coverage
    
    recentMessages.forEach(message => {
      
      
      // Handle both agent and system messages
      if ((message.type === 'agent' || message.type === 'system') && 
          !document.querySelector(`[data-message-id="${message.timestamp}"]`)) {
        
        
        
        const messageDiv = document.createElement('div');
        messageDiv.classList.add('n8n-union-message', 'n8n-union-bot-message');
        messageDiv.setAttribute('data-message-id', message.timestamp);
        
        const senderDiv = document.createElement('div');
        senderDiv.classList.add('sender');
        senderDiv.innerHTML = botIconSvg;
        const senderSpan = document.createElement('span');
        senderSpan.textContent = message.sender || '';
        senderDiv.appendChild(senderSpan);
        messageDiv.appendChild(senderDiv);

        const textDiv = document.createElement('div');
        textDiv.classList.add('text');
        textDiv.textContent = message.message || '';
        messageDiv.appendChild(textDiv);
        
        messages.appendChild(messageDiv);
        scrollToBottom();
      } else {
        
      }
    });
  }

  function checkChatStatus() {
    fetch(N8nUnionChat.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'n8n_union_visitor_status',
        visitor_id: visitorId,
        visitor_token: visitorToken,
        _ajax_nonce: N8nUnionChat.nonce
      })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.data) {
        const status = data.data.chatStatus;
        const agent = data.data.agent;
        
        if (status === 'human' && !isHumanMode) {
          // Agent has taken over the chat
          isHumanMode = true;
          currentAgent = agent;
          
          // Update chat header to show agent name
          const chatHeader = document.querySelector('#n8n-union-chat-header .header-title');
          if (chatHeader) {
            chatHeader.textContent = N8nUnionChat.header_title + ' - ' + agent.name;
          }
          
          // Show agent takeover message
          appendMessage(agent.name, "Hello! I'm " + agent.name + " and I'll be helping you today.", 'n8n-union-bot-message', { allowFeedback: false });
          
          // Start checking for agent messages every 500ms for real-time updates
          if (messageCheckInterval) clearInterval(messageCheckInterval);
          messageCheckInterval = setInterval(checkForAgentMessages, 500);
          
          // Hide human agent request button if it exists
          if (requestHumanBtn) {
            requestHumanBtn.style.display = 'none';
          }
        }
      }
    })
    .catch(err => {
      // Error handling for chat messages
    });
  }

  // Agent functionality removed for performance optimization

  // Agent message checking removed for performance optimization

  // Team chat functionality removed for performance optimization

  // Agent disclaimer and polling functions removed for performance optimization

  // All agent status checking and team reset functions removed for performance optimization

  function updateDisclaimerForAI() {
    const disclaimer = document.querySelector('.ai-disclaimer');
    if (disclaimer) {
        const disclaimerLines = N8nUnionChat.disclaimer_text.split('\n');
        let disclaimerHTML = '';
        
        disclaimerLines.forEach((line, index) => {
          if (line.trim()) {
            const className = index === 0 ? 'disclaimer-title' : 'disclaimer-subtitle';
            disclaimerHTML += `<div class="${className}">${line.trim()}</div>`;
          }
        });
        
        disclaimer.innerHTML = `
            <div class="disclaimer-content">
                <div class="disclaimer-icon">${getDisclaimerBotIconHtml()}</div>
                <div class="disclaimer-text">
                    ${disclaimerHTML}
                </div>
            </div>
        `;
        disclaimer.style.background = '#f8f9fa';
        disclaimer.style.borderColor = '#dee2e6';
    }
  }

  // Human disclaimer and AJAX handler functions removed for performance optimization

  // Mouse tracking for shimmer effect
  function updateShimmerPosition(e) {
    if (!greeting || greeting.classList.contains('hidden') || greeting.style.display === 'none') return;
    
    const rect = greeting.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    
    // Calculate angle from greeting center to mouse
    const angle = Math.atan2(e.clientY - centerY, e.clientX - centerX);
    const degrees = (angle * 180 / Math.PI + 360) % 360;
    
    // Update shimmer position based on mouse angle
    greeting.style.setProperty('--shimmer-angle', `${degrees}deg`);
  }

  // Add mouse move listener for shimmer effect
  document.addEventListener('mousemove', updateShimmerPosition);

  // DEBUGGING: Add manual test function to window
  window.testGreeting = function() {
    
    
    if (greeting) {
      
      
      
      
      
      
      
      greeting.style.display = 'block';
      greeting.style.visibility = 'visible';
      greeting.style.opacity = '1';
      greeting.classList.remove('hidden');
      
      
      
      
      
    }
  };
  
  // Show greeting message after 3 seconds
  
  setTimeout(() => {
    
    
    // Debug widget state
    
    
    
    
    
    
    
    
    if (greeting) {
      
      
      
      
      
      
      
    }
    
    // Check if widget is hidden (either by inline style or CSS)
    const computedDisplay = window.getComputedStyle(widget).display;
    const isWidgetHidden = widget.style.display === 'none' || (widget.style.display === '' && computedDisplay === 'none');
    
    
    
    
    
    
    
    if (greeting && isWidgetHidden) {
      
      
      
      
      
      
      greeting.style.display = 'block';
      greeting.style.visibility = 'visible';
      greeting.style.opacity = '1';
      greeting.classList.remove('hidden');
      
      
      
      
      
      
      
      
      
      
    } else if (greeting) {
      
      
      
    } else {
      
    }
    
    
  }, 3000);

  // IMMEDIATE GREETING TEST - Show greeting right now for debugging
  
  if (greeting) {
    greeting.style.display = 'block';
    greeting.style.visibility = 'visible'; 
    greeting.style.opacity = '1';
    greeting.classList.remove('hidden');
    
    
    
    
    
  }
  
  
  

}

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initN8nUnionChatWidget);
  } else {
    initN8nUnionChatWidget();
  }
})(); 
  function humanFileSize(bytes) {
    if (typeof bytes !== 'number' || isNaN(bytes) || bytes <= 0) {
      return '';
    }
    const KB = 1024;
    const MB = KB * 1024;
    if (bytes < KB) {
      return `${bytes} B`;
    }
    if (bytes < MB) {
      return `${(bytes / KB).toFixed(0)} KB`;
    }
    return `${(bytes / MB).toFixed(1)} MB`;
  }
