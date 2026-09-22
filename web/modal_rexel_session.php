<?php
// modal_rexel_session.php - Componente compartido de gestión de sesión Rexel USA
?>
<!-- ============================================================
     MODAL: REXEL SESSION MANAGER (COMPONENTE COMPARTIDO)
     ============================================================ -->
<div id="modal-rexel-session" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rexel-modal-title">
    <div class="modal-content" style="max-width: 650px; width: 94%;">

        <!-- Header -->
        <div class="modal-header">
            <h3 id="rexel-modal-title">🔐 Conectar Rexel</h3>
            <button class="btn-icon-only" onclick="closeRexelModal()" title="Cerrar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Stepper -->
        <div class="step-indicator" id="rexel-step-indicator">
            <div class="step-item" id="step-1">
                <div class="step-circle">1</div>
                <div class="step-label">Preparar</div>
            </div>
            <div class="step-line" id="step-line-1"></div>
            <div class="step-item" id="step-2">
                <div class="step-circle">2</div>
                <div class="step-label">Login</div>
            </div>
            <div class="step-line" id="step-line-2"></div>
            <div class="step-item" id="step-3">
                <div class="step-circle">3</div>
                <div class="step-label">Listo</div>
            </div>
        </div>

        <!-- Body: Panel dinámico según el estado -->
        <div class="modal-body">

            <!-- PANEL 0: Conectado (Sesión Activa) -->
            <div id="rexel-panel-connected" style="display:none;">
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 1rem; padding: 1.25rem; margin-bottom: 1.25rem; text-align: center;">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 0.6rem; border: 1px solid rgba(16, 185, 129, 0.3);">
                        ⚡
                    </div>
                    <h4 style="margin: 0 0 0.35rem 0; color: #10b981; font-size: 1.15rem; font-weight: 800;">
                        Sesión Activa de Rexel USA
                    </h4>
                    <p style="margin: 0; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;" id="rexel-connected-msg">
                        Autenticación corporativa lista para extracción de precios y catálogos.
                    </p>
                </div>

                <div style="background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 0.85rem; padding: 1rem; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Estado de conexión:</span>
                        <span class="session-status-badge status-ok" style="padding: 0.2rem 0.65rem; font-size: 0.75rem;">
                            <span class="badge-dot"></span> <span id="rexel-connected-status-pill">Conectado</span>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Tiempo restante:</span>
                        <span style="color: var(--text-primary); font-weight: 700; font-family: 'Consolas', monospace;" id="rexel-connected-time">
                            Calculando...
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Auto-renovación:</span>
                        <span style="color: #10b981; font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; gap: 0.35rem;">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                            Segundo Plano Continuo
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Almacenamiento:</span>
                        <span style="color: var(--text-secondary); font-size: 0.78rem; font-family: 'Consolas', monospace;">
                            scripts/storage_state.json
                        </span>
                    </div>
                </div>

                <div style="background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.3); border-radius: 0.75rem; padding: 0.75rem 1rem; font-size: 0.8rem; color: var(--text-secondary); line-height: 1.5;">
                    ⚡ <strong>Auto-renovación activa:</strong> El sistema renueva tus credenciales periódicamente en segundo plano con Playwright Headless para que no tengas que volver a iniciar sesión manualmente.
                </div>
            </div>

            <!-- PANEL 1: Listo para iniciar -->
            <div id="rexel-panel-ready">
                <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem;">
                    <button type="button" id="tab-btn-browser" onclick="switchRexelMethod('browser')" style="padding: 0.45rem 0.9rem; font-size: 0.83rem; font-weight: 700; border-radius: 0.5rem; border: none; cursor: pointer; background: var(--bg-card-hover); color: var(--text-primary);">
                        🌐 Navegador Automático
                    </button>
                    <button type="button" id="tab-btn-import" onclick="switchRexelMethod('import')" style="padding: 0.45rem 0.9rem; font-size: 0.83rem; font-weight: 600; border-radius: 0.5rem; border: none; cursor: pointer; background: transparent; color: var(--text-muted);">
                        📥 Importar / Sincronizar (VPS / Remoto)
                    </button>
                </div>

                <!-- Subseccion A: Navegador -->
                <div id="rexel-sub-browser">
                    <p style="color: var(--text-secondary); margin: 0 0 1.25rem 0; line-height: 1.7;">
                        Se abrirá una ventana de tu navegador (detectando automáticamente <strong>Brave</strong>, Chrome o Edge)
                        donde podrás iniciar sesión en Rexel con tus credenciales de forma segura.
                    </p>
                    <div class="login-status-panel" style="text-align: left; padding: 1rem 1.25rem;">
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.6rem;">
                            <span style="color: var(--accent-primary); font-size: 1.1rem; margin-top: 0.05rem;">1.</span>
                            <span style="color: var(--text-secondary); font-size: 0.9rem;">Haz clic en <strong style="color:var(--text-primary)">Abrir Navegador</strong> abajo.</span>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.6rem;">
                            <span style="color: var(--accent-primary); font-size: 1.1rem; margin-top: 0.05rem;">2.</span>
                            <span style="color: var(--text-secondary); font-size: 0.9rem;">Inicia sesión en la ventana del navegador.</span>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <span style="color: var(--accent-primary); font-size: 1.1rem; margin-top: 0.05rem;">3.</span>
                            <span style="color: var(--text-secondary); font-size: 0.9rem;">El sistema detectará el login <strong style="color:var(--text-primary)">automáticamente</strong> y se mantendrá renovada indefinidamente en segundo plano.</span>
                        </div>
                    </div>
                </div>

                <!-- Subseccion B: Importar / Sincronizar (para Servidores Headless/VPS) -->
                <div id="rexel-sub-import" style="display:none;">
                    <p style="color: var(--text-secondary); margin: 0 0 0.75rem 0; font-size: 0.88rem; line-height: 1.6;">
                        Ideal si ejecutas el sistema en un <strong>servidor VPS remoto</strong> sin pantalla:
                    </p>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.65rem; padding: 0.75rem 1rem; margin-bottom: 0.75rem; font-size: 0.8rem; line-height: 1.6;">
                        <strong>¿Cómo obtener tu sesión?</strong>
                        <ul style="margin: 0.4rem 0 0 1.2rem; padding: 0; color: var(--text-secondary);">
                            <li><strong>Opción A:</strong> Si ya tienes el archivo local, abre <code>scripts/storage_state.json</code>, copia todo su texto y pégalo abajo.</li>
                            <li><strong>Opción B (Directo de la web):</strong> Inicia sesión en <a href="https://www.rexelusa.com" target="_blank" style="color:var(--accent-primary); text-decoration:underline;">rexelusa.com</a> en tu navegador, presiona <kbd>F12</kbd> &rarr; Consola, pega el comando extractor y copia el JSON resultante:</li>
                        </ul>
                        <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" class="btn-mini btn-mini-primary" onclick="copyRexelExtractorSnippet()" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">
                                📋 Copiar Comando Extractor para Consola F12
                            </button>
                            <span id="copy-snippet-msg" style="font-size: 0.75rem; color: #10b981; display: none;">¡Copiado al portapapeles!</span>
                        </div>
                    </div>
                    <textarea id="rexel-import-json" placeholder='Pega aquí el JSON de sesión (storage_state)...' style="width: 100%; height: 95px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 0.6rem; color: var(--text-primary); font-family: 'Consolas', monospace; font-size: 0.75rem; padding: 0.6rem; resize: vertical; margin-bottom: 0.75rem;"></textarea>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="button" id="btn-do-import" onclick="importRexelSession()" class="btn-primary" style="font-size: 0.85rem; padding: 0.5rem 1rem;">
                            📥 Sincronizar Sesión
                        </button>
                    </div>
                </div>

                <div id="rexel-start-error" style="display:none;" class="alert-box alert-error"></div>
            </div>

            <!-- PANEL 2: Esperando (browser abierto) -->
            <div id="rexel-panel-waiting" style="display:none; text-align: center;">
                <div class="login-status-panel">
                    <span class="waiting-icon">🌐</span>
                    <p style="color: var(--text-primary); font-weight: 700; margin: 0 0 0.4rem 0;">
                        Navegador abierto
                    </p>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">
                        Inicia sesión en la ventana que se abrió<span class="waiting-dots"></span>
                    </p>
                    <div class="login-progress-bar-wrap">
                        <div class="login-progress-bar" id="login-progress-bar"></div>
                    </div>
                </div>
                <!-- Log en tiempo real -->
                <div style="margin-top: 0.75rem; text-align: left;">
                    <div style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted);
                                text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem;">
                        Monitor en tiempo real
                    </div>
                    <div id="rexel-live-log"
                         style="background: var(--bg-input); border: 1px solid var(--border-subtle);
                                border-radius: 0.5rem; padding: 0.75rem; font-family: 'Consolas', monospace;
                                font-size: 0.72rem; color: var(--text-secondary); height: 110px;
                                overflow-y: auto; text-align: left; line-height: 1.5;">
                        Iniciando...
                    </div>
                </div>
                <div class="countdown-timer">
                    Tiempo restante: <span id="countdown-value">5:00</span>
                </div>
            </div>

            <!-- PANEL 3: Éxito -->
            <div id="rexel-panel-success" style="display:none; text-align: center;">
                <span class="success-checkmark">✅</span>
                <h3 style="color: #10b981; margin: 0 0 0.4rem 0; font-weight: 800;">¡Sesión guardada!</h3>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
                    Rexel conectado correctamente. Los scrapers ya pueden funcionar.
                </p>
            </div>

            <!-- PANEL 4: Error -->
            <div id="rexel-panel-error" style="display:none; text-align: center;">
                <span style="font-size: 3rem; display: block; margin-bottom: 0.75rem;">❌</span>
                <h3 style="color: #ef4444; margin: 0 0 0.4rem 0; font-weight: 800;">No se pudo conectar</h3>
                <p id="rexel-error-detail" style="color: var(--text-secondary); margin: 0 0 1rem 0; font-size: 0.9rem;">
                    El proceso terminó sin detectar login exitoso.
                </p>
            </div>

        </div><!-- /modal-body -->

        <!-- Footer: Botones dinámicos -->
        <div class="modal-footer" id="rexel-modal-footer" style="padding: 1.1rem 1.5rem; background: var(--bg-app); border-top: 1px solid var(--border-subtle);">
            <!-- Estado connected -->
            <div id="rexel-footer-connected" style="display:none; justify-content:space-between; width:100%; align-items:center; gap:0.75rem;">
                <button type="button" class="btn-secondary" id="btn-disconnect-rexel" onclick="disconnectRexelSession()" style="padding: 0.6rem 0.95rem; font-size: 0.82rem; font-weight: 600; cursor: pointer; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.35rem; color: #f87171; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.06);">
                    <span>🗑️</span> Cerrar Sesión
                </button>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button type="button" class="btn-secondary" onclick="switchToRexelReauth()" title="Reabrir navegador para inicio manual" style="padding: 0.6rem 0.95rem; font-size: 0.82rem; font-weight: 600; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                        <span>🔄</span> Re-abrir
                    </button>
                    <button type="button" class="btn-primary" id="btn-refresh-token" onclick="refreshRexelToken()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #059669; font-weight: 700; padding: 0.6rem 1.15rem; font-size: 0.82rem; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);">
                        <span>⚡</span> Renovar Token Ahora
                    </button>
                </div>
            </div>
            <!-- Estado ready -->
            <div id="rexel-footer-ready" style="display:flex; gap:0.75rem; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-secondary" onclick="closeRexelModal()" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem;">Cancelar</button>
                <button type="button" class="btn-primary" id="btn-open-chrome" onclick="startRexelLogin()" style="padding: 0.6rem 1.3rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.45rem;">
                    <span>🌐</span> Abrir Navegador &rarr;
                </button>
            </div>
            <!-- Estado waiting -->
            <div id="rexel-footer-waiting" style="display:none; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-danger" onclick="cancelRexelLogin()" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <span>✕</span> Cancelar proceso
                </button>
            </div>
            <!-- Estado success -->
            <div id="rexel-footer-success" style="display:none; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-primary" onclick="closeRexelModal(); if (typeof refreshSessionBadge === 'function') refreshSessionBadge();" style="padding: 0.6rem 1.3rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700;">
                    Listo / Cerrar
                </button>
            </div>
            <!-- Estado error -->
            <div id="rexel-footer-error" style="display:none; gap:0.75rem; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-secondary" onclick="closeRexelModal()" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem;">Cerrar</button>
                <button type="button" class="btn-primary" onclick="resetRexelModal()" style="padding: 0.6rem 1.3rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700;">Reintentar</button>
            </div>
        </div>

    </div><!-- /modal-content -->
</div><!-- /modal-rexel-session -->

<script>
// Componente JavaScript para el Modal de Sesión Rexel
(function() {
    window.SESSION_MGR = window.SESSION_MGR || 'session_manager.php';
    window.CSRF_TOKEN = window.CSRF_TOKEN || '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    window.LOGIN_TIMEOUT = 300;
    window.latestRexelSession = null;
    let pollInterval = null;
    let countdownTimer = null;
    let countdownSecs = 300;
    let loginPid = null;
    let progressInterval = null;

    window.refreshSessionBadge = async function() {
        const badge = document.getElementById('rexel-session-badge');
        const text  = document.getElementById('rexel-badge-text');
        const heroDot = document.getElementById('hero-rexel-dot');
        const heroStatus = document.getElementById('hero-rexel-status');
        if (!badge && !heroStatus) return;

        if (badge) {
            badge.className = 'session-status-badge status-loading';
            if (text) text.textContent = 'Verificando...';
            badge.onclick = window.openRexelModal;
            badge.style.cursor = 'pointer';
        }

        try {
            const res  = await fetch(window.SESSION_MGR + '?action=status', { cache: 'no-store' });
            const data = await res.json();
            window.latestRexelSession = data;

            if (badge) {
                badge.style.pointerEvents = '';
                badge.onclick = window.openRexelModal;
                badge.style.cursor = 'pointer';
            }

            if (data.valid) {
                if (data.status === 'expiring_soon') {
                    if (badge) {
                        badge.className = 'session-status-badge status-warning';
                        if (text) text.textContent = `Rexel · Expira en ${data.days_left}d`;
                        badge.title = data.message + ' (Clic para gestionar)';
                    }
                    if (heroDot) heroDot.className = 'status-dot status-dot-warning';
                    if (heroStatus) heroStatus.textContent = `Expira en ${data.days_left}d`;
                } else {
                    if (badge) {
                        badge.className = 'session-status-badge status-ok';
                        if (text) text.textContent = `Rexel Conectado · ${data.days_left}d`;
                        badge.title = data.message + ' (Clic para gestionar)';
                    }
                    if (heroDot) heroDot.className = 'status-dot status-dot-active';
                    if (heroStatus) heroStatus.textContent = `Conectado (${data.days_left}d)`;
                }
            } else {
                if (badge) {
                    badge.className = 'session-status-badge status-error';
                    if (data.status === 'missing') {
                        if (text) text.textContent = 'Rexel · Sin sesión';
                        badge.title = 'Clic para conectar Rexel';
                    } else {
                        if (text) text.textContent = 'Rexel · Sesión expirada';
                        badge.title = data.message + ' (Clic para renovar)';
                    }
                }
                if (heroDot) heroDot.className = data.status === 'missing' ? 'status-dot status-dot-inactive' : 'status-dot status-dot-error';
                if (heroStatus) heroStatus.textContent = data.status === 'missing' ? 'Sin sesión (Clic)' : 'Expirada (Renovar)';
            }
        } catch (e) {
            if (badge) {
                badge.className = 'session-status-badge status-error';
                if (text) text.textContent = 'Rexel · Error';
                badge.onclick = window.openRexelModal;
            }
            if (heroDot) heroDot.className = 'status-dot status-dot-error';
            if (heroStatus) heroStatus.textContent = 'Error';
        }
    };

    window.openRexelModal = function() {
        window.resetRexelModal();
        const title = document.getElementById('rexel-modal-title');
        if (window.latestRexelSession && window.latestRexelSession.valid) {
            window.showPanel('connected');
            if (title) title.textContent = '🔐 Sesión Activa de Rexel';
            const timeEl = document.getElementById('rexel-connected-time');
            const msgEl = document.getElementById('rexel-connected-msg');
            const pillEl = document.getElementById('rexel-connected-status-pill');
            if (timeEl) timeEl.textContent = `${window.latestRexelSession.days_left} días y ${window.latestRexelSession.hours_left || 0} horas`;
            if (msgEl) msgEl.textContent = window.latestRexelSession.message || 'Autenticación corporativa activa para extracción de precios y catálogos.';
            if (pillEl) pillEl.textContent = window.latestRexelSession.status === 'expiring_soon' ? 'Expira pronto' : 'Conectado';
        } else {
            window.showPanel('ready');
            window.setStep(1);
            if (title) title.textContent = '🔐 Conectar Rexel';
        }
        const m = document.getElementById('modal-rexel-session');
        if (m) m.classList.add('show');
    };

    window.closeRexelModal = function() {
        const m = document.getElementById('modal-rexel-session');
        if (m) m.classList.remove('show');
        window.stopPolling();
    };

    window.switchToRexelReauth = function() {
        const title = document.getElementById('rexel-modal-title');
        if (title) title.textContent = '🔄 Renovar Sesión · Rexel USA';
        window.showPanel('ready');
        window.setStep(1);
    };

    window.switchRexelMethod = function(method) {
        const subBrowser = document.getElementById('rexel-sub-browser');
        const subImport = document.getElementById('rexel-sub-import');
        const tabBrowser = document.getElementById('tab-btn-browser');
        const tabImport = document.getElementById('tab-btn-import');
        const btnOpenChrome = document.getElementById('btn-open-chrome');

        if (method === 'import') {
            if (subBrowser) subBrowser.style.display = 'none';
            if (subImport) subImport.style.display = 'block';
            if (tabBrowser) {
                tabBrowser.style.background = 'transparent';
                tabBrowser.style.color = 'var(--text-muted)';
                tabBrowser.style.fontWeight = '600';
            }
            if (tabImport) {
                tabImport.style.background = 'var(--bg-card-hover)';
                tabImport.style.color = 'var(--text-primary)';
                tabImport.style.fontWeight = '700';
            }
            if (btnOpenChrome) btnOpenChrome.style.display = 'none';
        } else {
            if (subBrowser) subBrowser.style.display = 'block';
            if (subImport) subImport.style.display = 'none';
            if (tabBrowser) {
                tabBrowser.style.background = 'var(--bg-card-hover)';
                tabBrowser.style.color = 'var(--text-primary)';
                tabBrowser.style.fontWeight = '700';
            }
            if (tabImport) {
                tabImport.style.background = 'transparent';
                tabImport.style.color = 'var(--text-muted)';
                tabImport.style.fontWeight = '600';
            }
            if (btnOpenChrome) btnOpenChrome.style.display = '';
        }
    };

    window.refreshRexelToken = async function() {
        const btn = document.getElementById('btn-refresh-token');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '⏳ Renovando en background...';
        }
        try {
            const form = new FormData();
            form.append('action', 'refresh_token');
            form.append('csrf_token', window.CSRF_TOKEN);

            const res = await fetch(window.SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
            const data = await res.json();

            if (data.success) {
                await window.refreshSessionBadge();
                const timeEl = document.getElementById('rexel-connected-time');
                const msgEl = document.getElementById('rexel-connected-msg');
                if (timeEl) timeEl.textContent = `${data.days_left} días y ${data.hours_left || 0} horas`;
                if (msgEl) msgEl.textContent = `¡Token renovado con éxito! (Tardó ${data.elapsed_seconds || 4}s)`;
                alert('✅ Token renovado exitosamente en segundo plano.\nNueva vigencia: ' + data.days_left + ' días.');
            } else {
                alert('⚠️ No se pudo renovar automáticamente:\n' + (data.message || data.error));
            }
        } catch (e) {
            alert('Error al renovar token: ' + e.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    };

    window.copyRexelExtractorSnippet = function() {
        const snippet = `(() => {
  const ls = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    ls.push({ name: k, value: localStorage.getItem(k) });
  }
  const cookies = document.cookie.split('; ').filter(Boolean).map(c => {
    const [name, ...v] = c.split('=');
    return { name: name.trim(), value: v.join('='), domain: '.rexelusa.com', path: '/' };
  });
  const state = { cookies: cookies, origins: [{ origin: 'https://www.rexelusa.com', localStorage: ls }] };
  copy(JSON.stringify(state));
  alert('¡Sesión copiada al portapapeles! Ahora pégala en el cuadro de texto del panel VPS.');
})();`;

        navigator.clipboard.writeText(snippet).then(() => {
            const msg = document.getElementById('copy-snippet-msg');
            if (msg) {
                msg.style.display = 'inline';
                setTimeout(() => { msg.style.display = 'none'; }, 3500);
            }
        }).catch(() => {
            prompt('Copia manualmente este código y ejecútalo en la consola F12 de rexelusa.com:', snippet);
        });
    };

    window.importRexelSession = async function() {
        const jsonInput = document.getElementById('rexel-import-json');
        const raw = jsonInput ? jsonInput.value.trim() : '';
        if (!raw) {
            alert('Por favor pega el JSON de sesión (storage_state) primero.');
            return;
        }
        const btn = document.getElementById('btn-do-import');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Importando...';
        }
        try {
            const form = new FormData();
            form.append('action', 'import_session');
            form.append('session_json', raw);
            form.append('csrf_token', window.CSRF_TOKEN);

            const res = await fetch(window.SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
            const data = await res.json();

            if (data.success) {
                await window.refreshSessionBadge();
                window.openRexelModal();
                alert('✅ ' + data.message);
            } else {
                alert('❌ ' + (data.message || 'Error al importar sesión.'));
            }
        } catch (e) {
            alert('Error de red al importar: ' + e.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = '📥 Sincronizar Sesión';
            }
        }
    };

    window.resetRexelModal = function() {
        window.switchRexelMethod('browser');
        window.showPanel('ready');
        window.setStep(1);
        const errBox = document.getElementById('rexel-start-error');
        if (errBox) {
            errBox.style.display = 'none';
            errBox.textContent = '';
            errBox.className = 'alert-box alert-error';
        }
        window.stopPolling();
    };

    window.disconnectRexelSession = async function() {
        if (typeof showAppConfirm === 'function') {
            const ok = await showAppConfirm('¿Estás seguro de que deseas cerrar la sesión y borrar las cookies almacenadas de Rexel USA?', {
                title: 'Cerrar Sesión de Rexel',
                confirmText: '🗑️ Sí, Cerrar Sesión',
                cancelText: 'Cancelar',
                isDanger: true
            });
            if (!ok) return;
        } else {
            if (!confirm('¿Estás seguro de que deseas cerrar la sesión y borrar las cookies almacenadas de Rexel USA?')) return;
        }

        const btn = document.getElementById('btn-disconnect-rexel');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Borrando cookies...';
        }
        try {
            const form = new FormData();
            form.append('action', 'clear_session');
            form.append('csrf_token', window.CSRF_TOKEN);

            const res = await fetch(window.SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
            const data = await res.json();

            if (data.success) {
                window.latestRexelSession = null;
                await window.refreshSessionBadge();
                window.showPanel('ready');
                window.setStep(1);
                const title = document.getElementById('rexel-modal-title');
                if (title) title.textContent = '🔐 Conectar Rexel';
                const errBox = document.getElementById('rexel-start-error');
                if (errBox) {
                    errBox.className = 'alert-box alert-success';
                    errBox.textContent = '✅ Sesión cerrada y cookies eliminadas correctamente.';
                    errBox.style.display = 'block';
                }
            } else {
                alert('Error al cerrar sesión: ' + (data.error || 'Desconocido'));
            }
        } catch (e) {
            alert('Error de red al cerrar sesión: ' + e.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '🗑️ Cerrar Sesión (Borrar Cookies)';
            }
        }
    };

    window.startRexelLogin = async function() {
        const btn = document.getElementById('btn-open-chrome');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Iniciando...';
        }

        try {
            const form = new FormData();
            form.append('action', 'start_login');
            form.append('csrf_token', window.CSRF_TOKEN);

            const res  = await fetch(window.SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
            const data = await res.json();

            if (data.success) {
                loginPid = data.pid;
                window.showPanel('waiting');
                window.setStep(2);
                window.startCountdown();
                window.startProgressBar();
                window.startPolling();
            } else {
                window.showStartError('No se pudo lanzar el proceso. ' + (data.error || ''));
            }
        } catch (e) {
            window.showStartError('Error de red: ' + e.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '🌐 Abrir Navegador &rarr;';
            }
        }
    };

    window.cancelRexelLogin = function() {
        window.stopPolling();
        window.showPanel('error');
        const errDetail = document.getElementById('rexel-error-detail');
        if (errDetail) errDetail.textContent = 'Proceso cancelado por el usuario.';
        window.setStep(1);
    };

    window.startPolling = function() {
        pollInterval = setInterval(async () => {
            try {
                const url = window.SESSION_MGR + '?action=check_login' + (loginPid ? '&pid=' + loginPid : '');
                const res  = await fetch(url, { cache: 'no-store' });
                const data = await res.json();

                const logEl = document.getElementById('rexel-live-log');
                if (logEl && data.log) {
                    logEl.textContent = data.log;
                    logEl.scrollTop = logEl.scrollHeight;
                }

                if (data.done) {
                    window.stopPolling();
                    if (data.success) {
                        window.showPanel('success');
                        window.setStep(3, true);
                        window.refreshSessionBadge();
                    } else {
                        const errDetail = document.getElementById('rexel-error-detail');
                        if (errDetail && data.log) {
                            errDetail.innerHTML = 'El proceso terminó sin detectar login exitoso.<br>'
                                + '<small style="font-family:monospace; font-size:0.75rem; opacity:0.7;">'
                                + data.log.split('\n').pop()
                                + '</small>';
                        }
                        window.showPanel('error');
                        window.setStep(1);
                    }
                }
            } catch (e) { /* ignorar transitorios */ }
        }, 2500);
    };

    window.stopPolling = function() {
        clearInterval(pollInterval);
        clearInterval(countdownTimer);
        clearInterval(progressInterval);
        pollInterval = null;
        countdownTimer = null;
        progressInterval = null;
    };

    window.startCountdown = function() {
        countdownSecs = window.LOGIN_TIMEOUT;
        window.updateCountdownDisplay();
        countdownTimer = setInterval(() => {
            countdownSecs--;
            window.updateCountdownDisplay();
            if (countdownSecs <= 0) {
                window.stopPolling();
                window.showPanel('error');
                const errDetail = document.getElementById('rexel-error-detail');
                if (errDetail) errDetail.textContent = 'Tiempo agotado. El login no se completó a tiempo.';
            }
        }, 1000);
    };

    window.updateCountdownDisplay = function() {
        const el = document.getElementById('countdown-value');
        if (!el) return;
        const m = Math.floor(countdownSecs / 60).toString().padStart(1, '0');
        const s = (countdownSecs % 60).toString().padStart(2, '0');
        el.textContent = m + ':' + s;
    };

    window.startProgressBar = function() {
        const bar = document.getElementById('login-progress-bar');
        if (!bar) return;
        bar.style.width = '0%';
        let elapsed = 0;
        progressInterval = setInterval(() => {
            elapsed++;
            const pct = Math.min((elapsed / window.LOGIN_TIMEOUT) * 100, 98);
            bar.style.width = pct + '%';
        }, 1000);
    };

    window.showPanel = function(name) {
        ['connected', 'ready', 'waiting', 'success', 'error'].forEach(p => {
            const panel = document.getElementById('rexel-panel-' + p);
            if (panel) panel.style.display = (p === name) ? '' : 'none';
        });
        ['connected', 'ready', 'waiting', 'success', 'error'].forEach(p => {
            const el = document.getElementById('rexel-footer-' + p);
            if (el) el.style.display = (p === name) ? 'flex' : 'none';
        });
        const stepper = document.getElementById('rexel-step-indicator');
        if (stepper) {
            stepper.style.display = (name === 'connected') ? 'none' : 'flex';
        }
    };

    window.setStep = function(active, allDone = false) {
        for (let i = 1; i <= 3; i++) {
            const item = document.getElementById('step-' + i);
            if (!item) continue;
            item.classList.remove('active', 'done');
            if (allDone || i < active) item.classList.add('done');
            else if (i === active)     item.classList.add('active');
        }
        for (let i = 1; i <= 2; i++) {
            const line = document.getElementById('step-line-' + i);
            if (!line) continue;
            line.classList.remove('done', 'active');
            if (allDone || i < active) line.classList.add('done');
            else if (i === active)     line.classList.add('active');
        }
    };

    window.showStartError = function(msg) {
        const el = document.getElementById('rexel-start-error');
        if (el) {
            el.textContent = msg;
            el.style.display = 'block';
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('modal-rexel-session');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) window.closeRexelModal();
            });
        }
        window.refreshSessionBadge();
    });

    // Auto-actualizar estado de sesión cuando el usuario vuelve a enfocar la pestaña
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            window.refreshSessionBadge();
        }
    });
})();
</script>
