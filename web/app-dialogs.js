/**
 * ================================================================
 * BRIGHTRONIX APP DIALOGS (Custom Alert, Confirm & Prompt)
 * Reemplazo estético, moderno y con modo oscuro para los alerts
 * nativos del navegador ("localhost:8000 dice: ...").
 * ================================================================
 */

(function () {
    // Inyectar estilos CSS una sola vez
    const styleId = 'app-dialogs-styles';
    if (!document.getElementById(styleId)) {
        const styleEl = document.createElement('style');
        styleEl.id = styleId;
        styleEl.textContent = `
            #app-dialog-overlay {
                position: fixed;
                inset: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(10, 14, 23, 0.78);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.2s ease;
                padding: 1.25rem;
                box-sizing: border-box;
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            #app-dialog-overlay.active {
                opacity: 1;
                visibility: visible;
            }
            #app-dialog-card {
                background: var(--bg-panel, #242a38);
                border: 1px solid var(--border-subtle, #2f384a);
                border-radius: 1.25rem;
                width: 100%;
                max-width: 480px;
                box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05);
                transform: scale(0.92) translateY(12px);
                transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            #app-dialog-overlay.active #app-dialog-card {
                transform: scale(1) translateY(0);
            }
            .app-dialog-header {
                padding: 1.5rem 1.5rem 0.5rem 1.5rem;
                display: flex;
                align-items: flex-start;
                gap: 1rem;
            }
            .app-dialog-icon-wrap {
                width: 46px;
                height: 46px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.4rem;
                flex-shrink: 0;
            }
            .app-dialog-icon-success {
                background: rgba(16, 185, 129, 0.15);
                color: #10b981;
                border: 1px solid rgba(16, 185, 129, 0.3);
            }
            .app-dialog-icon-warning {
                background: rgba(245, 158, 11, 0.15);
                color: #f59e0b;
                border: 1px solid rgba(245, 158, 11, 0.3);
            }
            .app-dialog-icon-error {
                background: rgba(239, 68, 68, 0.15);
                color: #ef4444;
                border: 1px solid rgba(239, 68, 68, 0.3);
            }
            .app-dialog-icon-info {
                background: rgba(59, 130, 246, 0.15);
                color: #3b82f6;
                border: 1px solid rgba(59, 130, 246, 0.3);
            }
            .app-dialog-heading-group {
                flex: 1;
                min-width: 0;
            }
            .app-dialog-title {
                margin: 0 0 0.25rem 0;
                font-size: 1.15rem;
                font-weight: 800;
                color: var(--text-primary, #ffffff);
                line-height: 1.3;
            }
            .app-dialog-body {
                padding: 0.5rem 1.5rem 1.25rem 1.5rem;
                color: var(--text-secondary, #94a3b8);
                font-size: 0.92rem;
                line-height: 1.6;
                word-break: break-word;
                white-space: pre-wrap;
            }
            .app-dialog-input-wrap {
                margin-top: 0.85rem;
            }
            .app-dialog-input {
                width: 100%;
                background: var(--bg-input, #151a23);
                border: 1px solid var(--border-subtle, #2f384a);
                border-radius: 0.75rem;
                color: var(--text-primary, #ffffff);
                padding: 0.75rem 0.9rem;
                font-size: 0.9rem;
                font-family: inherit;
                box-sizing: border-box;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .app-dialog-input:focus {
                outline: none;
                border-color: var(--accent-primary, #fb5a3a);
                box-shadow: 0 0 0 3px rgba(251, 90, 58, 0.2);
            }
            .app-dialog-codebox {
                width: 100%;
                height: 110px;
                background: var(--bg-input, #151a23);
                border: 1px solid var(--border-subtle, #2f384a);
                border-radius: 0.75rem;
                color: #38bdf8;
                font-family: 'Consolas', monospace;
                font-size: 0.8rem;
                padding: 0.75rem;
                box-sizing: border-box;
                resize: vertical;
            }
            .app-dialog-footer {
                padding: 1rem 1.5rem;
                background: rgba(15, 23, 42, 0.4);
                border-top: 1px solid var(--border-subtle, #2f384a);
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 0.65rem;
            }
            .app-dialog-btn {
                padding: 0.65rem 1.25rem;
                border-radius: 0.75rem;
                font-size: 0.88rem;
                font-weight: 700;
                cursor: pointer;
                border: 1px solid transparent;
                transition: all 0.18s ease;
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                font-family: inherit;
            }
            .app-dialog-btn-primary {
                background: linear-gradient(135deg, #fb5a3a 0%, #ea580c 100%);
                color: #ffffff;
                border-color: #ea580c;
                box-shadow: 0 4px 12px rgba(251, 90, 58, 0.25);
            }
            .app-dialog-btn-primary:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 16px rgba(251, 90, 58, 0.35);
            }
            .app-dialog-btn-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #ffffff;
                border-color: #059669;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
            }
            .app-dialog-btn-danger {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: #ffffff;
                border-color: #dc2626;
                box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
            }
            .app-dialog-btn-secondary {
                background: var(--bg-surface, #242a38);
                color: var(--text-secondary, #94a3b8);
                border-color: var(--border-subtle, #2f384a);
            }
            .app-dialog-btn-secondary:hover {
                color: var(--text-primary, #ffffff);
                background: var(--bg-card-hover, rgba(255, 255, 255, 0.05));
            }
        `;
        document.head.appendChild(styleEl);
    }

    // Estructura DOM única
    let overlayEl = null;
    let titleEl = null;
    let messageEl = null;
    let iconWrapEl = null;
    let inputWrapEl = null;
    let btnCancel = null;
    let btnConfirm = null;
    let currentResolver = null;

    function ensureDialogDOM() {
        if (overlayEl) return;

        overlayEl = document.createElement('div');
        overlayEl.id = 'app-dialog-overlay';
        overlayEl.setAttribute('role', 'dialog');
        overlayEl.setAttribute('aria-modal', 'true');

        overlayEl.innerHTML = `
            <div id="app-dialog-card">
                <div class="app-dialog-header">
                    <div class="app-dialog-icon-wrap" id="app-dialog-icon">⚡</div>
                    <div class="app-dialog-heading-group">
                        <h3 class="app-dialog-title" id="app-dialog-title">Aviso</h3>
                    </div>
                </div>
                <div class="app-dialog-body" id="app-dialog-message"></div>
                <div id="app-dialog-input-container" class="app-dialog-input-wrap" style="padding: 0 1.5rem 1rem 1.5rem; display: none;"></div>
                <div class="app-dialog-footer">
                    <button type="button" class="app-dialog-btn app-dialog-btn-secondary" id="app-dialog-btn-cancel" style="display: none;">Cancelar</button>
                    <button type="button" class="app-dialog-btn app-dialog-btn-primary" id="app-dialog-btn-confirm">Entendido</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlayEl);

        titleEl = document.getElementById('app-dialog-title');
        messageEl = document.getElementById('app-dialog-message');
        iconWrapEl = document.getElementById('app-dialog-icon');
        inputWrapEl = document.getElementById('app-dialog-input-container');
        btnCancel = document.getElementById('app-dialog-btn-cancel');
        btnConfirm = document.getElementById('app-dialog-btn-confirm');

        // Eventos de botones
        btnCancel.addEventListener('click', function () {
            closeDialog(false);
        });

        btnConfirm.addEventListener('click', function () {
            const input = inputWrapEl.querySelector('input, textarea');
            if (input) {
                closeDialog(input.value);
            } else {
                closeDialog(true);
            }
        });

        // Clic fuera del card
        overlayEl.addEventListener('click', function (e) {
            if (e.target === overlayEl) {
                closeDialog(false);
            }
        });

        // Accesibilidad teclado: Escape cancela, Enter confirma
        document.addEventListener('keydown', function (e) {
            if (!overlayEl || !overlayEl.classList.contains('active')) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closeDialog(false);
            } else if (e.key === 'Enter') {
                const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
                if (activeTag !== 'textarea') {
                    e.preventDefault();
                    btnConfirm.click();
                }
            }
        });
    }

    function closeDialog(value) {
        if (overlayEl) {
            overlayEl.classList.remove('active');
        }
        if (currentResolver) {
            const res = currentResolver;
            currentResolver = null;
            res(value);
        }
    }

    /**
     * Deduce el tipo de diálogo a partir de texto / emojis
     */
    function detectType(text, explicitType) {
        if (explicitType) return explicitType;
        const lower = (text || '').toLowerCase();
        if (text.includes('✅') || lower.includes('exitosamente') || lower.includes('éxito') || lower.includes('guardado')) {
            return 'success';
        }
        if (text.includes('⚠️') || text.includes('⚠') || lower.includes('cuidado') || lower.includes('atención') || lower.includes('expirada')) {
            return 'warning';
        }
        if (text.includes('❌') || lower.includes('error') || lower.includes('falló') || lower.includes('fallo') || lower.includes('denegado')) {
            return 'error';
        }
        return 'info';
    }

    /**
     * Muestra un Alert moderno con Promesa
     */
    window.showAppAlert = function (message, options = {}) {
        ensureDialogDOM();
        const type = detectType(message, options.type);
        const cleanMsg = (message || '').replace(/^([✅⚠️❌ℹ️⚡🔒\s]+)/, '').trim();

        // Configurar icono
        iconWrapEl.className = 'app-dialog-icon-wrap app-dialog-icon-' + type;
        if (type === 'success') {
            iconWrapEl.textContent = '✓';
        } else if (type === 'warning') {
            iconWrapEl.textContent = '⚠️';
        } else if (type === 'error') {
            iconWrapEl.textContent = '✕';
        } else {
            iconWrapEl.textContent = 'ℹ️';
        }

        // Configurar títulos por defecto
        let defaultTitle = 'Aviso del Sistema';
        if (type === 'success') defaultTitle = 'Operación Exitosa';
        else if (type === 'warning') defaultTitle = 'Atención Requerida';
        else if (type === 'error') defaultTitle = 'Error';

        titleEl.textContent = options.title || defaultTitle;
        messageEl.textContent = cleanMsg || message;

        // Ocultar input y botón cancelar
        inputWrapEl.style.display = 'none';
        inputWrapEl.innerHTML = '';
        btnCancel.style.display = 'none';

        btnConfirm.textContent = options.confirmText || 'Entendido';
        btnConfirm.className = 'app-dialog-btn ' + (type === 'success' ? 'app-dialog-btn-success' : 'app-dialog-btn-primary');

        overlayEl.classList.add('active');
        setTimeout(() => btnConfirm.focus(), 50);

        return new Promise((resolve) => {
            currentResolver = resolve;
        });
    };

    /**
     * Muestra un diálogo de Confirmación moderno (Promise<boolean>)
     */
    window.showAppConfirm = function (message, options = {}) {
        ensureDialogDOM();
        const type = options.type || (options.isDanger ? 'warning' : 'info');

        iconWrapEl.className = 'app-dialog-icon-wrap app-dialog-icon-' + type;
        iconWrapEl.textContent = options.isDanger ? '⚠️' : '❓';

        titleEl.textContent = options.title || (options.isDanger ? '¿Confirmar Acción?' : 'Confirmación');
        messageEl.textContent = message;

        inputWrapEl.style.display = 'none';
        inputWrapEl.innerHTML = '';

        btnCancel.style.display = 'inline-flex';
        btnCancel.textContent = options.cancelText || 'Cancelar';

        btnConfirm.textContent = options.confirmText || 'Confirmar';
        btnConfirm.className = 'app-dialog-btn ' + (options.isDanger ? 'app-dialog-btn-danger' : 'app-dialog-btn-primary');

        overlayEl.classList.add('active');
        setTimeout(() => btnConfirm.focus(), 50);

        return new Promise((resolve) => {
            currentResolver = resolve;
        });
    };

    /**
     * Muestra un Prompt interactivo con campo de texto o textarea de código
     */
    window.showAppPrompt = function (message, defaultValue = '', options = {}) {
        ensureDialogDOM();
        const isCode = options.isCode || defaultValue.length > 80;

        iconWrapEl.className = 'app-dialog-icon-wrap app-dialog-icon-info';
        iconWrapEl.textContent = isCode ? '📋' : '✍️';

        titleEl.textContent = options.title || (isCode ? 'Copiar Información' : 'Ingresar Dato');
        messageEl.textContent = message;

        inputWrapEl.style.display = 'block';
        if (isCode) {
            inputWrapEl.innerHTML = `
                <textarea class="app-dialog-codebox" id="app-dialog-input-field" readonly>${defaultValue}</textarea>
                <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem;">
                    <button type="button" class="app-dialog-btn app-dialog-btn-secondary" id="btn-copy-prompt-code" style="padding: 0.4rem 0.85rem; font-size: 0.8rem;">
                        <span>📋</span> Copiar al Portapapeles
                    </button>
                </div>
            `;
            setTimeout(() => {
                const btnCopy = document.getElementById('btn-copy-prompt-code');
                const field = document.getElementById('app-dialog-input-field');
                if (btnCopy && field) {
                    btnCopy.onclick = function () {
                        navigator.clipboard.writeText(field.value);
                        btnCopy.textContent = '¡Copiado! ✓';
                        setTimeout(() => { btnCopy.textContent = '📋 Copiar al Portapapeles'; }, 2500);
                    };
                }
            }, 50);
        } else {
            inputWrapEl.innerHTML = `<input type="text" class="app-dialog-input" id="app-dialog-input-field" value="${defaultValue}" placeholder="${options.placeholder || ''}">`;
        }

        btnCancel.style.display = 'inline-flex';
        btnCancel.textContent = options.cancelText || 'Cancelar';

        btnConfirm.textContent = options.confirmText || 'Aceptar';
        btnConfirm.className = 'app-dialog-btn app-dialog-btn-primary';

        overlayEl.classList.add('active');
        setTimeout(() => {
            const field = document.getElementById('app-dialog-input-field');
            if (field) {
                field.focus();
                if (!isCode && field.select) field.select();
            }
        }, 50);

        return new Promise((resolve) => {
            currentResolver = resolve;
        });
    };

    // Sobrescribir nativos window.alert y window.confirm
    window.nativeAlert = window.alert;
    window.alert = function (message) {
        return window.showAppAlert(String(message));
    };

    window.nativeConfirm = window.confirm;
    // window.confirm nativo es sincrónico, pero proporcionamos aviso para usar showAppConfirm
    // y fallback seguro que no bloquea la pestaña
    window.confirm = function (message) {
        console.warn("[Brightronix] Para confirmaciones modernas no bloqueantes, usa: await showAppConfirm(...)");
        return window.nativeConfirm(message);
    };

    window.nativePrompt = window.prompt;
    window.prompt = function (message, defaultVal) {
        return window.showAppPrompt(message, defaultVal || '');
    };

})();
