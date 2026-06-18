<?php
$BASE = 'https://vecinet-production.up.railway.app';

function apiGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($result && $httpCode === 200) ? $result : '[]';
}

function apiPost($url, $body) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ?: '{"success":false,"mensaje":"Error de conexion"}';
}

session_start();

$loginError = '';
$loggedIn   = isset($_SESSION['usuario']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['pass'] ?? '';
    if ($email && $pass) {
        $respuesta = apiPost($BASE . '/login', ['email' => $email, 'password' => $pass]);
        $data      = json_decode($respuesta, true);
        if ($data && $data['success']) {
            $rol        = strtolower($data['rol'] ?? '');
            $autorizado = str_contains($rol, 'presidente') || str_contains($rol, 'comite') ||
                          str_contains($rol, 'encargado')  || str_contains($rol, 'guardia');
            if ($autorizado) {
                $_SESSION['usuario'] = [
                    'nombre'     => $data['nombre']     ?? '',
                    'rol'        => $data['rol']        ?? '',
                    'email'      => $email,
                    'fotoPerfil' => $data['fotoPerfil'] ?? null
                ];
                $loggedIn = true;
            } else {
                $loginError = 'ACCESO DENEGADO: El rol "' . htmlspecialchars($data['rol']) . '" no tiene acceso a este panel.';
            }
        } else {
            $loginError = 'ERROR: ' . htmlspecialchars($data['mensaje'] ?? 'Credenciales incorrectas.');
        }
    } else {
        $loginError = 'Ingrese correo y contrasena.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$reportesJson = '[]';
$usuariosJson = '[]';
$pagosJson    = '[]';
$bitacoraJson = '[]';
if ($loggedIn) {
    $reportesJson = apiGet($BASE . '/reportes');
    $usuariosJson = apiGet($BASE . '/usuarios');
    $pagosJson    = apiGet($BASE . '/vigilancia/pagos');
    $bitacoraJson = apiGet($BASE . '/bitacora');
}

$usuario = $_SESSION['usuario'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistema Semaforo Vecinal - VeciNet</title>
<link rel="stylesheet" href="css/styles.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
#btnNotif{position:relative;background:none;border:none;cursor:pointer;font-size:22px;padding:4px 6px;line-height:1;}
#notifBadge{display:none;position:absolute;top:-2px;right:-2px;background:#cc0000;color:white;border-radius:50%;font-size:10px;font-weight:bold;min-width:16px;height:16px;line-height:16px;text-align:center;padding:0 3px;font-family:Arial;pointer-events:none;}
#notifPanel{display:none;position:absolute;top:100%;right:0;width:340px;background:var(--bg-modal);border:2px solid var(--color-border);box-shadow:0 4px 16px rgba(0,0,0,0.3);z-index:2000;max-height:480px;overflow-y:auto;}
#notifPanel.on{display:block;}
.notif-head{background:#2c4a6e;color:white;padding:8px 14px;font-size:13px;font-weight:bold;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;}
.notif-head button{background:none;border:1px solid rgba(255,255,255,0.4);color:white;font-size:11px;cursor:pointer;padding:2px 8px;font-family:Arial;}
.notif-item{padding:10px 14px;border-bottom:1px solid var(--color-border2);cursor:pointer;transition:background .15s;}
.notif-item:hover{background:var(--bg-table-hover);}
.notif-item.unread{border-left:4px solid #2c4a6e;}
.notif-item.unread.tipo-rojo{border-left-color:#cc0000;}
.notif-titulo{font-size:13px;font-weight:bold;color:var(--color-text);margin-bottom:2px;}
.notif-sub{font-size:11px;color:var(--color-text2);}
.notif-hora{font-size:10px;color:var(--color-text3);margin-top:2px;text-align:right;}
.notif-empty{padding:24px;text-align:center;color:var(--color-text2);font-size:13px;font-style:italic;}
#notifWrapper{position:relative;}
.casa-tag{display:inline-block;background:#e8f0f7;border:1px solid #2c4a6e;color:#2c4a6e;font-size:11px;font-weight:bold;padding:1px 7px;}
body.dark .casa-tag{background:#0d2137;border-color:#4a90d9;color:#4a90d9;}
.badge-vigente{background:#006600;color:white;font-size:11px;font-weight:bold;padding:2px 8px;border-radius:2px;display:inline-block;}
.badge-pendiente{background:#cc0000;color:white;font-size:11px;font-weight:bold;padding:2px 8px;border-radius:2px;display:inline-block;}
.badge-entrada{background:#2c4a6e;color:white;font-size:11px;font-weight:bold;padding:2px 8px;border-radius:2px;display:inline-block;}
.badge-salida{background:#664488;color:white;font-size:11px;font-weight:bold;padding:2px 8px;border-radius:2px;display:inline-block;}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div id="pantallaLogin" style="display:flex;">
  <button id="btnTemaLogin" onclick="toggleTema()" title="Cambiar tema"></button>
  <div class="login-box">
    <div class="login-header">
      <div class="login-semaforo"><span class="s-r"></span><span class="s-y"></span><span class="s-g"></span></div>
      <h1>ACCESO SISTEMA VECINET</h1>
    </div>
    <div class="login-body">
      <form method="POST" action="">
        <input type="hidden" name="action" value="login" />
        <div class="form-row"><label>Correo Electronico</label><input type="email" name="email" placeholder="usuario@vecinet.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required /></div>
        <div class="form-row"><label>Contrasena</label><input type="password" name="pass" required /></div>
        <button type="submit" class="btn-login">INICIAR SESION</button>
        <?php if ($loginError): ?><div class="login-error" style="display:block;"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<div id="dashboard" style="display:flex;flex-direction:column;min-height:100vh;">
  <header>
    <div class="header-top">
      <span>SISTEMA SEMAFORO VECINAL - VECINET | Fraccionamiento Vista Real</span>
      <span id="hFecha"></span>
    </div>
    <div class="header-main">
      <div class="header-logo">
        <div class="header-semaforo"><span class="s-r"></span><span class="s-y"></span><span class="s-g"></span></div>
        <div class="header-title">
          <h1>SEMAFORO VECINAL</h1>
          <p>Panel de Control &mdash; <span id="hRol"><?= htmlspecialchars(strtoupper($usuario['rol'])) ?></span></p>
        </div>
      </div>
      <div class="header-user">
        <div style="text-align:right;">
          <div class="user-name"><?= htmlspecialchars(strtoupper($usuario['nombre'])) ?></div>
          <div class="user-rol"><?= htmlspecialchars(strtoupper($usuario['rol'])) ?></div>
        </div>
        <div id="accWrapper">
          <button id="btnAccesibilidad" onclick="toggleAccPanel()" title="Opciones de accesibilidad">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 6c-2.61.7-5.67 1-8.5 1s-5.89-.3-8.5-1L3 8c1.86.5 4 .83 6 1v13h2v-6h2v6h2V9c2-.17 4.14-.5 6-1l-.5-2zM12 6c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z"/></svg>
          </button>
          <div id="accPanel">
            <div class="acc-head"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M20.5 6c-2.61.7-5.67 1-8.5 1s-5.89-.3-8.5-1L3 8c1.86.5 4 .83 6 1v13h2v-6h2v6h2V9c2-.17 4.14-.5 6-1l-.5-2zM12 6c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z"/></svg> ACCESIBILIDAD</div>
            <div class="acc-sec">TEMA</div>
            <div class="acc-row" onclick="toggleTema()">
              <div class="acc-row-left"><div class="acc-indicator" id="accTemaIcon" style="background:transparent;font-size:18px;"></div><div class="acc-text-wrap"><span class="acc-label" id="accTemaLabel">Modo claro</span><span class="acc-desc">Toca para cambiar</span></div></div>
              <label class="acc-switch" onclick="event.stopPropagation()"><input type="checkbox" id="switchTema" onchange="toggleTema()" /><span class="acc-slider"></span></label>
            </div>
            <div class="acc-sec">CONTRASTE DE TEXTO</div>
            <div class="acc-row" onclick="toggleContraste()">
              <div class="acc-row-left"><div class="acc-indicator" id="accContrastIcon" style="background:transparent;border:2px solid var(--color-border);color:var(--color-text);font-size:14px;font-weight:bold;">A</div><div class="acc-text-wrap"><span class="acc-label" id="accContrastLabel">Contraste normal</span><span class="acc-desc" id="accContrastDesc">Toca para aumentar</span></div></div>
              <label class="acc-switch" onclick="event.stopPropagation()"><input type="checkbox" id="switchContraste" onchange="toggleContraste()" /><span class="acc-slider"></span></label>
            </div>
            <div class="acc-sec">VISION DE COLOR</div>
            <div class="acc-opcion activo" id="opt-none" onclick="setDaltonism('none')"><div class="acc-dot" style="background:#888;"></div><div class="acc-opcion-text"><span class="acc-opcion-nombre">Sin filtro</span><span class="acc-opcion-desc">Vision estandar</span></div><span class="acc-check" id="chk-none">&#x2713;</span></div>
            <div class="acc-opcion" id="opt-protanopia" onclick="setDaltonism('protanopia')"><div class="acc-dot" style="background:#E87722;"></div><div class="acc-opcion-text"><span class="acc-opcion-nombre">Protanopia</span><span class="acc-opcion-desc">Dificultad con el rojo</span></div><span class="acc-check" id="chk-protanopia" style="display:none;">&#x2713;</span></div>
            <div class="acc-opcion" id="opt-deuteranopia" onclick="setDaltonism('deuteranopia')"><div class="acc-dot" style="background:#0088CC;"></div><div class="acc-opcion-text"><span class="acc-opcion-nombre">Deuteranopia</span><span class="acc-opcion-desc">Dificultad con el verde</span></div><span class="acc-check" id="chk-deuteranopia" style="display:none;">&#x2713;</span></div>
            <div class="acc-opcion" id="opt-tritanopia" onclick="setDaltonism('tritanopia')"><div class="acc-dot" style="background:#FF7700;"></div><div class="acc-opcion-text"><span class="acc-opcion-nombre">Tritanopia</span><span class="acc-opcion-desc">Dificultad con azul-amarillo</span></div><span class="acc-check" id="chk-tritanopia" style="display:none;">&#x2713;</span></div>
          </div>
        </div>
        <div id="notifWrapper">
          <button id="btnNotif" onclick="toggleNotifPanel()" title="Notificaciones">&#x1F514;<span id="notifBadge">0</span></button>
          <div id="notifPanel">
            <div class="notif-head"><span>NOTIFICACIONES</span><button onclick="marcarTodasLeidas()">Marcar todo como leido</button></div>
            <div id="notifLista"><div class="notif-empty">Sin notificaciones nuevas</div></div>
          </div>
        </div>
        <form method="POST" action="" style="margin:0;"><input type="hidden" name="action" value="logout" /><button type="submit" class="btn-salir">SALIR</button></form>
      </div>
    </div>
  </header>
  <div class="layout"><nav id="menu"></nav><main id="contenido"></main></div>
  <footer>&copy; 2026 VeciNet - Sistema Integral de Vigilancia Vecinal | Todos los derechos reservados</footer>
</div>
<?php endif; ?>

<!-- MODALES -->
<div class="modal-bg" id="mDetalle"><div class="modal-box"><div class="modal-head"><h3>DETALLE DEL REPORTE</h3><button class="modal-x" onclick="cerrar('mDetalle')">&times;</button></div><div class="modal-body" id="mDetalleBody"></div><div class="modal-foot"><button class="btn" onclick="cerrar('mDetalle')">Cerrar</button></div></div></div>

<div class="modal-bg" id="mNuevo">
  <div class="modal-box">
    <div class="modal-head"><h3>REGISTRAR NUEVO USUARIO</h3><button class="modal-x" onclick="cerrar('mNuevo')">&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-field"><label>Nombre Completo</label><input type="text" id="nNombre" /></div>
        <div class="form-field"><label>Correo Electronico</label><input type="email" id="nEmail" /></div>
        <div class="form-field"><label>Telefono</label><input type="text" id="nTel" /></div>
        <div class="form-field"><label>Contrasena Inicial</label><input type="password" id="nPass" /></div>
        <div class="form-field form-full"><label>Rol del Usuario</label>
          <select id="nRol" onchange="toggleCerradaNuevo()">
            <option value="Presidente de Comite">Presidente de Comite</option>
            <option value="Comite">Comite</option>
            <option value="Encargado de Cerrada">Encargado de Cerrada</option>
            <option value="Residente">Residente</option>
            <option value="Guardia">Guardia</option>
          </select>
        </div>
        <div class="form-field form-full campo-cerrada" id="nCerradaWrap"><label>Cerrada</label>
          <select id="nCerrada"><option value="">-- Seleccionar --</option><option value="Boulevard">Boulevard</option><option value="Cerrada 1">Cerrada 1</option><option value="Cerrada 2">Cerrada 2</option><option value="Cerrada 3">Cerrada 3</option><option value="Cerrada 4">Cerrada 4</option><option value="Cerrada 5">Cerrada 5</option><option value="Cerrada 6">Cerrada 6</option><option value="Cerrada 7">Cerrada 7</option></select>
        </div>
        <div class="form-field form-full campo-cerrada" id="nCasaWrap"><label>Numero de casa</label>
          <select id="nCasa"><option value="">-- Sin asignar --</option><?php for($i=1;$i<=50;$i++) echo "<option value=\"$i\">Casa $i</option>"; ?></select>
        </div>
      </div>
      <div id="nMsg"></div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="cerrar('mNuevo')">Cancelar</button><button class="btn btn-p" onclick="guardarNuevo()">GUARDAR USUARIO</button></div>
  </div>
</div>

<div class="modal-bg" id="mEditar">
  <div class="modal-box">
    <div class="modal-head"><h3>EDITAR USUARIO</h3><button class="modal-x" onclick="cerrar('mEditar')">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="eId" />
      <div class="form-grid">
        <div class="form-field"><label>Nombre Completo</label><input type="text" id="eNombre" /></div>
        <div class="form-field"><label>Correo Electronico</label><input type="email" id="eEmail" /></div>
        <div class="form-field"><label>Telefono</label><input type="text" id="eTel" /></div>
        <div class="form-field"><label>Nueva Contrasena</label><input type="password" id="ePass" placeholder="Dejar vacio para no cambiar" /></div>
        <div class="form-field form-full"><label>Rol del Usuario</label>
          <select id="eRol" onchange="toggleCerradaEditar()">
            <option value="Presidente de Comite">Presidente de Comite</option>
            <option value="Comite">Comite</option>
            <option value="Encargado de Cerrada">Encargado de Cerrada</option>
            <option value="Residente">Residente</option>
            <option value="Guardia">Guardia</option>
          </select>
        </div>
        <div class="form-field form-full campo-cerrada" id="eCerradaWrap"><label>Cerrada</label>
          <select id="eCerrada"><option value="">-- Seleccionar --</option><option value="Boulevard">Boulevard</option><option value="Cerrada 1">Cerrada 1</option><option value="Cerrada 2">Cerrada 2</option><option value="Cerrada 3">Cerrada 3</option><option value="Cerrada 4">Cerrada 4</option><option value="Cerrada 5">Cerrada 5</option><option value="Cerrada 6">Cerrada 6</option></select>
        </div>
        <div class="form-field form-full campo-cerrada" id="eCasaWrap"><label>Numero de casa</label>
          <select id="eCasa"><option value="">-- Sin asignar --</option><?php for($i=1;$i<=50;$i++) echo "<option value=\"$i\">Casa $i</option>"; ?></select>
        </div>
      </div>
      <div id="eMsg"></div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="cerrar('mEditar')">Cancelar</button><button class="btn btn-p" onclick="guardarEdicion()">GUARDAR CAMBIOS</button></div>
  </div>
</div>

<div class="modal-bg" id="mEliminar">
  <div class="modal-box" style="max-width:420px;">
    <div class="modal-head"><h3>CONFIRMAR ELIMINACION</h3><button class="modal-x" onclick="cerrar('mEliminar')">&times;</button></div>
    <div class="modal-body">
      <p style="font-size:14px;margin-bottom:12px;">Esta a punto de eliminar al usuario:</p>
      <div style="background:#ffd0d0;border:1px solid #cc0000;padding:10px;font-weight:bold;" id="eNombreConfirm"></div>
      <p style="font-size:12px;margin-top:10px;">Esta accion no se puede deshacer.</p>
      <div id="delMsg"></div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="cerrar('mEliminar')">Cancelar</button><button class="btn btn-d" onclick="confirmarEliminar()">ELIMINAR USUARIO</button></div>
  </div>
</div>

<div class="modal-bg" id="mPago">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-head"><h3>REGISTRAR PAGO DE VIGILANCIA</h3><button class="modal-x" onclick="cerrar('mPago')">&times;</button></div>
    <div class="modal-body">
      <p style="font-size:13px;margin-bottom:16px;color:var(--color-text2);">El estado cambiara a <strong>VIGENTE</strong> por 30 dias automaticamente.</p>
      <div class="form-field form-full"><label>Seleccionar Residente</label>
        <select id="pEmailResidente" style="width:100%;padding:8px;border:1px solid var(--color-border);background:var(--bg-input);color:var(--color-text);font-size:13px;"><option value="">-- Seleccionar residente --</option></select>
      </div>
      <div id="pMsg" style="margin-top:8px;font-size:12px;"></div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="cerrar('mPago')">Cancelar</button><button class="btn btn-p" onclick="confirmarPago()">REGISTRAR PAGO</button></div>
  </div>
</div>

<div class="modal-bg" id="mFoto" onclick="cerrar('mFoto')"><img id="mFotoImg" style="max-width:90vw;max-height:90vh;border:3px solid #888;" /></div>

<script>
// @ts-nocheck
const BASE = 'https://vecinet-production.up.railway.app';
let reportes = <?= $reportesJson ?>;
let usuarios = <?= $usuariosJson ?>;
let pagos    = <?= $pagosJson ?>;
let bitacora = <?= $bitacoraJson ?>;

<?php if ($loggedIn): ?>
let usuario = {
    nombre:     "<?= htmlspecialchars($usuario['nombre'], ENT_QUOTES) ?>",
    rol:        "<?= htmlspecialchars($usuario['rol'],    ENT_QUOTES) ?>",
    email:      "<?= htmlspecialchars($usuario['email'],  ENT_QUOTES) ?>",
    fotoPerfil: <?= $usuario['fotoPerfil'] ? '"'.htmlspecialchars($usuario['fotoPerfil'], ENT_QUOTES).'"' : 'null' ?>
};
<?php else: ?>
let usuario = null;
<?php endif; ?>

let idEliminar = null, filtroRolActivo = 'todos';
let filtroEstadFechaInicio = null;
let filtroEstadFechaFin = null;
let filtroEstadTipo = 'todos';

// ── TEMA ──────────────────────────────────────────────────
let temaOscuro = localStorage.getItem('vecinet_tema') === 'oscuro';
function aplicarTema() {
    document.body.classList.toggle('dark', temaOscuro);
    const icono = temaOscuro ? '\u2600' : '\uD83C\uDF19';
    const b2 = document.getElementById('btnTemaLogin');
    if (b2) b2.textContent = icono;
    const sw = document.getElementById('switchTema');
    const lb = document.getElementById('accTemaLabel');
    const ic = document.getElementById('accTemaIcon');
    if (sw) sw.checked = temaOscuro;
    if (lb) lb.textContent = temaOscuro ? 'Modo oscuro' : 'Modo claro';
    if (ic) ic.textContent = temaOscuro ? '\u2600' : '\uD83C\uDF19';
}
function toggleTema() { temaOscuro = !temaOscuro; localStorage.setItem('vecinet_tema', temaOscuro ? 'oscuro' : 'claro'); aplicarTema(); reRenderActivo(); }
aplicarTema();

// ── CONTRASTE ─────────────────────────────────────────────
let highContrast = localStorage.getItem('vecinet_contraste') === 'true';
function aplicarContraste() {
    document.body.classList.toggle('high-contrast', highContrast);
    const sw = document.getElementById('switchContraste');
    const lb = document.getElementById('accContrastLabel');
    const desc = document.getElementById('accContrastDesc');
    const ic = document.getElementById('accContrastIcon');
    if (sw) sw.checked = highContrast;
    if (lb) lb.textContent = highContrast ? 'Alto contraste' : 'Contraste normal';
    if (desc) desc.textContent = highContrast ? 'Texto maximo legible' : 'Toca para aumentar';
    if (ic) { ic.style.background = highContrast ? '#000' : 'transparent'; ic.style.color = highContrast ? '#fff' : 'var(--color-text)'; ic.style.border = highContrast ? '2px solid #000' : '2px solid var(--color-border)'; }
}
function toggleContraste() { highContrast = !highContrast; localStorage.setItem('vecinet_contraste', highContrast); aplicarContraste(); }
aplicarContraste();

// ── DALTONISMO ────────────────────────────────────────────
let daltonismMode = localStorage.getItem('vecinet_daltonism') || 'none';
const PALETTE = {
    none:         { r:'#cc0000', a:'#cc8800', v:'#006600', ro:'#ff5252', ao:'#ffd740', vo:'#69f0ae', rBg:'#ffd0d0', aBg:'#fff3cc', vBg:'#d0f0d0', rBd:'#cc0000', aBd:'#cc8800', vBd:'#006600' },
    protanopia:   { r:'#E87722', a:'#cc8800', v:'#006600', ro:'#FF9A3C', ao:'#ffd740', vo:'#69f0ae', rBg:'#ffe8d0', aBg:'#fff3cc', vBg:'#d0f0d0', rBd:'#E87722', aBd:'#cc8800', vBd:'#006600' },
    deuteranopia: { r:'#cc0000', a:'#cc8800', v:'#0088CC', ro:'#ff5252', ao:'#ffd740', vo:'#40B4FF', rBg:'#ffd0d0', aBg:'#fff3cc', vBg:'#d0e8f8', rBd:'#cc0000', aBd:'#cc8800', vBd:'#0088CC' },
    tritanopia:   { r:'#cc0000', a:'#FF7700', v:'#009988', ro:'#ff5252', ao:'#FF9933', vo:'#1DE9B6', rBg:'#ffd0d0', aBg:'#ffe0cc', vBg:'#d0f0ee', rBd:'#cc0000', aBd:'#FF7700', vBd:'#009988' },
};
function cR()  { const p=PALETTE[daltonismMode]; return temaOscuro ? p.ro : p.r; }
function cA()  { const p=PALETTE[daltonismMode]; return temaOscuro ? p.ao : p.a; }
function cV()  { const p=PALETTE[daltonismMode]; return temaOscuro ? p.vo : p.v; }
function cRBg(){ return PALETTE[daltonismMode].rBg; }
function cABg(){ return PALETTE[daltonismMode].aBg; }
function cVBg(){ return PALETTE[daltonismMode].vBg; }
function cRBd(){ return PALETTE[daltonismMode].rBd; }
function cABd(){ return PALETTE[daltonismMode].aBd; }
function cVBd(){ return PALETTE[daltonismMode].vBd; }
function colorNivel(n) { if(n==3||n==='3') return cR(); if(n==2||n==='2') return cA(); return cV(); }
function aplicarDaltonism() {
    ['none','protanopia','deuteranopia','tritanopia'].forEach(k => {
        const opt = document.getElementById('opt-'+k);
        const chk = document.getElementById('chk-'+k);
        if (opt) opt.classList.toggle('activo', k === daltonismMode);
        if (chk) chk.style.display = k === daltonismMode ? '' : 'none';
    });
    reRenderActivo();
}
function setDaltonism(mode) { daltonismMode = mode; localStorage.setItem('vecinet_daltonism', mode); aplicarDaltonism(); }
aplicarDaltonism();

// ── ACCESIBILIDAD PANEL ───────────────────────────────────
function toggleAccPanel() { const p=document.getElementById('accPanel'); if(p) p.classList.toggle('on'); }
document.addEventListener('click', function(e) {
    const w = document.getElementById('accWrapper');
    if (w && !w.contains(e.target)) { const p=document.getElementById('accPanel'); if(p) p.classList.remove('on'); }
});
function reRenderActivo() {
    const activo = document.querySelector('nav#menu a.activo');
    if (!activo) return;
    const sec = activo.id.replace('nav-', '');
    const fns = { monitor:renderMonitor, reportesMapa:renderReportesMapa, reportesLst:renderReportesLista, estadisticas:renderEstadisticas, usuarios:renderUsuarios, vigilancia:renderVigilancia, bitacora:renderBitacora };
    if (fns[sec]) fns[sec]();
}

// ── NOTIFICACIONES ────────────────────────────────────────
let ultimoIdConocido = reportes.length > 0 ? Math.max(...reportes.map(r=>parseInt(r.id)||0)) : 0;
let notificaciones = [];
function agregarNotif(r) {
    const hora = new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'});
    notificaciones.unshift({ id:r.id, titulo:'\uD83D\uDEA8 Nuevo reporte: '+r.tipo, sub:'Nivel: '+nivelT(r.nivel)+' \u2014 '+r.usuarioNombre, hora, leida:false, tipoClass:r.nivel==3?'tipo-rojo':'tipo-normal' });
}
function renderNotifPanel() {
    const lista = document.getElementById('notifLista');
    const badge = document.getElementById('notifBadge');
    if(!lista||!badge) return;
    const noLeidas = notificaciones.filter(n=>!n.leida).length;
    badge.textContent = noLeidas > 9 ? '9+' : String(noLeidas);
    badge.style.display = noLeidas > 0 ? 'block' : 'none';
    lista.innerHTML = notificaciones.length ? notificaciones.map((n,i)=>`<div class="notif-item ${n.leida?'':'unread'} ${n.tipoClass}" onclick="clickNotif(${i})"><div class="notif-titulo">${n.titulo}</div><div class="notif-sub">${n.sub}</div><div class="notif-hora">${n.hora}</div></div>`).join('') : '<div class="notif-empty">Sin notificaciones nuevas</div>';
}
function clickNotif(idx) { notificaciones[idx].leida=true; toggleNotifPanel(); renderNotifPanel(); navegar('reportesLst'); }
function marcarTodasLeidas() { notificaciones.forEach(n=>n.leida=true); renderNotifPanel(); }
function toggleNotifPanel() { const p=document.getElementById('notifPanel'); if(p) p.classList.toggle('on'); }
document.addEventListener('click',function(e){ const w=document.getElementById('notifWrapper'); if(w&&!w.contains(e.target)){const p=document.getElementById('notifPanel');if(p)p.classList.remove('on');} });
async function pollNotificaciones() {
    try {
        const res = await fetch(BASE+'/reportes');
        const data = await res.json();
        if(!Array.isArray(data)) return;
        reportes = data;
        const nuevos = data.filter(r=>(parseInt(r.id)||0)>ultimoIdConocido);
        if(nuevos.length > 0) {
            ultimoIdConocido = Math.max(...data.map(r=>parseInt(r.id)||0));
            nuevos.sort((a,b)=>(parseInt(a.id)||0)-(parseInt(b.id)||0)).forEach(r=>agregarNotif(r));
            renderNotifPanel();
            const activo = document.querySelector('nav#menu a.activo');
            if(activo&&activo.id==='nav-reportesLst') renderReportesLista();
            if(activo&&activo.id==='nav-monitor') renderMonitor();
        }
    } catch(e) {}
}

// ── HELPERS ───────────────────────────────────────────────
setInterval(()=>{ const el=document.getElementById('hFecha'); if(el) el.textContent=new Date().toLocaleString('es-MX'); },1000);
async function fetchReportes(){try{const r=await fetch(BASE+'/reportes');reportes=await r.json();}catch(e){}}
async function fetchUsuarios(){try{const r=await fetch(BASE+'/usuarios');usuarios=await r.json();}catch(e){}}
async function fetchPagos(){try{const r=await fetch(BASE+'/vigilancia/pagos');pagos=await r.json();}catch(e){}}
async function fetchBitacora(){try{const r=await fetch(BASE+'/bitacora');bitacora=await r.json();}catch(e){}}
function esPresidente(){ return usuario?.rol?.toLowerCase().includes('presidente'); }
function esComite(){ const r=usuario?.rol?.toLowerCase(); return r?.includes('comite')||r?.includes('encargado'); }
function esGuardia(){ return usuario?.rol?.toLowerCase().includes('guardia'); }
function ubicacionTag(email) {
    const u = usuarios.find(x => x.email === email);
    if(!u) return '<span style="font-style:italic;color:#aaa;">-</span>';
    const p = [];
    if(u.numeroCasa) p.push('Casa '+u.numeroCasa);
    if(u.cerrada)    p.push(u.cerrada);
    return p.length ? `<span class="casa-tag">${p.join(' \u2014 ')}</span>` : '<span style="font-style:italic;color:#aaa;">-</span>';
}

// ── NAVEGACION ────────────────────────────────────────────
const SECS = {
    codigos:     { label:'Codigos de Acceso',    p:true,  c:true,  g:false },
    monitor:     { label:'Monitor en Vivo',       p:true,  c:true,  g:true  },
    reportesMapa:{ label:'Reportes en Mapa',      p:true,  c:true,  g:true  },
    reportesLst: { label:'Lista de Reportes',     p:true,  c:true,  g:true  },
    estadisticas:{ label:'Estadisticas',          p:true,  c:true,  g:false },
    usuarios:    { label:'Gestion de Usuarios',   p:true,  c:false, g:false },
    emergencias: { label:'Emergencias',           p:true,  c:true,  g:true  },
    vigilancia:  { label:'Pagos de Vigilancia',   p:true,  c:true,  g:false },
    bitacora:    { label:'Bitacora de Acceso',    p:true,  c:true,  g:true  },
};
function tieneAcceso(sec){ if(esPresidente()) return sec.p; if(esComite()) return sec.c; if(esGuardia()) return sec.g; return false; }
function renderNav() {
    const nav = document.getElementById('menu');
    nav.innerHTML = '<div class="nav-titulo">MODULOS DEL SISTEMA</div>';
    Object.entries(SECS).forEach(([key,sec]) => {
        if(!tieneAcceso(sec)) return;
        nav.innerHTML += `<a onclick="navegar('${key}')" id="nav-${key}">${sec.label}</a>`;
    });
}
function navegar(sec) {
    document.querySelectorAll('nav#menu a').forEach(a=>a.classList.remove('activo'));
    const el = document.getElementById('nav-'+sec);
    if(el) el.classList.add('activo');
    set('<div style="padding:24px;font-style:italic;">Cargando modulo...</div>');
    setTimeout(()=>{
        const fns = { codigos:renderCodigos, monitor:renderMonitor, reportesMapa:renderReportesMapa, reportesLst:renderReportesLista, estadisticas:renderEstadisticas, usuarios:renderUsuarios, emergencias:renderEmergencias, vigilancia:renderVigilancia, bitacora:renderBitacora };
        if(fns[sec]) fns[sec]();
    }, 40);
}

// ══════════════════════════════════════════════════════════
// CODIGOS DE ACCESO
// ══════════════════════════════════════════════════════════
async function renderCodigos() {
    set('<div style="padding:24px;font-style:italic;">Cargando codigos...</div>');
    try {
        const res  = await fetch(BASE+'/codigos');
        const data = await res.json();
        const activos  = data.filter(c => c.estado === 'activo').length;
        const vencidos = data.filter(c => c.estado === 'vencido').length;
        const enUso    = data.filter(c => parseInt(c.contadorUsos) > 0 && c.estado === 'activo').length;
        const estadoBadge = (c) => {
            if (c.estado === 'vencido')        return '<span class="badge b-rojo">VENCIDO</span>';
            if (parseInt(c.contadorUsos) > 0)  return '<span class="badge b-amarillo">EN USO</span>';
            return '<span class="badge b-verde">DISPONIBLE</span>';
        };
        const colorCodigo = (c) => {
            if (c.estado === 'vencido')        return '#888';
            if (parseInt(c.contadorUsos) > 0)  return '#cc8800';
            return '#2c4a6e';
        };
        // FIX: columna Accion agregada al final de cada fila
        const filas = data.length ? data.map(c => `<tr>
            <td><code style="font-size:15px;font-weight:bold;letter-spacing:2px;color:${colorCodigo(c)};">${c.codigo}</code></td>
            <td>${c.generadoPor}</td>
            <td>${c.nombreAsignado && c.nombreAsignado !== 'Sin asignar'
                ? `<strong>${c.nombreAsignado}</strong><br><small style="color:#888;">${c.emailAsignado||''}</small>`
                : '<span style="font-style:italic;color:#aaa;">Sin asignar</span>'}</td>
            <td>${estadoBadge(c)}</td>
            <td>${parseInt(c.contadorUsos) > 0
                ? `<span style="font-weight:bold;">${c.contadorUsos} uso(s)</span><br><small>${c.ultimoUso !== 'Nunca usado' ? c.ultimoUso.substring(0,16) : ''}</small>`
                : '<span style="color:#aaa;">Nunca usado</span>'}</td>
            <td>${c.fechaCreacion ? c.fechaCreacion.substring(0,10) : '-'}</td>
            <td>${c.fechaVencimiento ? c.fechaVencimiento.substring(0,10) : '-'}</td>
            <td><button class="btn btn-d" style="font-size:11px;" onclick="eliminarCodigo(${c.id},'${c.codigo}')">Eliminar</button></td>
        </tr>`).join('') : '<tr><td colspan="8" style="text-align:center;padding:20px;">Sin codigos generados</td></tr>';
        set(`<div class="breadcrumb">Inicio &gt; Codigos de Acceso</div>
            <div class="sec-title">CODIGOS DE ACCESO PARA NUEVOS USUARIOS</div>
            <div class="sec-body" style="padding:0;">
                <div class="sec-toolbar">
                    <button class="btn btn-p" onclick="generarCodigo()">+ Generar Nuevo Codigo</button>
                    <button class="btn" onclick="renderCodigos()">Actualizar</button>
                    <span style="font-size:12px;">
                        Total: ${data.length} |
                        <span style="color:#006600;font-weight:bold;">Disponibles: ${activos - enUso}</span> |
                        <span style="color:#cc8800;font-weight:bold;">En uso: ${enUso}</span> |
                        <span style="color:#888;">Vencidos: ${vencidos}</span>
                    </span>
                </div>
                <div id="codigoGenerado" style="display:none;padding:16px;background:#d0f0d0;border-bottom:2px solid #006600;text-align:center;">
                    <div style="font-size:12px;font-weight:bold;color:#004400;margin-bottom:4px;">CODIGO GENERADO — Entregalo al nuevo usuario</div>
                    <div id="codigoValor" style="font-size:32px;font-weight:bold;letter-spacing:6px;color:#004400;font-family:monospace;"></div>
                    <div id="codigoVence" style="font-size:12px;color:#006600;margin-top:4px;"></div>
                </div>
                <div class="tbl-wrap"><table>
                    <thead><tr><th>Codigo</th><th>Generado por</th><th>Asignado a</th><th>Estado</th><th>Usos</th><th>Fecha creacion</th><th>Vencimiento</th><th>Accion</th></tr></thead>
                    <tbody>${filas}</tbody>
                </table></div>
            </div>`);
    } catch(e) { set('<div style="padding:24px;color:#cc0000;">Error al cargar codigos.</div>'); }
}

async function generarCodigo() {
    const emails = usuarios.map(u=>`<option value="${u.email}" data-nombre="${u.nombre}">${u.nombre} — ${u.rol}</option>`).join('');
    const div = document.createElement('div');
    div.innerHTML = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;" id="modalGenerar">
        <div style="background:var(--bg-modal);border:2px solid var(--color-border);width:420px;">
            <div style="background:#2c4a6e;color:white;padding:10px 16px;font-weight:bold;font-size:14px;">ASIGNAR CODIGO A USUARIO</div>
            <div style="padding:20px;">
                <label style="font-size:12px;font-weight:bold;color:var(--color-text);display:block;margin-bottom:6px;">SELECCIONAR USUARIO</label>
                <select id="selUsuarioCodigo" style="width:100%;padding:8px;border:1px solid var(--color-border);background:var(--bg-input);color:var(--color-text);font-size:13px;">
                    <option value="">-- Seleccionar usuario --</option>${emails}
                </select>
                <div id="msgGenerar" style="margin-top:8px;font-size:12px;color:#cc0000;"></div>
            </div>
            <div style="padding:12px 16px;border-top:1px solid var(--color-border2);display:flex;gap:8px;justify-content:flex-end;background:var(--bg-modal-foot);">
                <button class="btn" onclick="document.getElementById('modalGenerar').remove()">Cancelar</button>
                <button class="btn btn-p" onclick="confirmarGenerarCodigo()">GENERAR Y ASIGNAR</button>
            </div>
        </div>
    </div>`;
    document.body.appendChild(div);
}

async function confirmarGenerarCodigo() {
    const sel   = document.getElementById('selUsuarioCodigo');
    const emailAsignado = sel.value;
    const msg   = document.getElementById('msgGenerar');
    if (!emailAsignado) { msg.textContent='Selecciona un usuario.'; return; }

    // FIX: obtener el nombre del residente seleccionado, no el del admin
    const opcionSeleccionada = sel.options[sel.selectedIndex];
    const nombreAsignado = opcionSeleccionada?.dataset?.nombre || '';

    msg.textContent = '';
    try {
        const res  = await fetch(BASE+'/codigos/generar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ generadoPor: usuario.nombre, emailAsignado, nombreAsignado })
        });
        const data = await res.json();
        if (data.success === 'true') {
            document.getElementById('modalGenerar').remove();
            await renderCodigos();
            const banner = document.getElementById('codigoGenerado');
            const valor  = document.getElementById('codigoValor');
            if (banner && valor) {
                valor.textContent = data.codigo;
                const vence = document.getElementById('codigoVence');
                if (vence) vence.textContent = data.fechaVencimiento
                    ? 'Vence el: ' + data.fechaVencimiento.substring(0,10)
                    : '';
                banner.style.display = 'block';
                setTimeout(() => banner.style.display = 'none', 15000);
            }
        } else { msg.textContent='Error: '+data.mensaje; }
    } catch(e) { msg.textContent='Error de conexion.'; }
}

// FIX: nueva funcion para eliminar codigos
async function eliminarCodigo(id, codigo) {
    if (!confirm('Eliminar el codigo ' + codigo + '?\nEsta accion no se puede deshacer.')) return;
    try {
        const res  = await fetch(BASE + '/codigos/' + id, { method: 'DELETE' });
        const data = await res.json();
        if (data.success === 'true') {
            await renderCodigos();
        } else {
            alert('Error: ' + data.mensaje);
        }
    } catch(e) { alert('Error de conexion.'); }
}

// ══════════════════════════════════════════════════════════
// MONITOR
// ══════════════════════════════════════════════════════════
function renderMonitor(){
    const t=reportes.length,r3=reportes.filter(x=>x.nivel==3).length,r2=reportes.filter(x=>x.nivel==2).length,r1=reportes.filter(x=>x.nivel==1).length;
    set(`<div class="breadcrumb">Inicio &gt; Monitor en Vivo</div>
        <div class="stats-row">
            <div class="stat-cell azul"><div class="stat-num">${t}</div><div class="stat-label">Total Reportes</div></div>
            <div class="stat-cell rojo"><div class="stat-num">${r3}</div><div class="stat-label">Emergencia Alta</div></div>
            <div class="stat-cell amarillo"><div class="stat-num">${r2}</div><div class="stat-label">Emergencia Media</div></div>
            <div class="stat-cell verde"><div class="stat-num">${r1}</div><div class="stat-label">Nivel Normal</div></div>
        </div>
        <div class="sec-title">MONITOR EN VIVO</div>
        <div class="sec-body">
            <div class="sec-toolbar"><button class="btn btn-p" onclick="fetchReportes().then(renderMonitor)">Actualizar</button><span style="font-size:12px;">Usuarios: ${usuarios.length}</span></div>
            <div style="padding:12px;"><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                <div style="background:${cRBg()};border:1px solid ${cRBd()};padding:12px;"><div style="font-weight:bold;font-size:12px;color:${cR()};margin-bottom:8px;">EMERGENCIA ALTA</div>${reportes.filter(x=>x.nivel==3).slice(0,4).map(r=>`<div style="font-size:12px;padding:3px 0;border-bottom:1px solid ${cRBd()}33;">${r.tipo} \u2014 ${r.usuarioNombre} ${ubicacionTag(r.usuarioEmail)}</div>`).join('')||'<div style="font-size:12px;font-style:italic;">Sin reportes</div>'}</div>
                <div style="background:${cABg()};border:1px solid ${cABd()};padding:12px;"><div style="font-weight:bold;font-size:12px;color:${cA()};margin-bottom:8px;">EMERGENCIA MEDIA</div>${reportes.filter(x=>x.nivel==2).slice(0,4).map(r=>`<div style="font-size:12px;padding:3px 0;">${r.tipo} \u2014 ${r.usuarioNombre}</div>`).join('')||'<div style="font-size:12px;font-style:italic;">Sin reportes</div>'}</div>
                <div style="background:${cVBg()};border:1px solid ${cVBd()};padding:12px;"><div style="font-weight:bold;font-size:12px;color:${cV()};margin-bottom:8px;">NIVEL NORMAL</div>${reportes.filter(x=>x.nivel==1).slice(0,4).map(r=>`<div style="font-size:12px;padding:3px 0;">${r.tipo} \u2014 ${r.usuarioNombre}</div>`).join('')||'<div style="font-size:12px;font-style:italic;">Sin reportes</div>'}</div>
            </div></div>
        </div>`);
}

// ══════════════════════════════════════════════════════════
// MAPA
// ══════════════════════════════════════════════════════════
function renderReportesMapa(){
    set(`<div class="breadcrumb">Inicio &gt; Reportes en Mapa</div><div class="sec-title">REPORTES EN MAPA</div><div class="sec-body" style="padding:0;"><div class="sec-toolbar"><button class="btn btn-p" onclick="fetchReportes().then(renderReportesMapa)">Actualizar</button></div><div id="mapaDiv"></div></div>`);
    setTimeout(initMapa,80);
}
function mapaCargado(){}
function initMapa(){
    const div=document.getElementById('mapaDiv');if(!div)return;
    if(typeof google==='undefined'||!google.maps){div.innerHTML='<div style="padding:24px;text-align:center;font-style:italic;">Mapa no disponible</div>';return;}
    const centro={lat:20.118528,lng:-98.414794};
    const mapa=new google.maps.Map(div,{zoom:15,center:centro,mapTypeControl:true,fullscreenControl:true});
    reportes.forEach(r=>{
        if(!r.latitud||!r.longitud)return;
        const lat=parseFloat(r.latitud),lng=parseFloat(r.longitud);if(isNaN(lat)||isNaN(lng))return;
        const marker=new google.maps.Marker({position:{lat,lng},map:mapa,title:r.tipo,icon:`https://maps.google.com/mapfiles/ms/icons/${r.nivel==3?'red':r.nivel==2?'yellow':'green'}-dot.png`});
        const info=new google.maps.InfoWindow({content:`<div style="font-family:Arial;font-size:13px;padding:8px;"><strong>${r.tipo}</strong><br>Nivel: ${nivelT(r.nivel)}<br>Por: ${r.usuarioNombre}<br>Fecha: ${r.fecha||'-'}</div>`});
        marker.addListener('click',()=>info.open(mapa,marker));
    });
}

// ══════════════════════════════════════════════════════════
// LISTA DE REPORTES
// ══════════════════════════════════════════════════════════
function renderReportesLista(){
    const activos=reportes.filter(r=>r.estado!=='resuelto');
    const resueltos=reportes.filter(r=>r.estado==='resuelto');
    const filasFn=(lista)=>lista.length?lista.map(r=>`<tr style="${r.estado==='resuelto'?'opacity:0.6;':''}">
        <td>${r.id}</td><td><span class="nivel-dot" style="background:${colorNivel(r.nivel)};"></span>${r.tipo}</td>
        <td>${r.usuarioNombre}</td><td>${ubicacionTag(r.usuarioEmail)}</td>
        <td><span class="badge ${r.nivel==3?'b-rojo':r.nivel==2?'b-amarillo':'b-verde'}">${nivelT(r.nivel)}</span></td>
        <td>${r.descripcion||'-'}</td><td>${r.fecha?r.fecha.substring(0,16):'-'}</td>
        <td>${r.fotoUrl?`<img src="${r.fotoUrl}" class="foto-thumb" onclick="verFoto(this.src)" />`:'<span>-</span>'}</td>
        <td><div style="display:flex;gap:4px;"><button class="btn btn-p" onclick="verReporte(${r.id})">Ver</button>${r.estado!=='resuelto'?`<button class="btn btn-e" onclick="resolverReporte(${r.id})">Resolver</button>`:'<span style="color:'+cV()+';font-size:11px;font-weight:bold;">Resuelto</span>'}</div></td>
    </tr>`).join(''):`<tr><td colspan="9" style="text-align:center;padding:20px;font-style:italic;">Sin reportes</td></tr>`;
    const enc=`<table><thead><tr><th>ID</th><th>Tipo</th><th>Por</th><th>Casa/Cerrada</th><th>Nivel</th><th>Descripcion</th><th>Fecha</th><th>Foto</th><th>Accion</th></tr></thead><tbody>`;
    set(`<div class="breadcrumb">Inicio &gt; Lista de Reportes</div>
        <div class="sec-title">LISTA DE REPORTES</div>
        <div class="sec-body" style="padding:0;">
            <div class="sec-toolbar"><button class="btn btn-p" onclick="fetchReportes().then(renderReportesLista)">Actualizar</button><span style="font-size:12px;">Total: ${reportes.length} | Activos: ${activos.length} | Resueltos: ${resueltos.length}</span></div>
            <div class="sec-title" style="font-size:12px;background:#4a6e8e;">ACTIVOS (${activos.length})</div>
            <div class="tbl-wrap">${enc}${filasFn(activos)}</tbody></table></div>
            <div class="sec-title" style="font-size:12px;background:#555;margin-top:16px;">RESUELTOS (${resueltos.length})</div>
            <div class="tbl-wrap">${enc}${filasFn(resueltos)}</tbody></table></div>
        </div>`);
}
async function resolverReporte(id){
    if(!confirm('Marcar como resuelto?'))return;
    try{const res=await fetch(BASE+'/reportes/'+id+'/resolver',{method:'PUT'});const data=await res.json();if(data.success==='true'||data.success===true){await fetchReportes();renderReportesLista();}}catch(e){alert('Error de conexion');}
}
function verReporte(id){
    const r=reportes.find(x=>x.id==id);if(!r)return;
    const badgeCls=r.nivel==3?'b-rojo':r.nivel==2?'b-amarillo':'b-verde';
    document.getElementById('mDetalleBody').innerHTML=`
        <div style="background:#2c4a6e;color:white;padding:8px 12px;font-weight:bold;font-size:13px;margin-bottom:12px;">${r.tipo.toUpperCase()}</div>
        <div class="det-row"><span class="det-key">Nivel</span><span><span class="badge ${badgeCls}">${nivelT(r.nivel)}</span></span></div>
        <div class="det-row"><span class="det-key">Reportado por</span><span>${r.usuarioNombre}</span></div>
        <div class="det-row"><span class="det-key">Casa / Cerrada</span><span>${ubicacionTag(r.usuarioEmail)}</span></div>
        <div class="det-row"><span class="det-key">Fecha</span><span>${r.fecha||'-'}</span></div>
        <div class="det-row"><span class="det-key">GPS</span><span>${r.latitud&&r.longitud?r.latitud+', '+r.longitud:'No disponible'}</span></div>
        ${r.descripcion?`<div class="det-row" style="flex-direction:column;gap:4px;"><span class="det-key">Descripcion</span><span style="padding:8px;background:#f4f4f4;border:1px solid #ccc;">${r.descripcion}</span></div>`:''}
        ${r.fotoUrl?`<div style="margin-top:12px;"><img src="${r.fotoUrl}" style="width:100%;border:2px solid #888;cursor:pointer;" onclick="verFoto(this.src)" /></div>`:''}`;
    abrir('mDetalle');
}

// ══════════════════════════════════════════════════════════
// FILTROS DE ESTADISTICAS
// ══════════════════════════════════════════════════════════
function obtenerReportesFiltrados(){
    let reportesFilt = reportes;
    const hoy = new Date();
    hoy.setHours(0,0,0,0);
    if(filtroEstadTipo==='hoy'){
        const fechaHoy = hoy.toISOString().substring(0,10);
        reportesFilt = reportes.filter(r=>r.fecha && r.fecha.substring(0,10)===fechaHoy);
    }else if(filtroEstadTipo==='ayer'){
        const ayer = new Date(hoy); ayer.setDate(ayer.getDate()-1);
        const fechaAyer = ayer.toISOString().substring(0,10);
        reportesFilt = reportes.filter(r=>r.fecha && r.fecha.substring(0,10)===fechaAyer);
    }else if(filtroEstadTipo==='semana'){
        const hace7 = new Date(hoy); hace7.setDate(hace7.getDate()-7);
        reportesFilt = reportes.filter(r=>r.fecha && new Date(r.fecha)>=hace7 && new Date(r.fecha)<=hoy);
    }else if(filtroEstadTipo==='mes'){
        const hace30 = new Date(hoy); hace30.setDate(hace30.getDate()-30);
        reportesFilt = reportes.filter(r=>r.fecha && new Date(r.fecha)>=hace30 && new Date(r.fecha)<=hoy);
    }else if(filtroEstadTipo==='rango' && filtroEstadFechaInicio && filtroEstadFechaFin){
        const inicio = new Date(filtroEstadFechaInicio);
        const fin = new Date(filtroEstadFechaFin); fin.setHours(23,59,59,999);
        reportesFilt = reportes.filter(r=>r.fecha && new Date(r.fecha)>=inicio && new Date(r.fecha)<=fin);
    }
    return reportesFilt;
}
function actualizarFiltroEstadisticas(){ renderEstadisticas(); }

// ══════════════════════════════════════════════════════════
// ESTADISTICAS
// ══════════════════════════════════════════════════════════
function renderEstadisticas(){
    const reportesFilt = obtenerReportesFiltrados();
    const tipos={};reportesFilt.forEach(r=>{tipos[r.tipo]=(tipos[r.tipo]||0)+1;});
    const byNivel=[reportesFilt.filter(x=>x.nivel==1).length,reportesFilt.filter(x=>x.nivel==2).length,reportesFilt.filter(x=>x.nivel==3).length];
    const byDia={};reportesFilt.forEach(r=>{const d=r.fecha?r.fecha.substring(0,10):'Sin fecha';byDia[d]=(byDia[d]||0)+1;});
    const porRol={};usuarios.forEach(u=>{porRol[u.rol]=(porRol[u.rol]||0)+1;});
    const formatoFecha=(f)=>f?new Date(f).toLocaleDateString('es-MX'):'Sin fecha';
    const labelFiltro=filtroEstadTipo==='todos'?'Todos los reportes':filtroEstadTipo==='hoy'?'Reportes de hoy':filtroEstadTipo==='ayer'?'Reportes de ayer':filtroEstadTipo==='semana'?'Últimos 7 días':filtroEstadTipo==='mes'?'Últimos 30 días':filtroEstadTipo==='rango'?`${formatoFecha(filtroEstadFechaInicio)} - ${formatoFecha(filtroEstadFechaFin)}`:'';
    set(`<div class="breadcrumb">Inicio &gt; Estadisticas</div><div class="sec-title">ESTADISTICAS DEL SISTEMA</div><div class="sec-body" style="padding:12px;">
    <div class="sec-toolbar" style="margin-bottom:12px;padding:8px;background:var(--bg-sec);border-radius:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <label style="font-weight:bold;color:var(--color-text2);">Filtrar por fecha:</label>
        <button class="btn ${filtroEstadTipo==='todos'?'btn-p':''}" onclick="filtroEstadTipo='todos';actualizarFiltroEstadisticas()" style="padding:6px 12px;font-size:12px;">Todo</button>
        <button class="btn ${filtroEstadTipo==='hoy'?'btn-p':''}" onclick="filtroEstadTipo='hoy';actualizarFiltroEstadisticas()" style="padding:6px 12px;font-size:12px;">Hoy</button>
        <button class="btn ${filtroEstadTipo==='ayer'?'btn-p':''}" onclick="filtroEstadTipo='ayer';actualizarFiltroEstadisticas()" style="padding:6px 12px;font-size:12px;">Ayer</button>
        <button class="btn ${filtroEstadTipo==='semana'?'btn-p':''}" onclick="filtroEstadTipo='semana';actualizarFiltroEstadisticas()" style="padding:6px 12px;font-size:12px;">Semana</button>
        <button class="btn ${filtroEstadTipo==='mes'?'btn-p':''}" onclick="filtroEstadTipo='mes';actualizarFiltroEstadisticas()" style="padding:6px 12px;font-size:12px;">Mes</button>
        <span style="width:1px;height:20px;background:var(--color-border3);"></span>
        <input type="date" id="filtroFechaInicio" value="${filtroEstadFechaInicio||''}" onchange="filtroEstadFechaInicio=this.value;filtroEstadTipo='rango';actualizarFiltroEstadisticas()" style="padding:6px;font-size:12px;background:var(--bg-input);border:1px solid var(--color-border);border-radius:3px;color:var(--color-text);">
        <span style="color:var(--color-text2);">a</span>
        <input type="date" id="filtroFechaFin" value="${filtroEstadFechaFin||''}" onchange="filtroEstadFechaFin=this.value;filtroEstadTipo='rango';actualizarFiltroEstadisticas()" style="padding:6px;font-size:12px;background:var(--bg-input);border:1px solid var(--color-border);border-radius:3px;color:var(--color-text);">
    </div>
    <div style="padding:8px;background:var(--bg-sec2);border-radius:4px;margin-bottom:12px;font-size:13px;"><strong>Filtro activo:</strong> ${labelFiltro} | <strong>Total reportes:</strong> ${reportesFilt.length}</div>
    <div class="charts-row" style="margin-bottom:12px;"><div class="chart-panel"><div class="chart-ptitle">REPORTES POR NIVEL</div><div class="chart-body"><canvas id="cNivel"></canvas></div></div><div class="chart-panel"><div class="chart-ptitle">TIPOS DE REPORTE</div><div class="chart-body"><canvas id="cTipos"></canvas></div></div></div><div class="charts-row"><div class="chart-panel"><div class="chart-ptitle">REPORTES POR DIA</div><div class="chart-body"><canvas id="cDia"></canvas></div></div><div class="chart-panel"><div class="chart-ptitle">USUARIOS POR ROL</div><div class="chart-body"><canvas id="cRoles"></canvas></div></div></div></div>`);
    setTimeout(()=>{
        const isDark=document.body.classList.contains('dark');
        const tick=isDark?'#a0a0b8':'#555';const grid=isDark?'#2a2a48':'#e0e0e0';const border=isDark?'#1a1a2e':'#888';
        new Chart(document.getElementById('cNivel'),{type:'doughnut',data:{labels:['Normal','Medio','Alto'],datasets:[{data:byNivel,backgroundColor:[cV(),cA(),cR()],borderWidth:2,borderColor:border}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{color:tick}}}}});
        new Chart(document.getElementById('cTipos'),{type:'bar',data:{labels:Object.keys(tipos),datasets:[{label:'Cantidad',data:Object.values(tipos),backgroundColor:'#2c4a6e'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1,color:tick},grid:{color:grid}},x:{ticks:{color:tick}}}}});
        const dias=Object.keys(byDia).sort().slice(-10);
        new Chart(document.getElementById('cDia'),{type:'line',data:{labels:dias,datasets:[{label:'Reportes',data:dias.map(d=>byDia[d]),borderColor:'#2c4a6e',backgroundColor:'rgba(44,74,110,0.15)',fill:true,tension:0,pointRadius:4}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{stepSize:1,color:tick},grid:{color:grid}},x:{ticks:{color:tick}}}}});
        new Chart(document.getElementById('cRoles'),{type:'doughnut',data:{labels:Object.keys(porRol),datasets:[{data:Object.values(porRol),backgroundColor:[cA(),'#2c4a6e','#664488',cV(),'#888'],borderWidth:2,borderColor:border}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{color:tick}}}}});
    },100);
}

// ══════════════════════════════════════════════════════════
// GESTION DE USUARIOS
// ══════════════════════════════════════════════════════════
function renderUsuarios(filtro){
    if(!esPresidente()){set('<div style="padding:24px;color:#cc0000;font-weight:bold;">ACCESO DENEGADO</div>');return;}
    if(filtro!==undefined) filtroRolActivo=filtro;
    const uf=filtroRolActivo==='todos'?usuarios:usuarios.filter(u=>u.rol.toLowerCase().includes(filtroRolActivo.toLowerCase()));
    const filas=uf.length?uf.map(u=>`<tr><td>${u.id}</td><td><strong>${u.nombre}</strong></td><td>${u.email}</td><td>${u.telefono||'-'}</td><td>${rolTag(u.rol)}</td><td>${u.cerrada||'-'}</td><td>${u.numeroCasa?'Casa '+u.numeroCasa:'-'}</td><td><div style="display:flex;gap:4px;"><button class="btn btn-e" onclick="abrirEditar(${u.id})">Editar</button><button class="btn btn-d" onclick="abrirEliminar(${u.id},'${u.nombre.replace(/'/g,"\\'")}')">Eliminar</button></div></td></tr>`).join(''):`<tr><td colspan="8" style="text-align:center;padding:20px;font-style:italic;">Sin usuarios</td></tr>`;
    const cnt=k=>k==='todos'?usuarios.length:usuarios.filter(u=>u.rol.toLowerCase().includes(k)).length;
    const filtros=[{key:'todos',label:'TODOS ('+cnt('todos')+')',cls:''},{key:'presidente',label:'PRESIDENTE',cls:'f-presidente'},{key:'comite',label:'COMITE',cls:'f-comite'},{key:'encargado',label:'ENCARGADO',cls:'f-encargado'},{key:'guardia',label:'GUARDIA',cls:'f-guardia'},{key:'residente',label:'RESIDENTE',cls:'f-residente'}];
    set(`<div class="breadcrumb">Inicio &gt; Gestion de Usuarios</div>
        <div class="sec-title">GESTION DE USUARIOS</div>
        <div class="sec-body" style="padding:0;">
            <div class="sec-toolbar">
                <button class="btn btn-p" onclick="abrir('mNuevo')">+ Registrar Usuario</button>
                <button class="btn" onclick="fetchUsuarios().then(()=>renderUsuarios())">Actualizar</button>
                <div class="filtros-rol">${filtros.map(f=>`<button class="filtro-btn ${f.cls} ${filtroRolActivo===f.key?'activo':''}" onclick="renderUsuarios('${f.key}')">${f.label}</button>`).join('')}</div>
            </div>
            <div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Telefono</th><th>Rol</th><th>Cerrada</th><th>Casa</th><th>Acciones</th></tr></thead><tbody>${filas}</tbody></table></div>
        </div>`);
}

function esRolAdministrativo(rol) {
    const r = rol.toLowerCase();
    return r.includes('presidente') || r.includes('comite') || r.includes('guardia');
}
function toggleCerradaNuevo() {
    const rol = document.getElementById('nRol').value;
    const necesita = !esRolAdministrativo(rol);
    document.getElementById('nCerradaWrap').classList.toggle('visible', necesita);
    document.getElementById('nCasaWrap').classList.toggle('visible', necesita);
}
function toggleCerradaEditar() {
    const rol = document.getElementById('eRol').value;
    const necesita = !esRolAdministrativo(rol);
    document.getElementById('eCerradaWrap').classList.toggle('visible', necesita);
    document.getElementById('eCasaWrap').classList.toggle('visible', necesita);
}
function abrirEditar(id){
    const u=usuarios.find(x=>x.id==id);if(!u)return;
    document.getElementById('eId').value=u.id;
    document.getElementById('eNombre').value=u.nombre;
    document.getElementById('eEmail').value=u.email;
    document.getElementById('eTel').value=u.telefono||'';
    document.getElementById('ePass').value='';
    document.getElementById('eRol').value=u.rol;
    document.getElementById('eCerrada').value=u.cerrada||'';
    document.getElementById('eCasa').value=u.numeroCasa||'';
    document.getElementById('eMsg').innerHTML='';
    toggleCerradaEditar();
    abrir('mEditar');
}
function abrirEliminar(id,nombre){idEliminar=id;document.getElementById('eNombreConfirm').textContent=nombre;document.getElementById('delMsg').innerHTML='';abrir('mEliminar');}
async function guardarNuevo(){
    const nombre=document.getElementById('nNombre').value.trim(),email=document.getElementById('nEmail').value.trim();
    const tel=document.getElementById('nTel').value.trim(),pass=document.getElementById('nPass').value;
    const rol=document.getElementById('nRol').value;
    const msg=document.getElementById('nMsg');
    if(!nombre||!email||!tel||!pass){msg.className='msg-err';msg.textContent='ERROR: Todos los campos son obligatorios.';return;}
    msg.className='';msg.textContent='Procesando...';
    try{
        const body={nombre,email,telefono:tel,password:pass,rol};
        if(!esRolAdministrativo(rol)){
            const cerrada=document.getElementById('nCerrada').value;
            const casa=document.getElementById('nCasa').value;
            if(cerrada) body.cerrada=cerrada;
            if(casa) body.numeroCasa=parseInt(casa);
        }
        const res=await fetch(BASE+'/usuarios/nuevo',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const data=await res.json();
        if(data.success==='true'||data.success===true){msg.className='msg-ok';msg.textContent='Usuario registrado.';await fetchUsuarios();setTimeout(()=>{cerrar('mNuevo');limpiarNuevo();renderUsuarios();},1200);}
        else{msg.className='msg-err';msg.textContent='ERROR: '+(data.mensaje||'No se pudo registrar.');}
    }catch(e){msg.className='msg-err';msg.textContent='ERROR DE CONEXION.';}
}
async function guardarEdicion(){
    const id=document.getElementById('eId').value,nombre=document.getElementById('eNombre').value.trim();
    const email=document.getElementById('eEmail').value.trim(),tel=document.getElementById('eTel').value.trim();
    const pass=document.getElementById('ePass').value,rol=document.getElementById('eRol').value;
    const msg=document.getElementById('eMsg');
    if(!nombre||!email){msg.className='msg-err';msg.textContent='ERROR: Nombre y email son obligatorios.';return;}
    const body={nombre,email,telefono:tel,rol};
    if(pass) body.password=pass;
    if(!esRolAdministrativo(rol)){
        const cerrada=document.getElementById('eCerrada').value;
        const casa=document.getElementById('eCasa').value;
        body.cerrada=cerrada||'';
        body.numeroCasa=casa?parseInt(casa):null;
    } else {
        body.cerrada='';
        body.numeroCasa=null;
    }
    msg.className='';msg.textContent='Procesando...';
    try{
        const res=await fetch(BASE+'/usuarios/'+id,{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const data=await res.json();
        if(data.success==='true'||data.success===true){msg.className='msg-ok';msg.textContent='Usuario actualizado.';await fetchUsuarios();setTimeout(()=>{cerrar('mEditar');renderUsuarios();},1200);}
        else{msg.className='msg-err';msg.textContent='ERROR: '+(data.mensaje||'No se pudo actualizar.');}
    }catch(e){msg.className='msg-err';msg.textContent='ERROR DE CONEXION.';}
}
async function confirmarEliminar(){
    const msg=document.getElementById('delMsg');msg.className='';msg.textContent='Procesando...';
    try{
        const res=await fetch(BASE+'/usuarios/'+idEliminar,{method:'DELETE'});
        const data=await res.json();
        if(data.success==='true'||data.success===true){msg.className='msg-ok';msg.textContent='Usuario eliminado.';await fetchUsuarios();setTimeout(()=>{cerrar('mEliminar');renderUsuarios();},1200);}
        else{msg.className='msg-err';msg.textContent='ERROR: '+(data.mensaje||'No se pudo eliminar.');}
    }catch(e){msg.className='msg-err';msg.textContent='ERROR DE CONEXION.';}
}
function limpiarNuevo(){
    ['nNombre','nEmail','nTel','nPass'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('nCerrada').value='';
    document.getElementById('nCasa').value='';
    document.getElementById('nCerradaWrap').classList.remove('visible');
    document.getElementById('nCasaWrap').classList.remove('visible');
    document.getElementById('nMsg').innerHTML='';
}

// ══════════════════════════════════════════════════════════
// EMERGENCIAS
// ══════════════════════════════════════════════════════════
function renderEmergencias(){
    set(`<div class="breadcrumb">Inicio &gt; Numeros de Emergencia</div><div class="sec-title">NUMEROS DE EMERGENCIA</div><div class="sec-body" style="padding:0;"><div class="tbl-wrap"><table><thead><tr><th>Servicio</th><th>Numero</th><th>Disponibilidad</th></tr></thead><tbody><tr><td>Policia</td><td><strong style="font-size:16px;">911</strong></td><td>24/7</td></tr><tr><td>Ambulancia</td><td><strong style="font-size:16px;">912</strong></td><td>24/7</td></tr><tr><td>Bomberos</td><td><strong style="font-size:16px;">913</strong></td><td>24/7</td></tr><tr><td>Seguridad Vecinal</td><td><strong style="font-size:16px;">555-0100</strong></td><td>24/7</td></tr></tbody></table></div></div>`);
}

// ══════════════════════════════════════════════════════════
// PAGOS DE VIGILANCIA
// ══════════════════════════════════════════════════════════
async function renderVigilancia() {
    set('<div style="padding:24px;font-style:italic;">Cargando pagos...</div>');
    await fetchPagos();
    await fetchUsuarios();
    const vigentes   = pagos.filter(p => p.estado === 'vigente').length;
    const pendientes = pagos.filter(p => p.estado === 'pendiente').length;
    const emailsConPago = pagos.map(p => p.emailResidente);
    const residentes    = usuarios.filter(u => u.rol.toLowerCase().includes('residente'));
    const sinRegistro   = residentes.filter(u => !emailsConPago.includes(u.email));
    const filas = pagos.length ? pagos.map(p => {
        const esV = p.estado === 'vigente';
        return `<tr>
            <td><strong>${p.nombreResidente}</strong></td><td>${p.emailResidente}</td>
            <td>${p.numeroCasa ? 'Casa '+p.numeroCasa : '-'}</td><td>${p.cerrada || '-'}</td>
            <td>${esV ? '<span class="badge-vigente">VIGENTE</span>' : '<span class="badge-pendiente">PENDIENTE</span>'}</td>
            <td>${p.fechaPago ? p.fechaPago.substring(0,10) : '-'}</td>
            <td>${p.fechaVencimiento ? p.fechaVencimiento.substring(0,10) : '-'}</td>
            <td>${p.registradoPor}</td>
            <td><div style="display:flex;gap:4px;">
                <button class="btn btn-p" onclick="registrarPagoDirecto('${p.emailResidente}','${p.nombreResidente.replace(/'/g,"\\'")}')">Renovar</button>
                ${esV
                    ? `<button class="btn btn-d" style="font-size:11px;" onclick="cambiarEstadoPago('${p.emailResidente}','pendiente')">Pendiente</button>`
                    : `<button class="btn btn-e" style="font-size:11px;" onclick="registrarPagoDirecto('${p.emailResidente}','${p.nombreResidente.replace(/'/g,"\\'")}')">Activar</button>`
                }
            </div></td>
        </tr>`;
    }).join('') : '<tr><td colspan="9" style="text-align:center;padding:20px;font-style:italic;">Sin registros de pago</td></tr>';
    const filasSin = sinRegistro.length ? sinRegistro.map(u => `<tr style="background:#fff8f0;">
        <td>${u.nombre}</td><td>${u.email}</td>
        <td>${u.numeroCasa ? 'Casa '+u.numeroCasa : '-'}</td><td>${u.cerrada || '-'}</td>
        <td><button class="btn btn-p" onclick="registrarPagoDirecto('${u.email}','${u.nombre.replace(/'/g,"\\'")}')">Registrar pago</button></td>
    </tr>`).join('') : '';
    set(`<div class="breadcrumb">Inicio &gt; Pagos de Vigilancia</div>
        <div class="stats-row">
            <div class="stat-cell verde"><div class="stat-num">${vigentes}</div><div class="stat-label">Vigentes</div></div>
            <div class="stat-cell rojo"><div class="stat-num">${pendientes}</div><div class="stat-label">Pendientes</div></div>
            <div class="stat-cell amarillo"><div class="stat-num">${sinRegistro.length}</div><div class="stat-label">Sin registro</div></div>
            <div class="stat-cell azul"><div class="stat-num">${pagos.length}</div><div class="stat-label">Total registrados</div></div>
        </div>
        <div class="sec-title">CONTROL DE PAGOS DE VIGILANCIA</div>
        <div class="sec-body" style="padding:0;">
            <div class="sec-toolbar">
                <button class="btn btn-p" onclick="abrirModalPago()">+ Registrar Pago</button>
                <button class="btn" onclick="renderVigilancia()">Actualizar</button>
                <span style="font-size:12px;">Pagos vigentes por 30 dias | Vencidos se marcan PENDIENTE automaticamente</span>
            </div>
            <div class="tbl-wrap"><table>
                <thead><tr><th>Residente</th><th>Email</th><th>Casa</th><th>Cerrada</th><th>Estado</th><th>Fecha pago</th><th>Vencimiento</th><th>Registrado por</th><th>Acciones</th></tr></thead>
                <tbody>${filas}</tbody>
            </table></div>
        </div>
        ${sinRegistro.length ? `
        <div class="sec-title" style="margin-top:16px;background:#7a3e1a;">RESIDENTES SIN PAGO REGISTRADO (${sinRegistro.length})</div>
        <div class="sec-body" style="padding:0;">
            <div class="tbl-wrap"><table>
                <thead><tr><th>Nombre</th><th>Email</th><th>Casa</th><th>Cerrada</th><th>Accion</th></tr></thead>
                <tbody>${filasSin}</tbody>
            </table></div>
        </div>` : ''}`);
}
function abrirModalPago() {
    const sel = document.getElementById('pEmailResidente');
    sel.innerHTML = '<option value="">-- Seleccionar residente --</option>';
    usuarios.filter(u => u.rol.toLowerCase().includes('residente')).forEach(u => {
        const pago   = pagos.find(p => p.emailResidente === u.email);
        const estado = pago ? ` [${pago.estado.toUpperCase()}]` : ' [SIN REGISTRO]';
        sel.innerHTML += `<option value="${u.email}">${u.nombre}${estado} — ${u.email}</option>`;
    });
    document.getElementById('pMsg').innerHTML = '';
    abrir('mPago');
}
async function confirmarPago() {
    const email = document.getElementById('pEmailResidente').value;
    const msg   = document.getElementById('pMsg');
    if (!email) { msg.style.color='#cc0000'; msg.textContent='Selecciona un residente.'; return; }
    msg.style.color=''; msg.textContent='Registrando...';
    try {
        const res  = await fetch(BASE+'/vigilancia/pago', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ emailResidente:email, registradoPor:usuario.nombre }) });
        const data = await res.json();
        if (data.success === 'true') {
            msg.style.color='#006600'; msg.textContent='Pago registrado correctamente.';
            setTimeout(()=>{ cerrar('mPago'); renderVigilancia(); }, 1200);
        } else { msg.style.color='#cc0000'; msg.textContent='Error: '+data.mensaje; }
    } catch(e) { msg.style.color='#cc0000'; msg.textContent='Error de conexion.'; }
}
async function registrarPagoDirecto(email, nombre) {
    if (!confirm('Registrar pago de vigilancia para '+nombre+'?\nEstado cambiara a VIGENTE por 30 dias.')) return;
    try {
        const res  = await fetch(BASE+'/vigilancia/pago', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ emailResidente:email, registradoPor:usuario.nombre }) });
        const data = await res.json();
        if (data.success === 'true') { await renderVigilancia(); }
        else { alert('Error: '+data.mensaje); }
    } catch(e) { alert('Error de conexion.'); }
}
async function cambiarEstadoPago(email, estado) {
    if (!confirm(estado==='pendiente'?'Marcar como PENDIENTE?':'Activar como VIGENTE?')) return;
    try {
        const res  = await fetch(BASE+'/vigilancia/pago/'+encodeURIComponent(email), { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ estado }) });
        const data = await res.json();
        if (data.success === 'true') { await renderVigilancia(); }
        else { alert('Error: '+data.mensaje); }
    } catch(e) { alert('Error de conexion.'); }
}

// ══════════════════════════════════════════════════════════
// BITACORA DE ACCESOS
// ══════════════════════════════════════════════════════════
async function renderBitacora() {
    set('<div style="padding:24px;font-style:italic;">Cargando bitacora...</div>');
    await fetchBitacora();
    const entradas = bitacora.filter(b => b.tipo === 'entrada').length;
    const salidas  = bitacora.filter(b => b.tipo === 'salida').length;
    const hoy      = new Date().toISOString().substring(0, 10);
    const hoyReg   = bitacora.filter(b => b.fecha && b.fecha.startsWith(hoy)).length;
    const filtroCerrada = document.getElementById('bFiltroCerrada')?.value || '';
    const filtroTipo    = document.getElementById('bFiltroTipo')?.value    || '';
    const filtroFecha   = document.getElementById('bFiltroFecha')?.value   || '';
    let filtrada = bitacora;
    if (filtroCerrada) filtrada = filtrada.filter(b => b.cerrada === filtroCerrada);
    if (filtroTipo)    filtrada = filtrada.filter(b => b.tipo === filtroTipo);
    if (filtroFecha)   filtrada = filtrada.filter(b => b.fecha && b.fecha.startsWith(filtroFecha));
    const filas = filtrada.length ? filtrada.map(b => `<tr>
        <td>${b.id}</td><td><strong>${b.nombreResidente}</strong></td><td>${b.emailResidente}</td>
        <td>${b.numeroCasa ? 'Casa '+b.numeroCasa : '-'}</td><td>${b.cerrada || '-'}</td>
        <td>${b.tipo === 'entrada' ? '<span class="badge-entrada">&#x2197; ENTRADA</span>' : '<span class="badge-salida">&#x2198; SALIDA</span>'}</td>
        <td>${b.fecha ? b.fecha.substring(0,16) : '-'}</td><td>${b.registradoPor || '-'}</td>
    </tr>`).join('') : '<tr><td colspan="8" style="text-align:center;padding:20px;font-style:italic;">Sin registros</td></tr>';
    set(`<div class="breadcrumb">Inicio &gt; Bitacora de Acceso</div>
        <div class="stats-row">
            <div class="stat-cell azul"><div class="stat-num">${bitacora.length}</div><div class="stat-label">Total</div></div>
            <div class="stat-cell verde"><div class="stat-num">${entradas}</div><div class="stat-label">Entradas</div></div>
            <div class="stat-cell rojo"><div class="stat-num">${salidas}</div><div class="stat-label">Salidas</div></div>
            <div class="stat-cell amarillo"><div class="stat-num">${hoyReg}</div><div class="stat-label">Hoy</div></div>
        </div>
        <div class="sec-title">BITACORA DE ACCESOS AL FRACCIONAMIENTO</div>
        <div class="sec-body" style="padding:0;">
            <div class="sec-toolbar">
                <button class="btn btn-p" onclick="renderBitacora()">Actualizar</button>
                <select id="bFiltroCerrada" onchange="renderBitacora()" style="padding:6px;border:1px solid var(--color-border);background:var(--bg-input);color:var(--color-text);font-size:12px;">
                    <option value="">Todas las cerradas</option>
                    <option value="Boulevard">Boulevard</option>
                    <option value="Cerrada 1">Cerrada 1</option>
                    <option value="Cerrada 2">Cerrada 2</option>
                    <option value="Cerrada 3">Cerrada 3</option>
                    <option value="Cerrada 4">Cerrada 4</option>
                    <option value="Cerrada 5">Cerrada 5</option>
                    <option value="Cerrada 6">Cerrada 6</option>
                    <option value="Cerrada 7">Cerrada 7</option>
                </select>
                <select id="bFiltroTipo" onchange="renderBitacora()" style="padding:6px;border:1px solid var(--color-border);background:var(--bg-input);color:var(--color-text);font-size:12px;">
                    <option value="">Entradas y salidas</option>
                    <option value="entrada">Solo entradas</option>
                    <option value="salida">Solo salidas</option>
                </select>
                <input type="date" id="bFiltroFecha" onchange="renderBitacora()" style="padding:6px;border:1px solid var(--color-border);background:var(--bg-input);color:var(--color-text);font-size:12px;" />
                <span style="font-size:12px;">Mostrando: ${filtrada.length} de ${bitacora.length} registros</span>
            </div>
            <div class="tbl-wrap"><table>
                <thead><tr><th>ID</th><th>Residente</th><th>Email</th><th>Casa</th><th>Cerrada</th><th>Tipo</th><th>Fecha y Hora</th><th>Registrado por</th></tr></thead>
                <tbody>${filas}</tbody>
            </table></div>
        </div>`);
}

// ══════════════════════════════════════════════════════════
// UTILIDADES
// ══════════════════════════════════════════════════════════
function nivelT(n){return n==3||n==='3'?'EMERGENCIA ALTA':n==2||n==='2'?'EMERGENCIA MEDIA':'NIVEL NORMAL';}
function rolTag(rol){const r=rol.toLowerCase();let cls='r-visitante';if(r.includes('presidente'))cls='r-presidente';else if(r.includes('comite'))cls='r-comite';else if(r.includes('encargado'))cls='r-comite';else if(r.includes('guardia'))cls='r-guardia';else if(r.includes('residente'))cls='r-residente';return `<span class="rol-tag ${cls}">${rol.toUpperCase()}</span>`;}
function set(html){document.getElementById('contenido').innerHTML=html;}
function abrir(id){document.getElementById(id).classList.add('on');}
function cerrar(id){document.getElementById(id).classList.remove('on');}
function verFoto(url){document.getElementById('mFotoImg').src=url;abrir('mFoto');}

document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('.modal-bg').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('on');});});
    <?php if ($loggedIn): ?>
    renderNav();
    navegar('monitor');
    renderNotifPanel();
    setInterval(pollNotificaciones, 30000);
    <?php endif; ?>
});
window.onerror=function(){return false;};
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCdP0COLhCjAjt8dCel8-Pv5VWqJ-Ee_Lw&callback=mapaCargado" async defer></script>
</body>
</html>