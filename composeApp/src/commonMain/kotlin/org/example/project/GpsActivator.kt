package org.example.project

import androidx.compose.runtime.Composable

@Composable
expect fun rememberGpsActivator(
    onGpsListo: () -> Unit,
    onGpsNoDisponible: () -> Unit
): () -> Unit