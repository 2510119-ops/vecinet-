<?php
// ============================================================
// VECINET - Sistema Semaforo Vecinal
// index.php - Panel de Control Institucional
//
// ARQUITECTURA:
// PHP maneja: autenticacion con sesiones, pre-carga de datos
//             via cURL hacia el backend Ktor en Railway
// JS maneja:  toda la logica del dashboard, modulos,
//             tablas, filtros y actualizaciones dinamicas
// ============================================================

// URL base del backend Ktor desplegado en Railway
$BASE = 'https://vecinet-production.up.railway.app';

// ------------------------------------------------------------
// FUNCION: apiGet
// Realiza una peticion HTTP GET al backend Ktor usando cURL.
// Al ejecutarse en el servidor PHP no tiene restricciones de
// CORS, a diferencia de fetch() que corre en el navegador.
// Parametros:
//   $url - URL completa del endpoint a consultar
// Retorna:
//   String con el JSON de respuesta, o '[]' si falla
// ------------------------------------------------------------
function apiGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Retorna resultado como string
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);            // Tiempo maximo de espera: 8 segundos
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Permite HTTPS sin verificar certificado
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Codigo HTTP de la respuesta
    curl_close($ch);
    // Solo retorna datos si la peticion fue exitosa (HTTP 200)
    return ($result && $httpCode === 200) ? $result : '[]';
}

// ------------------------------------------------------------
// FUNCION: apiPost
// Realiza una peticion HTTP POST al backend Ktor usando cURL.
// Se usa exclusivamente para el login server-side, enviando
// las credenciales del usuario al endpoint /login de Ktor.
// Parametros:
//   $url  - URL completa del endpoint
//   $body - Arreglo asociativo con los datos a enviar
// Retorna:
//   String con el JSON de respuesta del servidor
// ------------------------------------------------------------
function apiPost($url, $body) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);                          // Indica que es POST
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));      // Serializa el cuerpo a JSON
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $result = curl_exec($ch);
    curl_close($ch);
    // Si cURL falla retorna un JSON de error estandar
    return $result ?: '{"success":false,"mensaje":"Error de conexion"}';
}

// ------------------------------------------------------------
// INICIO DE SESION PHP
// session_start() activa el manejo de sesiones del servidor.
// La sesion permite recordar que usuario esta autenticado
// entre distintas peticiones HTTP sin repetir el login.
// ------------------------------------------------------------
session_start();

$loginError = '';                              // Mensaje de error del formulario de login
$loggedIn   = isset($_SESSION['usuario']);     // Verifica si ya existe una sesion activa

// ------------------------------------------------------------
// PROCESAMIENTO DEL FORMULARIO DE LOGIN (method="POST")
// Se ejecuta cuando el usuario envia el formulario de login.
// Valida que los campos no esten vacios, llama al backend
// Ktor via cURL, evalua la respuesta y crea la sesion PHP
// si las credenciales son correctas y el rol tiene acceso.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'login') {

    $email = trim($_POST['email'] ?? '');  // Elimina espacios del correo
    $pass  = $_POST['pass']  ?? '';

    if ($email && $pass) {
        // Envia credenciales al backend Ktor via cURL (server-to-server, sin CORS)
        $respuesta = apiPost($BASE . '/login', ['email' => $email, 'password' => $pass]);
        $data      = json_decode($respuesta, true); // Decodifica JSON a arreglo PHP

        if ($data && $data['success']) {
            $rol = strtolower($data['rol'] ?? '');

            // Solo estos roles tienen acceso al panel web institucional.
            // Residentes y Visitantes solo pueden usar la app movil.
            $autorizado = str_contains($rol, 'presidente') ||
                          str_contains($rol, 'comite')     ||
                          str_contains($rol, 'encargado')  ||
                          str_contains($rol, 'guardia');

            if ($autorizado) {
                // Guarda los datos del usuario en la sesion del servidor
                $_SESSION['usuario'] = [
                    'nombre'     => $data['nombre']     ?? '',
                    'rol'        => $data['rol']        ?? '',
                    'email'      => $email,
                    'fotoPerfil' => $data['fotoPerfil'] ?? null
                ];
                $loggedIn = true;
            } else {
                $loginError = 'ACCESO DENEGADO: El rol "' .
                    htmlspecialchars($data['rol']) . '" no tiene acceso a este panel.';
            }
        } else {
            $loginError = 'ERROR: ' .
                htmlspecialchars($data['mensaje'] ?? 'Credenciales incorrectas.');
        }
    } else {
        $loginError = 'Ingrese correo y contrasena.';
    }
}

// ------------------------------------------------------------
// PROCESAMIENTO DEL CIERRE DE SESION (method="POST")
// Se ejecuta cuando el usuario hace clic en el boton SALIR.
// Destruye completamente la sesion del servidor y redirige
// al inicio para mostrar el formulario de login.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'logout') {

    session_destroy();                            // Elimina todos los datos de la sesion
    header('Location: ' . $_SERVER['PHP_SELF']); // Redirige a la misma pagina (login)
    exit;                                         // Detiene la ejecucion del script
}

// ------------------------------------------------------------
// PRE-CARGA DE DATOS VIA cURL
// Solo se ejecuta si el usuario ya tiene sesion activa.
// PHP consulta el backend Ktor antes de generar el HTML,
// de modo que cuando el navegador recibe la pagina los datos
// ya estan incluidos en las variables JS como JSON literal.
// Esto elimina el parpadeo inicial de cargando que ocurriria
// si JS tuviera que hacer fetch() al arrancar.
// ------------------------------------------------------------
$reportesJson = '[]'; // Valor por defecto si no hay sesion o falla la peticion
$usuariosJson = '[]';

if ($loggedIn) {
    $reportesJson = apiGet($BASE . '/reportes'); // Todos los reportes del sistema
    $usuariosJson = apiGet($BASE . '/usuarios'); // Todos los usuarios registrados
}

// Referencia directa a los datos del usuario de la sesion actual
$usuario = $_SESSION['usuario'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistema Semaforo Vecinal - VeciNet</title>
<!-- Hoja de estilos externa con todos los estilos visuales del sistema -->
<link rel="stylesheet" href="css/styles.css">
<!-- Libreria Chart.js para graficas estadisticas del modulo de estadisticas -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ============================================================
     BLOQUE PHP: PANTALLA DE LOGIN
     PHP decide mostrar este bloque cuando no hay sesion activa.
     El formulario usa method="POST" para que PHP procese las
     credenciales en el servidor antes de redirigir al dashboard.
============================================================ -->
<div id="pantallaLogin" style="display:flex;">
  <div class="login-box">
    <div class="login-header">
      <!-- Semaforo decorativo con los tres colores del sistema -->
      <div class="login-semaforo">
        <span class="s-r"></span> <!-- Rojo: emergencia alta -->
        <span class="s-y"></span> <!-- Amarillo: emergencia media -->
        <span class="s-g"></span> <!-- Verde: nivel normal -->
      </div>
      <h1>ACCESO SISTEMA VECINET</h1>
    </div>
    <div class="login-body">
      <!-- Formulario con method POST: las credenciales se envian al servidor -->
      <!-- action="" significa que el POST se procesa en la misma pagina -->
      <form method="POST" action="">
        <!-- Campo oculto que identifica que accion debe ejecutar PHP al recibir el POST -->
        <input type="hidden" name="action" value="login" />

        <div class="form-row">
          <label>Correo Electronico</label>
          <!-- PHP rellena el valor si hubo error de login, para no perder lo escrito -->
          <input type="email" name="email" id="lgEmail"
            placeholder="usuario@vecinet.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            required />
        </div>

        <div class="form-row">
          <label>Contrasena</label>
          <!-- type="password" oculta los caracteres al escribir -->
          <input type="password" name="pass" id="lgPass" required />
        </div>

        <!-- type="submit" envia el formulario al servidor al hacer clic -->
        <button type="submit" class="btn-login" id="lgBtn">INICIAR SESION</button>

        <?php if ($loginError): ?>
          <!-- PHP renderiza el error solo si existe uno -->
          <!-- htmlspecialchars previene inyeccion de HTML malicioso (XSS) -->
          <div class="login-error" style="display:block;">
            <?= htmlspecialchars($loginError) ?>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ============================================================
     BLOQUE PHP: DASHBOARD PRINCIPAL
     PHP muestra este bloque solo cuando hay sesion activa.
     Los datos del usuario se inyectan desde la sesion PHP
     sin necesidad de JS ni fetch().
============================================================ -->
<div id="dashboard" style="display:flex;flex-direction:column;min-height:100vh;">
  <header>
    <!-- Barra superior con nombre del sistema y reloj (el reloj lo actualiza JS cada segundo) -->
    <div class="header-top">
      <span>SISTEMA SEMAFORO VECINAL - VECINET | Fraccionamiento Vista Real</span>
      <span id="hFecha"></span> <!-- JS actualiza este elemento cada segundo -->
    </div>

    <div class="header-main">
      <div class="header-logo">
        <div class="header-semaforo">
          <span class="s-r"></span>
          <span class="s-y"></span>
          <span class="s-g"></span>
        </div>
        <div class="header-title">
          <h1>SEMAFORO VECINAL</h1>
          <!-- PHP inyecta el rol del usuario desde la sesion directamente en el HTML -->
          <p>Panel de Control &mdash;
            <span id="hRol"><?= htmlspecialchars(strtoupper($usuario['rol'])) ?></span>
          </p>
        </div>
      </div>

      <div class="header-user">
        <div style="text-align:right;">
          <!-- PHP inyecta nombre y rol del usuario autenticado desde la sesion -->
          <div class="user-name" id="hNombre">
            <?= htmlspecialchars(strtoupper($usuario['nombre'])) ?>
          </div>
          <div class="user-rol" id="hRolBadge">
            <?= htmlspecialchars(strtoupper($usuario['rol'])) ?>
          </div>
        </div>

        <!-- Formulario de cierre de sesion via POST a PHP -->
        <!-- Se usa formulario en lugar de enlace para mayor seguridad -->
        <form method="POST" action="" style="margin:0;">
          <input type="hidden" name="action" value="logout" />
          <button type="submit" class="btn-salir">SALIR</button>
        </form>
      </div>
    </div>
  </header>

  <div class="layout">
    <!-- Menu lateral: JS lo genera dinamicamente segun el rol del usuario activo -->
    <nav id="menu"></nav>
    <!-- Area de contenido: JS reemplaza su contenido al navegar entre modulos -->
    <main id="contenido"></main>
  </div>

  <footer>
    &copy; 2026 VeciNet - Sistema Integral de Vigilancia Vecinal | Todos los derechos reservados
  </footer>
</div>

<?php endif; ?>

<!-- ============================================================
     MODALES
     Siempre presentes en el DOM pero ocultas.
     JS las muestra agregando la clase CSS "on".
     JS las oculta removiendo la clase CSS "on".
============================================================ -->

<!-- Modal: Detalle completo de un reporte seleccionado -->
<div class="modal-bg" id="mDetalle">
  <div class="modal-box">
    <div class="modal-head">
      <h3>DETALLE DEL REPORTE</h3>
      <button class="modal-x" onclick="cerrar('mDetalle')">&times;</button>
    </div>
    <!-- JS inyecta el contenido desde verReporte() cuando el usuario hace clic en Ver -->
    <div class="modal-body" id="mDetalleBody"></div>
    <div class="modal-foot">
      <button class="btn" onclick="cerrar('mDetalle')">Cerrar</button>
    </div>
  </div>
</div>

<!-- Modal: Formulario para registrar un nuevo usuario -->
<div class="modal-bg" id="mNuevo">
  <div class="modal-box">
    <div class="modal-head">
      <h3>REGISTRAR NUEVO USUARIO</h3>
      <button class="modal-x" onclick="cerrar('mNuevo')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-field"><label>Nombre Completo</label><input type="text" id="nNombre" /></div>
        <div class="form-field"><label>Correo Electronico</label><input type="email" id="nEmail" /></div>
        <div class="form-field"><label>Telefono</label><input type="text" id="nTel" /></div>
        <div class="form-field"><label>Contrasena Inicial</label><input type="password" id="nPass" /></div>
        <div class="form-field form-full">
          <label>Rol del Usuario</label>
          <!-- Al cambiar el rol JS ejecuta toggleCerradaNuevo() para mostrar/ocultar cerrada -->
          <select id="nRol" onchange="toggleCerradaNuevo()">
            <option value="Presidente de Comite">Presidente de Comite</option>
            <option value="Comite">Comite</option>
            <option value="Encargado de Cerrada">Encargado de Cerrada</option>
            <option value="Residente">Residente</option>
            <option value="Guardia">Guardia</option>
          </select>
        </div>
        <!-- Solo visible cuando el rol es Residente o Encargado de Cerrada -->
        <div class="form-field form-full campo-cerrada" id="nCerradaWrap">
          <label>Cerrada a la que pertenece</label>
          <select id="nCerrada">
            <option value="">-- Seleccionar cerrada --</option>
            <option value="Boulevard">Boulevard</option>
            <option value="Cerrada 1">Cerrada 1</option>
            <option value="Cerrada 2">Cerrada 2</option>
            <option value="Cerrada 3">Cerrada 3</option>
            <option value="Cerrada 4">Cerrada 4</option>
            <option value="Cerrada 5">Cerrada 5</option>
            <option value="Cerrada 6">Cerrada 6</option>
            <option value="Cerrada 7">Cerrada 7</option>
          </select>
        </div>
      </div>
      <!-- JS muestra mensajes de exito o error aqui -->
      <div id="nMsg"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="cerrar('mNuevo')">Cancelar</button>
      <!-- JS envia el formulario via fetch() POST al hacer clic -->
      <button class="btn btn-p" onclick="guardarNuevo()">GUARDAR USUARIO</button>
    </div>
  </div>
</div>

<!-- Modal: Formulario para editar un usuario existente -->
<div class="modal-bg" id="mEditar">
  <div class="modal-box">
    <div class="modal-head">
      <h3>EDITAR USUARIO</h3>
      <button class="modal-x" onclick="cerrar('mEditar')">&times;</button>
    </div>
    <div class="modal-body">
      <!-- Campo oculto que guarda el ID del usuario que se esta editando -->
      <input type="hidden" id="eId" />
      <div class="form-grid">
        <div class="form-field"><label>Nombre Completo</label><input type="text" id="eNombre" /></div>
        <div class="form-field"><label>Correo Electronico</label><input type="email" id="eEmail" /></div>
        <div class="form-field"><label>Telefono</label><input type="text" id="eTel" /></div>
        <!-- Si se deja vacio el backend no modifica la contrasena existente -->
        <div class="form-field">
          <label>Nueva Contrasena</label>
          <input type="password" id="ePass" placeholder="Dejar vacio para no cambiar" />
        </div>
        <div class="form-field form-full">
          <label>Rol del Usuario</label>
          <select id="eRol" onchange="toggleCerradaEditar()">
            <option value="Presidente de Comite">Presidente de Comite</option>
            <option value="Comite">Comite</option>
            <option value="Encargado de Cerrada">Encargado de Cerrada</option>
            <option value="Residente">Residente</option>
            <option value="Guardia">Guardia</option>
          </select>
        </div>
        <div class="form-field form-full campo-cerrada" id="eCerradaWrap">
          <label>Cerrada a la que pertenece</label>
          <select id="eCerrada">
            <option value="">-- Seleccionar cerrada --</option>
            <option value="Cerrada 1">Cerrada 1</option>
            <option value="Cerrada 2">Cerrada 2</option>
            <option value="Cerrada 3">Cerrada 3</option>
            <option value="Cerrada 4">Cerrada 4</option>
            <option value="Cerrada 5">Cerrada 5</option>
            <option value="Cerrada 6">Cerrada 6</option>
          </select>
        </div>
      </div>
      <div id="eMsg"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="cerrar('mEditar')">Cancelar</button>
      <button class="btn btn-p" onclick="guardarEdicion()">GUARDAR CAMBIOS</button>
    </div>
  </div>
</div>

<!-- Modal: Confirmacion antes de eliminar un usuario -->
<div class="modal-bg" id="mEliminar">
  <div class="modal-box" style="max-width:420px;">
    <div class="modal-head">
      <h3>CONFIRMAR ELIMINACION</h3>
      <button class="modal-x" onclick="cerrar('mEliminar')">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;margin-bottom:12px;">Esta a punto de eliminar al usuario:</p>
      <!-- JS inyecta el nombre del usuario a eliminar aqui desde abrirEliminar() -->
      <div style="background:#ffd0d0;border:1px solid #cc0000;padding:10px;font-weight:bold;"
        id="eNombreConfirm"></div>
      <p style="font-size:12px;color:#666;margin-top:10px;">Esta accion no se puede deshacer.</p>
      <div id="delMsg"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="cerrar('mEliminar')">Cancelar</button>
      <button class="btn btn-d" onclick="confirmarEliminar()">ELIMINAR USUARIO</button>
    </div>
  </div>
</div>

<!-- Modal: Visor de fotos en pantalla completa -->
<!-- Clic en el fondo oscuro cierra el modal -->
<div class="modal-bg" id="mFoto" onclick="cerrar('mFoto')">
  <img id="mFotoImg" style="max-width:90vw;max-height:90vh;border:3px solid #888;" />
</div>

<!-- ============================================================
     JAVASCRIPT - Corre en el navegador del usuario
     PHP inyecta los datos iniciales como JSON literal en las
     variables reportes y usuarios para que JS los use de inmediato
     sin necesidad de fetch() al arrancar la pagina.
============================================================ -->
<script>
// Suprime advertencias de tipos en el editor, no afecta la ejecucion
// @ts-nocheck

// URL base del backend Ktor en Railway, usada en todas las peticiones fetch()
const BASE = 'https://vecinet-production.up.railway.app';

// ============================================================
// DATOS PRE-CARGADOS POR PHP
// La sintaxis <?= ?> imprime el valor PHP directamente en el JS.
// Al llegar al navegador estas variables ya tienen los datos
// sin necesidad de ninguna peticion adicional.
// ============================================================
let reportes = <?= $reportesJson ?>; // Arreglo con todos los reportes
let usuarios = <?= $usuariosJson ?>; // Arreglo con todos los usuarios

// ============================================================
// USUARIO ACTIVO - Inyectado desde la sesion PHP
// PHP genera el bloque JS correcto segun si hay sesion o no.
// ENT_QUOTES en htmlspecialchars protege las comillas dentro
// de los strings JS para que no rompan la sintaxis.
// ============================================================
<?php if ($loggedIn): ?>
let usuario = {
    nombre:     "<?= htmlspecialchars($usuario['nombre'], ENT_QUOTES) ?>",
    rol:        "<?= htmlspecialchars($usuario['rol'],    ENT_QUOTES) ?>",
    email:      "<?= htmlspecialchars($usuario['email'],  ENT_QUOTES) ?>",
    // fotoPerfil puede ser null si el usuario no ha subido foto de perfil
    fotoPerfil: <?= $usuario['fotoPerfil']
        ? '"' . htmlspecialchars($usuario['fotoPerfil'], ENT_QUOTES) . '"'
        : 'null' ?>
};
<?php else: ?>
let usuario = null; // Sin sesion activa
<?php endif; ?>

let idEliminar      = null;    // ID del usuario pendiente de eliminacion
let filtroRolActivo = 'todos'; // Filtro activo en la tabla de gestion de usuarios

// Actualiza el reloj del encabezado cada 1 segundo con la hora en formato mexicano
setInterval(() => {
    const el = document.getElementById('hFecha');
    if(el) el.textContent = new Date().toLocaleString('es-MX');
}, 1000);

// ============================================================
// LOGIN y SALIR - Funciones vacias intencionalmente
// El login lo procesa PHP via formulario POST.
// El cierre de sesion lo maneja el formulario con action=logout.
// Existen para evitar errores JS si algo las invoca.
// ============================================================
async function login() {}
function salir()       {}

// ============================================================
// mostrarDashboard
// No necesita fetch() porque PHP pre-cargo los datos.
// Solo activa el dashboard y navega al modulo monitor.
// ============================================================
function mostrarDashboard() {
    const login = document.getElementById('pantallaLogin');
    const dash  = document.getElementById('dashboard');
    if(login) login.style.display = 'none';
    if(dash)  dash.style.display  = 'flex';
    renderNav();
    navegar('monitor');
}

// fetchReportes y fetchUsuarios se conservan para los botones Actualizar
// en cada modulo. Hacen GET al backend via fetch() desde el navegador.
async function fetchReportes() {
    try { const r = await fetch(BASE+'/reportes'); reportes = await r.json(); }
    catch(e) { reportes = []; }
}
async function fetchUsuarios() {
    try { const r = await fetch(BASE+'/usuarios'); usuarios = await r.json(); }
    catch(e) { usuarios = []; }
}

// Funciones que evaluan el rol del usuario para control de acceso en JS
function esPresidente() { return usuario?.rol?.toLowerCase().includes('presidente'); }
function esComite()     { const r=usuario?.rol?.toLowerCase(); return r?.includes('comite')||r?.includes('encargado'); }
function esGuardia()    { return usuario?.rol?.toLowerCase().includes('guardia'); }

// Definicion de modulos y permisos por rol
// p=Presidente, c=Comite/Encargado, g=Guardia
const SECS = {
    monitor:      { label:'Monitor en Vivo',     p:true,  c:true,  g:true  },
    reportesMapa: { label:'Reportes en Mapa',    p:true,  c:true,  g:true  },
    reportesLst:  { label:'Lista de Reportes',   p:true,  c:true,  g:true  },
    estadisticas: { label:'Estadisticas',        p:true,  c:true,  g:false },
    usuarios:     { label:'Gestion de Usuarios', p:true,  c:false, g:false },
    emergencias:  { label:'Emergencias',         p:true,  c:true,  g:true  },
    bitacora:     { label:'Bitacora de Acceso',  p:true,  c:false, g:true  },
};

// Retorna true si el usuario activo puede acceder al modulo dado
function tieneAcceso(sec) {
    if(esPresidente()) return sec.p;
    if(esComite())     return sec.c;
    if(esGuardia())    return sec.g;
    return false;
}

// Genera los enlaces del menu lateral filtrando por permisos del rol activo
function renderNav() {
    const nav = document.getElementById('menu');
    nav.innerHTML = '<div class="nav-titulo">MODULOS DEL SISTEMA</div>';
    Object.entries(SECS).forEach(([key, sec]) => {
        if(!tieneAcceso(sec)) return;
        nav.innerHTML += `<a onclick="navegar('${key}')" id="nav-${key}">${sec.label}</a>`;
    });
}

// Marca el enlace activo, muestra carga y llama al renderizador del modulo
function navegar(sec) {
    document.querySelectorAll('nav#menu a').forEach(a => a.classList.remove('activo'));
    const el = document.getElementById('nav-' + sec);
    if(el) el.classList.add('activo');
    document.getElementById('contenido').innerHTML =
        '<div style="padding:24px;color:#666;font-style:italic;">Cargando modulo...</div>';
    setTimeout(() => {
        switch(sec) {
            case 'monitor':      renderMonitor();       break;
            case 'reportesMapa': renderReportesMapa();  break;
            case 'reportesLst':  renderReportesLista(); break;
            case 'estadisticas': renderEstadisticas();  break;
            case 'usuarios':     renderUsuarios();      break;
            case 'emergencias':  renderEmergencias();   break;
            case 'bitacora':     renderBitacora();      break;
        }
    }, 40);
}

// Monitor en vivo: contadores por nivel y ultimas 4 incidencias por columna
function renderMonitor(){
  const t=reportes.length,r3=reportes.filter(x=>x.nivel==3).length,r2=reportes.filter(x=>x.nivel==2).length,r1=reportes.filter(x=>x.nivel==1).length;
  set(`<div class="breadcrumb">Inicio &gt; Monitor en Vivo</div>
    <div class="stats-row">
      <div class="stat-cell azul"><div class="stat-num">${t}</div><div class="stat-label">Total Reportes</div></div>
      <div class="stat-cell rojo"><div class="stat-num">${r3}</div><div class="stat-label">Emergencia Alta</div></div>
      <div class="stat-cell amarillo"><div class="stat-num">${r2}</div><div class="stat-label">Emergencia Media</div></div>
      <div class="stat-cell verde"><div class="stat-num">${r1}</div><div class="stat-label">Nivel Normal</div></div>
    </div>
    <div class="sec-title">MONITOR EN VIVO - ESTADO DEL SISTEMA</div>
    <div class="sec-body">
      <div class="sec-toolbar"><button class="btn btn-p" onclick="fetchReportes().then(renderMonitor)">Actualizar</button><span style="font-size:12px;color:#666;">Usuarios: ${usuarios.length}</span></div>
      <div style="padding:12px;"><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div style="background:#ffd0d0;border:1px solid #cc0000;padding:12px;"><div style="font-weight:bold;font-size:12px;color:#8b0000;margin-bottom:8px;">EMERGENCIA ALTA</div>${reportes.filter(x=>x.nivel==3).slice(0,4).map(r=>`<div style="font-size:12px;padding:3px 0;border-bottom:1px solid #ecc;">${r.tipo} - ${r.usuarioNombre}</div>`).join('')||'<div style="font-size:12px;color:#888;font-style:italic;">Sin reportes</div>'}</div>
        <div style="background:#fff3cc;border:1px solid #cc8800;padding:12px;"><div style="font-weight:bold;font-size:12px;color:#664400;margin-bottom:8px;">EMERGENCIA MEDIA</div>${reportes.filter(x=>x.nivel==2).slice(0,4).map(r=>`<div style="font-size:12px;padding:3px 0;border-bottom:1px solid #edc;">${r.tipo} - ${r.usuarioNombre}</div>`).join('')||'<div style="font-size:12px;color:#888;font-style:italic;">Sin reportes</div>'}</div>
        <div style="background:#d0f0d0;border:1px solid #006600;padding:12px;"><div style="font-weight:bold;font-size:12px;color:#004400;margin-bottom:8px;">NIVEL NORMAL</div>${reportes.filter(x=>x.nivel==1).slice(0,4).map(r=>`<div style="font-size:12px;padding:3px 0;border-bottom:1px solid #cec;">${r.tipo} - ${r.usuarioNombre}</div>`).join('')||'<div style="font-size:12px;color:#888;font-style:italic;">Sin reportes</div>'}</div>
      </div></div>
    </div>`);
}

// Mapa: genera el contenedor y llama a initMapa con 80ms de retraso para que el DOM este listo
function renderReportesMapa(){
  set(`<div class="breadcrumb">Inicio &gt; Reportes en Mapa</div>
    <div class="sec-title">REPORTES EN MAPA - GOOGLE MAPS</div>
    <div class="sec-body" style="padding:0;">
      <div class="sec-toolbar"><button class="btn btn-p" onclick="fetchReportes().then(renderReportesMapa)">Actualizar</button><span style="font-size:12px;color:#666;">${reportes.length} reportes | Con ubicacion: ${reportes.filter(r=>r.latitud&&r.longitud).length}</span></div>
      <div id="mapaDiv"></div>
    </div>`);
  setTimeout(initMapa,80);
}

// Callback vacio requerido por la API de Google Maps al cargar
function mapaCargado(){}

// Inicializa Google Maps con marcadores de colores por nivel, o tabla de respaldo si falla
function initMapa(){
  const div=document.getElementById('mapaDiv');if(!div)return;
  if(typeof google==='undefined'||!google.maps){
    div.className='mapa-no-api';
    div.innerHTML=`<div style="text-align:center;"><div style="font-weight:bold;font-size:13px;margin-bottom:6px;">MAPA NO DISPONIBLE</div><div style="font-size:11px;color:#666;">Verificar clave de Google Maps API</div></div>
      <div style="max-height:240px;overflow-y:auto;width:92%;border:1px solid #333;"><table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="background:#1a2e44;color:white;"><th style="padding:5px 8px;text-align:left;">Tipo</th><th style="padding:5px 8px;">Nivel</th><th style="padding:5px 8px;text-align:left;">Por</th><th style="padding:5px 8px;text-align:left;">Coords</th></tr></thead>
        <tbody>${reportes.map(r=>`<tr style="border-bottom:1px solid #333;"><td style="padding:4px 8px;color:#ccc;">${r.tipo}</td><td style="padding:4px 8px;text-align:center;color:${r.nivel==3?'#ff6666':r.nivel==2?'#ffaa00':'#66ff66'};font-size:11px;">${nivelT(r.nivel)}</td><td style="padding:4px 8px;color:#ccc;">${r.usuarioNombre}</td><td style="padding:4px 8px;color:#aaa;font-size:11px;">${r.latitud&&r.longitud?parseFloat(r.latitud).toFixed(4)+', '+parseFloat(r.longitud).toFixed(4):'Sin ubicacion'}</td></tr>`).join('')||'<tr><td colspan="4" style="padding:12px;text-align:center;color:#666;">Sin reportes</td></tr>'}</tbody>
      </table></div>`;
    return;
  }
  // Coordenadas del Fraccionamiento Vista Real, Jaltepec, Hidalgo
  const centro={lat:20.118528,lng:-98.414794};
  /*
  Modos disponibles (descomentar el deseado):
  Restriccion a Vista Real: const mapa=new google.maps.Map(div,{zoom:18,center:centro,mapTypeControl:false,fullscreenControl:false,restriction:{latLngBounds:{north:20.1225,south:20.1145,east:-98.4095,west:-98.4200},strictBounds:false}});
  Abierto sin controles: const mapa=new google.maps.Map(div,{zoom:15,center:centro,mapTypeControl:false,fullscreenControl:false});
  */
  // Modo activo: abierto con selector de tipo de vista y boton de pantalla completa
  const mapa=new google.maps.Map(div,{zoom:15,center:centro,mapTypeControl:true,mapTypeControlOptions:{mapTypeIds:['roadmap','satellite','hybrid','terrain']},fullscreenControl:true});
  // Marcador por cada reporte con coordenadas validas, color segun nivel
  reportes.forEach(r=>{
    if(!r.latitud||!r.longitud)return;
    const lat=parseFloat(r.latitud),lng=parseFloat(r.longitud);if(isNaN(lat)||isNaN(lng))return;
    const marker=new google.maps.Marker({position:{lat,lng},map:mapa,title:r.tipo,icon:`http://maps.google.com/mapfiles/ms/icons/${r.nivel==3?'red':r.nivel==2?'yellow':'green'}-dot.png`});
    const info=new google.maps.InfoWindow({content:`<div style="font-family:Arial;font-size:13px;padding:8px;min-width:200px;"><strong>${r.tipo}</strong><br>Nivel: <strong>${nivelT(r.nivel)}</strong><br>Por: ${r.usuarioNombre}<br>Fecha: ${r.fecha||'-'}<br>${r.descripcion?'Desc: '+r.descripcion+'<br>':''}${r.fotoUrl?'<img src="'+r.fotoUrl+'" style="width:100%;margin-top:6px;border:1px solid #888;" />':''}</div>`});
    marker.addListener('click',()=>info.open(mapa,marker));
  });
  // Marcador azul fijo en el centro como referencia del fraccionamiento
  new google.maps.Marker({position:centro,map:mapa,title:'Fraccionamiento Vista Real',icon:'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'});
}

// Lista de reportes: dos tablas (activos y resueltos) con filtros combinables por nivel y cerrada
function renderReportesLista(){
  const activos=reportes.filter(r=>r.estado!=='resuelto');
  const resueltos=reportes.filter(r=>r.estado==='resuelto');
  // Funcion interna que genera las filas HTML para una lista de reportes
  const filasFn=(lista)=>lista.length?lista.map(r=>`<tr style="${r.estado==='resuelto'?'opacity:0.6;':''}">
    <td>${r.id}</td><td><span class="nivel-dot" style="background:${r.nivel==3?'#cc0000':r.nivel==2?'#cc8800':'#006600'};"></span>${r.tipo}</td>
    <td>${r.usuarioNombre}</td><td><span class="badge ${r.nivel==3?'b-rojo':r.nivel==2?'b-amarillo':'b-verde'}">${nivelT(r.nivel)}</span></td>
    <td>${r.descripcion||'<span style="color:#aaa;font-style:italic;">-</span>'}</td>
    <td>${r.fecha?r.fecha.substring(0,16):'-'}</td>
    <td>${r.latitud&&r.longitud?parseFloat(r.latitud).toFixed(5)+', '+parseFloat(r.longitud).toFixed(5):'<span style="color:#aaa;">Sin ubicacion</span>'}</td>
    <td>${r.fotoUrl?`<img src="${r.fotoUrl}" class="foto-thumb" onclick="verFoto(this.src)" />`:'<span style="color:#aaa;">-</span>'}</td>
    <td><div style="display:flex;gap:4px;"><button class="btn btn-p" onclick="verReporte(${r.id})">Ver</button>${r.estado!=='resuelto'?`<button class="btn btn-e" onclick="resolverReporte(${r.id})">Marcar como resuelto</button>`:'<span style="color:#006600;font-size:11px;font-weight:bold;">Resuelto</span>'}</div></td>
  </tr>`).join(''):`<tr><td colspan="9" style="text-align:center;padding:20px;font-style:italic;color:#888;">Sin reportes</td></tr>`;
  set(`<div class="breadcrumb">Inicio &gt; Lista de Reportes</div>
    <div class="sec-title">LISTA DE REPORTES DEL SISTEMA</div>
    <div class="sec-body" style="padding:0;">
      <div class="sec-toolbar">
        <button class="btn btn-p" onclick="fetchReportes().then(renderReportesLista)">Actualizar</button>
        <span style="font-size:12px;color:#666;">Total: ${reportes.length} | Activos: ${activos.length} | Resueltos: ${resueltos.length}</span>
        <!-- Filtro por nivel: onchange llama a aplicarFiltrosReportes() -->
        <select id="filtroNivel" onchange="aplicarFiltrosReportes()" style="padding:4px 8px;border:1px solid #888;font-family:Arial;font-size:12px;">
          <option value="">Todos los niveles</option>
          <option value="3">Emergencia Alta</option>
          <option value="2">Emergencia Media</option>
          <option value="1">Nivel Normal</option>
        </select>
        <!-- Filtro por cerrada: busca la cerrada del usuario que genero el reporte -->
        <select id="filtroCerrada" onchange="aplicarFiltrosReportes()" style="padding:4px 8px;border:1px solid #888;font-family:Arial;font-size:12px;">
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
      </div>
      <div class="sec-title" style="font-size:12px;background:#4a6e8e;">REPORTES ACTIVOS (${activos.length})</div>
      <div class="tbl-wrap" id="tablaActivos"><table><thead><tr><th>ID</th><th>Tipo</th><th>Reportado por</th><th>Nivel</th><th>Descripcion</th><th>Fecha</th><th>Coordenadas</th><th>Foto</th><th>Accion</th></tr></thead><tbody>${filasFn(activos)}</tbody></table></div>
      <div class="sec-title" style="font-size:12px;background:#555;margin-top:16px;">REPORTES RESUELTOS (${resueltos.length})</div>
      <div class="tbl-wrap" id="tablaResueltos"><table><thead><tr><th>ID</th><th>Tipo</th><th>Reportado por</th><th>Nivel</th><th>Descripcion</th><th>Fecha</th><th>Coordenadas</th><th>Foto</th><th>Accion</th></tr></thead><tbody>${filasFn(resueltos)}</tbody></table></div>
    </div>`);
}

// Aplica filtros combinables de nivel y cerrada sobre el arreglo de reportes
// Actualiza solo el contenido de las tablas sin recargar todo el modulo
function aplicarFiltrosReportes(){
  const nivel=document.getElementById('filtroNivel')?.value||'';
  const cerrada=document.getElementById('filtroCerrada')?.value||'';
  let filtrados=reportes;
  if(nivel) filtrados=filtrados.filter(r=>r.nivel==nivel);
  // Para filtrar por cerrada busca el usuario por email y compara su cerrada
  if(cerrada) filtrados=filtrados.filter(r=>{
    const u=usuarios.find(u=>u.email===r.usuarioEmail);
    return u&&u.cerrada===cerrada;
  });
  const activos=filtrados.filter(r=>r.estado!=='resuelto');
  const resueltos=filtrados.filter(r=>r.estado==='resuelto');
  const filasFn=(lista)=>lista.length?lista.map(r=>`<tr>
    <td>${r.id}</td><td><span class="nivel-dot" style="background:${r.nivel==3?'#cc0000':r.nivel==2?'#cc8800':'#006600'};"></span>${r.tipo}</td>
    <td>${r.usuarioNombre}</td><td><span class="badge ${r.nivel==3?'b-rojo':r.nivel==2?'b-amarillo':'b-verde'}">${nivelT(r.nivel)}</span></td>
    <td>${r.descripcion||'<span style="color:#aaa;font-style:italic;">-</span>'}</td>
    <td>${r.fecha?r.fecha.substring(0,16):'-'}</td>
    <td>${r.latitud&&r.longitud?parseFloat(r.latitud).toFixed(5)+', '+parseFloat(r.longitud).toFixed(5):'<span style="color:#aaa;">Sin ubicacion</span>'}</td>
    <td>${r.fotoUrl?`<img src="${r.fotoUrl}" class="foto-thumb" onclick="verFoto(this.src)" />`:'<span style="color:#aaa;">-</span>'}</td>
    <td><div style="display:flex;gap:4px;"><button class="btn btn-p" onclick="verReporte(${r.id})">Ver</button>${r.estado!=='resuelto'?`<button class="btn btn-e" onclick="resolverReporte(${r.id})">Resolver</button>`:'<span style="color:#006600;font-size:11px;font-weight:bold;">Resuelto</span>'}</div></td>
  </tr>`).join(''):`<tr><td colspan="9" style="text-align:center;padding:12px;font-style:italic;color:#888;">Sin resultados</td></tr>`;
  const encabezado=`<table><thead><tr><th>ID</th><th>Tipo</th><th>Reportado por</th><th>Nivel</th><th>Descripcion</th><th>Fecha</th><th>Coordenadas</th><th>Foto</th><th>Accion</th></tr></thead><tbody>`;
  const ta=document.getElementById('tablaActivos');
  const tr=document.getElementById('tablaResueltos');
  if(ta) ta.innerHTML=encabezado+filasFn(activos)+'</tbody></table>';
  if(tr) tr.innerHTML=encabezado+filasFn(resueltos)+'</tbody></table>';
}

// Pide confirmacion y hace PUT a /reportes/{id}/resolver para cambiar estado a resuelto
async function resolverReporte(id){
  if(!confirm('Marcar este reporte como resuelto?'))return;
  try{
    const res=await fetch(BASE+'/reportes/'+id+'/resolver',{method:'PUT'});
    const data=await res.json();
    if(data.success==='true'||data.success===true){await fetchReportes();renderReportesLista();}
  }catch(e){alert('Error de conexion');}
}

// Busca el reporte en el arreglo local y muestra su detalle completo en el modal
function verReporte(id){
  const r=reportes.find(x=>x.id==id);if(!r)return;
  document.getElementById('mDetalleBody').innerHTML=`
    <div style="background:#2c4a6e;color:white;padding:8px 12px;font-weight:bold;font-size:13px;margin-bottom:12px;">${r.tipo.toUpperCase()} - ${nivelT(r.nivel)}</div>
    <div class="det-row"><span class="det-key">ID</span><span>${r.id}</span></div>
    <div class="det-row"><span class="det-key">Tipo</span><span>${r.tipo}</span></div>
    <div class="det-row"><span class="det-key">Nivel</span><span><span class="badge ${r.nivel==3?'b-rojo':r.nivel==2?'b-amarillo':'b-verde'}">${nivelT(r.nivel)}</span></span></div>
    <div class="det-row"><span class="det-key">Reportado por</span><span>${r.usuarioNombre}</span></div>
    <div class="det-row"><span class="det-key">Email</span><span>${r.usuarioEmail}</span></div>
    <div class="det-row"><span class="det-key">Fecha</span><span>${r.fecha||'-'}</span></div>
    <div class="det-row"><span class="det-key">Coordenadas</span><span>${r.latitud&&r.longitud?r.latitud+', '+r.longitud:'No disponible'}</span></div>
    ${r.descripcion?`<div class="det-row" style="flex-direction:column;gap:4px;"><span class="det-key">Descripcion</span><span style="padding:8px;background:#f4f4f4;border:1px solid #ccc;">${r.descripcion}</span></div>`:''}
    ${r.fotoUrl?`<div style="margin-top:12px;"><div class="det-key" style="margin-bottom:6px;">EVIDENCIA FOTOGRAFICA</div><img src="${r.fotoUrl}" style="width:100%;border:2px solid #888;cursor:pointer;" onclick="verFoto(this.src)" /></div>`:''}`;
  abrir('mDetalle');
}

// Estadisticas: 4 graficas Chart.js calculadas desde los arreglos en memoria
// setTimeout(100) garantiza que los canvas existan en el DOM antes de inicializarlos
function renderEstadisticas(){
  const tipos={};reportes.forEach(r=>{tipos[r.tipo]=(tipos[r.tipo]||0)+1;});
  const byNivel=[reportes.filter(x=>x.nivel==1).length,reportes.filter(x=>x.nivel==2).length,reportes.filter(x=>x.nivel==3).length];
  const byDia={};reportes.forEach(r=>{const d=r.fecha?r.fecha.substring(0,10):'Sin fecha';byDia[d]=(byDia[d]||0)+1;});
  const porRol={};usuarios.forEach(u=>{porRol[u.rol]=(porRol[u.rol]||0)+1;});
  set(`<div class="breadcrumb">Inicio &gt; Estadisticas</div><div class="sec-title">ESTADISTICAS DEL SISTEMA</div>
    <div class="sec-body" style="padding:12px;">
      <div class="charts-row" style="margin-bottom:12px;">
        <div class="chart-panel"><div class="chart-ptitle">REPORTES POR NIVEL</div><div class="chart-body"><canvas id="cNivel"></canvas></div></div>
        <div class="chart-panel"><div class="chart-ptitle">TIPOS DE REPORTE</div><div class="chart-body"><canvas id="cTipos"></canvas></div></div>
      </div>
      <div class="charts-row">
        <div class="chart-panel"><div class="chart-ptitle">REPORTES POR DIA</div><div class="chart-body"><canvas id="cDia"></canvas></div></div>
        <div class="chart-panel"><div class="chart-ptitle">USUARIOS POR ROL</div><div class="chart-body"><canvas id="cRoles"></canvas></div></div>
      </div>
    </div>`);
  setTimeout(()=>{
    new Chart(document.getElementById('cNivel'),{type:'doughnut',data:{labels:['Normal','Medio','Alto'],datasets:[{data:byNivel,backgroundColor:['#006600','#cc8800','#cc0000'],borderWidth:2,borderColor:'#888'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{family:'Arial',size:11}}}}}});
    new Chart(document.getElementById('cTipos'),{type:'bar',data:{labels:Object.keys(tipos),datasets:[{label:'Cantidad',data:Object.values(tipos),backgroundColor:'#2c4a6e',borderColor:'#1a2e44',borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
    const dias=Object.keys(byDia).slice(-10);
    new Chart(document.getElementById('cDia'),{type:'line',data:{labels:dias,datasets:[{label:'Reportes',data:dias.map(d=>byDia[d]),borderColor:'#2c4a6e',backgroundColor:'rgba(44,74,110,0.15)',fill:true,tension:0,pointRadius:4}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
    new Chart(document.getElementById('cRoles'),{type:'doughnut',data:{labels:Object.keys(porRol),datasets:[{data:Object.values(porRol),backgroundColor:['#cc8800','#2c4a6e','#664488','#006600','#888'],borderWidth:2,borderColor:'#888'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{family:'Arial',size:11}}}}}});
  },100);
}

// Gestion de usuarios: solo accesible para Presidente, tabla filtrable por rol con CRUD
function renderUsuarios(filtro){
  if(!esPresidente()){set('<div style="padding:24px;color:#cc0000;font-weight:bold;">ACCESO DENEGADO</div>');return;}
  if(filtro!==undefined)filtroRolActivo=filtro;
  const uf=filtroRolActivo==='todos'?usuarios:usuarios.filter(u=>u.rol.toLowerCase().includes(filtroRolActivo.toLowerCase()));
  const filas=uf.length?uf.map(u=>`<tr><td>${u.id}</td><td><strong>${u.nombre}</strong></td><td>${u.email}</td><td>${u.telefono||'-'}</td><td>${rolTag(u.rol)}</td><td>${u.cerrada||'<span style="color:#aaa;">-</span>'}</td><td><div style="display:flex;gap:4px;"><button class="btn btn-e" onclick="abrirEditar(${u.id})">Editar</button><button class="btn btn-d" onclick="abrirEliminar(${u.id},'${u.nombre.replace(/'/g,"\'")}')">Eliminar</button></div></td></tr>`).join(''):`<tr><td colspan="7" style="text-align:center;padding:20px;font-style:italic;color:#888;">No hay usuarios con este rol</td></tr>`;
  const cnt=k=>k==='todos'?usuarios.length:usuarios.filter(u=>u.rol.toLowerCase().includes(k)).length;
  const filtros=[{key:'todos',label:'TODOS ('+cnt('todos')+')',cls:''},{key:'presidente',label:'PRESIDENTE ('+cnt('presidente')+')',cls:'f-presidente'},{key:'comite',label:'COMITE ('+cnt('comite')+')',cls:'f-comite'},{key:'encargado',label:'ENCARGADO ('+cnt('encargado')+')',cls:'f-encargado'},{key:'guardia',label:'GUARDIA ('+cnt('guardia')+')',cls:'f-guardia'},{key:'residente',label:'RESIDENTE ('+cnt('residente')+')',cls:'f-residente'}];
  set(`<div class="breadcrumb">Inicio &gt; Gestion de Usuarios</div>
    <div class="sec-title">GESTION DE USUARIOS DEL SISTEMA</div>
    <div class="sec-body" style="padding:0;">
      <div class="sec-toolbar">
        <button class="btn btn-p" onclick="abrir('mNuevo')">+ Registrar Usuario</button>
        <button class="btn" onclick="fetchUsuarios().then(()=>renderUsuarios())">Actualizar</button>
        <span style="font-size:12px;color:#666;">Filtrar:</span>
        <div class="filtros-rol">${filtros.map(f=>`<button class="filtro-btn ${f.cls} ${filtroRolActivo===f.key?'activo':''}" onclick="renderUsuarios('${f.key}')">${f.label}</button>`).join('')}</div>
      </div>
      <div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Telefono</th><th>Rol</th><th>Cerrada</th><th>Acciones</th></tr></thead><tbody>${filas}</tbody></table></div>
    </div>
    <div class="sec-title" style="margin-top:16px;">RESUMEN DE PERMISOS POR ROL</div>
    <div class="sec-body" style="padding:0;"><div class="tbl-wrap"><table>
      <thead><tr><th>Rol</th><th>Monitor</th><th>Mapa</th><th>Reportes</th><th>Estadisticas</th><th>Usuarios</th><th>Emergencias</th><th>Bitacora</th><th>Panel Web</th></tr></thead>
      <tbody>
        <tr><td>${rolTag('Presidente de Comite')}</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td></tr>
        <tr><td>${rolTag('Comite')}</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td><td>NO</td><td>SI</td><td>NO</td><td>SI</td></tr>
        <tr><td>${rolTag('Encargado de Cerrada')}</td><td>SI</td><td>SI</td><td>SI</td><td>SI</td><td>NO</td><td>SI</td><td>NO</td><td>SI</td></tr>
        <tr><td>${rolTag('Guardia')}</td><td>SI</td><td>SI</td><td>SI</td><td>NO</td><td>NO</td><td>SI</td><td>SI</td><td>SI</td></tr>
        <tr><td>${rolTag('Residente')}</td><td colspan="8" style="color:#888;font-style:italic;text-align:center;">Sin acceso al panel web - Solo app movil</td></tr>
        <tr><td>${rolTag('Visitante')}</td><td colspan="8" style="color:#888;font-style:italic;text-align:center;">Sin acceso al panel web - Solo app movil</td></tr>
      </tbody>
    </table></div></div>`);
}

// Muestra/oculta el campo cerrada en nuevo usuario segun el rol seleccionado
function toggleCerradaNuevo(){const r=document.getElementById('nRol').value;document.getElementById('nCerradaWrap').classList.toggle('visible',r==='Residente'||r==='Encargado de Cerrada');}

// Muestra/oculta el campo cerrada en editar usuario segun el rol seleccionado
function toggleCerradaEditar(){const r=document.getElementById('eRol').value;document.getElementById('eCerradaWrap').classList.toggle('visible',r==='Residente'||r==='Encargado de Cerrada');}

// Rellena el formulario de edicion con los datos del usuario y abre el modal
function abrirEditar(id){
  const u=usuarios.find(x=>x.id==id);if(!u)return;
  document.getElementById('eId').value=u.id;document.getElementById('eNombre').value=u.nombre;
  document.getElementById('eEmail').value=u.email;document.getElementById('eTel').value=u.telefono||'';
  document.getElementById('ePass').value='';document.getElementById('eRol').value=u.rol;
  document.getElementById('eCerrada').value=u.cerrada||'';document.getElementById('eMsg').innerHTML='';
  toggleCerradaEditar();abrir('mEditar');
}

// Guarda el ID a eliminar y abre el modal de confirmacion
function abrirEliminar(id,nombre){idEliminar=id;document.getElementById('eNombreConfirm').textContent=nombre;document.getElementById('delMsg').innerHTML='';abrir('mEliminar');}

// Valida el formulario y hace POST a /usuarios/nuevo via fetch()
async function guardarNuevo(){
  const nombre=document.getElementById('nNombre').value.trim(),email=document.getElementById('nEmail').value.trim();
  const tel=document.getElementById('nTel').value.trim(),pass=document.getElementById('nPass').value;
  const rol=document.getElementById('nRol').value,cerrada=document.getElementById('nCerrada').value;
  const msg=document.getElementById('nMsg');
  if(!nombre||!email||!tel||!pass){msg.className='msg-err';msg.textContent='ERROR: Todos los campos son obligatorios.';return;}
  if((rol==='Residente'||rol==='Encargado de Cerrada')&&!cerrada){msg.className='msg-err';msg.textContent='ERROR: Debe seleccionar la cerrada.';return;}
  msg.className='';msg.textContent='Procesando...';
  try{
    const res=await fetch(BASE+'/usuarios/nuevo',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({nombre,email,telefono:tel,password:pass,rol,cerrada:cerrada||''})});
    const data=await res.json();
    if(data.success==='true'||data.success===true){msg.className='msg-ok';msg.textContent='Usuario registrado correctamente.';await fetchUsuarios();setTimeout(()=>{cerrar('mNuevo');limpiarNuevo();renderUsuarios();},1200);}
    else{msg.className='msg-err';msg.textContent='ERROR: '+(data.mensaje||'No se pudo registrar.');}
  }catch(e){msg.className='msg-err';msg.textContent='ERROR DE CONEXION.';}
}

// Valida el formulario de edicion y hace PUT a /usuarios/{id} via fetch()
// La contrasena solo se incluye si el campo no esta vacio
async function guardarEdicion(){
  const id=document.getElementById('eId').value,nombre=document.getElementById('eNombre').value.trim();
  const email=document.getElementById('eEmail').value.trim(),tel=document.getElementById('eTel').value.trim();
  const pass=document.getElementById('ePass').value,rol=document.getElementById('eRol').value;
  const cerrada=document.getElementById('eCerrada').value,msg=document.getElementById('eMsg');
  if(!nombre||!email){msg.className='msg-err';msg.textContent='ERROR: Nombre y email son obligatorios.';return;}
  if((rol==='Residente'||rol==='Encargado de Cerrada')&&!cerrada){msg.className='msg-err';msg.textContent='ERROR: Debe seleccionar la cerrada.';return;}
  const body={nombre,email,telefono:tel,rol,cerrada:cerrada||''};if(pass)body.password=pass;
  msg.className='';msg.textContent='Procesando...';
  try{
    const res=await fetch(BASE+'/usuarios/'+id,{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const data=await res.json();
    if(data.success==='true'||data.success===true){msg.className='msg-ok';msg.textContent='Usuario actualizado correctamente.';await fetchUsuarios();setTimeout(()=>{cerrar('mEditar');renderUsuarios();},1200);}
    else{msg.className='msg-err';msg.textContent='ERROR: '+(data.mensaje||'No se pudo actualizar.');}
  }catch(e){msg.className='msg-err';msg.textContent='ERROR DE CONEXION.';}
}

// Hace DELETE a /usuarios/{id} con el ID guardado en idEliminar
async function confirmarEliminar(){
  const msg=document.getElementById('delMsg');msg.className='';msg.textContent='Procesando...';
  try{
    const res=await fetch(BASE+'/usuarios/'+idEliminar,{method:'DELETE'});
    const data=await res.json();
    if(data.success==='true'||data.success===true){msg.className='msg-ok';msg.textContent='Usuario eliminado correctamente.';await fetchUsuarios();setTimeout(()=>{cerrar('mEliminar');renderUsuarios();},1200);}
    else{msg.className='msg-err';msg.textContent='ERROR: '+(data.mensaje||'No se pudo eliminar.');}
  }catch(e){msg.className='msg-err';msg.textContent='ERROR DE CONEXION.';}
}

// Limpia todos los campos del formulario de nuevo usuario
function limpiarNuevo(){
  ['nNombre','nEmail','nTel','nPass'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('nCerrada').value='';document.getElementById('nCerradaWrap').classList.remove('visible');document.getElementById('nMsg').innerHTML='';
}

// Modulo estatico con numeros de emergencia del fraccionamiento
function renderEmergencias(){set(`<div class="breadcrumb">Inicio &gt; Numeros de Emergencia</div><div class="sec-title">NUMEROS DE EMERGENCIA</div><div class="sec-body" style="padding:0;"><div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Servicio</th><th>Numero</th><th>Disponibilidad</th></tr></thead><tbody><tr><td>E001</td><td>Policia</td><td><strong style="font-size:16px;">911</strong></td><td>24 horas / 7 dias</td></tr><tr><td>E002</td><td>Ambulancia</td><td><strong style="font-size:16px;">912</strong></td><td>24 horas / 7 dias</td></tr><tr><td>E003</td><td>Bomberos</td><td><strong style="font-size:16px;">913</strong></td><td>24 horas / 7 dias</td></tr><tr><td>E004</td><td>Seguridad Vecinal</td><td><strong style="font-size:16px;">555-0100</strong></td><td>24 horas / 7 dias</td></tr></tbody></table></div></div>`);}

// Modulo con datos de ejemplo para bitacora vehicular (datos estaticos de prototipo)
function renderBitacora(){set(`<div class="breadcrumb">Inicio &gt; Bitacora de Acceso</div><div class="sec-title">BITACORA DE ACCESO VEHICULAR</div><div class="sec-body" style="padding:0;"><div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Fecha</th><th>Hora</th><th>Vehiculo</th><th>Tipo</th><th>Residente</th></tr></thead><tbody><tr><td>001</td><td>2026-03-21</td><td>08:30</td><td>ABC-1234</td><td>Entrada</td><td>Juan Perez</td></tr><tr><td>002</td><td>2026-03-21</td><td>10:15</td><td>XYZ-5678</td><td>Salida</td><td>Maria Garcia</td></tr><tr><td>003</td><td>2026-03-21</td><td>14:45</td><td>DEF-9012</td><td>Entrada</td><td>Carlos Lopez</td></tr></tbody></table></div></div><div class="sec-title" style="margin-top:16px;">CONTROL DE ACCESOS</div><div class="sec-body" style="padding:0;"><div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Nombre</th><th>Vehiculo</th><th>Placa</th><th>Contacto</th></tr></thead><tbody><tr><td>V001</td><td>Juan Perez</td><td>Toyota Corolla</td><td>ABC-1234</td><td>555-1234</td></tr><tr><td>V002</td><td>Maria Garcia</td><td>Honda Civic</td><td>XYZ-5678</td><td>555-5678</td></tr><tr><td>V003</td><td>Carlos Lopez</td><td>Ford Focus</td><td>DEF-9012</td><td>555-9012</td></tr></tbody></table></div></div>`);}

// Convierte el valor numerico del nivel a texto descriptivo en mayusculas
function nivelT(n){return n==3||n==='3'?'EMERGENCIA ALTA':n==2||n==='2'?'EMERGENCIA MEDIA':'NIVEL NORMAL';}

// Genera una etiqueta HTML con color semantico segun el rol del usuario
function rolTag(rol){const r=rol.toLowerCase();let cls='r-visitante';if(r.includes('presidente'))cls='r-presidente';else if(r.includes('comite'))cls='r-comite';else if(r.includes('encargado'))cls='r-comite';else if(r.includes('guardia'))cls='r-guardia';else if(r.includes('residente'))cls='r-residente';return `<span class="rol-tag ${cls}">${rol.toUpperCase()}</span>`;}

// Reemplaza el contenido del area principal del dashboard con el HTML dado
function set(html){document.getElementById('contenido').innerHTML=html;}

// Muestra un modal agregando la clase CSS 'on' que cambia su display a flex
function abrir(id){document.getElementById(id).classList.add('on');}

// Oculta un modal removiendo la clase CSS 'on'
function cerrar(id){document.getElementById(id).classList.remove('on');}

// Asigna la URL de la foto al visor y abre el modal fotografico
function verFoto(url){document.getElementById('mFotoImg').src=url;abrir('mFoto');}

// ============================================================
// INICIALIZACION AL CARGAR EL DOM
// DOMContentLoaded se dispara cuando el HTML termina de parsearse.
// PHP inyecta el bloque condicional para navegar al monitor
// directamente si ya hay sesion activa.
// ============================================================
document.addEventListener('DOMContentLoaded',()=>{
  // Cierra modales al hacer clic en el fondo oscuro
  document.querySelectorAll('.modal-bg').forEach(m=>{
    m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('on');});
  });
  <?php if ($loggedIn): ?>
  // Sesion PHP activa: datos pre-cargados por PHP, navega directo al dashboard
  renderNav();
  navegar('monitor');
  <?php endif; ?>
});

// Manejador global de errores: si JS falla evita que la pagina quede en estado invalido
window.onerror=function(){return false;};
</script>

<!-- API de Google Maps cargada de forma asincrona con defer para no bloquear el HTML.
     callback=mapaCargado indica que funcion llamar cuando la API termina de cargar. -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCdP0COLhCjAjt8dCel8-Pv5VWqJ-Ee_Lw&callback=mapaCargado" async defer></script>
</body>
</html>