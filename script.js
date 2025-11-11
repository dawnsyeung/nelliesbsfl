// DOM Elements
const navToggle = document.getElementById('nav-toggle');
const navMenu = document.querySelector('.nav__menu');
const navLinks = document.querySelectorAll('.nav__link');
const contactForm = document.querySelector('.contact__form');
const cookieNotice = document.getElementById('cookie-notice');
const acceptCookiesBtn = document.getElementById('accept-cookies');
const header = document.querySelector('.header');

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
        header.style.backgroundColor = 'rgba(62, 39, 35, 0.98)';
    } else {
        header.style.backgroundColor = 'rgba(62, 39, 35, 0.95)';
    }
}

// Form validation and submission
function handleFormSubmission(e) {
    e.preventDefault();
    
    const formData = new FormData(contactForm);
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
    const submitBtn = contactForm.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Sending...';
    submitBtn.disabled = true;
    
    // Submit to Formspree
    fetch(contactForm.action, {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (response.ok) {
            showNotification('Thank you for your message! We\'ll get back to you soon.', 'success');
            contactForm.reset();
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
    
    if (contactForm) {
        contactForm.addEventListener('submit', handleFormSubmission);
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
    
    // Setup image map helper for development
    setupImageMapHelper();
    
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