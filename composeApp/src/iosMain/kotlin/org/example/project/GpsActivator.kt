package org.example.project

import androidx.compose.runtime.Composable

@Composable
actual fun rememberGpsActivator(
    onGpsListo: () -> Unit,
    onGpsNoDisponible: () -> Unit
): () -> Unit {
    return { onGpsListo() }
}