// DOM Elements
const navToggle = document.getElementById('nav-toggle');
const navMenu = document.querySelector('.nav__menu');
const navLinks = document.querySelectorAll('.nav__link');
const contactForms = document.querySelectorAll('.contact__form');
const cookieNotice = document.getElementById('cookie-notice');
const acceptCookiesBtn = document.getElementById('accept-cookies');
const header = document.querySelector('.header');
const askAgentForm = document.getElementById('ask-agent-form');
const askAgentInput = document.getElementById('ask-agent-input');
const askAgentResponse = document.getElementById('ask-agent-response');
const askAgentVoiceButton = document.getElementById('ask-agent-voice');
const askAgentVoiceStatus = document.getElementById('ask-agent-voice-status');
const askAgentPromptButtons = document.querySelectorAll('[data-ask-prompt]');
const onboardingForm = document.querySelector('.onboarding-form');

const askAgentKnowledgeBase = [
    {
        id: 'inclusion-rate',
        keywords: ['inclusion', 'rate', 'starter', 'grower', 'finisher', 'diet', 'larvae oil', 'oil'],
        answer: `Begin with a 10% inclusion in starter diets and ramp toward 20% in grower and finisher rations over two flocks. Pair those trials with a 5% larvae oil dose to keep energy balanced. We will share historic FCR curves by breed and help your nutritionist tweak amino targets as data rolls in.`
    },
    {
        id: 'pricing-lock',
        keywords: ['pricing', 'lock', 'hedging', 'contract', '12 months', 'quarterly', 'volume', 'tons'],
        answer: `Yes—BSFL supply can be priced quarterly with hedges tied to feedstock indices. Commitments above roughly 2,500 tons per year unlock blended pricing plus off-take credits when you sell frass back through your grower network. We can co-write the contract language with your procurement lead.`
    },
    {
        id: 'frass-credits',
        keywords: ['frass', 'credit', 'roi', 'revenue', 'soil', 'offtake', 'worksheet'],
        answer: `Frass revenue typically shows up as a negative cost line inside the ROI workbook. Buyers who loop frass into their agronomy program earn $40–$90 per ton in soil and turf deployments, which covers 8–12% of your annual operating cost. We track every load for traceability so auditors can count it as an off-take credit.`
    },
    {
        id: 'regulator-kpi',
        keywords: ['regulator', 'kpi', 'compliance', 'report', 'permit', 'scope 3', 'esg'],
        answer: `Regulators want to see waste diversion %, pathogen log reduction, and documented vector controls. Sustainability teams overlay Scope 3 carbon savings, feed conversion deltas, and soil biology scores. Our dashboards package those KPIs with QA certificates so it is easy to drop into ESG or permitting reports.`
    },
    {
        id: 'lead-time',
        keywords: ['lead', 'timeline', 'deploy', 'unit', 'installation', 'weeks'],
        answer: `Modular BSFL units ship on an 8–12 week timeline depending on electrical work. We stage feedstock qualification alongside permitting so the first larvae go live within 30 days of delivery. Payback trackers update automatically once weight tickets hit the CRM.`
    }
];

const askAgentFallbackAnswer = `I do not have that data in the on-page agent yet, but share more context through the contact form and we will respond with a sourced answer.`;
let askAgentSpeechRecognition = null;
let askAgentListening = false;

// Mobile Navigation Toggle
function toggleMobileMenu() {
    navMenu.classList.toggle('active');
    navToggle.classList.toggle('active');
    
    // Prevent body scroll when menu is open
    if (navMenu.classList.contains('active')) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = 'auto';
    }
}

// Close mobile menu when clicking on a link
function closeMobileMenu() {
    navMenu.classList.remove('active');
    navToggle.classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Smooth scrolling for navigation links
function smoothScroll(target) {
    const element = document.querySelector(target);
    if (element) {
        const headerHeight = header.offsetHeight;
        const elementPosition = element.offsetTop - headerHeight;
        
        window.scrollTo({
            top: elementPosition,
            behavior: 'smooth'
        });
    }
}

function isHashLink(href) {
    if (!href) return false;
    if (href.startsWith('#')) return true;
    try {
        const url = new URL(href, window.location.href);
        const currentPath = window.location.pathname.replace(/\/+$/, '');
        const targetPath = url.pathname.replace(/\/+$/, '');
        return Boolean(url.hash) && targetPath === currentPath;
    } catch (error) {
        return false;
    }
}

// Header scroll effect
function handleHeaderScroll() {
    if (window.scrollY > 100) {
        header.style.backgroundColor = 'rgba(28, 19, 16, 0.94)';
    } else {
        header.style.backgroundColor = 'rgba(28, 19, 16, 0.82)';
    }
}

// Form validation and submission
function handleFormSubmission(e) {
    e.preventDefault();
    
    const form = e.currentTarget;
    const formData = new FormData(form);
    const name = formData.get('name').trim();
    const email = formData.get('email').trim();
    const message = formData.get('message').trim();
    const newsletter = formData.get('newsletter');
    
    // Basic validation
    if (!name || !email || !message) {
        showNotification('Please fill in all required fields.', 'error');
        return;
    }
    
    if (!isValidEmail(email)) {
        showNotification('Please enter a valid email address.', 'error');
        return;
    }
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Sending...';
    submitBtn.disabled = true;
    
    // Submit to Formspree
    fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (response.ok) {
            showNotification('Thank you for your message! We\'ll get back to you soon.', 'success');
            form.reset();
        } else {
            throw new Error('Form submission failed');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Sorry, there was an error sending your message. Please try again.', 'error');
    })
    .finally(() => {
        // Reset button state
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    });
}

// Email validation
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Notification system
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification--${type}`;
    notification.innerHTML = `
        <div class="notification__content">
            <span class="notification__message">${message}</span>
            <button class="notification__close">&times;</button>
        </div>
    `;
    
    // Add styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 4px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        z-index: 10000;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        max-width: 400px;
    `;
    
    // Add to DOM
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.style.transform = 'translateX(0)';
    }, 100);
    
    // Auto remove after 5 seconds
    const autoRemove = setTimeout(() => {
        removeNotification(notification);
    }, 5000);
    
    // Close button functionality
    const closeBtn = notification.querySelector('.notification__close');
    closeBtn.addEventListener('click', () => {
        clearTimeout(autoRemove);
        removeNotification(notification);
    });
}

function removeNotification(notification) {
    notification.style.transform = 'translateX(100%)';
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 300);
}

function setupOnboardingForm() {
    if (!onboardingForm) {
        return;
    }

    const confirmation = document.getElementById('onboarding-confirmation');
    const submitBtn = onboardingForm.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.textContent : '';
    const formAction = onboardingForm.action || '';
    const formspreeEmail = document.getElementById('formspree_email');
    const formspreeName = document.getElementById('formspree_name');

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        // Minimal fallback; good enough for common field names.
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function clearOnboardingFieldErrors() {
        onboardingForm.querySelectorAll('.field-error').forEach((el) => el.remove());
        onboardingForm.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
        onboardingForm.querySelectorAll('.form__input--error, .form__textarea--error').forEach((el) => {
            el.classList.remove('form__input--error', 'form__textarea--error');
        });
    }

    function applyOnboardingFieldErrors(errors) {
        if (!errors || typeof errors !== 'object') {
            return;
        }

        Object.entries(errors).forEach(([field, message]) => {
            const safeField = cssEscape(field);
            const input =
                onboardingForm.querySelector(`[name="${safeField}"]`) ||
                onboardingForm.querySelector(`#${safeField}`);

            if (!input) {
                return;
            }

            input.setAttribute('aria-invalid', 'true');
            if (input.classList.contains('form__textarea')) {
                input.classList.add('form__textarea--error');
            } else {
                input.classList.add('form__input--error');
            }

            const group = input.closest('.form__group') || input.parentElement;
            if (!group) {
                return;
            }

            // Avoid duplicating errors for the same group.
            if (group.querySelector('.field-error')) {
                return;
            }

            const errorEl = document.createElement('div');
            errorEl.className = 'field-error';
            errorEl.textContent = String(message || 'This field has an error.');
            group.appendChild(errorEl);
        });
    }

    async function parseResponseBody(response) {
        const contentType = (response.headers.get('content-type') || '').toLowerCase();
        if (contentType.includes('application/json')) {
            try {
                return await response.json();
            } catch (e) {
                return null;
            }
        }

        try {
            const text = await response.text();
            if (!text) return null;
            try {
                return JSON.parse(text);
            } catch (e) {
                return { error: text };
            }
        } catch (e) {
            return null;
        }
    }

    onboardingForm.addEventListener('submit', async (event) => {
        // If the browser says the form is invalid, let native UI handle it.
        if (typeof onboardingForm.reportValidity === 'function' && !onboardingForm.reportValidity()) {
            return;
        }

        event.preventDefault();
        clearOnboardingFieldErrors();

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        }

        try {
            const formData = new FormData(onboardingForm);
            // Populate Formspree reply-to helpers (harmless for backend PHP as well)
            const primaryName = String(formData.get('primary_contact_name') || '').trim();
            const primaryEmail = String(formData.get('primary_contact_email') || '').trim();
            if (formspreeEmail && !formData.get('email') && primaryEmail) {
                formspreeEmail.value = primaryEmail;
                formData.set('email', primaryEmail);
            }
            if (formspreeName && !formData.get('name') && primaryName) {
                formspreeName.value = primaryName;
                formData.set('name', primaryName);
            }

            async function submitTo(url) {
                return await fetch(url, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
            }

            const response = await submitTo(formAction);

            if (!response.ok) {
                const payload = await parseResponseBody(response);
                // Formspree can return { errors: [...] } or other formats.
                let fieldErrors = null;
                if (payload && payload.errors) {
                    if (Array.isArray(payload.errors)) {
                        // Convert array errors into a single summary message.
                        const summary = payload.errors.map((e) => (e && e.message ? String(e.message) : '')).filter(Boolean).join(' ');
                        throw new Error(summary || `Registration submission failed (${response.status}). Please try again.`);
                    }
                    if (typeof payload.errors === 'object') {
                        fieldErrors = payload.errors;
                    }
                }
                if (fieldErrors) {
                    applyOnboardingFieldErrors(fieldErrors);
                    const messages = Object.values(fieldErrors).filter(Boolean);
                    const summary = messages.length ? messages.join(' ') : (payload && payload.error ? payload.error : 'Please fix the highlighted fields and try again.');
                    showNotification(summary, 'error');

                    const firstInvalid = onboardingForm.querySelector('[aria-invalid="true"]');
                    if (firstInvalid && typeof firstInvalid.scrollIntoView === 'function') {
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        if (typeof firstInvalid.focus === 'function') {
                            firstInvalid.focus({ preventScroll: true });
                        }
                    }
                    return;
                }

                const message = (payload && payload.error) ? payload.error : `Registration submission failed (${response.status}). Please try again.`;
                throw new Error(message);
            }

            const payload = await parseResponseBody(response);
            if (payload && payload.success === false) {
                const message = payload.error || 'Registration submission failed. Please try again.';
                showNotification(message, 'error');
                return;
            }

            // Show on-site confirmation
            if (confirmation) {
                confirmation.hidden = false;
                confirmation.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            showNotification('Registration received. We’ll follow up shortly.', 'success');
            onboardingForm.reset();
        } catch (error) {
            console.error('Onboarding submission error:', error);
            const message = (error && error.message) ? error.message : 'Sorry—there was an error submitting your registration. Please try again.';
            showNotification(message, 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalBtnText;
            }
        }
    });
}

// Cookie notice functionality
function showCookieNotice() {
    if (!localStorage.getItem('cookiesAccepted')) {
        cookieNotice.classList.add('show');
    }
}

function hideCookieNotice() {
    cookieNotice.classList.remove('show');
    localStorage.setItem('cookiesAccepted', 'true');
}

// Smooth scrolling for any anchor links
function setupSmoothScrolling() {
    const links = document.querySelectorAll('a[href^="#"]');
    links.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const target = document.querySelector(link.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

// Add notification styles
function addNotificationStyles() {
    const style = document.createElement('style');
    style.textContent = `
        .notification__content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        
        .notification__close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }
        
        .notification__close:hover {
            opacity: 0.8;
        }
    `;
    document.head.appendChild(style);
}

// Performance optimization: Throttle scroll events
function throttle(func, limit) {
    let inThrottle;
    return function() {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    }
}

// Image map coordinate helper (for finding the "future" word coordinates)
function setupImageMapHelper() {
    const heroImage = document.querySelector('.hero__compost-image');
    
    // Add click handler to help find coordinates (only in development)
    // Remove this in production or when coordinates are finalized
    if (heroImage && window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        heroImage.addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const x = Math.round(((e.clientX - rect.left) / rect.width) * this.naturalWidth);
            const y = Math.round(((e.clientY - rect.top) / rect.height) * this.naturalHeight);
            console.log(`Clicked coordinates: ${x}, ${y}`);
            console.log(`Image size: ${this.naturalWidth} x ${this.naturalHeight}`);
            console.log(`To create an area around this point, use coords like: "${x-50},${y-20},${x+50},${y+20}"`);
        });
    }
}

function setupAskAgent() {
    if (!askAgentForm || !askAgentInput || !askAgentResponse) {
        return;
    }

    askAgentForm.addEventListener('submit', (event) => {
        event.preventDefault();
        processAskAgentQuestion(askAgentInput.value);
    });

    if (askAgentPromptButtons.length > 0) {
        askAgentPromptButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const prompt = button.getAttribute('data-ask-prompt') || '';
                askAgentInput.value = prompt;
                askAgentInput.focus();
                processAskAgentQuestion(prompt, false);
            });
        });
    }

    setupAskAgentVoice();
}

function processAskAgentQuestion(question, shouldSpeak = true) {
    if (!askAgentResponse) {
        return;
    }

    const cleanedQuestion = (question || '').trim();
    if (!cleanedQuestion) {
        renderAskAgentAnswer('', 'Ask about feed programs, pricing, or permitting to get started.');
        return;
    }

    const answer = getAskAgentAnswer(cleanedQuestion);
    renderAskAgentAnswer(cleanedQuestion, answer);

    if (shouldSpeak) {
        speakAskAgentAnswer(answer);
    }
}

function getAskAgentAnswer(question) {
    const normalized = question.toLowerCase();
    let bestScore = 0;
    let bestAnswer = askAgentFallbackAnswer;

    askAgentKnowledgeBase.forEach((entry) => {
        const score = entry.keywords.reduce((total, keyword) => {
            return normalized.includes(keyword.toLowerCase()) ? total + 1 : total;
        }, 0);

        if (score > bestScore) {
            bestScore = score;
            bestAnswer = entry.answer;
        }
    });

    return bestAnswer;
}

function renderAskAgentAnswer(question, answer) {
    if (!askAgentResponse) {
        return;
    }

    askAgentResponse.innerHTML = '';

    if (!question) {
        const placeholder = document.createElement('p');
        placeholder.className = 'ask-agent__placeholder';
        placeholder.textContent = answer;
        askAgentResponse.appendChild(placeholder);
        return;
    }

    const questionEl = document.createElement('p');
    questionEl.className = 'ask-agent__question';
    questionEl.textContent = `You asked: ${question}`;

    const answerEl = document.createElement('p');
    answerEl.className = 'ask-agent__answer';
    answerEl.textContent = answer;

    askAgentResponse.append(questionEl, answerEl);
}

function speakAskAgentAnswer(answer) {
    if (!('speechSynthesis' in window) || !answer) {
        return;
    }

    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(answer);
    utterance.lang = 'en-US';
    utterance.rate = 0.98;
    window.speechSynthesis.speak(utterance);
}

function setupAskAgentVoice() {
    if (!askAgentVoiceButton || !askAgentInput) {
        return;
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
        askAgentVoiceButton.disabled = true;
        askAgentVoiceButton.textContent = 'Voice not supported';
        updateAskAgentVoiceStatus('Your browser does not support speech input. Type your question instead.');
        return;
    }

    askAgentSpeechRecognition = new SpeechRecognition();
    askAgentSpeechRecognition.lang = 'en-US';
    askAgentSpeechRecognition.interimResults = false;
    askAgentSpeechRecognition.maxAlternatives = 1;

    askAgentSpeechRecognition.onstart = () => {
        updateAskAgentVoiceState(true, 'Listening... speak naturally.');
    };

    askAgentSpeechRecognition.onend = () => {
        updateAskAgentVoiceState(false, 'Voice capture stopped. Tap to ask another question.');
    };

    askAgentSpeechRecognition.onresult = (event) => {
        const transcript = event.results[0][0].transcript;
        askAgentInput.value = transcript;
        processAskAgentQuestion(transcript);
    };

    askAgentSpeechRecognition.onerror = (event) => {
        console.error('Voice error:', event.error);
        updateAskAgentVoiceState(false, `Voice error: ${event.error}`);
    };

    askAgentVoiceButton.addEventListener('click', () => {
        if (!askAgentSpeechRecognition) {
            return;
        }

        try {
            if (askAgentListening) {
                askAgentSpeechRecognition.stop();
            } else {
                askAgentSpeechRecognition.start();
            }
        } catch (error) {
            console.error('Speech recognition start error:', error);
            updateAskAgentVoiceState(false, 'Unable to access microphone permission.');
        }
    });
}

function updateAskAgentVoiceState(listening, statusText = '') {
    askAgentListening = listening;

    if (!askAgentVoiceButton) {
        return;
    }

    if (listening) {
        askAgentVoiceButton.classList.add('ask-agent__voice-btn--listening');
        askAgentVoiceButton.textContent = 'Listening... Tap to stop';
    } else {
        askAgentVoiceButton.classList.remove('ask-agent__voice-btn--listening');
        askAgentVoiceButton.textContent = 'Tap to Speak';
    }

    if (statusText) {
        updateAskAgentVoiceStatus(statusText);
    }
}

function updateAskAgentVoiceStatus(text) {
    if (askAgentVoiceStatus) {
        askAgentVoiceStatus.textContent = text;
    }
}

// Initialize all functionality
function init() {
    // Add notification styles
    addNotificationStyles();
    
    // Event listeners
    if (navToggle) {
        navToggle.addEventListener('click', toggleMobileMenu);
    }
    
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href') || '';
            if (isHashLink(href)) {
                e.preventDefault();
                const target = href.startsWith('#') ? href : new URL(href, window.location.href).hash;
                smoothScroll(target);
                closeMobileMenu();
            } else {
                closeMobileMenu();
            }
        });
    });
    
    if (contactForms.length > 0) {
        contactForms.forEach(form => {
            form.addEventListener('submit', handleFormSubmission);
        });
    }
    
    if (acceptCookiesBtn) {
        acceptCookiesBtn.addEventListener('click', hideCookieNotice);
    }
    
    // Scroll events (throttled for performance)
    window.addEventListener('scroll', throttle(handleHeaderScroll, 10));
    
    // Setup smooth scrolling
    setupSmoothScrolling();
    
    // Show cookie notice if not accepted
    showCookieNotice();

    // Setup customer registration onboarding form (stay on-site after submit)
    setupOnboardingForm();
    
    // Setup image map helper for development
    setupImageMapHelper();

    // Initialize Ask Nellie's agent
    setupAskAgent();
    
    // Add loading complete class to body
    document.body.classList.add('loaded');
}

// Handle window resize
window.addEventListener('resize', () => {
    // Close mobile menu on resize to desktop
    if (window.innerWidth > 768) {
        closeMobileMenu();
    }
});

// Wait for DOM to be fully loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// Add some utility functions for external use
window.NelliesBSF = {
    showNotification,
    hideCookieNotice,
    showCookieNotice,
    toggleMobileMenu,
    closeMobileMenu,
    smoothScroll
};

// Console welcome message
console.log('%c🐛 Nellie\'s Black Soldier Fly Larvae Website Loaded!', 'color: #2c5530; font-size: 16px; font-weight: bold;');
console.log('%c🌱 Sustainable farming for a healthier future', 'color: #666666; font-size: 12px;');