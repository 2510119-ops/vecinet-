package org.example.project

import android.graphics.Bitmap
import android.graphics.Color
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch

// ── Genera un Bitmap con el QR del email ─────────────────────
private fun generarQr(contenido: String, size: Int = 600): Bitmap {
    val writer    = QRCodeWriter()
    val bitMatrix = writer.encode(contenido, BarcodeFormat.QR_CODE, size, size)
    val bmp       = Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565)
    for (x in 0 until size) {
        for (y in 0 until size) {
            bmp.setPixel(x, y, if (bitMatrix[x, y]) Color.BLACK else Color.WHITE)
        }
    }
    return bmp
}

// ============================================================
// PANTALLA QR — para residentes
// ============================================================
@Composable
actual fun QrResidenteScreen(
    email      : String,
    nombre     : String,
    numeroCasa : String,
    cerrada    : String,
    onVolver   : () -> Unit
) {
    val qrBitmap = remember(email) { generarQr(email) }

    Box(
        modifier         = Modifier.fillMaxSize().background(AppTheme.fondo),
        contentAlignment = Alignment.Center
    ) {
        Column(
            modifier            = Modifier.fillMaxSize().padding(32.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                text       = "Mi codigo QR de acceso",
                fontSize   = 22.sp,
                fontWeight = FontWeight.Bold,
                color      = AppTheme.texto,
                modifier   = Modifier.padding(bottom = 8.dp)
            )
            Text(
                text      = "Presenta este codigo al guardia\npara registrar tu entrada o salida.",
                fontSize  = 14.sp,
                color     = AppTheme.textoSec,
                textAlign = TextAlign.Center,
                modifier  = Modifier.padding(bottom = 24.dp)
            )

            // QR
            Box(
                modifier = Modifier
                    .size(260.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(androidx.compose.ui.graphics.Color.White)
                    .padding(16.dp),
                contentAlignment = Alignment.Center
            ) {
                Image(
                    bitmap             = qrBitmap.asImageBitmap(),
                    contentDescription = "QR de acceso",
                    modifier           = Modifier.fillMaxSize()
                )
            }

            Spacer(modifier = Modifier.height(24.dp))

            // Info del residente
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape    = RoundedCornerShape(12.dp),
                colors   = CardDefaults.cardColors(containerColor = AppTheme.card)
            ) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Text(
                        nombre,
                        fontSize   = 18.sp,
                        fontWeight = FontWeight.Bold,
                        color      = AppTheme.texto
                    )
                    if (numeroCasa.isNotBlank()) {
                        Text(
                            "Casa $numeroCasa",
                            fontSize = 14.sp,
                            color    = AppTheme.textoSec
                        )
                    }
                    if (cerrada.isNotBlank()) {
                        Text(
                            cerrada,
                            fontSize = 14.sp,
                            color    = AppTheme.textoSec
                        )
                    }
                    Text(
                        email,
                        fontSize = 12.sp,
                        color    = AppTheme.textoSec
                    )
                }
            }

            Spacer(modifier = Modifier.height(24.dp))

            Button(
                onClick  = onVolver,
                modifier = Modifier.fillMaxWidth().height(50.dp),
                colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.accent)
            ) {
                Text("Volver", color = androidx.compose.ui.graphics.Color.White, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ============================================================
// PANTALLA SCANNER — para guardias
// ============================================================
@Composable
actual fun ScannerGuardiaScreen(
    nombreGuardia: String,
    emailGuardia : String,
    onVolver     : () -> Unit
) {
    val scope = rememberCoroutineScope()

    // Estado del resultado del escaneo
    var estado      by remember { mutableStateOf("esperando") } // esperando | cargando | vigente | denegado
    var nombreRes   by remember { mutableStateOf("") }
    var casaRes     by remember { mutableStateOf("") }
    var cerradaRes  by remember { mutableStateOf("") }
    var tipoAcceso  by remember { mutableStateOf("") }  // entrada | salida
    var mensajeErr  by remember { mutableStateOf("") }
    var emailEscaneado by remember { mutableStateOf("") }

    // Launcher del scanner ZXing
    val scanLauncher = rememberLauncherForActivityResult(ScanContract()) { result ->
        if (result.contents != null) {
            val emailQr = result.contents.trim()
            emailEscaneado = emailQr
            estado = "cargando"

            scope.launch {
                try {
                    val res = verificarEstadoAcceso(emailQr)
                    if (res.success) {
                        nombreRes  = res.nombre
                        casaRes    = res.numeroCasa
                        cerradaRes = res.cerrada
                        tipoAcceso = res.siguienteTipo

                        if (res.estadoPago == "vigente") {
                            // Registrar en bitacora
                            val reg = registrarAcceso(emailQr, res.siguienteTipo, nombreGuardia)
                            estado = if (reg.success) "vigente" else "denegado"
                            if (!reg.success) mensajeErr = reg.mensaje
                        } else {
                            estado     = "denegado"
                            mensajeErr = "ACCESO DENEGADO — Pago pendiente de vigilancia"
                        }
                    } else {
                        estado     = "denegado"
                        mensajeErr = res.mensaje.ifBlank { "Residente no encontrado" }
                    }
                } catch (e: Exception) {
                    estado     = "denegado"
                    mensajeErr = "Error de conexion: ${e.message}"
                }
            }
        }
    }

    Box(modifier = Modifier.fillMaxSize().background(AppTheme.fondo)) {
        Column(
            modifier            = Modifier.fillMaxSize().padding(32.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                text       = "Control de Acceso",
                fontSize   = 24.sp,
                fontWeight = FontWeight.Bold,
                color      = AppTheme.texto,
                modifier   = Modifier.padding(bottom = 4.dp)
            )
            Text(
                text     = "Guardia: $nombreGuardia",
                fontSize = 14.sp,
                color    = AppTheme.textoSec,
                modifier = Modifier.padding(bottom = 32.dp)
            )

            when (estado) {

                // ── En espera ────────────────────────────────
                "esperando" -> {
                    Box(
                        modifier = Modifier
                            .size(200.dp)
                            .clip(RoundedCornerShape(16.dp))
                            .background(AppTheme.card),
                        contentAlignment = Alignment.Center
                    ) {
                        Text("📷", fontSize = 64.sp)
                    }
                    Spacer(modifier = Modifier.height(32.dp))
                    Button(
                        onClick = {
                            val opts = ScanOptions().apply {
                                setPrompt("Apunta la camara al QR del residente")
                                setBeepEnabled(true)
                                setOrientationLocked(false)
                                setBarcodeImageEnabled(false)
                            }
                            scanLauncher.launch(opts)
                        },
                        modifier = Modifier.fillMaxWidth().height(56.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.accent)
                    ) {
                        Text(
                            "ESCANEAR QR",
                            color      = androidx.compose.ui.graphics.Color.White,
                            fontWeight = FontWeight.Bold,
                            fontSize   = 18.sp
                        )
                    }
                }

                // ── Cargando ─────────────────────────────────
                "cargando" -> {
                    CircularProgressIndicator(
                        color    = AppTheme.accent,
                        modifier = Modifier.size(64.dp)
                    )
                    Spacer(modifier = Modifier.height(16.dp))
                    Text("Verificando...", color = AppTheme.textoSec, fontSize = 16.sp)
                }

                // ── Acceso vigente ────────────────────────────
                "vigente" -> {
                    val colorFondo = if (tipoAcceso == "entrada")
                        androidx.compose.ui.graphics.Color(0xFF006600)
                    else
                        androidx.compose.ui.graphics.Color(0xFF664488)

                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape    = RoundedCornerShape(16.dp),
                        colors   = CardDefaults.cardColors(containerColor = colorFondo)
                    ) {
                        Column(
                            modifier            = Modifier.padding(24.dp),
                            horizontalAlignment = Alignment.CenterHorizontally
                        ) {
                            Text(
                                text       = if (tipoAcceso == "entrada") "✅ ENTRADA REGISTRADA" else "✅ SALIDA REGISTRADA",
                                fontSize   = 20.sp,
                                fontWeight = FontWeight.Bold,
                                color      = androidx.compose.ui.graphics.Color.White,
                                textAlign  = TextAlign.Center
                            )
                            Spacer(modifier = Modifier.height(16.dp))
                            Text(nombreRes, fontSize = 18.sp, fontWeight = FontWeight.Bold, color = androidx.compose.ui.graphics.Color.White)
                            if (casaRes.isNotBlank()) Text("Casa $casaRes", fontSize = 14.sp, color = androidx.compose.ui.graphics.Color.White.copy(alpha = 0.8f))
                            if (cerradaRes.isNotBlank()) Text(cerradaRes, fontSize = 14.sp, color = androidx.compose.ui.graphics.Color.White.copy(alpha = 0.8f))
                            Text(emailEscaneado, fontSize = 12.sp, color = androidx.compose.ui.graphics.Color.White.copy(alpha = 0.7f))
                        }
                    }
                    Spacer(modifier = Modifier.height(24.dp))
                    Button(
                        onClick  = { estado = "esperando" },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.accent)
                    ) {
                        Text("Escanear otro", color = androidx.compose.ui.graphics.Color.White, fontWeight = FontWeight.Bold)
                    }
                }

                // ── Acceso denegado ───────────────────────────
                "denegado" -> {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape    = RoundedCornerShape(16.dp),
                        colors   = CardDefaults.cardColors(containerColor = androidx.compose.ui.graphics.Color(0xFFCC0000))
                    ) {
                        Column(
                            modifier            = Modifier.padding(24.dp),
                            horizontalAlignment = Alignment.CenterHorizontally
                        ) {
                            Text(
                                text       = "🚫 ACCESO DENEGADO",
                                fontSize   = 20.sp,
                                fontWeight = FontWeight.Bold,
                                color      = androidx.compose.ui.graphics.Color.White,
                                textAlign  = TextAlign.Center
                            )
                            Spacer(modifier = Modifier.height(12.dp))
                            Text(
                                text      = mensajeErr,
                                fontSize  = 14.sp,
                                color     = androidx.compose.ui.graphics.Color.White,
                                textAlign = TextAlign.Center
                            )
                            if (nombreRes.isNotBlank()) {
                                Spacer(modifier = Modifier.height(8.dp))
                                Text(nombreRes, fontSize = 16.sp, fontWeight = FontWeight.Bold, color = androidx.compose.ui.graphics.Color.White)
                                if (casaRes.isNotBlank()) Text("Casa $casaRes", fontSize = 14.sp, color = androidx.compose.ui.graphics.Color.White.copy(alpha = 0.8f))
                            }
                        }
                    }
                    Spacer(modifier = Modifier.height(24.dp))
                    Button(
                        onClick  = { estado = "esperando"; nombreRes = ""; mensajeErr = "" },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = AppTheme.accent)
                    ) {
                        Text("Intentar de nuevo", color = androidx.compose.ui.graphics.Color.White, fontWeight = FontWeight.Bold)
                    }
                }
            }

            Spacer(modifier = Modifier.height(16.dp))
            TextButton(onClick = onVolver) {
                Text("Volver al semaforo", color = AppTheme.textoSec)
            }
        }
    }
}