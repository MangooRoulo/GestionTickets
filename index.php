<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TickMetrics Hub - Gestión de Tickets</title>
    <meta name="description" content="Sistema de gestión de tickets con métricas, auditoría y base de datos MySQL">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container">
        <!-- Banner de migración (solo si hay datos en localStorage) -->
        <div id="migration-banner" class="migration-banner" style="display:none;">
            <span>⚠️ Se detectaron datos locales. ¿Desea migrarlos a la base de datos?</span>
            <button class="btn btn-primary btn-sm" onclick="migrateData()">Migrar Ahora</button>
            <button class="btn btn-secondary btn-sm" onclick="dismissMigration()">Descartar</button>
        </div>

        <header>
            <div class="logo">TickMetrics <span class="logo-badge">DB</span></div>
            <div class="controls" style="flex-wrap:nowrap; gap:8px;">
                <button class="btn-theme" id="theme-toggle" title="Cambiar Tema">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M12 5a7 7 0 100 14 7 7 0 000-14z"></path></svg>
                </button>
                <div class="config-controls" style="background:rgba(59,130,246,0.1); border-radius:8px; padding:2px 4px; display:flex; align-items:center;">
                    <button id="prev-date" title="Día anterior" style="background:none; border:none; cursor:pointer; color:var(--accent-primary); width:28px; height:28px; display:flex; align-items:center; justify-content:center; border-radius:4px;"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg></button>
                    <input type="date" id="filter-date" class="search-input" style="width:120px; padding:4px; border:none; background:transparent; font-weight:600; text-align:center; padding:0;">
                    <button id="next-date" title="Día siguiente" style="background:none; border:none; cursor:pointer; color:var(--accent-primary); width:28px; height:28px; display:flex; align-items:center; justify-content:center; border-radius:4px;"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                </div>
                <div class="config-controls" style="border-radius:8px; padding:0 4px; flex-shrink:0;">
                    <label for="stale-limit" style="font-size:0.75rem; padding:0.6rem; font-weight:600;">Días</label>
                    <input type="number" id="stale-limit" value="7" min="1" max="99" style="width:30px; border:none; background:transparent; font-size:0.8rem; text-align:center; outline:none; font-weight:600;">
                </div>
                <div class="btn-group" style="display:flex; gap:4px; flex-wrap:nowrap; align-items:center; flex-shrink:0;">
                    <button class="btn btn-secondary btn-sm" id="export-informes" title="Reporte Informes" style="white-space:nowrap;">Reporte Informes</button>
                    <button class="btn btn-secondary btn-sm" id="report-otros" title="Reporte General" style="white-space:nowrap;">Reporte General</button>
                    <button class="btn btn-secondary btn-sm" id="export-history" title="Exportar Excel" style="white-space:nowrap; padding:0.4rem 0.6rem;">Exportar Múltiple</button>
                    <button class="btn btn-secondary btn-sm" id="copy-closure" title="Copiar Resumen" style="display:none; white-space:nowrap; padding:0.4rem 0.6rem;">📋</button>
                </div>

                <div style="display:flex; gap:4px; align-items:center; flex-shrink:0;">
                    <button class="btn btn-danger btn-sm" id="undo-import" title="Deshacer Importación" style="padding:0.4rem;"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 010 10H7m-4-10l4-4m-4 4l4 4"></path></svg></button>
                    <button class="btn-theme" id="restore-mode" title="Modo Restauración" style="width:32px; height:32px; background:rgba(239,68,68,0.1); border-color:var(--status-reabierto); color:var(--status-reabierto);">🛠️</button>
                    <button class="btn btn-secondary btn-sm" id="btn-backup-dia" title="Descargar respaldo del día" style="padding:0.4rem 0.6rem; white-space:nowrap; border-color:#10b981; color:#10b981;" onclick="exportDailyBackup()">💾 Día</button>
                    <label for="restore-file-import" class="btn btn-secondary btn-sm" style="padding:0.4rem 0.6rem; cursor:pointer; white-space:nowrap; margin-bottom:0; border-color:#f59e0b; color:#f59e0b;" title="Restaurar backup del día">
                        ⬆️ Día
                    </label>
                    <input type="file" id="restore-file-import" accept=".xlsx,.xls" hidden onchange="restoreDailyBackup(event)">
                </div>

                <label for="file-import" class="btn btn-primary btn-sm" style="padding:0.4rem 0.8rem; white-space:nowrap;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Importar General
                </label>
                <input type="file" id="file-import" accept=".xlsx,.xls" hidden>
            </div>
        </header>

        <div class="tabs">
            <button class="tab-btn active" data-view="dashboard">Panel de Métricas</button>
            <button class="tab-btn" data-view="tickets">Explorador de Tickets</button>
            <button class="tab-btn" data-view="estados-section">Configuración</button>
            <button class="tab-btn" data-view="historial-section">Historial</button>
            <button class="tab-btn" data-view="ticket-insight-section">🔍 Rastreo de Ticket</button>
            <button class="tab-btn" data-view="mensual-section">Resumen Mensual</button>
        </div>

        <!-- VISTA: Dashboard -->
        <section id="dashboard" class="view-section active">
            <div class="metrics-grid">
                <div class="kpi-card" style="border:1px solid var(--status-reabierto);background:rgba(239,68,68,0.05);">
                    <div class="kpi-title" id="kpi-total-label">Pendientes de Gestionar</div>
                    <div class="kpi-value" id="kpi-total" style="color:var(--status-reabierto)">0</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title" id="stale-title">>= 7 Días Sin Derivación</div>
                    <div class="kpi-value" id="kpi-stale" style="color:var(--status-enproceso)">0</div>
                </div>
                <div class="kpi-card" style="border:1px solid var(--status-corregido);background:rgba(16,185,129,0.05);">
                    <div class="kpi-title" id="mgmt-kpi-label">Gestionados Hoy</div>
                    <div class="kpi-value" id="kpi-pending-mgmt" style="color:var(--status-corregido)">0</div>
                </div>
                <div class="kpi-card" id="kpi-closure-time" style="position:relative;border:1px solid var(--accent-primary);background:rgba(59,130,246,0.05);">
                    <div class="kpi-title" style="color:var(--accent-primary);font-weight:700;">⏱️ Tiempos Totales</div>
                    <div class="kpi-value" id="kpi-daily-hours">0h</div>
                    <button id="toggle-privacy" title="Ocultar/Mostrar" style="position:absolute;top:1rem;right:1rem;background:none;border:none;cursor:pointer;color:var(--text-secondary);opacity:0.6;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                    </button>
                </div>
            </div>
            <!-- Chart Grid Top: Ahora 4 columnas posibles para acomodar los nuevos gráficos -->
            <div class="chart-grid-top" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
                <div class="chart-container">
                    <div class="chart-title">Estados de Tickets Importados</div>
                    <canvas id="chart-estado"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Revisados vs Importados por Tipo</div>
                    <canvas id="chart-gestion-tipo"></canvas>
                </div>
                <!--<div class="chart-container">
                    <div class="chart-title">Tiempo Dedicado por Tipo</div>
                    <canvas id="chart-tiempo-tipo"></canvas>
                </div>-->
                <div class="chart-container">
                    <div class="chart-title">Estimado vs Dedicado por Tipo</div>
                    <canvas id="chart-tiempo-comparativo"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Días sin Derivación</div>
                    <canvas id="chart-derivacion"></canvas>
                </div>
            </div>
            <div class="chart-grid-bottom">
                <div class="chart-container">
                    <div class="chart-title">Productividad por Día</div>
                    <canvas id="chart-historico"></canvas>
                </div>
            </div>
        </section>

        <!-- VISTA: Explorador de Tickets -->
        <section id="tickets" class="view-section">
            <div class="search-filters">
                <input type="text" class="search-input" id="search-text" placeholder="Buscar por resumen, ID o módulo...">
                <select class="filter-select" id="filter-estado"><option value="">Todos los Estados</option></select>
                <select class="filter-select" id="filter-gravedad"><option value="">Todas las Gravedades</option></select>
                <select class="filter-select" id="filter-tipo"><option value="">Todos los Tipos</option></select>
                <div style="display:flex;align-items:center;gap:0.5rem;margin-left:auto;">
                    <input type="checkbox" id="show-managed" style="width:18px;height:18px;cursor:pointer;">
                    <label for="show-managed" style="font-size:0.9rem;font-weight:500;cursor:pointer;color:var(--text-secondary);">Ver gestionados</label>
                    
                    <div style="width:1px; height:20px; background:var(--border-color); margin:0 8px;"></div>
                    
                    <input type="checkbox" id="filter-worked-only" style="width:18px;height:18px;cursor:pointer;">
                    <label for="filter-worked-only" style="font-size:0.9rem;font-weight:700;cursor:pointer;color:var(--accent-primary);">Solo trabajados</label>
                </div>
            </div>
            <div class="table-responsive">
                <table id="tickets-table">
                    <thead>
                        <tr>
                            <th>Resumen / Módulo</th>
                            <th>Estado Excel</th>
                            <th>Gravedad</th>
                            <th>Est. (Min)</th>
                            <th>Ded. (Min)</th>
                            <th>Gestión</th>
                            <th>En Proceso</th>
                            <th>Días Deriv.</th>
                            <th>Puntaje</th>
                            <th>Fecha Entrega</th>
                        </tr>
                    </thead>
                    <tbody id="table-body"></tbody>
                </table>
            </div>
        </section>

        <!-- VISTA: Configuración de Estados -->
        <section id="estados-section" class="view-section">
            <div class="config-card">
                <h2 class="section-title">Estados de Gestión</h2>
                <p class="section-desc">Administra los estados disponibles para la gestión manual de tickets.</p>
                <table class="config-table">
                    <thead><tr><th>Nombre</th><th>Color</th><th>Orden</th><th>Activo</th><th>Acciones</th></tr></thead>
                    <tbody id="estados-body"></tbody>
                </table>
                <div class="add-form">
                    <input type="text" id="new-state-name" placeholder="Nombre del nuevo estado..." class="search-input">
                    <input type="color" id="new-state-color" value="#3b82f6" class="color-input">
                    <button class="btn btn-primary" onclick="addState()">+ Agregar Estado</button>
                </div>
            </div>
        </section>

        <!-- VISTA: Historial de Auditoría -->
        <section id="historial-section" class="view-section">
            <div class="config-card">
                <h2 class="section-title">Historial de Auditoría</h2>
                <div class="search-filters" style="margin-bottom:1.5rem; display:flex; gap:12px; align-items:center;">
                    <div style="flex:1; display:flex; gap:12px;">
                        <input type="text" id="historial-ticket" placeholder="Buscar por Ticket ID..." class="search-input" style="max-width:180px;">
                        <input type="date" id="historial-desde" class="search-input" style="width:145px;">
                        <span style="color:var(--text-secondary); align-self:center;">hasta</span>
                        <input type="date" id="historial-hasta" class="search-input" style="width:145px;">
                    </div>
                    <button class="btn btn-primary" onclick="resetAndLoadHistorial()" style="padding: 0.6rem 1.5rem;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        Filtrar Auditoría
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Fecha</th><th>Ticket</th><th>Campo</th><th>Acción</th></tr></thead>
                        <tbody id="historial-body"></tbody>
                    </table>
                </div>
                
                <div class="pagination-controls" style="display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; margin-top:1.5rem; gap:20px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:0.85rem; color:var(--text-secondary);">Ver</span>
                        <select id="historial-limit" class="search-input" style="width:70px; padding:0.3rem 0.5rem; height:auto; font-size:0.85rem;" onchange="resetAndLoadHistorial()">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span style="font-size:0.85rem; color:var(--text-secondary);">registros</span>
                    </div>

                    <div id="historial-pagination" style="display:flex; gap:5px; align-items:center;">
                        <!-- JS inyectará los números aquí -->
                    </div>

                    <div style="text-align:right;">
                        <span id="historial-info" style="font-size:0.85rem; color:var(--text-secondary); font-weight:500;">Mostrando 0 registros</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- VISTA: Ticket Insight Center -->
        <section id="ticket-insight-section" class="view-section">
            <div class="search-filters" style="margin-bottom:1rem; align-items:center;">
                <input type="number" id="insight-ticket-id" placeholder="ID del Ticket a Investigar..." class="search-input" style="max-width:300px; font-weight:bold;">
                <button class="btn btn-primary" onclick="loadTicketInsight()">Buscar</button>
            </div>
            
            <div id="insight-results" style="display:none;">
                <!-- Nuevo Header de Ticket -->
                <div class="insight-header-card">
                    <div class="insight-header-main">
                        <div class="insight-ticket-badge">#<span id="insight-header-id">-</span></div>
                        <div class="insight-title-group">
                            <h2 id="insight-header-resumen" class="insight-resumen">Cargando...</h2>
                            <div class="insight-meta-info">
                                <span class="insight-meta-item">📦 <strong id="insight-header-modulo">-</strong></span>
                                <span class="insight-meta-item">🏢 <strong id="insight-header-cliente">-</strong></span>
                                <span class="insight-meta-item">🛠️ <strong id="insight-header-tipo">-</strong></span>
                            </div>
                        </div>
                        <div id="insight-header-gravedad" class="badge">GRAVEDAD</div>
                    </div>
                </div>

                <div class="metrics-grid" style="margin-bottom:2rem;">
                    <div class="kpi-card insight-kpi" style="border-left: 4px solid var(--accent-primary);">
                        <div class="kpi-title">Sesiones de Trabajo</div>
                        <div class="kpi-value" id="insight-kpi-dias" style="color:var(--accent-primary)">0</div>
                        <div class="kpi-sub">Días distintos con actividad</div>
                    </div>
                    <div class="kpi-card insight-kpi" style="border-left: 4px solid var(--status-corregido);">
                        <div class="kpi-title">Inversión de Tiempo</div>
                        <div class="kpi-value" id="insight-kpi-dedicado" style="color:var(--status-corregido)">0h</div>
                        <div class="kpi-sub">Total acumulado histórico</div>
                    </div>
                    <div class="kpi-card insight-kpi" style="border-left: 4px solid var(--status-enproceso);">
                        <div class="kpi-title">Última Estimación</div>
                        <div class="kpi-value" id="insight-kpi-estimado" style="color:var(--status-enproceso)">0h</div>
                        <div class="kpi-sub">Tiempo previsto de resolución</div>
                    </div>
                    <div class="kpi-card insight-kpi" style="border-left: 4px solid var(--accent-secondary);">
                        <div class="kpi-title">Estado de Gestión</div>
                        <div class="kpi-value" id="insight-kpi-estado" style="font-size:1.6rem; color:var(--text-primary); padding-top:0.5rem;">-</div>
                        <div class="kpi-sub">Situación actual del sistema</div>
                    </div>
                </div>

                <div class="chart-grid-top" style="grid-template-columns: 2fr 1fr; margin-bottom: 2rem; gap:20px;">
                    <div class="chart-container insight-chart" style="min-height:350px;">
                        <div class="chart-title">Cronología de Esfuerzo (Minutos)</div>
                        <canvas id="chart-insight-tiempos"></canvas>
                    </div>
                    <div class="chart-container insight-chart" style="min-height:350px;">
                        <div class="chart-title">Frecuencia de Estados</div>
                        <canvas id="chart-insight-estados"></canvas>
                    </div>
                </div>

                <div class="config-card insight-log-card">
                    <div class="insight-log-header">
                        <h2 class="section-title">Historial Detallado de Eventos</h2>
                        <p class="section-desc">Auditoría completa de cambios realizados específicamente sobre este ticket.</p>
                    </div>
                    <div class="table-responsive">
                        <table class="insight-log-table">
                            <thead>
                                <tr>
                                    <th>Fecha y Hora</th>
                                    <th>Concepto</th>
                                    <th>Detalle de la Acción</th>
                                </tr>
                            </thead>
                            <tbody id="insight-historial-body"></tbody>
                        </table>
                    </div>

                    <!-- Paginación de Logs del Insight -->
                    <div class="pagination-controls" style="display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; margin-top:1.5rem; gap:20px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="font-size:0.85rem; color:var(--text-secondary);">Ver</span>
                            <select id="insight-log-limit" class="search-input" style="width:70px; padding:0.3rem 0.5rem; height:auto; font-size:0.85rem;" onchange="loadTicketInsight()">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                            </select>
                        </div>
                        <div id="insight-log-pagination" style="display:flex; gap:5px; align-items:center;"></div>
                        <div style="text-align:right;">
                            <span id="insight-log-info" style="font-size:0.85rem; color:var(--text-secondary); font-weight:500;">Mostrando 0 registros</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- VISTA: Resumen Mensual (Rediseñado) -->
        <section id="mensual-section" class="view-section">
            <div class="search-filters" style="margin-bottom:1.5rem; display:flex; gap:12px; align-items:center;">
                <select id="mensual-month-filter" class="search-input" style="width:220px; font-weight:700; border: 2px solid var(--accent-primary);"></select>
                <div style="flex:1; text-align:right; font-size:0.9rem; color:var(--text-secondary); font-weight:500;">
                    📊 Panel de Inteligencia Mensual
                </div>
            </div>

            <!-- Insights Grid -->
            <div class="metrics-grid" style="margin-bottom:2rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                <div class="kpi-card" style="border-top: 4px solid var(--accent-primary);">
                    <div class="kpi-title">Capacidad Total</div>
                    <div class="kpi-value" id="res-kpi-importados">0</div>
                    <div class="kpi-desc">Tickets en el radar</div>
                </div>
                <div class="kpi-card" style="border-top: 4px solid var(--status-corregido);">
                    <div class="kpi-title">Gestión Efectiva</div>
                    <div class="kpi-value" id="res-kpi-trabajados">0</div>
                    <div class="kpi-desc">Tickets con acción real</div>
                </div>
                <div class="kpi-card" style="border-top: 4px solid var(--status-enproceso);">
                    <div class="kpi-title">Eficiencia (Ratio)</div>
                    <div class="kpi-value" id="res-kpi-ratio">0%</div>
                    <div class="kpi-desc">Efectividad de cierre</div>
                </div>
                <div class="kpi-card" style="border-top: 4px solid var(--status-reabierto);">
                    <div class="kpi-title">Carga de Defectos</div>
                    <div class="kpi-value" id="res-kpi-defectos">0</div>
                    <div class="kpi-desc">Bugs corregidos</div>
                </div>
                <div class="kpi-card" style="border-top: 4px solid var(--status-espera);">
                    <div class="kpi-title">Retraso Promedio</div>
                    <div class="kpi-value" id="res-kpi-stale">0d</div>
                    <div class="kpi-desc">Días desde derivación</div>
                </div>
            </div>

            <div class="chart-grid-top" style="grid-template-columns: 2fr 1fr; margin-bottom: 2rem; gap:20px;">
                <div class="chart-container" style="min-height:350px;">
                    <div class="chart-title">Tendencia de Trabajo Diaria (Hitos)</div>
                    <canvas id="chart-mensual-trend"></canvas>
                </div>
                <div class="chart-container" style="min-height:350px;">
                    <div class="chart-title">Distribución de Solicitudes</div>
                    <canvas id="chart-mensual-tipos"></canvas>
                </div>
            </div>

            <div class="chart-grid-bottom" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:2rem;">
                <div class="chart-container" style="min-height:300px;">
                    <div class="chart-title">Progreso Semanal (Importados vs Trabajados)</div>
                    <canvas id="chart-mensual-semanal"></canvas>
                </div>
                <div class="chart-container" style="min-height:300px;">
                    <div class="chart-title">Patrón de Productividad por Día</div>
                    <canvas id="chart-mensual-patron"></canvas>
                </div>
            </div>
            
            <div class="config-card">
                <h2 class="section-title">Detalle Semanal del Mes</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Semana</th>
                                <th>Rango</th>
                                <th>Importados</th>
                                <th>Trabajados</th>
                                <th>Defectos</th>
                                <th>Requerimientos</th>
                                <th>Incidencia de Bugs (%)</th>
                            </tr>
                        </thead>
                        <tbody id="mensual-table-body"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- MODAL: Exportación de Reporte -->
        <div id="export-modal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Reporte</h3>
                    <button id="close-modal" class="btn-theme">&times;</button>
                </div>
                <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:1rem;">Copia el reporte para tus indicadores diarios:</p>
                <textarea id="export-text" readonly></textarea>
                <div class="modal-footer">
                    <button id="copy-report" class="btn btn-primary">Copiar al Portapapeles</button>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js?v=<?= time() ?>"></script>
</body>

</html>