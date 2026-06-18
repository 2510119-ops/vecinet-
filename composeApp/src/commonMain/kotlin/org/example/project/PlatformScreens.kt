package org.example.project

import androidx.compose.runtime.Composable

@Composable
expect fun QrResidenteScreen(
    email      : String,
    nombre     : String,
    numeroCasa : String,
    cerrada    : String,
    onVolver   : () -> Unit
)

@Composable
expect fun ScannerGuardiaScreen(
    nombreGuardia: String,
    emailGuardia : String,
    onVolver     : () -> Unit
)