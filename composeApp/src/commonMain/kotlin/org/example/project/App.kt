package org.example.project

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Accessibility
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import kotlinproject.composeapp.generated.resources.Res
import kotlinproject.composeapp.generated.resources.logo
import kotlinx.coroutines.launch
import org.jetbrains.compose.resources.painterResource

data class OpcionDaltonismo(
    val modo       : DaltonismMode,
    val nombre     : String,
    val descripcion: String,
    val color      : Color
)

data class Reporte(
    val tipo       : String,
    val nivel      : String,
    val color      : Color,
    val descripcion: String,
    val foto       : String?,
    val ubicacion  : Ubicacion?
)

// ============================================================
// APP
// ============================================================
@Composable
fun App(fcmToken: String? = null) {
    var isDarkMode   by remember { mutableStateOf(false) }
    var daltonism    by remember { mutableStateOf(DaltonismMode.NONE) }
    var highContrast by remember { mutableStateOf(false) }

    AppTheme.isDark       = isDarkMode
    AppTheme.daltonism    = daltonism
    AppTheme.highContrast = highContrast

    MaterialTheme {
        RequestAppPermissions()

        val scope = rememberCoroutineScope()

        var currentScreen              by remember { mutableStateOf("splash") }
        var nombreUsuario              by remember { mutableStateOf("") }
        var emailUsuario               by remember { mutableStateOf("") }
        var rolUsuario                 by remember { mutableStateOf("") }
        var nivelEmergencia            by remember { mutableStateOf("") }
        var tipoReporte                by remember { mutableStateOf("") }
        var fotoPerfil                 by remember { mutableStateOf<String?>(null) }
        var mostrarDialogoCerrarSesion by remember { mutableStateOf(false) }
        val reportes = remember { mutableStateListOf<Reporte>() }

        // Verificar sesion guardada al iniciar
        LaunchedEffect(Unit) {
            val sesion = SessionManager.obtenerSesion()
            if (sesion != null) {
                nombreUsuario = sesion.nombre
                emailUsuario  = sesion.email
                rolUsuario    = sesion.rol
                fotoPerfil    = sesion.foto
                // Renovar fecha al abrir la app (opcion B — expira 90 dias sin actividad)
                SessionManager.guardarSesion(sesion.nombre, sesion.email, sesion.rol, sesion.foto)
                if (!fcmToken.isNullOrBlank()) {
                    try { registrarToken(sesion.email, fcmToken) } catch (e: Exception) { }
                }
                currentScreen = "semaforo"
            } else {
                currentScreen = "login"
            }
        }

        if (mostrarDialogoCerrarSesion) {
            AlertDialog(
                onDismissRequest = { mostrarDialogoCerrarSesion = false },
                containerColor   = AppTheme.card,
                title  = { Text("Cerrar sesion", color = AppTheme.texto) },
                text   = { Text("Deseas cerrar sesion?", color = AppTheme.textoSec) },
                confirmButton = {
                    TextButton(onClick = {
                        mostrarDialogoCerrarSesion = false
                        scope.launch { SessionManager.cerrarSesion() }
                        currentScreen = "login"
                    }) { Text("Si, salir", color = AppTheme.accent) }
                },
                dismissButton = {
                    TextButton(onClick = { mostrarDialogoCerrarSesion = false }) {
                        Text("Cancelar", color = AppTheme.textoSec)
                    }
                }
            )
        }

        when (currentScreen) {

            "splash" -> {
                Box(
                    modifier         = Modifier.fillMaxSize().background(AppTheme.fondo),
                    contentAlignment = Alignment.Center
                ) {
                    CircularProgressIndicator(color = AppTheme.accent)
                }
            }

            "login" -> LoginScreen(
                isDarkMode        = isDarkMode,
                onToggleDark      = { isDarkMode = !isDarkMode },
                daltonism         = daltonism,
                onChangeDaltonism = { daltonism = it },
                highContrast      = highContrast,
                onToggleContrast  = { highContrast = !highContrast },
                onLoginSuccess    = { nombre, rol, email, foto ->
                    nombreUsuario = nombre
                    rolUsuario    = rol
                    emailUsuario  = email
                    fotoPerfil    = foto
                    scope.launch {
                        SessionManager.guardarSesion(nombre, email, rol, foto)
                        if (!fcmToken.isNullOrBlank()) {
                            try { registrarToken(email, fcmToken) } catch (e: Exception) { }
                        }
                    }
                    val rolLower = rol.lowercase()
                    currentScreen = if (
                        rolLower.contains("presidente") ||
                        rolLower.contains("comite")     ||
                        rolLower.contains("encargado")  ||
                        rolLower.contains("guardia")
                    ) "semaforo" else "codigo"
                }
            )

            "codigo" -> CodigoAccesoScreen(
                email          = emailUsuario,
                isDarkMode     = isDarkMode,
                onCodigoValido = { currentScreen = "semaforo" },
                onVolver       = { currentScreen = "login" }
            )

            "semaforo" -> {
                BackHandlerWrapper { mostrarDialogoCerrarSesion = true }
                SemaforoScreen(
                    nombre              = nombreUsuario,
                    rol                 = rolUsuario,
                    email               = emailUsuario,
                    fotoPerfil          = fotoPerfil,
                    isDarkMode          = isDarkMode,
                    onToggleDark        = { isDarkMode = !isDarkMode },
                    daltonism           = daltonism,
                    onChangeDaltonism   = { daltonism = it },
                    highContrast        = highContrast,
                    onToggleContrast    = { highContrast = !highContrast },
                    onFotoChanged       = { fotoPerfil = it },
                    onNivelSeleccionado = { nivel: String, tipo: String ->
                        nivelEmergencia = nivel
                        tipoReporte     = tipo
                        currentScreen   = "emergencia"
                    },
                    onVerReportes = { currentScreen = "reportes" },
                    onVerQr       = { currentScreen = "qr" },
                    onScanner     = { currentScreen = "scanner" },
                    onLogout      = { mostrarDialogoCerrarSesion = true }
                )
            }

            "qr" -> {
                BackHandlerWrapper { currentScreen = "semaforo" }
                QrResidenteScreen(
                    email      = emailUsuario,
                    nombre     = nombreUsuario,
                    numeroCasa = "",
                    cerrada    = "",
                    onVolver   = { currentScreen = "semaforo" }
                )
            }

            "scanner" -> {
                BackHandlerWrapper { currentScreen = "semaforo" }
                ScannerGuardiaScreen(
                    nombreGuardia = nombreUsuario,
                    emailGuardia  = emailUsuario,
                    onVolver      = { currentScreen = "semaforo" }
                )
            }

            "detalle" -> {
                BackHandlerWrapper { currentScreen = "emergencia" }
                DetalleReporteScreen(
                    nombre            = nombreUsuario,
                    email             = emailUsuario,
                    nivel             = nivelEmergencia,
                    tipo              = tipoReporte,
                    fotoPerfil        = fotoPerfil,
                    isDarkMode        = isDarkMode,
                    onToggleDark      = { isDarkMode = !isDarkMode },
                    daltonism         = daltonism,
                    onChangeDaltonism = { daltonism = it },
                    highContrast      = highContrast,
                    onToggleContrast  = { highContrast = !highContrast },
                    onFotoChanged     = { fotoPerfil = it },
                    onReportar        = { descripcion: String, foto: String?, ubicacion: Ubicacion? ->
                        val color = when (nivelEmergencia) {
                            "ROJO"     -> AppTheme.rojo
                            "AMARILLO" -> AppTheme.amarillo
                            else       -> AppTheme.verde
                        }
                        reportes.add(Reporte(tipoReporte, nivelEmergencia, color, descripcion, foto, ubicacion))
                        currentScreen = "semaforo"
                    },
                    onVolver = { currentScreen = "emergencia" }
                )
            }

            "emergencia" -> {
                BackHandlerWrapper { currentScreen = "semaforo" }
                EmergenciaScreen(
                    nombre             = nombreUsuario,
                    email              = emailUsuario,
                    nivel              = nivelEmergencia,
                    fotoPerfil         = fotoPerfil,
                    isDarkMode         = isDarkMode,
                    onToggleDark       = { isDarkMode = !isDarkMode },
                    daltonism          = daltonism,
                    onChangeDaltonism  = { daltonism = it },
                    highContrast       = highContrast,
                    onToggleContrast   = { highContrast = !highContrast },
                    onFotoChanged      = { fotoPerfil = it },
                    onTipoSeleccionado = { tipo: String ->
                        tipoReporte   = tipo
                        currentScreen = "detalle"
                    },
                    onVolver = { currentScreen = "semaforo" }
                )
            }

            "reportes" -> {
                BackHandlerWrapper { currentScreen = "semaforo" }
                ReportesScreen(
                    nombre            = nombreUsuario,
                    email             = emailUsuario,
                    fotoPerfil        = fotoPerfil,
                    isDarkMode        = isDarkMode,
                    onToggleDark      = { isDarkMode = !isDarkMode },
                    daltonism         = daltonism,
                    onChangeDaltonism = { daltonism = it },
                    highContrast      = highContrast,
                    onToggleContrast  = { highContrast = !highContrast },
                    onFotoChanged     = { fotoPerfil = it },
                    reportes          = reportes,
                    onVolver          = { currentScreen = "semaforo" }
                )
            }
        }
    }
}

// ============================================================
// CODIGO DE ACCESO SCREEN
// ============================================================
@Composable
fun CodigoAccesoScreen(
    email          : String,
    isDarkMode     : Boolean,
    onCodigoValido : () -> Unit,
    onVolver       : () -> Unit
) {
    val scope    = rememberCoroutineScope()
    var codigo   by remember { mutableStateOf("") }
    var errorMsg by remember { mutableStateOf("") }
    var loading  by remember { mutableStateOf(false) }

    Box(modifier = Modifier.fillMaxSize().background(AppTheme.fondo)) {
        Column(
            modifier            = Modifier.fillMaxSize().padding(32.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                text       = "Codigo de acceso",
                fontSize   = 24.sp,
                fontWeight = FontWeight.Bold,
                color      = AppTheme.texto,
                modifier   = Modifier.padding(bottom = 8.dp)
            )
            Text(
                text      = "Ingresa el codigo de 9 digitos\nproporcionado por el presidente o comite.",
                fontSize  = 14.sp,
                color     = AppTheme.textoSec,
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                modifier  = Modifier.padding(bottom = 32.dp)
            )
            OutlinedTextField(
                value         = codigo,
                onValueChange = { if (it.length <= 9) codigo = it.uppercase() },
                label         = { Text("Codigo (9 caracteres)", color = AppTheme.textoSec) },
                singleLine    = true,
                modifier      = Modifier.fillMaxWidth().padding(bottom = 24.dp),
                colors        = OutlinedTextFieldDefaults.colors(
                    focusedTextColor        = AppTheme.texto,
                    unfocusedTextColor      = AppTheme.texto,
                    focusedBorderColor      = AppTheme.accent,
                    unfocusedBorderColor    = AppTheme.textoSec,
                    focusedContainerColor   = AppTheme.surface,
                    unfocusedContainerColor = AppTheme.surface
                )
            )
            if (errorMsg.isNotEmpty()) {
                Text(errorMsg, color = AppTheme.rojo, modifier = Modifier.padding(bottom = 16.dp))
            }
            Button(
                onClick = {
                    scope.launch {
                        loading  = true
                        errorMsg = ""
                        try {
                            val res = validarCodigo(codigo.trim(), email)
                            if (res.success) onCodigoValido()
                            else errorMsg = res.mensaje
                        } catch (e: Exception) {
                            errorMsg = "Error de conexion"
                        }
                        loading = false
                    }
                },
                modifier = Modifier.fillMaxWidth().height(50.dp),
                colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.accent),
                enabled  = codigo.length == 9 && !loading
            ) {
                if (loading) CircularProgressIndicator(color = Color.White, modifier = Modifier.size(24.dp))
                else Text("Verificar codigo", color = Color.White, fontWeight = FontWeight.Bold)
            }
            TextButton(onClick = onVolver, modifier = Modifier.padding(top = 8.dp)) {
                Text("Volver al login", color = AppTheme.textoSec)
            }
        }
    }
}

// ============================================================
// MENU DE ACCESIBILIDAD
// ============================================================
@Composable
fun MenuAccesibilidad(
    isDarkMode        : Boolean,
    onToggleDark      : () -> Unit,
    daltonism         : DaltonismMode,
    onChangeDaltonism : (DaltonismMode) -> Unit,
    highContrast      : Boolean,
    onToggleContrast  : () -> Unit
) {
    var expandido by remember { mutableStateOf(false) }

    val opcionesDaltonismo = listOf(
        OpcionDaltonismo(DaltonismMode.NONE,         "Sin filtro",    "Vision estandar",              Color.Gray),
        OpcionDaltonismo(DaltonismMode.PROTANOPIA,   "Protanopia",    "Dificultad con el rojo",       VecinetColors.rojoProtan),
        OpcionDaltonismo(DaltonismMode.DEUTERANOPIA, "Deuteranopia",  "Dificultad con el verde",      VecinetColors.verdeDeutan),
        OpcionDaltonismo(DaltonismMode.TRITANOPIA,   "Tritanopia",    "Dificultad con azul-amarillo", VecinetColors.amarilloTritan)
    )

    Box {
        IconButton(onClick = { expandido = true }) {
            Icon(
                imageVector        = Icons.Filled.Accessibility,
                contentDescription = "Menu de accesibilidad",
                tint               = Color.White,
                modifier           = Modifier.size(26.dp)
            )
        }

        DropdownMenu(
            expanded         = expandido,
            onDismissRequest = { expandido = false },
            modifier         = Modifier.width(268.dp).background(AppTheme.card)
        ) {
            DropdownMenuItem(enabled = false, text = {
                Text("TEMA", fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    color = AppTheme.accent, letterSpacing = 1.sp)
            }, onClick = {})

            DropdownMenuItem(
                text = {
                    Row(verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween,
                        modifier = Modifier.fillMaxWidth()) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(if (isDarkMode) "\u2600" else "\uD83C\uDF19",
                                fontSize = 18.sp, modifier = Modifier.width(28.dp))
                            Spacer(Modifier.width(8.dp))
                            Column {
                                Text(if (isDarkMode) "Modo oscuro" else "Modo claro",
                                    fontSize = 13.sp, fontWeight = FontWeight.Medium, color = AppTheme.texto)
                                Text("Toca para cambiar", fontSize = 11.sp, color = AppTheme.textoSec)
                            }
                        }
                        Switch(checked = isDarkMode, onCheckedChange = { onToggleDark() },
                            colors = SwitchDefaults.colors(checkedThumbColor = Color.White,
                                checkedTrackColor = AppTheme.accent,
                                uncheckedTrackColor = Color.Gray.copy(alpha = 0.4f)))
                    }
                },
                onClick = { onToggleDark() }
            )

            HorizontalDivider(modifier = Modifier.padding(vertical = 4.dp),
                color = AppTheme.textoSec.copy(alpha = 0.2f))

            DropdownMenuItem(enabled = false, text = {
                Text("CONTRASTE DE TEXTO", fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    color = AppTheme.accent, letterSpacing = 1.sp)
            }, onClick = {})

            DropdownMenuItem(
                text = {
                    Row(verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween,
                        modifier = Modifier.fillMaxWidth()) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(modifier = Modifier.size(28.dp).clip(RoundedCornerShape(4.dp))
                                .background(if (highContrast) Color.Black else Color.Transparent),
                                contentAlignment = Alignment.Center) {
                                Text("A",
                                    fontSize   = if (highContrast) 18.sp else 15.sp,
                                    fontWeight = if (highContrast) FontWeight.Black else FontWeight.Normal,
                                    color      = if (highContrast) Color.White else AppTheme.texto)
                            }
                            Spacer(Modifier.width(8.dp))
                            Column {
                                Text(if (highContrast) "Alto contraste" else "Contraste normal",
                                    fontSize = 13.sp, fontWeight = FontWeight.Medium, color = AppTheme.texto)
                                Text(if (highContrast) "Texto maximo legible" else "Toca para aumentar",
                                    fontSize = 11.sp, color = AppTheme.textoSec)
                            }
                        }
                        Switch(checked = highContrast, onCheckedChange = { onToggleContrast() },
                            colors = SwitchDefaults.colors(checkedThumbColor = Color.White,
                                checkedTrackColor = AppTheme.accent,
                                uncheckedTrackColor = Color.Gray.copy(alpha = 0.4f)))
                    }
                },
                onClick = { onToggleContrast() }
            )

            HorizontalDivider(modifier = Modifier.padding(vertical = 4.dp),
                color = AppTheme.textoSec.copy(alpha = 0.2f))

            DropdownMenuItem(enabled = false, text = {
                Text("VISION DE COLOR", fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    color = AppTheme.accent, letterSpacing = 1.sp)
            }, onClick = {})

            opcionesDaltonismo.forEach { opcion ->
                val activo = daltonism == opcion.modo
                DropdownMenuItem(
                    text = {
                        Row(verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth()) {
                            Box(modifier = Modifier.size(16.dp).clip(CircleShape)
                                .background(if (activo) AppTheme.accent else opcion.color))
                            Spacer(Modifier.width(10.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(opcion.nombre, fontSize = 13.sp,
                                    fontWeight = if (activo) FontWeight.Bold else FontWeight.Normal,
                                    color = AppTheme.texto)
                                Text(opcion.descripcion, fontSize = 11.sp, color = AppTheme.textoSec)
                            }
                            if (activo) Text("\u2713", color = AppTheme.accent,
                                fontWeight = FontWeight.Bold, fontSize = 14.sp)
                        }
                    },
                    onClick = { onChangeDaltonism(opcion.modo); expandido = false }
                )
            }
        }
    }
}

// ============================================================
// NAVBAR
// ============================================================
@Composable
fun Navbar(
    nombre            : String,
    email             : String,
    fotoPerfil        : String?,
    isDarkMode        : Boolean,
    onToggleDark      : () -> Unit,
    daltonism         : DaltonismMode,
    onChangeDaltonism : (DaltonismMode) -> Unit,
    highContrast      : Boolean,
    onToggleContrast  : () -> Unit,
    onFotoChanged     : (String?) -> Unit,
    onLogout          : () -> Unit
) {
    var mostrarMenuFoto by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val pickImage = rememberImagePickerLauncher { uri ->
        if (uri != null) {
            scope.launch {
                val bytes = leerBytesDeImagen(uri)
                if (bytes != null) {
                    val url = subirFotoPerfil(email, bytes)
                    onFotoChanged(url ?: uri)
                }
            }
        }
    }

    Row(
        modifier = Modifier.fillMaxWidth().background(AppTheme.navbar)
            .statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box {
            if (fotoPerfil != null) {
                AsyncImage(model = fotoPerfil, contentDescription = "Foto de perfil",
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.size(42.dp).clip(CircleShape).clickable { mostrarMenuFoto = true })
            } else {
                Box(modifier = Modifier.size(42.dp).clip(CircleShape)
                    .background(AppTheme.accent).clickable { mostrarMenuFoto = true },
                    contentAlignment = Alignment.Center) {
                    Text(nombre.firstOrNull()?.uppercase() ?: "?",
                        color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                }
            }
            DropdownMenu(expanded = mostrarMenuFoto, onDismissRequest = { mostrarMenuFoto = false }) {
                DropdownMenuItem(text = { Text("Cambiar foto") },
                    onClick = { mostrarMenuFoto = false; pickImage() })
                if (fotoPerfil != null) {
                    DropdownMenuItem(text = { Text("Quitar foto") },
                        onClick = { onFotoChanged(null); mostrarMenuFoto = false })
                }
            }
        }

        Spacer(modifier = Modifier.width(12.dp))

        Column(modifier = Modifier.weight(1f)) {
            Text("Bienvenido", color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp)
            Text(nombre, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp)
        }

        MenuAccesibilidad(
            isDarkMode        = isDarkMode,
            onToggleDark      = onToggleDark,
            daltonism         = daltonism,
            onChangeDaltonism = onChangeDaltonism,
            highContrast      = highContrast,
            onToggleContrast  = onToggleContrast
        )

        TextButton(onClick = onLogout) {
            Text("Cerrar sesion", color = Color.White)
        }
    }
}

@Composable
fun BotonRegresar(onClick: () -> Unit) {
    Box(modifier = Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.BottomEnd) {
        FloatingActionButton(onClick = onClick, containerColor = AppTheme.primary,
            contentColor = Color.White, modifier = Modifier.size(56.dp)) {
            Text("<", fontSize = 22.sp, fontWeight = FontWeight.Bold)
        }
    }
}

// ============================================================
// LOGIN SCREEN
// ============================================================
@Composable
fun LoginScreen(
    isDarkMode        : Boolean,
    onToggleDark      : () -> Unit,
    daltonism         : DaltonismMode,
    onChangeDaltonism : (DaltonismMode) -> Unit,
    highContrast      : Boolean,
    onToggleContrast  : () -> Unit,
    onLoginSuccess    : (String, String, String, String?) -> Unit
) {
    val scope = rememberCoroutineScope()
    var email    by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var errorMsg by remember { mutableStateOf("") }
    var loading  by remember { mutableStateOf(false) }

    Box(modifier = Modifier.fillMaxSize().background(AppTheme.fondo)) {
        Box(modifier = Modifier.fillMaxWidth().statusBarsPadding()
            .padding(end = 8.dp, top = 4.dp), contentAlignment = Alignment.TopEnd) {
            MenuAccesibilidad(isDarkMode = isDarkMode, onToggleDark = onToggleDark,
                daltonism = daltonism, onChangeDaltonism = onChangeDaltonism,
                highContrast = highContrast, onToggleContrast = onToggleContrast)
        }

        Column(modifier = Modifier.fillMaxSize().padding(32.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally) {
            Image(painter = painterResource(Res.drawable.logo),
                contentDescription = "Logo VecinetApp",
                modifier = Modifier.size(200.dp).padding(bottom = 8.dp))
            Text("VecinetApp", fontSize = 32.sp, fontWeight = FontWeight.Bold,
                color = AppTheme.texto, modifier = Modifier.padding(bottom = 32.dp))
            OutlinedTextField(value = email, onValueChange = { email = it },
                label = { Text("Email", color = AppTheme.textoSec) },
                modifier = Modifier.fillMaxWidth().padding(bottom = 16.dp), singleLine = true,
                colors = OutlinedTextFieldDefaults.colors(
                    focusedTextColor = AppTheme.texto, unfocusedTextColor = AppTheme.texto,
                    focusedBorderColor = AppTheme.accent, unfocusedBorderColor = AppTheme.textoSec,
                    focusedContainerColor = AppTheme.surface, unfocusedContainerColor = AppTheme.surface))
            OutlinedTextField(value = password, onValueChange = { password = it },
                label = { Text("Password", color = AppTheme.textoSec) },
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth().padding(bottom = 24.dp), singleLine = true,
                colors = OutlinedTextFieldDefaults.colors(
                    focusedTextColor = AppTheme.texto, unfocusedTextColor = AppTheme.texto,
                    focusedBorderColor = AppTheme.accent, unfocusedBorderColor = AppTheme.textoSec,
                    focusedContainerColor = AppTheme.surface, unfocusedContainerColor = AppTheme.surface))
            if (errorMsg.isNotEmpty()) {
                Text(errorMsg, color = AppTheme.rojo, modifier = Modifier.padding(bottom = 16.dp))
            }
            Button(
                onClick = {
                    scope.launch {
                        loading = true; errorMsg = ""
                        try {
                            val response = login(email, password)
                            if (response.success)
                                onLoginSuccess(response.nombre, response.rol, email, response.fotoPerfil)
                            else errorMsg = response.mensaje
                        } catch (e: Exception) { errorMsg = "Error: ${e.message}" }
                        loading = false
                    }
                },
                modifier = Modifier.fillMaxWidth().height(50.dp),
                colors = ButtonDefaults.buttonColors(containerColor = AppTheme.accent),
                enabled = !loading
            ) {
                if (loading) CircularProgressIndicator(color = Color.White, modifier = Modifier.size(24.dp))
                else Text("Iniciar Sesion", fontWeight = FontWeight.Bold, color = Color.White)
            }
        }
    }
}

// ============================================================
// SEMAFORO SCREEN
// ============================================================
@Composable
fun SemaforoScreen(
    nombre              : String,
    rol                 : String,
    email               : String,
    fotoPerfil          : String?,
    isDarkMode          : Boolean,
    onToggleDark        : () -> Unit,
    daltonism           : DaltonismMode,
    onChangeDaltonism   : (DaltonismMode) -> Unit,
    highContrast        : Boolean,
    onToggleContrast    : () -> Unit,
    onFotoChanged       : (String?) -> Unit,
    onNivelSeleccionado : (String, String) -> Unit,
    onVerReportes       : () -> Unit,
    onVerQr             : () -> Unit,
    onScanner           : () -> Unit,
    onLogout            : () -> Unit
) {
    val rolLower = rol.lowercase()
    val esResidente = rolLower.contains("residente") || rolLower.contains("visitante")
    val esGuardia   = rolLower.contains("guardia")

    Column(modifier = Modifier.fillMaxSize()) {
        Navbar(nombre = nombre, email = email, fotoPerfil = fotoPerfil,
            isDarkMode = isDarkMode, onToggleDark = onToggleDark,
            daltonism = daltonism, onChangeDaltonism = onChangeDaltonism,
            highContrast = highContrast, onToggleContrast = onToggleContrast,
            onFotoChanged = onFotoChanged, onLogout = onLogout)
        Box(modifier = Modifier.fillMaxSize().background(AppTheme.fondo)) {
            Column(modifier = Modifier.fillMaxSize().padding(32.dp),
                verticalArrangement = Arrangement.Center,
                horizontalAlignment = Alignment.CenterHorizontally) {

                Text("Nivel de Emergencia", fontSize = 24.sp, fontWeight = FontWeight.Bold,
                    color = AppTheme.texto, modifier = Modifier.padding(bottom = 8.dp))
                Text(rol, fontSize = 14.sp, color = AppTheme.textoSec,
                    modifier = Modifier.padding(bottom = 32.dp))

                // Semaforo
                Box(modifier = Modifier.width(140.dp).clip(RoundedCornerShape(70.dp))
                    .background(if (isDarkMode) Color(0xFF1E1E2E) else Color(0xFF222222))
                    .padding(20.dp), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(16.dp)) {
                        Button(onClick = { onNivelSeleccionado("ROJO", "") },
                            modifier = Modifier.size(80.dp), shape = CircleShape,
                            contentPadding = PaddingValues(0.dp),
                            colors = ButtonDefaults.buttonColors(containerColor = AppTheme.rojo)) {}
                        Button(onClick = { onNivelSeleccionado("AMARILLO", "") },
                            modifier = Modifier.size(80.dp), shape = CircleShape,
                            contentPadding = PaddingValues(0.dp),
                            colors = ButtonDefaults.buttonColors(containerColor = AppTheme.amarillo)) {}
                        Button(onClick = { onNivelSeleccionado("VERDE", "") },
                            modifier = Modifier.size(80.dp), shape = CircleShape,
                            contentPadding = PaddingValues(0.dp),
                            colors = ButtonDefaults.buttonColors(containerColor = AppTheme.verde)) {}
                    }
                }

                Spacer(modifier = Modifier.height(32.dp))

                // Ver reportes — todos los roles
                OutlinedButton(
                    onClick  = onVerReportes,
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                    border   = BorderStroke(1.5.dp, AppTheme.texto),
                    colors   = ButtonDefaults.outlinedButtonColors(
                        containerColor = Color.Transparent, contentColor = AppTheme.texto)
                ) {
                    Text("Ver mis reportes", color = AppTheme.texto,
                        fontWeight = FontWeight.Medium, fontSize = 15.sp)
                }

                // QR de acceso — solo residentes y visitantes
                if (esResidente) {
                    Spacer(modifier = Modifier.height(12.dp))
                    Button(
                        onClick  = onVerQr,
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.primary)
                    ) {
                        Text("Mi QR de acceso", color = Color.White, fontWeight = FontWeight.Bold)
                    }
                }

                // Scanner — solo guardias
                if (esGuardia) {
                    Spacer(modifier = Modifier.height(12.dp))
                    Button(
                        onClick  = onScanner,
                        modifier = Modifier.fillMaxWidth().height(56.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.verde)
                    ) {
                        Text("Escanear QR de acceso", color = Color.White,
                            fontWeight = FontWeight.Bold, fontSize = 17.sp)
                    }
                }
            }
        }
    }
}

// ============================================================
// EMERGENCIA SCREEN
// ============================================================
@Composable
fun EmergenciaScreen(
    nombre             : String,
    email              : String,
    nivel              : String,
    fotoPerfil         : String?,
    isDarkMode         : Boolean,
    onToggleDark       : () -> Unit,
    daltonism          : DaltonismMode,
    onChangeDaltonism  : (DaltonismMode) -> Unit,
    highContrast       : Boolean,
    onToggleContrast   : () -> Unit,
    onFotoChanged      : (String?) -> Unit,
    onTipoSeleccionado : (String) -> Unit,
    onVolver           : () -> Unit
) {
    val colorFondo = when (nivel) {
        "ROJO"     -> AppTheme.rojo
        "AMARILLO" -> AppTheme.amarillo
        "VERDE"    -> AppTheme.verde
        else       -> Color.Gray
    }
    val titulo = when (nivel) {
        "ROJO"     -> "EMERGENCIA ALTA"
        "AMARILLO" -> "EMERGENCIA MEDIA"
        "VERDE"    -> "NIVEL NORMAL"
        else       -> "DESCONOCIDO"
    }
    val botones = when (nivel) {
        "ROJO"     -> listOf("Asalto/Robo", "Violencia", "Emergencia medica")
        "AMARILLO" -> listOf("Sospechoso", "Incendio", "Ruido extrano")
        "VERDE"    -> listOf("Fuga de agua", "Bache", "Llegada de servicios", "Otro")
        else       -> emptyList()
    }
    val colorTexto = if (nivel == "AMARILLO") Color.Black else Color.White
    val colorBoton = if (nivel == "AMARILLO") Color.Black.copy(alpha = 0.15f)
    else Color.White.copy(alpha = 0.2f)

    Column(modifier = Modifier.fillMaxSize()) {
        Navbar(nombre = nombre, email = email, fotoPerfil = fotoPerfil,
            isDarkMode = isDarkMode, onToggleDark = onToggleDark,
            daltonism = daltonism, onChangeDaltonism = onChangeDaltonism,
            highContrast = highContrast, onToggleContrast = onToggleContrast,
            onFotoChanged = onFotoChanged, onLogout = onVolver)
        Box(modifier = Modifier.fillMaxSize()) {
            Column(modifier = Modifier.fillMaxSize().background(colorFondo).padding(32.dp),
                verticalArrangement = Arrangement.Center,
                horizontalAlignment = Alignment.CenterHorizontally) {
                Text(titulo, fontSize = 28.sp, fontWeight = FontWeight.Bold,
                    color = colorTexto, modifier = Modifier.padding(bottom = 8.dp))
                Text("Selecciona el tipo de reporte", fontSize = 16.sp,
                    color = colorTexto.copy(alpha = 0.8f), modifier = Modifier.padding(bottom = 32.dp))
                botones.forEach { tipo ->
                    Button(onClick = { onTipoSeleccionado(tipo) },
                        colors = ButtonDefaults.buttonColors(containerColor = colorBoton),
                        modifier = Modifier.fillMaxWidth().height(56.dp)) {
                        Text(tipo, color = colorTexto, fontWeight = FontWeight.Bold, fontSize = 16.sp)
                    }
                    Spacer(modifier = Modifier.height(8.dp))
                }
            }
            BotonRegresar(onClick = onVolver)
        }
    }
}

// ============================================================
// DETALLE REPORTE SCREEN
// ============================================================
@Composable
fun DetalleReporteScreen(
    nombre            : String,
    email             : String,
    nivel             : String,
    tipo              : String,
    fotoPerfil        : String?,
    isDarkMode        : Boolean,
    onToggleDark      : () -> Unit,
    daltonism         : DaltonismMode,
    onChangeDaltonism : (DaltonismMode) -> Unit,
    highContrast      : Boolean,
    onToggleContrast  : () -> Unit,
    onFotoChanged     : (String?) -> Unit,
    onReportar        : (String, String?, Ubicacion?) -> Unit,
    onVolver          : () -> Unit
) {
    val colorFondo   = when (nivel) { "ROJO" -> AppTheme.rojo; "AMARILLO" -> AppTheme.amarillo; "VERDE" -> AppTheme.verde; else -> Color.Gray }
    val colorTexto   = if (nivel == "AMARILLO") Color.Black else Color.White
    val colorBoton   = if (nivel == "AMARILLO") Color.Black.copy(alpha = 0.15f) else Color.White.copy(alpha = 0.2f)
    val permitefotos = nivel == "ROJO" || nivel == "AMARILLO"

    var descripcion by remember { mutableStateOf("") }
    var fotoReporte by remember { mutableStateOf<String?>(null) }
    var ubicacion   by remember { mutableStateOf<Ubicacion?>(null) }
    var obteniendo  by remember { mutableStateOf(true) }
    var enviando    by remember { mutableStateOf(false) }
    var errorEnvio  by remember { mutableStateOf("") }
    val scope           = rememberCoroutineScope()
    val locationService = remember { LocationService() }
    val takePhoto       = rememberCameraLauncher { uri -> fotoReporte = uri }

    val activarGps = rememberGpsActivator(
        onGpsListo = {
            scope.launch {
                obteniendo = true
                ubicacion  = locationService.obtenerUbicacion()
                obteniendo = false
            }
        },
        onGpsNoDisponible = { obteniendo = false }
    )

    LaunchedEffect(Unit) { activarGps() }

    Column(modifier = Modifier.fillMaxSize()) {
        Navbar(nombre = nombre, email = email, fotoPerfil = fotoPerfil,
            isDarkMode = isDarkMode, onToggleDark = onToggleDark,
            daltonism = daltonism, onChangeDaltonism = onChangeDaltonism,
            highContrast = highContrast, onToggleContrast = onToggleContrast,
            onFotoChanged = onFotoChanged, onLogout = onVolver)
        Box(modifier = Modifier.fillMaxSize()) {
            Column(modifier = Modifier.fillMaxSize().background(colorFondo).padding(32.dp),
                verticalArrangement = Arrangement.Center,
                horizontalAlignment = Alignment.CenterHorizontally) {
                Text(tipo, fontSize = 24.sp, fontWeight = FontWeight.Bold,
                    color = colorTexto, modifier = Modifier.padding(bottom = 24.dp))
                OutlinedTextField(value = descripcion, onValueChange = { descripcion = it },
                    label = { Text("Descripcion del reporte", color = colorTexto.copy(alpha = 0.7f)) },
                    modifier = Modifier.fillMaxWidth().height(120.dp), maxLines = 4,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedTextColor = colorTexto, unfocusedTextColor = colorTexto,
                        focusedBorderColor = colorTexto, unfocusedBorderColor = colorTexto.copy(alpha = 0.5f)))
                Spacer(modifier = Modifier.height(16.dp))
                if (permitefotos) {
                    if (fotoReporte != null) {
                        AsyncImage(model = fotoReporte, contentDescription = "Foto del reporte",
                            contentScale = ContentScale.Crop,
                            modifier = Modifier.fillMaxWidth().height(160.dp).clip(RoundedCornerShape(12.dp)))
                        Spacer(modifier = Modifier.height(8.dp))
                        TextButton(onClick = { fotoReporte = null }) {
                            Text("Quitar foto", color = colorTexto.copy(alpha = 0.7f))
                        }
                    } else {
                        Button(onClick = { takePhoto() },
                            colors = ButtonDefaults.buttonColors(containerColor = colorBoton),
                            modifier = Modifier.fillMaxWidth().height(50.dp)) {
                            Text("Tomar foto", color = colorTexto, fontWeight = FontWeight.Bold)
                        }
                    }
                    Spacer(modifier = Modifier.height(8.dp))
                }
                if (obteniendo) {
                    CircularProgressIndicator(color = colorTexto, modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                    Spacer(modifier = Modifier.height(8.dp))
                } else if (ubicacion == null) {
                    Text("GPS no disponible. Activa la ubicacion y reintenta.",
                        color = colorTexto, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.padding(bottom = 8.dp))
                    Button(onClick = { activarGps() },
                        colors = ButtonDefaults.buttonColors(containerColor = colorBoton),
                        modifier = Modifier.fillMaxWidth().height(50.dp)) {
                        Text("Activar GPS", color = colorTexto, fontWeight = FontWeight.Bold)
                    }
                    Spacer(modifier = Modifier.height(8.dp))
                }
                if (errorEnvio.isNotEmpty()) {
                    Text(errorEnvio, color = colorTexto, fontSize = 13.sp,
                        modifier = Modifier.padding(bottom = 8.dp))
                }
                Button(
                    onClick = {
                        scope.launch {
                            enviando = true; errorEnvio = ""
                            try {
                                val nivelInt = when (nivel) { "ROJO" -> 3; "AMARILLO" -> 2; else -> 1 }
                                var urlFoto: String? = null
                                if (fotoReporte != null) {
                                    val bytes = leerBytesDeImagen(fotoReporte!!)
                                    if (bytes != null) urlFoto = subirFoto(bytes, "foto_reporte.jpg")
                                }
                                val request = ReporteRequest(
                                    usuarioEmail  = email,
                                    usuarioNombre = nombre,
                                    tipo          = tipo,
                                    nivel         = nivelInt,
                                    descripcion   = descripcion.ifBlank { null },
                                    fotoUrl       = urlFoto,
                                    latitud       = ubicacion?.latitud,
                                    longitud      = ubicacion?.longitud
                                )
                                val response = enviarReporte(request)
                                if (response.success) onReportar(descripcion, fotoReporte, ubicacion)
                                else errorEnvio = response.mensaje
                            } catch (e: Exception) { errorEnvio = "Error: ${e.message}" }
                            enviando = false
                        }
                    },
                    colors = ButtonDefaults.buttonColors(containerColor = colorBoton),
                    modifier = Modifier.fillMaxWidth().height(56.dp),
                    enabled = !enviando && !obteniendo && ubicacion != null
                ) {
                    if (enviando) CircularProgressIndicator(color = colorTexto, modifier = Modifier.size(20.dp))
                    else Text("Enviar reporte", color = colorTexto, fontWeight = FontWeight.Bold, fontSize = 16.sp)
                }
            }
            BotonRegresar(onClick = onVolver)
        }
    }
}

// ============================================================
// REPORTES SCREEN
// ============================================================
@Composable
fun ReportesScreen(
    nombre            : String,
    email             : String,
    fotoPerfil        : String?,
    isDarkMode        : Boolean,
    onToggleDark      : () -> Unit,
    daltonism         : DaltonismMode,
    onChangeDaltonism : (DaltonismMode) -> Unit,
    highContrast      : Boolean,
    onToggleContrast  : () -> Unit,
    onFotoChanged     : (String?) -> Unit,
    reportes          : List<Reporte>,
    onVolver          : () -> Unit
) {
    Column(modifier = Modifier.fillMaxSize()) {
        Navbar(nombre = nombre, email = email, fotoPerfil = fotoPerfil,
            isDarkMode = isDarkMode, onToggleDark = onToggleDark,
            daltonism = daltonism, onChangeDaltonism = onChangeDaltonism,
            highContrast = highContrast, onToggleContrast = onToggleContrast,
            onFotoChanged = onFotoChanged, onLogout = onVolver)
        Box(modifier = Modifier.fillMaxSize().background(AppTheme.fondo)) {
            Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
                Text("Mis Reportes", fontSize = 24.sp, fontWeight = FontWeight.Bold,
                    color = AppTheme.texto, modifier = Modifier.padding(bottom = 16.dp))
                if (reportes.isEmpty()) {
                    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        Text("No has realizado reportes aun", color = AppTheme.textoSec, fontSize = 16.sp)
                    }
                } else {
                    LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(reportes.size) { index ->
                            val reporte = reportes[reportes.size - 1 - index]
                            Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(12.dp),
                                colors = CardDefaults.cardColors(containerColor = AppTheme.card)) {
                                Column(modifier = Modifier.padding(16.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Box(modifier = Modifier.size(14.dp).clip(CircleShape).background(reporte.color))
                                        Spacer(modifier = Modifier.width(10.dp))
                                        Column {
                                            Text(reporte.tipo, fontWeight = FontWeight.Bold,
                                                fontSize = 16.sp, color = AppTheme.texto)
                                            Text("Nivel: ${reporte.nivel}", color = AppTheme.textoSec, fontSize = 13.sp)
                                        }
                                    }
                                    if (reporte.descripcion.isNotBlank()) {
                                        Spacer(modifier = Modifier.height(8.dp))
                                        Text(reporte.descripcion, fontSize = 14.sp, color = AppTheme.textoSec)
                                    }
                                    if (reporte.ubicacion != null) {
                                        Spacer(modifier = Modifier.height(4.dp))
                                        Text("Lat: ${reporte.ubicacion.latitud}, Lon: ${reporte.ubicacion.longitud}",
                                            fontSize = 12.sp, color = AppTheme.textoSec)
                                    }
                                    if (reporte.foto != null) {
                                        Spacer(modifier = Modifier.height(8.dp))
                                        AsyncImage(model = reporte.foto, contentDescription = "Foto del reporte",
                                            contentScale = ContentScale.Crop,
                                            modifier = Modifier.fillMaxWidth().height(140.dp).clip(RoundedCornerShape(8.dp)))
                                    }
                                }
                            }
                        }
                    }
                }
            }
            BotonRegresar(onClick = onVolver)
        }
    }
}