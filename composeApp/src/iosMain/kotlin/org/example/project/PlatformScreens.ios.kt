package org.example.project

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier

@Composable
actual fun QrResidenteScreen(
    email: String, nombre: String, numeroCasa: String,
    cerrada: String, onVolver: () -> Unit
) {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Text("QR no disponible en iOS")
    }
}

@Composable
actual fun ScannerGuardiaScreen(
    nombreGuardia: String, emailGuardia: String, onVolver: () -> Unit
) {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Text("Scanner no disponible en iOS")
    }
}