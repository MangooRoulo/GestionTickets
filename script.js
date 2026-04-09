// Estado global de la aplicación
let tickets = [];
let estados = [];
let staleDaysLimit = 7;
let editMode = false; // Modo Restauración
let showManaged = false;
let selectedDate = '';
let charts = {};
let totalActivosFecha = 0; // Total de tickets importados en la fecha seleccionada
let historyOffset = 0;
let historyTotal = 0;

let insightLogOffset = 0;
let insightLogTotal = 0;

// URL base de la API
const API = 'api';

// Obtener fecha local en formato YYYY-MM-DD
function getLocalDate() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Convertir texto de tiempo a horas decimales (número puro = minutos)
function parseTimeToHours(val) {
    if (!val) return 0;
    val = val.toString().toLowerCase().trim();
    // Formato 1:30
    if (val.includes(':')) {
        const p = val.split(':');
        return (parseInt(p[0]) || 0) + (parseInt(p[1]) || 0) / 60;
    }
    // Formato con h y/o m (ej: 1h 30m)
    let h = 0, m = 0;
    const hM = val.match(/(\d+\.?\d*)h/);
    const mM = val.match(/(\d+\.?\d*)m/);
    if (hM) h = parseFloat(hM[1]);
    if (mM) m = parseFloat(mM[1]);
    if (hM || mM) return h + (m / 60);
    // Número puro = minutos por defecto
    return (parseFloat(val) || 0) / 60;
}

// Convertir horas decimales a texto legible (1h 30m)
function formatHoursToText(hours) {
    if (!hours || hours <= 0) return '';
    const totalMin = Math.round(hours * 60);
    const h = Math.floor(totalMin / 60);
    const m = totalMin % 60;
    let r = '';
    if (h > 0) r += `${h}h `;
    if (m > 0) r += `${m}m`;
    return r.trim() || '0m';
}

// Formatear fecha de Excel a YYYY-MM-DD
function formatDate(val) {
    if (!val) return '';
    if (val instanceof Date) return val.toISOString().split('T')[0];
    if (typeof val === 'string' && val.includes('-')) {
        const p = val.split('-');
        return p[0].length === 4 ? val : `${p[2]}-${p[1]}-${p[0]}`;
    }
    return val;
}

// Extraer días de texto (ej: "5 Días" -> 5)
function parseDays(val) {
    if (!val) return 0;
    if (typeof val === 'number') return val;
    const m = val.toString().match(/\d+/);
    return m ? parseInt(m[0]) : 0;
}

// Llamada GET genérica a la API
async function apiGet(endpoint) {
    const res = await fetch(`${API}/${endpoint}`);
    return res.json();
}

// Llamada POST/PUT/DELETE genérica a la API
async function apiSend(endpoint, method, body) {
    const res = await fetch(`${API}/${endpoint}`, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: body ? JSON.stringify(body) : null
    });
    return res.json();
}

// Elementos del DOM
const themeToggle = document.getElementById('theme-toggle');
const fileImport = document.getElementById('file-import');
const tabBtns = document.querySelectorAll('.tab-btn');
const sections = document.querySelectorAll('.view-section');
const tableBody = document.getElementById('table-body');
const searchInput = document.getElementById('search-text');
const filterEstado = document.getElementById('filter-estado');
const filterGravedad = document.getElementById('filter-gravedad');
const filterTipo = document.getElementById('filter-tipo');
const staleLimitInput = document.getElementById('stale-limit');
const showManagedCheckbox = document.getElementById('show-managed');
const filterDate = document.getElementById('filter-date');
const togglePrivacyBtn = document.getElementById('toggle-privacy');
const copyClosureBtn = document.getElementById('copy-closure');
const undoImportBtn = document.getElementById('undo-import');
const modal = document.getElementById('export-modal');
const closeModal = document.getElementById('close-modal');
const exportText = document.getElementById('export-text');
const copyBtn = document.getElementById('copy-report');

// Inicialización principal
async function init() {
    // Cargar tema guardado localmente
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }

    // Cargar configuración desde la base de datos
    try {
        const config = await apiGet('config.php');
        staleDaysLimit = parseInt(config.stale_days_limit) || 7;
        if (config.theme) {
            document.documentElement.setAttribute('data-theme', config.theme);
            localStorage.setItem('theme', config.theme);
        }
        privateMode = config.private_mode === 'true';
    } catch (e) {
        console.log('Usando configuración por defecto');
    }

    // Configurar controles iniciales
    staleLimitInput.value = staleDaysLimit;
    showManagedCheckbox.checked = showManaged;
    // Respetar fecha si el navegador la restauró
    filterDate.max = getLocalDate();
    if (!filterDate.value) {
        selectedDate = getLocalDate();
        filterDate.value = selectedDate;
    } else {
        selectedDate = filterDate.value;
    }
    updateStaleTitle();

    // Cargar estados de gestión desde BD
    await loadEstados();

    // Modo Restauración (Puzzle Icon)
    const restoreBtn = document.getElementById('restore-mode');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', () => {
            editMode = !editMode;
            restoreBtn.style.background = editMode ? 'var(--status-reabierto)' : 'rgba(239,68,68,0.1)';
            restoreBtn.style.color = editMode ? '#fff' : 'var(--status-reabierto)';
            renderTable();
        });
    }

    // Cargar tickets desde BD (respetando fecha si es pasada)
    await loadTickets(selectedDate !== getLocalDate() ? selectedDate : null);

    // Verificar si hay datos para migrar desde localStorage
    checkMigration();

    // Listener: cambio de fecha — recarga datos del servidor para la fecha seleccionada
    filterDate.addEventListener('change', async (e) => {
        const today = getLocalDate();
        let targetDate = e.target.value;
        if (targetDate > today) {
            targetDate = today;
            e.target.value = today;
        }
        selectedDate = targetDate;
        
        // Si la fecha seleccionada es hoy, cargamos sin parámetro (datos de hoy)
        // Si es una fecha pasada, cargamos con esa fecha para obtener gd_* históricos
        await loadTickets(selectedDate !== today ? selectedDate : null);
    });

    // Listener: modo privado
    togglePrivacyBtn.addEventListener('click', () => {
        privateMode = !privateMode;
        apiSend('config.php', 'PUT', { clave: 'private_mode', valor: String(privateMode) });
        updateKPIs();
    });

    // Listener: copiar cierre
    copyClosureBtn.addEventListener('click', copyClosureSummary);

    // Listener: deshacer última importación
    undoImportBtn.addEventListener('click', undoLastImport);

    // Listener: mostrar gestionados
    showManagedCheckbox.addEventListener('change', (e) => { showManaged = e.target.checked; renderTable(); });

    // Listener: días límite
    staleLimitInput.addEventListener('input', (e) => {
        staleDaysLimit = parseInt(e.target.value) || 7;
        apiSend('config.php', 'PUT', { clave: 'stale_days_limit', valor: String(staleDaysLimit) });
        updateStaleTitle();
        updateKPIs();
    });

    // Navegación de Calendario
    const prevDateBtn = document.getElementById('prev-date');
    const nextDateBtn = document.getElementById('next-date');
    if (prevDateBtn) prevDateBtn.addEventListener('click', () => {
        const d = new Date(selectedDate + 'T00:00:00');
        d.setDate(d.getDate() - 1);
        filterDate.value = d.toISOString().split('T')[0];
        filterDate.dispatchEvent(new Event('change'));
    });
    if (nextDateBtn) nextDateBtn.addEventListener('click', () => {
        const d = new Date(selectedDate + 'T00:00:00');
        d.setDate(d.getDate() + 1);
        const nextDateStr = d.toISOString().split('T')[0];
        
        if (nextDateStr > getLocalDate()) return; // No avanzar al futuro
        
        filterDate.value = nextDateStr;
        filterDate.dispatchEvent(new Event('change'));
    });

    // Listener: filtros de búsqueda en tiempo real
    [searchInput, filterEstado, filterGravedad, filterTipo].forEach(el => {
        if (el) el.addEventListener('input', renderTable);
    });

    // Listener: búsqueda por Enter en Insight Center
    const insightInput = document.getElementById('insight-ticket-id');
    if (insightInput) {
        insightInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') resetAndLoadInsight();
        });
    }
}

// Cargar estados de gestión desde la BD
async function loadEstados() {
    try {
        estados = await apiGet('estados.php');
    } catch (e) {
        estados = [{ id: 1, nombre: 'Pendiente', color: '#94a3b8' }];
    }
}

// Cargar tickets desde la BD con datos de gestión del día indicado
// fecha=null → usa hoy; fecha='YYYY-MM-DD' → carga gd_* de esa fecha
async function loadTickets(fecha = null) {
    try {
        const url = fecha ? `tickets.php?fecha=${fecha}` : 'tickets.php';
        const response = await apiGet(url);
        // La API devuelve { tickets: [...], total_activos: N }
        if (Array.isArray(response)) {
            // Retrocompatibilidad por si algo devuelve array plano
            tickets = response;
            totalActivosFecha = tickets.length;
        } else {
            tickets = response.tickets || [];
            totalActivosFecha = response.total_activos !== undefined ? response.total_activos : tickets.length;
        }
    } catch (e) {
        tickets = [];
        totalActivosFecha = 0;
    }
    populateFilterOptions();
    renderAll();
}

// Cambio de tema claro/oscuro
themeToggle.addEventListener('click', () => {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    apiSend('config.php', 'PUT', { clave: 'theme', valor: next });
    updateChartsTheme();
});

// Actualizar colores de gráficos según tema
function updateChartsTheme() {
    if (typeof Chart === 'undefined') return;
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const color = isDark ? '#94a3b8' : '#64748b';
    Chart.defaults.color = color;
    Object.values(charts).forEach(chart => {
        if (chart && chart.canvas && chart.canvas.ownerDocument && chart.options && chart.options.scales) {
            if (chart.options.scales.x && chart.options.scales.x.grid) chart.options.scales.x.grid.color = isDark ? '#334155' : '#e2e8f0';
            if (chart.options.scales.y && chart.options.scales.y.grid) chart.options.scales.y.grid.color = isDark ? '#334155' : '#e2e8f0';
            chart.update();
        }
    });
}

// Navegación entre pestañas
tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        tabBtns.forEach(b => b.classList.remove('active'));
        sections.forEach(s => s.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.view).classList.add('active');
        if (btn.dataset.view === 'dashboard') renderDashboard();
        if (btn.dataset.view === 'estados-section') renderEstadosUI();
        if (btn.dataset.view === 'historial-section') loadHistorial();
    });
});

// Importar archivo Excel
fileImport.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (event) => {
        const data = new Uint8Array(event.target.result);
        const wb = XLSX.read(data, { type: 'array', cellDates: true, dateNF: 'yyyy-mm-dd' });
        const sheet = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
        processImport(rows, file.name);
        fileImport.value = '';
    };
    reader.readAsArrayBuffer(file);
});

// Procesar e importar datos del Excel al servidor
async function processImport(rows, fileName) {
    if (rows.length < 2) return;

    const today = getLocalDate();
    let importDate = today;

    // Si el usuario está viendo una fecha pasada, preguntar si quiere importar para esa fecha
    if (selectedDate && selectedDate !== today) {
        const confirmMsg = `Estás consultando el historial del ${selectedDate}.\n\n¿Deseas que esta importación se registre para la fecha SELECCIONADA (${selectedDate})?\n\n- Aceptar: Importar para ${selectedDate}\n- Cancelar: Importar para HOY (${today})`;
        if (confirm(confirmMsg)) {
            importDate = selectedDate;
        } else {
            importDate = today;
        }
    }

    // Parsear cada fila del Excel a objeto
    const parsed = [];
    for (let i = 1; i < rows.length; i++) {
        const r = rows[i];
        if (!r || r.length < 2) continue;
        const rawId = String(r[1] || '').trim();
        const ticketId = parseInt(rawId.split(/[.,]/)[0]);
        if (isNaN(ticketId)) continue;
        parsed.push({
            id: ticketId,
            gravedad: r[0] || 'No definida',
            fecha_apertura: formatDate(r[2]),
            tipo_solicitud: r[3] || 'Desconocido',
            resumen: r[4] || '',
            estado_excel: String(r[5] || 'Abierto').trim(),
            cliente: r[6] || '',
            modulo: r[7] || '',
            componente: r[8] || '',
            responsable: r[9] || 'Sin asignar',
            ultima_actualizacion: formatDate(r[10]),
            dias_ultima_derivacion: parseDays(r[11]),
            puntaje: parseInt(r[12]) || 0,
            iteraciones: parseInt(r[13]) || 0,
            fecha_entrega: formatDate(r[14])
        });
    }

    // Enviar al servidor para sync/merge
    try {
        const result = await apiSend('tickets.php', 'POST', {
            archivo: fileName,
            tickets: parsed,
            fecha: importDate
        });
        if (result.error) { alert('Error: ' + result.error); return; }
        alert(`Sincronización Realizada para el ${importDate}.\n\n- Tickets en esta carga: ${result.activos}\n- Total histórico en sistema: ${result.total}\n\nInsertados: ${result.insertados}\nActualizados: ${result.actualizados}`);
        await loadTickets(selectedDate !== today ? selectedDate : null);
    } catch (e) {
        alert('Error de conexión con el servidor.');
    }
}

// Deshacer la última importación
async function undoLastImport() {
    try {
        // Obtener info de la última importación
        const last = await apiGet('importaciones.php');
        if (last.error) { alert(last.error); return; }
        // Confirmar con detalles
        const msg = `¿Deshacer la importación de "${last.archivo_nombre}"?\n\nFecha: ${last.fecha_importacion}\nInsertados: ${last.total_insertados}\nActualizados: ${last.total_actualizados}\n\nEsta acción restaurará el estado anterior.`;
        if (!confirm(msg)) return;
        // Ejecutar rollback
        const result = await apiSend('importaciones.php', 'DELETE');
        if (result.error) { alert('Error: ' + result.error); return; }
        alert('Importación deshecha correctamente.');
        await loadTickets();
    } catch (e) {
        alert('Error al deshacer importación.');
    }
}

// ════════════════════════════════════════════════════════════════
// Módulo de Respaldo Diario
// ════════════════════════════════════════════════════════════════

// Exportar todos los datos del día
function exportDailyBackup() {
    if (tickets.length === 0) return alert('No hay información en pantalla para respaldar.');
    
    // El respaldo toma exactamente la información actual del array tickets
    // incluyendo tiempos, IDs, etc que están vinculados a la fecha actual
    const backupDate = selectedDate || getLocalDate();
    
    // Crear el arreglo a mandar al excel
    const data = tickets.map(t => ({
        '_BACKUP_FECHA': backupDate, // Marca mágica para el importer
        'ID_TICKET': t.id,
        'ESTADO_GESTION_ID': t.gd_estado_gestion_id,
        'FUE_GESTIONADO': t.gd_fue_gestionado || 0,
        'TIEMPO_ESTIMADO': t.gd_tiempo_estimado || 0,
        'TIEMPO_DEDICADO': t.gd_tiempo_dedicado || 0,
        'RESUMEN': t.resumen,
        'MODULO': t.modulo
    }));

    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Backup_Data");
    XLSX.writeFile(wb, `TickMetrics_Respaldo_${backupDate}.xlsx`);
}

// Restaurar a partir de un respaldo del día
function restoreDailyBackup(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = async (e) => {
        try {
            const data = new Uint8Array(e.target.result);
            const wb = XLSX.read(data, { type: 'array' });
            const sheet = wb.Sheets[wb.SheetNames[0]];
            const json = XLSX.utils.sheet_to_json(sheet);

            if (json.length === 0) return alert("El archivo está vacío.");
            
            // Validar que sea un archivo de backup
            // Debe contener '_BACKUP_FECHA' y 'ID_TICKET'
            if (!json[0].hasOwnProperty('_BACKUP_FECHA') || !json[0].hasOwnProperty('ID_TICKET')) {
                return alert("El archivo proporcionado no es un archivo de respaldo de TickMetrics válido.");
            }

            const backupDate = json[0]['_BACKUP_FECHA'];
            
            if (!confirm(`Se va a reescribir toda la gestión diaria para la fecha: ${backupDate}.\n\n¿Deseas continuar?`)) {
                return;
            }

            // Llamar endpoint especial para purgar el día y reescribir todo
            const response = await apiSend('restaurar_backup.php', 'POST', {
                fecha: backupDate,
                filas: json
            });

            if (response.error) {
                alert("Error al restaurar: " + response.error);
            } else {
                alert(`Restauración exitosa para el día ${backupDate}.\nRegistros restaurados: ${response.restaurados}`);
                
                // Si la fecha seleccionada es la del backup, recargar los tickets
                if (selectedDate === backupDate || (!selectedDate && backupDate === getLocalDate())) {
                    await loadTickets(selectedDate !== getLocalDate() ? selectedDate : null);
                } else {
                    // Forzar que el UI salte a ese dia
                    filterDate.value = backupDate;
                    filterDate.dispatchEvent(new Event('change'));
                }
            }
        } catch (error) {
            console.error(error);
            alert("Ocurrió un error al procesar el archivo Excel.");
        } finally {
            event.target.value = ''; // Limpiar el input para volver a subir el mismo archivo si es necesario
        }
    };
    reader.readAsArrayBuffer(file);
}

// Renderizar todo asegurando que los KPIs se actualicen primero
function renderAll() {
    try {
        updateKPIs();
    } catch (e) {
        console.error('Error al actualizar KPIs:', e);
    }

    try {
        renderTable();
    } catch (e) {
        console.error('Error al renderizar tabla:', e);
    }

    try {
        renderDashboard();
    } catch (e) {
        console.error('Error al renderizar dashboard:', e);
    }
}

// Actualizar título de días límite
function updateStaleTitle() {
    const el = document.getElementById('stale-title');
    if (el) el.innerText = `>= ${staleDaysLimit} Días Sin Derivación`;
}

// Obtener ID del estado "Pendiente"
function getPendienteId() {
    const p = estados.find(e => e.nombre === 'Pendiente');
    return p ? p.id : 1;
}

// Actualizar KPIs del dashboard
function updateKPIs() {
    const today = getLocalDate();
    const dMode = selectedDate && selectedDate !== today;
    const pendienteId = getPendienteId();

    const totalEl = document.getElementById('kpi-total');
    const totalLabel = document.getElementById('kpi-total-label');
    const staleEl = document.getElementById('kpi-stale');
    const mgmtEl = document.getElementById('kpi-pending-mgmt');
    const mgmtLabel = document.getElementById('mgmt-kpi-label');
    const hoursEl = document.getElementById('kpi-daily-hours');

    if (dMode) {
        // --- MODO AUDITORÍA (Fecha Pasada) ---
        const hasData = totalActivosFecha > 0 || tickets.some(t => t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0);

        if (!hasData) {
            if (totalEl) {
                totalEl.innerText = '-';
                totalEl.style.color = 'var(--text-secondary)';
                if (totalEl.parentElement) {
                    totalEl.parentElement.style.border = '1px solid var(--border-color)';
                    totalEl.parentElement.style.background = 'var(--bg-color)';
                }
            }
            if (totalLabel) totalLabel.innerText = 'Sin Datos en esta Fecha';
            if (staleEl) staleEl.innerText = '-';
            if (mgmtEl) mgmtEl.innerText = '-';
            if (mgmtLabel) mgmtLabel.innerText = 'Trabajados ese Día';
            if (hoursEl) hoursEl.innerText = '-';
        } else {
            const universoFecha = totalActivosFecha > 0 ? totalActivosFecha : tickets.length;

            // Trabajados exactos de esa fecha (unificando criterio total)
            const trabajadosEnFecha = tickets.filter(t => t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0);
            const managedCount = trabajadosEnFecha.length;
            const totalHours = trabajadosEnFecha.reduce((acc, t) => acc + (parseFloat(t.gd_tiempo_dedicado) || 0), 0);

            // Dias Sin Derivación (Histórico): Usa historic_dias_ultima_derivacion de la BD (si null, fallback a default)
            const staleCount = tickets.filter(t => (t.historic_dias_ultima_derivacion !== null && t.historic_dias_ultima_derivacion !== undefined ? t.historic_dias_ultima_derivacion : t.dias_ultima_derivacion) >= staleDaysLimit).length;

            if (totalEl) {
                totalEl.innerText = universoFecha;
                totalEl.style.color = 'var(--accent-primary)';
                if (totalEl.parentElement) {
                    totalEl.parentElement.style.border = '1px solid var(--accent-primary)';
                    totalEl.parentElement.style.background = 'rgba(59,130,246,0.05)';
                }
            }
            if (totalLabel) totalLabel.innerText = 'Importados al Día';

            if (staleEl) staleEl.innerText = staleCount;
            if (mgmtEl) mgmtEl.innerText = managedCount;
            if (mgmtLabel) mgmtLabel.innerText = 'Trabajados ese Día';
            if (hoursEl) hoursEl.innerText = privateMode ? '****' : formatHoursToText(totalHours) || '0m';
        }

    } else {
        // --- MODO HOY ---
        const activosHoy = tickets.filter(t => t.es_activo == 1);

        // Trabajados Hoy en vivo (Cualquier ticket con tiempo, estado gestionado O marcado 'En Proceso')
        const trabajandoHoy = tickets.filter(t => t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0 || t.gd_en_proceso == 1);
        const managedCount = trabajandoHoy.length;

        // Tickets que cuentan como 'completados' hoy para restar de pendientes
        // Solo restamos si tiene tiempo/estado Y NO está marcado como 'en proceso'
        const efectivamenteCompletados = activosHoy.filter(t => (t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0) && t.gd_en_proceso != 1);
        const pendingCount = activosHoy.length - efectivamenteCompletados.length;

        const totalHours = trabajandoHoy.reduce((acc, t) => acc + (parseFloat(t.gd_tiempo_dedicado) || 0), 0);

        const staleCount = activosHoy.filter(t => t.dias_ultima_derivacion >= staleDaysLimit).length;

        if (totalEl) {
            totalEl.innerText = activosHoy.length; // Cambiado para mostrar la meta total (impo)
            totalEl.style.color = 'var(--status-reabierto)';
            if (totalEl.parentElement) {
                totalEl.parentElement.style.border = '1px solid var(--status-reabierto)';
                totalEl.parentElement.style.background = 'rgba(239,68,68,0.05)';
            }
        }
        if (totalLabel) totalLabel.innerText = 'Pendientes de Gestionar';

        if (staleEl) staleEl.innerText = staleCount;
        if (mgmtEl) mgmtEl.innerText = managedCount;
        if (mgmtLabel) mgmtLabel.innerText = 'Trabajados Hoy';
        if (hoursEl) hoursEl.innerText = privateMode ? '****' : formatHoursToText(totalHours);
    }

    if (togglePrivacyBtn) togglePrivacyBtn.querySelector('svg').style.opacity = privateMode ? '1' : '0.5';

    // El botón se abilita SOLAMENTE para el pasado histórico
    if (copyClosureBtn) copyClosureBtn.style.display = dMode ? 'flex' : 'none';
}

// Renderizar tabla de tickets
function renderTable() {
    const q = searchInput.value.toLowerCase();
    const fEstado = filterEstado.value;
    const fGravedad = filterGravedad.value;
    const fTipo = filterTipo.value;
    const today = getLocalDate();
    const dMode = selectedDate && selectedDate !== today;

    const filtered = tickets.filter(t => {
        const pId = getPendienteId();
        const effectivelyManaged = t.gd_fue_gestionado == 1 && t.gd_estado_gestion_id != pId;
        if (dMode) {
            if (!t.tad_id) return false;
            if (!showManaged && effectivelyManaged) return false;
        } else {
            // MODO HOY: Si está 'en proceso', SIEMPRE es visible (no importa showManaged)
            if (t.gd_en_proceso == 1) {
                // Forzar visibilidad
            } else {
                const visible = t.es_activo == 1 || t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0;
                if (!visible) return false;
                if (!showManaged && effectivelyManaged) return false;
            }
        }
        // Filtros de búsqueda
        const matchSearch = !q || (t.resumen || '').toLowerCase().includes(q) || String(t.id).includes(q) || (t.modulo || '').toLowerCase().includes(q);
        const matchEstado = !fEstado || t.estado_excel === fEstado;
        const matchGravedad = !fGravedad || t.gravedad === fGravedad;
        const matchTipo = !fTipo || t.tipo_solicitud === fTipo;
        return matchSearch && matchEstado && matchGravedad && matchTipo;
    });

    if (filtered.length === 0) {
        tableBody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:2rem;">No hay tickets ${dMode ? 'en esta fecha' : 'en la carga actual'}.</td></tr>`;
        return;
    }

    tableBody.innerHTML = filtered.map(t => {
        const canManage = t.gd_tiempo_estimado > 0 || editMode;
        const options = estados.filter(e => e.activo == 1).map(e =>
            `<option value="${e.id}" ${t.gd_estado_gestion_id == e.id ? 'selected' : ''}>${e.nombre}</option>`
        ).join('');

        const isManaged = t.gd_fue_gestionado == 1 && t.gd_estado_gestion_id != getPendienteId() && t.gd_en_proceso != 1;
        const today = getLocalDate();
        const hasPastMgmt = (t.fue_gestionado_manualmente == 1 && t.fecha_gestion && t.fecha_gestion !== today);
        const reactivatedBadge = (!dMode && !isManaged && hasPastMgmt)
            ? `<span title="Última gestión el ${t.fecha_gestion}" style="font-size:0.65rem; background:var(--accent-warning,#f59e0b); color:#fff; border-radius:4px; padding:1px 4px; margin-top:4px; display:inline-block;">↩ ${t.fecha_gestion}</span>`
            : '';

        return `
        <tr class="${isManaged ? 'row-managed' : ''} ${t.es_activo != 1 ? 'row-inactive' : ''}">
            <td>
                <div style="font-weight:700; color:var(--accent-primary);">#${t.id} | ${t.modulo}</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">${t.resumen} ${reactivatedBadge}</div>
            </td>
            <td><span class="badge ${getStatusClass(t.estado_excel)}">${t.estado_excel}</span></td>
            <td><span class="badge ${getGravedadClass(t.gravedad)}">${t.gravedad}</span></td>
            <td>
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:0.8rem; font-weight:600; color:var(--text-secondary); min-width:30px;">${formatHoursToText(t.gd_tiempo_estimado)}</span>
                    <input type="text" placeholder="+m" class="input-time" onchange="updateTicketTime(${t.id},'tiempo_estimado',this.value)" ${(dMode && !editMode) ? 'disabled' : ''} style="width:40px;">
                </div>
            </td>
            <td>
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:0.8rem; font-weight:600; color:var(--text-secondary); min-width:30px;">${formatHoursToText(t.gd_tiempo_dedicado)}</span>
                    <input type="text" placeholder="+m" class="input-time" onchange="updateTicketTime(${t.id},'tiempo_dedicado',this.value)" ${(dMode && !editMode) ? 'disabled' : ''} style="width:40px;">
                </div>
            </td>
            <td>
                <select class="select-gestion" data-id="${t.id}" onchange="updateGestionStatus(this)" ${(!canManage && !editMode) || (dMode && !editMode) ? 'disabled' : ''}>
                    ${options}
                </select>
            </td>
            <td style="text-align:center;">
                <input type="checkbox" title="Mantener En Proceso" style="width:16px;height:16px;cursor:pointer;" onchange="updateEnProcesoStatus(${t.id}, this.checked)" ${t.gd_en_proceso == 1 ? 'checked' : ''} ${(dMode && !editMode) ? 'disabled' : ''}>
            </td>
            <td style="${t.dias_ultima_derivacion >= staleDaysLimit ? 'color:var(--status-reabierto); font-weight:bold;' : ''}">${t.dias_ultima_derivacion}</td>
            <td>${t.puntaje}</td>
            <td style="font-size:0.8rem;">${t.fecha_entrega || '-'}</td>
        </tr>`;
    }).join('');
}

// Convertir entrada de tiempo a horas (base decimal)
function parseTimeToHours(str) {
    let s = str.toString().trim();
    if (!s) return 0;

    // Si contiene ":", es formato HH:MM
    if (s.includes(':')) {
        const [h, m] = s.split(':').map(Number);
        return (h || 0) + (m || 0) / 60;
    }

    const val = parseFloat(s);
    if (isNaN(val)) return 0;

    // REGLA DE ORO: Si el valor es > 5, asumimos que son MINUTOS
    // Si es <= 5, asumimos que ya son HORAS (ej: 0.5, 2, 4)
    return (val > 5) ? val / 60 : val;
}

// Actualizar tiempo de un ticket
async function updateTicketTime(id, field, inputValue) {
    const raw = inputValue.toString().trim();
    if (!raw) return;

    const ticket = tickets.find(t => t.id == id);
    if (!ticket) return;

    // Lógica Aditiva o Reemplazo
    const isAdditive = raw.startsWith('+');
    const cleanVal = isAdditive ? raw.substring(1) : raw;
    const addedHours = parseTimeToHours(cleanVal);

    const gdField = field === 'tiempo_estimado' ? 'gd_tiempo_estimado' : 'gd_tiempo_dedicado';
    const baseValue = isAdditive ? (parseFloat(ticket[gdField]) || 0) : 0;
    const finalValue = baseValue + addedHours;

    try {
        const today = getLocalDate();
        const url = (selectedDate && selectedDate !== today) ? `tickets.php?id=${id}&fecha=${selectedDate}` : `tickets.php?id=${id}`;
        const updatedTicket = await apiSend(url, 'PUT', { field, value: finalValue });

        if (updatedTicket.error) return alert(updatedTicket.error);

        const idx = tickets.findIndex(t => t.id == id);
        if (idx !== -1) tickets[idx] = updatedTicket;
        renderAll();
    } catch (e) {
        alert('Error al actualizar tiempo.');
    }
}

// Actualizar estado de gestión manual
async function updateGestionStatus(select) {
    const id = select.dataset.id;
    const value = select.value;
    try {
        const today = getLocalDate();
        const url = (selectedDate && selectedDate !== today) ? `tickets.php?id=${id}&fecha=${selectedDate}` : `tickets.php?id=${id}`;
        const ticket = await apiSend(url, 'PUT', { field: 'estado_gestion_id', value });
        if (ticket.error) return alert(ticket.error);
        const idx = tickets.findIndex(t => t.id == id);
        if (idx !== -1) tickets[idx] = ticket;
        renderAll();
    } catch (e) {
        alert('Error al actualizar estado.');
    }
}

// Actualizar bandera 'En Proceso' manual
async function updateEnProcesoStatus(id, isChecked) {
    try {
        const today = getLocalDate();
        const url = (selectedDate && selectedDate !== today) ? `tickets.php?id=${id}&fecha=${selectedDate}` : `tickets.php?id=${id}`;
        const ticket = await apiSend(url, 'PUT', { field: 'en_proceso', value: isChecked ? 1 : 0 });
        if (ticket.error) return alert(ticket.error);
        const idx = tickets.findIndex(t => t.id == id);
        if (idx !== -1) tickets[idx] = ticket;
        renderAll();
    } catch (e) {
        alert('Error al actualizar proceso.');
    }
}

// Clase CSS para badge de estado del Excel (ahora alineado con estados de gestión)
function getStatusClass(status) {
    const s = (status || '').toLowerCase();

    // Buscar coincidencia en los estados cargados de la BD
    const matchingDB = estados.find(e => s.includes(e.nombre.toLowerCase()));
    if (matchingDB) {
        // Mapeo tonal basado en el color de la BD
        const c = matchingDB.color.toLowerCase();
        if (c.includes('corregido') || c.includes('green') || c.includes('10b981')) return 'badge-tonal-green';
        if (c.includes('enproceso') || c.includes('orange') || c.includes('f59e0b')) return 'badge-tonal-orange';
        if (c.includes('reabierto') || c.includes('red') || c.includes('ef4444')) return 'badge-tonal-red';
        if (c.includes('blue') || c.includes('3b82f6')) return 'badge-tonal-blue';
    }

    // Fallbacks naturales
    if (s.includes('re-abierto') || s.includes('reabierto') || s.includes('devuelto') || s.includes('error')) return 'badge-tonal-red';
    if (s.includes('corregido') || s.includes('implementa') || s.includes('terminado') || s.includes('producci')) return 'badge-tonal-green';
    if (s.includes('proceso') || s.includes('desarrollo')) return 'badge-tonal-orange';

    return 'badge-tonal-slate';
}

// Clase CSS tonal para gravedad
function getGravedadClass(grav) {
    const g = (grav || '').toLowerCase();
    if (g.includes('muy grave')) return 'badge-tonal-red';
    if (g.includes('grave')) return 'badge-tonal-orange';
    if (g.includes('media')) return 'badge-tonal-blue';
    if (g.includes('leve')) return 'badge-tonal-green';
    return 'badge-tonal-slate';
}

// Poblar opciones de filtros con valores únicos
function populateFilterOptions() {
    const activos = tickets.filter(t => t.es_activo == 1);
    updateSelect('filter-estado', [...new Set(activos.map(t => t.estado_excel))].sort(), 'Todos los Estados');
    updateSelect('filter-gravedad', [...new Set(activos.map(t => t.gravedad))].sort(), 'Todas las Gravedades');
    updateSelect('filter-tipo', [...new Set(activos.map(t => t.tipo_solicitud))].sort(), 'Todos los Tipos');
}

// Actualizar un select con opciones
function updateSelect(id, items, defaultText) {
    const sel = document.getElementById(id);
    if (!sel) return;
    const val = sel.value;
    sel.innerHTML = `<option value="">${defaultText}</option>` + items.map(i => `<option value="${i}" ${i === val ? 'selected' : ''}>${i}</option>`).join('');
}

// Verificar si un ticket pertenece al módulo Informes
function isTicketInformes(t) {
    const m = (t.modulo || '').toLowerCase();
    const c = (t.componente || '').toLowerCase();
    const r = (t.resumen || '').toLowerCase();
    // Palabras clave de módulo o componente para Informes
    const keywords = ['informes', 'informe', 'boletines', 'boletin', 'boletín', 'exportador', 'schooltrack', 'accounttrack', 'mediatrack', 'analitic', 'analitica', 'analítica', 'analítico'];
    // Verificar si alguna keyword aparece en módulo, componente o resumen
    for (const kw of keywords) {
        if (m.includes(kw) || c.includes(kw)) return true;
    }
    // Verificar componentes específicos que contienen "plg" + "colegium" + "informes"
    //if (c.includes('plg') && c.includes('colegium')) return true;
    // Verificar "exportador de datos" en resumen
    if (r.includes('exportador de datos')) return true;
    return false;
}

// Generar reporte (Informes o General)
function generateReport(isInformes) {
    const dateToFilter = selectedDate || getLocalDate();
    const pendienteId = getPendienteId();
    // Utilizar gd_fue_gestionado del día consultado
    const dataSet = tickets.filter(t => t.gd_fue_gestionado == 1);
    const filtered = dataSet.filter(t => {
        const match = isTicketInformes(t);
        return isInformes ? match : !match;
    });
    if (filtered.length === 0) return alert(`No hay tickets para ${isInformes ? 'Informes' : 'General'} en ${dateToFilter}.`);
    const stateName = (id) => { const e = estados.find(s => s.id == id); return e ? e.nombre : 'Pendiente'; };
    exportText.value = filtered.map(t => `${t.id} - ${stateName(t.gd_estado_gestion_id)}`).join('\n');
    modal.classList.add('active');
    document.querySelector('.modal-header h3').innerText = `Reporte: ${isInformes ? 'Módulo Informes' : 'Áreas Generales'} (${dateToFilter})`;
}

// Copiar resumen de cierre diario
function copyClosureSummary() {
    if (!selectedDate) return;
    // QA FIX: Incluir los tickets con horas registradas (unificado)
    const day = tickets.filter(t => t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0);
    if (day.length === 0) return alert('No hay tickets trabajados en esta fecha.');
    const totalH = day.reduce((a, t) => a + (parseFloat(t.gd_tiempo_dedicado) || 0), 0);
    const stateName = (id) => { const e = estados.find(s => s.id == id); return e ? e.nombre : '?'; };
    const summary = `📊 CIERRE DE GESTIÓN - ${selectedDate}\n-----------------------------------\n✅ Tickets Trabajados: ${day.length}\n⏱️ Tiempo Total: ${formatHoursToText(totalH)}\n\nDetalle:\n` +
        day.map(t => `- [#${t.id}] ${t.modulo}: ${t.resumen} (${stateName(t.gd_estado_gestion_id)})`).join('\n') +
        '\n\nGenerado por TickMetrics Hub';
    navigator.clipboard.writeText(summary).then(() => alert('Resumen copiado al portapapeles.'));
}

// Exportar historial a Excel
document.getElementById('export-history').addEventListener('click', () => {
    const managed = tickets.filter(t => t.fue_gestionado_manualmente == 1);
    if (managed.length === 0) return alert('No hay tickets gestionados para exportar.');
    const stateName = (id) => { const e = estados.find(s => s.id == id); return e ? e.nombre : '?'; };
    const data = managed.map(t => ({
        'ID': t.id, 'Resumen': t.resumen, 'Módulo': t.modulo, 'Estado Gestión': stateName(t.estado_gestion_id),
        'Estimado': formatHoursToText(t.tiempo_estimado), 'Dedicado': formatHoursToText(t.tiempo_dedicado),
        'Fecha Gestión': t.fecha_gestion, 'Gravedad': t.gravedad
    }));
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Historial');
    XLSX.writeFile(wb, `Gestion_${new Date().toISOString().split('T')[0]}.xlsx`);
});

// Listeners de reportes y modal
document.getElementById('export-informes').onclick = () => generateReport(true);
document.getElementById('report-otros').onclick = () => generateReport(false);
closeModal.onclick = () => modal.classList.remove('active');
window.onclick = (e) => { if (e.target === modal) modal.classList.remove('active'); };
copyBtn.onclick = () => { exportText.select(); document.execCommand('copy'); alert('Copiado.'); };

// Renderizar gráficos del dashboard
function renderDashboard() {
    if (tickets.length === 0) return;
    const today = getLocalDate();
    const dMode = selectedDate && selectedDate !== today;
    // Modo hoy: activos + gestionados HOY
    // Modo fecha: todos los que estuvieron en la importación (tad_id)
    const dataSet = dMode
        ? tickets.filter(t => t.tad_id)
        : tickets.filter(t => t.es_activo == 1 || t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0);
        
    createChart('chart-estado', 'doughnut', 'x', dataSet, 'estado_excel');
    
    createGroupedGestionTipoChart('chart-gestion-tipo', dataSet);
    createTiempoTipoChart('chart-tiempo-tipo', dataSet);
    createEstimadoVsDedicadoChart('chart-tiempo-comparativo', dataSet);
    
    createChart('chart-derivacion', 'bar', 'y', dataSet, dMode ? 'historic_dias_ultima_derivacion' : 'dias_ultima_derivacion', true);
    createHistoricoChart();
    updateChartsTheme();
}

function createGroupedGestionTipoChart(id, dataSet) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    if (charts[id]) charts[id].destroy();
    
    const typesMap = {};
    dataSet.forEach(t => { 
        const type = t.tipo_solicitud ?? 'Sin definir';
        if (!typesMap[type]) typesMap[type] = { importados: 0, gestionados: 0 };
        typesMap[type].importados++;
        // Si fue gestionado (tiempo o estado), suma 1 a gestión
        if (t.gd_fue_gestionado == 1 || parseFloat(t.gd_tiempo_dedicado) > 0 || parseFloat(t.gd_tiempo_estimado) > 0) {
            typesMap[type].gestionados++;
        }
    });

    const labels = Object.keys(typesMap);
    const dataImportados = labels.map(l => typesMap[l].importados);
    const dataGestionados = labels.map(l => typesMap[l].gestionados);

    charts[id] = new Chart(canvas, {
        type: 'bar', 
        data: { 
            labels: labels, 
            datasets: [
                { label: 'Importados', data: dataImportados, backgroundColor: '#cbd5e1', borderRadius: 4 },
                { label: 'Revisados', data: dataGestionados, backgroundColor: '#3b82f6', borderRadius: 4 }
            ] 
        },
        options: { 
            indexAxis: 'x', 
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });
}

function createTiempoTipoChart(id, dataSet) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    if (charts[id]) charts[id].destroy();
    
    const typesMap = {};
    dataSet.forEach(t => { 
        const type = t.tipo_solicitud ?? 'Sin definir';
        if (!typesMap[type]) typesMap[type] = 0;
        typesMap[type] += parseFloat(t.gd_tiempo_dedicado || 0);
    });

    const labels = Object.keys(typesMap);
    const dataHours = labels.map(l => typesMap[l]);

    charts[id] = new Chart(canvas, {
        type: 'bar', 
        data: { 
            labels: labels, 
            datasets: [
                { label: 'Horas Dedicadas', data: dataHours, backgroundColor: '#10b981', borderRadius: 4 }
            ] 
        },
        options: { 
            indexAxis: 'x', 
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return formatHoursToText(context.raw) || '0m';
                        }
                    }
                }
            },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Horas' } } }
        }
    });
}

function createEstimadoVsDedicadoChart(id, dataSet) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    if (charts[id]) charts[id].destroy();
    
    const typesMap = {};
    dataSet.forEach(t => { 
        const type = t.tipo_solicitud ?? 'Sin definir';
        if (!typesMap[type]) typesMap[type] = { estimado: 0, dedicado: 0 };
        typesMap[type].estimado += parseFloat(t.gd_tiempo_estimado || 0);
        typesMap[type].dedicado += parseFloat(t.gd_tiempo_dedicado || 0);
    });

    const labels = Object.keys(typesMap);
    const dataEstimado = labels.map(l => typesMap[l].estimado);
    const dataDedicado = labels.map(l => typesMap[l].dedicado);

    charts[id] = new Chart(canvas, {
        type: 'bar', 
        data: { 
            labels: labels, 
            datasets: [
                { label: 'Tiempo Estimado', data: dataEstimado, backgroundColor: '#f59e0b', borderRadius: 4 },
                { label: 'Tiempo Dedicado', data: dataDedicado, backgroundColor: '#10b981', borderRadius: 4 }
            ] 
        },
        options: { 
            indexAxis: 'x', 
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { 
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + (formatHoursToText(context.raw) || '0m');
                        }
                    }
                }
            },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Horas' } } }
        }
    });
}

// Crear un gráfico genérico con Chart.js
function createChart(id, type, axis, dataSet, field, noEvents) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    if (charts[id]) charts[id].destroy();
    const counts = {};
    dataSet.forEach(t => { const v = t[field] ?? 'Sin definir'; counts[v] = (counts[v] || 0) + 1; });
    const isDona = type === 'doughnut';
    const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#6366f1'];
    charts[id] = new Chart(canvas, {
        type, data: { labels: Object.keys(counts), datasets: [{ data: Object.values(counts), backgroundColor: isDona ? palette : '#3b82f6', borderRadius: isDona ? 0 : 5 }] },
        options: { indexAxis: axis, responsive: true, maintainAspectRatio: false, events: noEvents ? [] : ['mousemove', 'mouseout', 'click', 'touchstart', 'touchmove'], plugins: { legend: { display: isDona, position: 'bottom' }, tooltip: { enabled: !noEvents } } }
    });
}

// Crear gráfico histórico de productividad
async function createHistoricoChart() {
    if (charts['chart-historico']) charts['chart-historico'].destroy();
    try {
        const histData = await apiGet('gestion_diaria.php?tipo=historico');
        if (!histData || histData.error) return;

        const labels = histData.map(d => d.fecha);
        const data = histData.map(d => parseInt(d.cantidad));

        const canvas = document.getElementById('chart-historico');
        if (!canvas) return;
        charts['chart-historico'] = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Gestión Manual',
                    data: data,
                    borderColor: '#6366f1',
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    } catch (e) {
        console.error('Error al cargar histórico:', e);
    }
}

// Renderizar interfaz CRUD de estados
function renderEstadosUI() {
    const body = document.getElementById('estados-body');
    if (!body) return;
    body.innerHTML = estados.map(e => `
        <tr>
            <td><input type="text" value="${e.nombre}" id="sn-${e.id}" ${e.nombre === 'Pendiente' ? 'disabled' : ''}></td>
            <td><input type="color" value="${e.color}" id="sc-${e.id}" class="color-input"></td>
            <td><input type="number" value="${e.orden}" id="so-${e.id}" style="width:60px;" class="input-time"></td>
            <td>${e.activo == 1 ? '✅' : '❌'}</td>
            <td>
                <button class="btn-icon save" onclick="saveState(${e.id})" title="Guardar">💾</button>
                ${e.nombre !== 'Pendiente' ? `<button class="btn-icon" onclick="deleteState(${e.id})" title="Eliminar">🗑️</button>` : ''}
            </td>
        </tr>
    `).join('');
}

// Agregar nuevo estado
window.addState = async function () {
    const name = document.getElementById('new-state-name').value.trim();
    const color = document.getElementById('new-state-color').value;
    if (!name) return alert('Ingresa un nombre.');
    const result = await apiSend('estados.php', 'POST', { nombre: name, color: color });
    if (result.error) return alert(result.error);
    document.getElementById('new-state-name').value = '';
    await loadEstados();
    renderEstadosUI();
};

// Guardar cambios en un estado
window.saveState = async function (id) {
    const nombre = document.getElementById(`sn-${id}`).value.trim();
    const color = document.getElementById(`sc-${id}`).value;
    const orden = parseInt(document.getElementById(`so-${id}`).value) || 0;
    const result = await apiSend(`estados.php?id=${id}`, 'PUT', { nombre, color, orden });
    if (result.error) return alert(result.error);
    await loadEstados();
    alert('Estado actualizado.');
};

// Eliminar un estado
window.deleteState = async function (id) {
    if (!confirm('¿Eliminar este estado?')) return;
    const result = await apiSend(`estados.php?id=${id}`, 'DELETE');
    if (result.error) return alert(result.error);
    await loadEstados();
    renderEstadosUI();
};

window.resetAndLoadHistorial = function() {
    historyOffset = 0;
    loadHistorial();
}

window.goToPage = function(pageNum) {
    const limit = parseInt(document.getElementById('historial-limit').value) || 25;
    historyOffset = (pageNum - 1) * limit;
    loadHistorial();
}

function renderPagination(total, limit, currentOffset) {
    const nav = document.getElementById('historial-pagination');
    if (!nav) return;
    
    const totalPages = Math.ceil(total / limit);
    const currentPage = Math.floor(currentOffset / limit) + 1;
    
    let html = '';
    
    // Botón Inicio y Anterior
    html += `<button class="page-btn" onclick="goToPage(1)" ${currentPage === 1 ? 'disabled' : ''} title="Primera página">«</button>`;
    html += `<button class="page-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} title="Anterior">‹</button>`;
    
    // Números de página (Lógica de ventana 1-2 [3] 4-5)
    let start = Math.max(1, currentPage - 2);
    let end = Math.min(totalPages, start + 4);
    if (end - start < 4) start = Math.max(1, end - 4);
    
    if (start > 1) html += `<span class="page-dots">...</span>`;
    
    for (let i = start; i <= end; i++) {
        html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }
    
    if (end < totalPages) html += `<span class="page-dots">...</span>`;
    
    // Botón Siguiente y Último
    html += `<button class="page-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''} title="Siguiente">›</button>`;
    html += `<button class="page-btn" onclick="goToPage(${totalPages})" ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''} title="Última página">»</button>`;
    
    nav.innerHTML = html;
}

// Cargar historial de auditoría
async function loadHistorial() {
    const ticketId = document.getElementById('historial-ticket')?.value || '';
    const desde = document.getElementById('historial-desde')?.value || '';
    const hasta = document.getElementById('historial-hasta')?.value || '';
    const limit = parseInt(document.getElementById('historial-limit')?.value) || 25;
    
    let url = `historial.php?limit=${limit}&offset=${historyOffset}`;
    if (ticketId) url += `&ticket_id=${ticketId}`;
    if (desde) url += `&desde=${desde}`;
    if (hasta) url += `&hasta=${hasta}`;
    
    try {
        const response = await apiGet(url);
        const data = response.data || [];
        historyTotal = response.total || 0;
        
        const body = document.getElementById('historial-body');
        const info = document.getElementById('historial-info');
        const btnPrev = document.getElementById('historial-prev');
        const btnNext = document.getElementById('historial-next');
        
        if (!body) return;
        
        if (data.length === 0) { 
            body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:2rem;">Sin registros.</td></tr>'; 
            if (info) info.innerText = 'Mostrando 0 registros';
            if (btnPrev) btnPrev.disabled = true;
            if (btnNext) btnNext.disabled = true;
            return; 
        }
        
        if (info) info.innerText = `Mostrando ${historyOffset + 1} a ${Math.min(historyOffset + limit, historyTotal)} de ${historyTotal} registros`;
        
        // Renderizar nueva paginación
        renderPagination(historyTotal, limit, historyOffset);
        
        body.innerHTML = data.map(h => {
             let accionTexto = '';
             let prev = h.valor_anterior;
             let newV = h.valor_nuevo;
             
             if (h.campo_modificado.startsWith('estado_gestion_id')) {
                 const ePrev = estados.find(s => s.id == prev);
                 const eNew = estados.find(s => s.id == newV);
                 accionTexto = `<span style="color:var(--text-secondary)">${ePrev ? ePrev.nombre : (prev || 'Pendiente')}</span> <strong style="color:var(--accent-primary)">➔</strong> <span style="font-weight:600">${eNew ? eNew.nombre : newV}</span>`;
             } else if (h.campo_modificado.startsWith('tiempo_estimado') || h.campo_modificado.startsWith('tiempo_dedicado')) {
                 const minVAnterior = prev === '*' ? 0 : Math.round(parseFloat(prev || 0) * 60);
                 const minVNuevo = Math.round(parseFloat(newV || 0) * 60);
                 const sumado = minVNuevo - minVAnterior;
                 
                 const sumadoStr = sumado > 0 ? `+${sumado}` : `${sumado}`;
                 const styleSumado = sumado > 0 ? "color:var(--status-corregido)" : "color:var(--status-danger)";
                 
                 if (minVAnterior === 0) {
                     accionTexto = `Carga inicial: 0 min ➔ sumado <strong style="${styleSumado}">${sumadoStr} min</strong> ➔ Nuevo tiempo: <strong>${minVNuevo} min</strong>`;
                 } else {
                     accionTexto = `Venía trabajando ${minVAnterior} min ➔ sumado <strong style="${styleSumado}">${sumadoStr} min</strong> ➔ Nuevo tiempo: <strong>${minVNuevo} min</strong>`;
                 }
             } else if (h.campo_modificado.startsWith('en_proceso')) {
                 const isNew = newV == 1;
                 const badgeColor = isNew ? 'var(--status-corregido)' : 'var(--text-secondary)';
                 accionTexto = `En Proceso: ${prev == 1 ? 'SÍ' : 'NO'} ➔ <strong style="color:${badgeColor}; font-weight:bold;">${isNew ? 'SÍ' : 'NO'}</strong>`;
             } else {
                 accionTexto = `${prev} ➔ ${newV}`;
             }
             
             return `
            <tr>
                <td style="white-space:nowrap; font-size:0.8rem">${h.fecha_cambio}</td>
                <td style="font-weight:bold; color:var(--accent-primary)">#${h.ticket_id}</td>
                <td><span class="badge" style="background:rgba(59,130,246,0.1); color:#3b82f6; text-transform:uppercase">${h.campo_modificado.replace('_', ' ')}</span></td>
                <td>${accionTexto}</td>
            </tr>
        `}).join('');
    } catch (e) {
        console.error('Error cargando historial:', e);
    }
}

// Verificar si hay datos en localStorage para migrar
function checkMigration() {
    const localData = localStorage.getItem('tickets_data');
    if (localData) {
        document.getElementById('migration-banner').style.display = 'flex';
    }
}

// Migrar datos de localStorage a la base de datos
window.migrateData = async function () {
    const localData = JSON.parse(localStorage.getItem('tickets_data') || '[]');
    if (localData.length === 0) return;
    // Transformar datos locales al formato de la API
    const parsed = localData.map(t => ({
        id: t.id,
        gravedad: t.gravedad || 'No definida',
        fecha_apertura: t.fechaApertura || '',
        tipo_solicitud: t.tipoSolicitud || 'Desconocido',
        resumen: t.resumen || '',
        estado_excel: t.estado || 'Abierto',
        cliente: t.cliente || '',
        modulo: t.modulo || '',
        componente: t.componente || '',
        responsable: t.responsable || 'Sin asignar',
        ultima_actualizacion: t.ultimaActualizacion || '',
        dias_ultima_derivacion: t.diasUltimaDerivacion || 0,
        puntaje: t.puntaje || 0,
        iteraciones: t.iteraciones || 0,
        fecha_entrega: t.fechaEntrega || ''
    }));
    try {
        const result = await apiSend('tickets.php', 'POST', { archivo: 'migracion_localStorage', tickets: parsed });
        if (result.error) { alert('Error en migración: ' + result.error); return; }
        // Migrar estados de gestión manual (PUT individual para preservar tiempos)
        for (const t of localData) {
            if (t.tiempoEstimado > 0) await apiSend(`tickets.php?id=${t.id}`, 'PUT', { field: 'tiempo_estimado', value: t.tiempoEstimado });
            if (t.tiempoDedicado > 0) await apiSend(`tickets.php?id=${t.id}`, 'PUT', { field: 'tiempo_dedicado', value: t.tiempoDedicado });
            // Buscar estado de gestión por nombre
            if (t.estadoGestion && t.estadoGestion !== 'Pendiente') {
                const estado = estados.find(e => e.nombre === t.estadoGestion);
                if (estado) await apiSend(`tickets.php?id=${t.id}`, 'PUT', { field: 'estado_gestion_id', value: estado.id });
            }
        }
        // Limpiar localStorage
        localStorage.removeItem('tickets_data');
        document.getElementById('migration-banner').style.display = 'none';
        alert(`Migración completada: ${result.insertados} insertados, ${result.actualizados} actualizados.`);
        await loadTickets();
    } catch (e) {
        alert('Error de conexión durante migración.');
    }
};

// Descartar migración
window.dismissMigration = function () {
    document.getElementById('migration-banner').style.display = 'none';
};

// -- TICKET INSIGHT CENTER --
window.resetAndLoadInsight = function() {
    insightLogOffset = 0;
    loadTicketInsight();
}

window.goToInsightPage = function(pageNum) {
    const limit = parseInt(document.getElementById('insight-log-limit').value) || 10;
    insightLogOffset = (pageNum - 1) * limit;
    loadTicketInsight();
}

function renderPaginationInsight(total, limit, currentOffset) {
    const nav = document.getElementById('insight-log-pagination');
    if (!nav) return;
    
    const totalPages = Math.ceil(total / limit);
    const currentPage = Math.floor(currentOffset / limit) + 1;
    
    let html = '';
    
    html += `<button class="page-btn" onclick="goToInsightPage(1)" ${currentPage === 1 ? 'disabled' : ''} title="Primero">«</button>`;
    html += `<button class="page-btn" onclick="goToInsightPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} title="Atrás">‹</button>`;
    
    let start = Math.max(1, currentPage - 1);
    let end = Math.min(totalPages, start + 2);
    if (end - start < 2) start = Math.max(1, end - 2);
    
    for (let i = start; i <= end; i++) {
        html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToInsightPage(${i})">${i}</button>`;
    }
    
    html += `<button class="page-btn" onclick="goToInsightPage(${currentPage + 1})" ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''} title="Siguiente">›</button>`;
    html += `<button class="page-btn" onclick="goToInsightPage(${totalPages})" ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''} title="Último">»</button>`;
    
    nav.innerHTML = html;
}

window.loadTicketInsight = async function() {
    const id = document.getElementById('insight-ticket-id').value;
    if (!id) return alert('Inserta un ID primero');
    
    const limit = parseInt(document.getElementById('insight-log-limit').value) || 10;

    try {
        const res = await apiGet(`ticket_insight.php?ticket_id=${id}&limit=${limit}&offset=${insightLogOffset}`);
        // Renderizar la vista
        document.getElementById('insight-results').style.display = 'block';

        // 1. KPIs
        document.getElementById('insight-kpi-dias').innerText = res.totales.dias_distintos;
        document.getElementById('insight-kpi-dedicado').innerText = formatHoursToText(res.totales.tiempo_dedicado_total);
        document.getElementById('insight-kpi-estimado').innerText = formatHoursToText(res.totales.tiempo_estimado_actual);
        document.getElementById('insight-kpi-estado').innerText = res.ticket.estado_gestion_nombre || 'Desconocido';

        // 2. Gráficas
        renderInsightCharts(res.dias_trabajados, res.auditoria);

        // 3. Tabla Log
        const tbody = document.getElementById('insight-historial-body');
        const infoLog = document.getElementById('insight-log-info');
        insightLogTotal = res.total_auditoria || 0;

        if (infoLog) infoLog.innerText = `Mostrando ${insightLogOffset + 1} a ${Math.min(insightLogOffset + limit, insightLogTotal)} de ${insightLogTotal}`;
        renderPaginationInsight(insightLogTotal, limit, insightLogOffset);

        if (!res.auditoria || res.auditoria.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">Sin registro de auditoría</td></tr>';
        } else {
            tbody.innerHTML = res.auditoria.map(h => {
             let accionTexto = '';
             let prev = h.valor_anterior;
             let newV = h.valor_nuevo;
             
             if (h.campo_modificado.startsWith('estado_gestion_id')) {
                 const ePrev = estados.find(s => s.id == prev);
                 const eNew = estados.find(s => s.id == newV);
                 accionTexto = `${ePrev ? ePrev.nombre : (prev || 'Pendiente')} ➔ ${eNew ? eNew.nombre : newV}`;
             } else if (h.campo_modificado.startsWith('tiempo_estimado') || h.campo_modificado.startsWith('tiempo_dedicado')) {
                 const minVAnterior = prev === '*' ? 0 : Math.round(parseFloat(prev || 0) * 60);
                 const minVNuevo = Math.round(parseFloat(newV || 0) * 60);
                 const sumado = minVNuevo - minVAnterior;
                 accionTexto = `Anterior: ${minVAnterior} min ➔ Nuevo total: ${minVNuevo} min (${sumado > 0 ? '+'+sumado : sumado} min)`;
             } else if (h.campo_modificado.startsWith('en_proceso')) {
                 accionTexto = `En Proceso: ${prev == 1 ? 'SÍ' : 'NO'} ➔ ${newV == 1 ? 'SÍ' : 'NO'}`;
             } else {
                 accionTexto = `${prev} ➔ ${newV}`;
             }

             // Color dinámico por campo
             let badgeColor = '#475569';
             let badgeBg = '#e2e8f0';
             if (h.campo_modificado.includes('estado')) { badgeBg = 'rgba(59,130,246,0.1)'; badgeColor = '#3b82f6'; }
             if (h.campo_modificado.includes('tiempo')) { badgeBg = 'rgba(16,185,129,0.1)'; badgeColor = '#10b981'; }
             if (h.campo_modificado.includes('proceso')) { badgeBg = 'rgba(139,92,246,0.1)'; badgeColor = '#8b5cf6'; }

             return `
               <tr>
                  <td style="font-size:0.8rem">${h.fecha_cambio}</td>
                  <td><span class="badge" style="background:${badgeBg}; color:${badgeColor};">${h.campo_modificado.replace('_', ' ')}</span></td>
                  <td>${accionTexto}</td>
               </tr>`;
            }).join('');
        }

    } catch (e) {
        alert('No se pudo encontrar el ticket en el sistema.');
    }
}

function renderInsightCharts(diasTrabajados, auditoria) {
    // A. Chart Tiempos (Bar)
    const ctxTime = document.getElementById('chart-insight-tiempos');
    if (charts['chart-insight-tiempos']) charts['chart-insight-tiempos'].destroy();

    const labelsTime = diasTrabajados.map(d => d.fecha);
    const dataTime = diasTrabajados.map(d => parseFloat(d.tiempo_dedicado));

    charts['chart-insight-tiempos'] = new Chart(ctxTime, {
        type: 'line',
        data: {
            labels: labelsTime.length ? labelsTime : ['Sin datos'],
            datasets: [{
                label: 'Horas Dedicadas Hito Diario',
                data: dataTime.length ? dataTime : [0],
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
        }
    });

    // B. Chart Variación Estados (Doughnut)
    const ctxStates = document.getElementById('chart-insight-estados');
    if (charts['chart-insight-estados']) charts['chart-insight-estados'].destroy();

    const statusMap = {};
    let hasStates = false;
    auditoria.filter(a => a.campo_modificado === 'estado_gestion_id').forEach(a => {
        const estadoNombre = (estados.find(s => s.id == a.valor_nuevo) || {nombre: a.valor_nuevo}).nombre;
        if (!statusMap[estadoNombre]) statusMap[estadoNombre] = 0;
        statusMap[estadoNombre]++;
        hasStates = true;
    });

    charts['chart-insight-estados'] = new Chart(ctxStates, {
        type: 'doughnut',
        data: {
            labels: hasStates ? Object.keys(statusMap) : ['Sin movimientos'],
            datasets: [{
                data: hasStates ? Object.values(statusMap) : [1],
                backgroundColor: hasStates ? Object.keys(statusMap).map((_,i) => ['#8b5cf6','#f59e0b','#10b981','#ef4444','#3b82f6'][i%5]) : ['#e2e8f0']
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false
        }
    });
}

// Iniciar la aplicación
init();
